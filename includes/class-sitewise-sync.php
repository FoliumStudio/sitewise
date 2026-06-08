<?php
/**
 * Sync layer.
 *
 * Watches content changes, marks the corpus dirty, and on a cron tick rebuilds
 * the corpus and pushes it to the Cloudflare Worker's /sync endpoint (auth via
 * the shared secret). Also exposes a /health connectivity check for the admin
 * screen and a force-rebuild used by the activation hook + admin button.
 *
 * MVP is "stuff-the-prompt": any content change triggers a full rebuild. Per-post
 * incremental chunking (for RAG) is a later phase.
 *
 * @package Sitewise
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Sitewise_Sync {

	const PENDING_FLAG = 'sitewise_sync_pending';
	const STATUS_OPT   = 'sitewise_sync_status';

	/**
	 * @var Sitewise_Corpus
	 */
	private $corpus;

	/**
	 * @param Sitewise_Corpus $corpus Corpus generator.
	 */
	public function __construct( Sitewise_Corpus $corpus ) {
		$this->corpus = $corpus;

		// Content-change triggers.
		add_action( 'save_post', array( $this, 'on_post_change' ), 10, 2 );
		add_action( 'wp_trash_post', array( $this, 'on_post_removed' ) );
		add_action( 'deleted_post', array( $this, 'on_post_removed' ) );
		add_action( 'transition_post_status', array( $this, 'on_status_change' ), 10, 3 );

		// Cron flush + the one-shot rebuild scheduled on activation / by admin.
		add_action( SITEWISE_CRON_HOOK, array( $this, 'flush_queue' ) );
		add_action( 'sitewise_rebuild_all', array( $this, 'rebuild_all' ) );
	}

	/**
	 * Mark dirty on save, ignoring autosaves/revisions and non-corpus types.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function on_post_change( $post_id, $post ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! $this->is_corpus_type( $post->post_type ) ) {
			return;
		}
		$this->mark_dirty();
	}

	/**
	 * Mark dirty on trash/delete.
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_post_removed( $post_id ) {
		$post = get_post( $post_id );
		if ( $post && $this->is_corpus_type( $post->post_type ) ) {
			$this->mark_dirty();
		}
	}

	/**
	 * Mark dirty when a post enters or leaves 'publish'.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post object.
	 */
	public function on_status_change( $new_status, $old_status, $post ) {
		if ( $new_status === $old_status ) {
			return;
		}
		if ( ( 'publish' === $new_status || 'publish' === $old_status ) && $this->is_corpus_type( $post->post_type ) ) {
			$this->mark_dirty();
		}
	}

	/**
	 * Is this post type part of the corpus per current settings?
	 *
	 * @param string $type Post type slug.
	 * @return bool
	 */
	private function is_corpus_type( $type ) {
		$types = (array) Sitewise::get( 'post_types', array( 'post', 'page' ) );
		return in_array( $type, $types, true );
	}

	/**
	 * Flag the corpus as needing a rebuild on the next cron tick.
	 */
	public function mark_dirty() {
		update_option( self::PENDING_FLAG, 1, false );
	}

	/**
	 * Cron handler: rebuild + push only if dirty.
	 */
	public function flush_queue() {
		if ( ! get_option( self::PENDING_FLAG ) ) {
			return;
		}
		delete_option( self::PENDING_FLAG );
		$this->rebuild_all();
	}

	/**
	 * Force a full rebuild and push. Returns the combined status.
	 *
	 * @return array
	 */
	public function rebuild_all() {
		$stats = $this->corpus->build_all();
		$push  = $this->push_to_worker();

		$status = array(
			'last_build' => $stats,
			'last_push'  => $push,
			'updated_at' => time(),
		);
		update_option( self::STATUS_OPT, $status, false );
		return $status;
	}

	/**
	 * Push the corpus to the Worker's /sync endpoint. No-op (with a clear
	 * status) if the Worker isn't configured yet.
	 *
	 * @return array{ok:bool,message:string,code:int}
	 */
	public function push_to_worker() {
		$worker_url = trim( (string) Sitewise::get( 'worker_url', '' ) );
		$secret     = (string) Sitewise::get( 'shared_secret', '' );

		if ( '' === $worker_url ) {
			return array(
				'ok'      => false,
				'message' => __( 'Worker URL not set — corpus built locally but not pushed.', 'wp-call-me-back' ),
				'code'    => 0,
			);
		}

		$urls = Sitewise_Corpus::file_urls();
		$dir  = Sitewise_Corpus::storage_dir();
		$full = is_readable( $dir . Sitewise_Corpus::FILE_FULL ) ? file_get_contents( $dir . Sitewise_Corpus::FILE_FULL ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$map  = is_readable( $dir . Sitewise_Corpus::FILE_MAP ) ? file_get_contents( $dir . Sitewise_Corpus::FILE_MAP ) : '';   // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$body = wp_json_encode(
			array(
				'site_key'  => $this->site_key(),
				'site_name' => get_bloginfo( 'name' ),
				'origin'    => home_url(),
				'contact'   => Sitewise::get( 'contact_url', home_url( '/contact/' ) ),
				'map'       => $map,
				'full'      => $full,
				'map_url'   => $urls['map'],
				'full_url'  => $urls['full'],
			)
		);

		$response = wp_remote_post(
			trailingslashit( $worker_url ) . 'sync',
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type'      => 'application/json',
					'X-Sitewise-Secret' => $secret,
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'message' => $response->get_error_message(),
				'code'    => 0,
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		return array(
			'ok'      => $code >= 200 && $code < 300,
			'message' => $code >= 200 && $code < 300
				? __( 'Corpus pushed to Worker.', 'wp-call-me-back' )
				: sprintf( /* translators: %d: HTTP status code */ __( 'Worker rejected the push (HTTP %d).', 'wp-call-me-back' ), $code ),
			'code'    => $code,
		);
	}

	/**
	 * Hit the Worker /health endpoint for the admin connectivity check.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function health_check() {
		$worker_url = trim( (string) Sitewise::get( 'worker_url', '' ) );
		if ( '' === $worker_url ) {
			return array( 'ok' => false, 'message' => __( 'No Worker URL configured.', 'wp-call-me-back' ) );
		}

		$response = wp_remote_get(
			trailingslashit( $worker_url ) . 'health',
			array( 'timeout' => 15 )
		);

		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'message' => $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		return array(
			'ok'      => 200 === $code,
			'message' => 200 === $code
				? __( 'Worker reachable.', 'wp-call-me-back' )
				: sprintf( /* translators: %d: HTTP status code */ __( 'Worker returned HTTP %d.', 'wp-call-me-back' ), $code ),
		);
	}

	/**
	 * Resolve the site key. In hosted mode this is the user-pasted key; in BYO
	 * mode we derive a stable key from the site URL host.
	 *
	 * @return string
	 */
	private function site_key() {
		$key = trim( (string) Sitewise::get( 'site_key', '' ) );
		if ( '' !== $key ) {
			return $key;
		}
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return $host ? $host : 'default';
	}
}
