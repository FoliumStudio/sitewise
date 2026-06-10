<?php
/**
 * Admin settings screen.
 *
 * One page under its own top-level menu: connection to the Worker, chatbot
 * appearance/behaviour, corpus build rules, and the call-back module toggle.
 * Form handling is manual (nonce + explicit sanitisation) so we control the
 * merge into the single settings array.
 *
 * @package Sitewise
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Sitewise_Admin {

	const PAGE_SLUG = 'sitewise';
	const NONCE     = 'sitewise_settings_nonce';

	/**
	 * @var Sitewise_Corpus
	 */
	private $corpus;

	/**
	 * @var Sitewise_Sync
	 */
	private $sync;

	/**
	 * Transient health-check result to surface after a "Test connection" click.
	 *
	 * @var array|null
	 */
	private $health = null;

	/**
	 * Settings page hook suffix (for scoped asset enqueue).
	 *
	 * @var string
	 */
	private $page_hook = '';

	/**
	 * @param Sitewise_Corpus $corpus Corpus generator.
	 * @param Sitewise_Sync   $sync   Sync layer.
	 */
	public function __construct( Sitewise_Corpus $corpus, Sitewise_Sync $sync ) {
		$this->corpus = $corpus;
		$this->sync   = $sync;

		add_action( 'admin_post_sitewise_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_sitewise_rebuild', array( $this, 'handle_rebuild' ) );
		add_action( 'admin_post_sitewise_test', array( $this, 'handle_test' ) );

		// Per-post "AI summary" meta box for tuning the short map.
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_meta_box' ), 10, 2 );

		// App (Folium frame) ajax endpoints.
		add_action( 'wp_ajax_sitewise_app_save', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_sitewise_app_reset', array( $this, 'ajax_reset' ) );
		add_action( 'wp_ajax_sitewise_app_rebuild', array( $this, 'ajax_rebuild' ) );

		if ( class_exists( 'Folium_UI' ) ) {
			// Nest under the shared "Folium" menu — no own top-level item.
			Folium_UI::register_plugin(
				array(
					'slug'     => self::PAGE_SLUG,
					'name'     => __( 'Sitewise', 'wp-call-me-back' ),
					'tagline'  => __( 'Grounded on-page chat', 'wp-call-me-back' ),
					'icon'     => 'S',
					'icon_url' => SITEWISE_URL . 'assets/img/sitewise-icon.png',
					'render'   => array( $this, 'render_page' ),
				)
			);
			add_action( 'folium_ui_enqueue', array( $this, 'on_folium_enqueue' ) );
		} else {
			// Fallback (should not happen — folium-ui is vendored): own menu.
			add_action( 'admin_menu', array( $this, 'register_menu_fallback' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_fallback' ) );
		}
	}

	/**
	 * Enqueue Sitewise's page CSS when Folium UI loads the Sitewise screen.
	 *
	 * @param string $slug Plugin slug being rendered.
	 */
	public function on_folium_enqueue( $slug ) {
		if ( self::PAGE_SLUG !== $slug ) {
			return;
		}
		wp_enqueue_style( 'sitewise-app', SITEWISE_URL . 'assets/css/sitewise-app.css', array( 'folium-ui' ), Sitewise::asset_ver( SITEWISE_DIR . 'assets/css/sitewise-app.css' ) );
		wp_enqueue_script( 'sitewise-app', SITEWISE_URL . 'assets/js/sitewise-app.js', array( 'folium-ui', 'folium-app' ), Sitewise::asset_ver( SITEWISE_DIR . 'assets/js/sitewise-app.js' ), true );
		wp_localize_script( 'sitewise-app', 'SitewiseData', $this->app_data() );
	}

	/**
	 * Data injected into the Sitewise app (real settings + corpus + status).
	 *
	 * @return array
	 */
	private function app_data() {
		$s      = Sitewise::get_settings();
		$status = get_option( Sitewise_Sync::STATUS_OPT, array() );
		$built  = isset( $status['updated_at'] ) ? human_time_diff( (int) $status['updated_at'] ) . ' ago' : 'never';
		$key    = trim( (string) $s['site_key'] );
		if ( '' === $key ) {
			$host = wp_parse_url( home_url(), PHP_URL_HOST );
			$key  = $host ? $host : 'default';
		}

		return array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'sitewise_app' ),
			'actions' => array(
				'save'    => 'sitewise_app_save',
				'reset'   => 'sitewise_app_reset',
				'rebuild' => 'sitewise_app_rebuild',
			),
			'state'   => array(
				'strictCorpus'    => true,
				'redirectContact' => '' !== trim( (string) $s['contact_url'] ),
				'widget'          => array(
					'accent'       => $s['brand_colour'],
					'pos'          => 'bottom-left' === $s['launcher_pos'] ? 'bl' : 'br',
					'opening'      => $s['opening_message'],
					'powered'      => (bool) $s['powered_by'],
					'autoInject'   => (bool) $s['auto_inject'],
					'frontendMode' => 'chat' === $s['frontend_mode'] ? 'chat' : 'callback',
				),
			),
			'real'    => $this->real_status( $s, $built, $key ),
		);
	}

	/**
	 * Live status block for the dashboard (real where we have it).
	 *
	 * @param array  $s     Settings.
	 * @param string $built Human last-built string.
	 * @param string $key   Site key.
	 * @return array
	 */
	private function real_status( $s, $built, $key ) {
		$worker_ok = '' !== trim( (string) $s['worker_url'] );
		return array(
			'workerOk'     => $worker_ok,
			'workerStatus' => $worker_ok ? __( 'Connected', 'wp-call-me-back' ) : __( 'Not connected', 'wp-call-me-back' ),
			'siteKey'      => $key,
			'model'        => 'Llama 3.1 8B',
			'lastSync'     => $built,
			'crawl'        => $this->corpus->admin_crawl_rows(),
			'logRows'      => array(),
		);
	}

	/** Shared ajax guard. */
	private function ajax_guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		check_ajax_referer( 'sitewise_app', 'nonce' );
	}

	/** Persist app state (the mappable subset) back into settings. */
	public function ajax_save() {
		$this->ajax_guard();
		// ajax_guard() verifies the nonce; decoded fields are sanitized below.
		$raw_json = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw      = '' !== $raw_json ? json_decode( $raw_json, true ) : array();
		$patch = array();
		if ( is_array( $raw ) ) {
			if ( isset( $raw['widget'] ) && is_array( $raw['widget'] ) ) {
				$w = $raw['widget'];
				if ( isset( $w['accent'] ) ) {
					$patch['brand_colour'] = sanitize_hex_color( $w['accent'] );
				}
				if ( isset( $w['pos'] ) ) {
					$patch['launcher_pos'] = ( 'bl' === $w['pos'] ) ? 'bottom-left' : 'bottom-right';
				}
				if ( isset( $w['opening'] ) ) {
					$patch['opening_message'] = sanitize_text_field( $w['opening'] );
				}
				if ( isset( $w['powered'] ) ) {
					$patch['powered_by'] = $w['powered'] ? 1 : 0;
				}
				if ( isset( $w['autoInject'] ) ) {
					$patch['auto_inject'] = $w['autoInject'] ? 1 : 0;
				}
				if ( isset( $w['frontendMode'] ) ) {
					$patch['frontend_mode'] = ( 'chat' === $w['frontendMode'] ) ? 'chat' : 'callback';
				}
			}
		}
		if ( $patch ) {
			Sitewise::update( $patch );
		}
		wp_send_json_success( array( 'saved' => array_keys( $patch ) ) );
	}

	/** Restore defaults (settings only; corpus + connections kept). */
	public function ajax_reset() {
		$this->ajax_guard();
		$keep    = array( 'worker_url', 'shared_secret', 'site_key', 'mode' );
		$current = Sitewise::get_settings();
		$reset   = Sitewise::default_settings();
		foreach ( $keep as $k ) {
			$reset[ $k ] = $current[ $k ];
		}
		update_option( SITEWISE_OPTION, $reset );
		wp_send_json_success();
	}

	/** Rebuild + push the corpus, returning fresh status for the dashboard. */
	public function ajax_rebuild() {
		$this->ajax_guard();
		$this->sync->rebuild_all();
		$s      = Sitewise::get_settings();
		$status = get_option( Sitewise_Sync::STATUS_OPT, array() );
		$built  = isset( $status['updated_at'] ) ? human_time_diff( (int) $status['updated_at'] ) . ' ago' : 'just now';
		$key    = trim( (string) $s['site_key'] );
		if ( '' === $key ) {
			$host = wp_parse_url( home_url(), PHP_URL_HOST );
			$key  = $host ? $host : 'default';
		}
		wp_send_json_success( array( 'real' => $this->real_status( $s, $built, $key ) ) );
	}

	/**
	 * Degraded fallback menu if the shared Folium UI library is unavailable.
	 */
	public function register_menu_fallback() {
		$this->page_hook = add_menu_page(
			__( 'Sitewise', 'wp-call-me-back' ),
			__( 'Sitewise', 'wp-call-me-back' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			'dashicons-format-chat',
			81
		);
	}

	/**
	 * Asset enqueue for the fallback menu.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_fallback( $hook ) {
		if ( $hook === $this->page_hook ) {
			wp_enqueue_style( 'sitewise-admin', SITEWISE_URL . 'assets/css/admin.css', array(), SITEWISE_VERSION );
		}
	}

	/**
	 * Save handler (admin-post).
	 */
	public function handle_save() {
		$this->guard();

		$in = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().

		$post_types = isset( $in['post_types'] ) && is_array( $in['post_types'] )
			? array_map( 'sanitize_key', $in['post_types'] )
			: array();

		$mode     = isset( $in['mode'] ) ? $in['mode'] : 'byo';
		$position = isset( $in['launcher_pos'] ) ? $in['launcher_pos'] : 'bottom-right';

		$patch = array(
			'mode'              => in_array( $mode, array( 'byo', 'hosted' ), true ) ? $mode : 'byo',
			'worker_url'        => esc_url_raw( trim( $in['worker_url'] ?? '' ) ),
			'shared_secret'     => sanitize_text_field( $in['shared_secret'] ?? '' ),
			'site_key'          => sanitize_text_field( $in['site_key'] ?? '' ),

			'chat_enabled'      => empty( $in['chat_enabled'] ) ? 0 : 1,
			'auto_inject'       => empty( $in['auto_inject'] ) ? 0 : 1,
			'brand_colour'      => sanitize_hex_color( $in['brand_colour'] ?? '#2563eb' ),
			'launcher_pos'      => in_array( $position, array( 'bottom-right', 'bottom-left' ), true ) ? $position : 'bottom-right',
			'opening_message'   => sanitize_text_field( $in['opening_message'] ?? '' ),
			'powered_by'        => empty( $in['powered_by'] ) ? 0 : 1,
			'contact_url'       => esc_url_raw( trim( $in['contact_url'] ?? '' ) ),
			'chat_handoff'      => empty( $in['chat_handoff'] ) ? 0 : 1,

			'post_types'        => $post_types,
			'exclude_noindex'   => empty( $in['exclude_noindex'] ) ? 0 : 1,
			'exclude_protected' => empty( $in['exclude_protected'] ) ? 0 : 1,
			'orientation'       => sanitize_textarea_field( $in['orientation'] ?? '' ),
			'faq'               => sanitize_textarea_field( $in['faq'] ?? '' ),

			'callback_enabled'  => empty( $in['callback_enabled'] ) ? 0 : 1,
		);

		Sitewise::update( $patch );
		$this->redirect_back( 'saved' );
	}

	/**
	 * Rebuild-now handler (admin-post).
	 */
	public function handle_rebuild() {
		$this->guard();
		$this->sync->rebuild_all();
		$this->redirect_back( 'rebuilt' );
	}

	/**
	 * Test-connection handler (admin-post).
	 */
	public function handle_test() {
		$this->guard();
		$result = $this->sync->health_check();
		set_transient( 'sitewise_health_' . get_current_user_id(), $result, 60 );
		$this->redirect_back( 'tested' );
	}

	/**
	 * Shared capability + nonce gate for all admin-post handlers.
	 */
	private function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wp-call-me-back' ) );
		}
		check_admin_referer( self::NONCE );
	}

	/**
	 * Redirect back to the settings page with a status flag.
	 *
	 * @param string $status Status slug.
	 */
	private function redirect_back( $status ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => self::PAGE_SLUG,
					'sitewise_status'  => $status,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$s     = Sitewise::get_settings();
		$stats = get_option( 'sitewise_corpus_stats', array() );
		$urls  = Sitewise_Corpus::file_urls();
		$health = get_transient( 'sitewise_health_' . get_current_user_id() );

		require SITEWISE_DIR . 'includes/views/settings-page.php';
	}

	/**
	 * Register the per-post "Sitewise summary" meta box.
	 */
	public function register_meta_box() {
		$types = (array) Sitewise::get( 'post_types', array( 'post', 'page' ) );
		foreach ( $types as $type ) {
			add_meta_box(
				'sitewise_summary',
				__( 'Sitewise summary', 'wp-call-me-back' ),
				array( $this, 'render_meta_box' ),
				$type,
				'side',
				'low'
			);
		}
	}

	/**
	 * Render the meta box.
	 *
	 * @param WP_Post $post Post object.
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'sitewise_summary_save', 'sitewise_summary_nonce' );
		$value = get_post_meta( $post->ID, Sitewise_Corpus::SUMMARY_META, true );
		echo '<p>' . esc_html__( 'One or two sentences describing this page for the assistant\'s content map. Leave blank to auto-derive from the excerpt or content.', 'wp-call-me-back' ) . '</p>';
		echo '<textarea name="sitewise_summary" rows="3" style="width:100%;">' . esc_textarea( $value ) . '</textarea>';
	}

	/**
	 * Persist the meta box value.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function save_meta_box( $post_id, $post ) {
		if ( ! isset( $_POST['sitewise_summary_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sitewise_summary_nonce'] ) ), 'sitewise_summary_save' ) ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$value = isset( $_POST['sitewise_summary'] ) ? sanitize_textarea_field( wp_unslash( $_POST['sitewise_summary'] ) ) : '';
		if ( '' === $value ) {
			delete_post_meta( $post_id, Sitewise_Corpus::SUMMARY_META );
		} else {
			update_post_meta( $post_id, Sitewise_Corpus::SUMMARY_META, $value );
		}
	}
}
