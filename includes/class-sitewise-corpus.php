<?php
/**
 * Corpus generator.
 *
 * Compiles the site's public content into two text files that mirror the
 * shinepics llms.txt / llms-full.txt pattern, but built at runtime from
 * WordPress instead of at build time:
 *
 *   - llms.txt       short map: title + URL + 1-2 line blurb per page.
 *   - llms-full.txt  full corpus: orientation → per-page prose → FAQ.
 *
 * The files live in uploads/sitewise/ and are public on purpose, so other AI
 * agents can read them too. The Cloudflare Worker answers only from this corpus.
 *
 * @package Sitewise
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Sitewise_Corpus {

	const DIRNAME      = 'sitewise';
	const FILE_MAP     = 'llms.txt';
	const FILE_FULL    = 'llms-full.txt';
	const SUMMARY_META = '_sitewise_summary';
	const RAG_TOKEN_THRESHOLD = 30000; // above this, RAG mode is recommended.

	/**
	 * Resolve the uploads-relative storage directory, creating it if needed.
	 *
	 * @return string Absolute path with trailing slash.
	 */
	public static function ensure_storage_dir() {
		$dir = self::storage_dir();
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	/**
	 * @return string Absolute storage path (trailing slash).
	 */
	public static function storage_dir() {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . self::DIRNAME . '/';
	}

	/**
	 * @return string Public storage URL (trailing slash).
	 */
	public static function storage_url() {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['baseurl'] ) . self::DIRNAME . '/';
	}

	/**
	 * Public URLs of the two corpus files.
	 *
	 * @return array{map:string,full:string}
	 */
	public static function file_urls() {
		$base = self::storage_url();
		return array(
			'map'  => $base . self::FILE_MAP,
			'full' => $base . self::FILE_FULL,
		);
	}

	/**
	 * Real per-page rows for the admin dashboard / Knowledge tab: path, title,
	 * status (ok|skip|err), token estimate, sync label.
	 *
	 * @return array<int,array>
	 */
	public function admin_crawl_rows() {
		$settings = Sitewise::get_settings();
		$types    = ! empty( $settings['post_types'] ) ? (array) $settings['post_types'] : array( 'post', 'page' );

		$query = new WP_Query(
			array(
				'post_type'           => $types,
				'post_status'         => 'publish',
				'posts_per_page'      => 100,
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			)
		);

		$rows = array();
		foreach ( $query->posts as $post ) {
			$status = 'ok';
			$note   = '';
			if ( ! empty( $settings['exclude_protected'] ) && ! empty( $post->post_password ) ) {
				$status = 'skip';
				$note   = 'protected';
			} elseif ( ! empty( $settings['exclude_noindex'] ) && $this->is_noindex( $post ) ) {
				$status = 'skip';
				$note   = 'noindex';
			}
			$tokens = ( 'ok' === $status ) ? (int) ceil( strlen( $this->post_to_prose( $post ) ) / 4 ) : 0;
			$path   = wp_make_link_relative( get_permalink( $post ) );

			$rows[] = array(
				'path'   => $path ? $path : '/',
				'title'  => get_the_title( $post ),
				'status' => $status,
				'tokens' => $tokens,
				'synced' => 'synced',
				'note'   => $note,
			);
		}
		wp_reset_postdata();

		return $rows;
	}

	/**
	 * Build the full corpus across all configured post types and write both
	 * files. Returns a stats array for the admin screen / sync layer.
	 *
	 * @return array{pages:int,bytes:int,tokens:int,built_at:int,error:string}
	 */
	public function build_all() {
		$settings   = Sitewise::get_settings();
		$post_types = ! empty( $settings['post_types'] ) ? (array) $settings['post_types'] : array( 'post', 'page' );

		$entries = $this->collect_entries( $post_types, $settings );

		$map  = $this->render_map( $entries, $settings );
		$full = $this->render_full( $entries, $settings );

		$dir = self::ensure_storage_dir();

		$ok_map  = $this->write_file( $dir . self::FILE_MAP, $map );
		$ok_full = $this->write_file( $dir . self::FILE_FULL, $full );

		$bytes  = strlen( $full );
		$tokens = (int) ceil( $bytes / 4 ); // rough heuristic, good enough for the RAG-threshold notice.

		$stats = array(
			'pages'    => count( $entries ),
			'bytes'    => $bytes,
			'tokens'   => $tokens,
			'built_at' => time(),
			'error'    => ( $ok_map && $ok_full ) ? '' : __( 'Could not write corpus files to the uploads directory.', 'wp-call-me-back' ),
		);

		update_option( 'sitewise_corpus_stats', $stats, false );

		return $stats;
	}

	/**
	 * Walk WP_Query across the configured post types and normalise each post
	 * into a corpus entry.
	 *
	 * @param array $post_types Post types to include.
	 * @param array $settings   Plugin settings.
	 * @return array<int,array> Entries grouped-friendly, each with type/title/url/summary/prose.
	 */
	private function collect_entries( array $post_types, array $settings ) {
		$entries = array();
		$paged   = 1;

		do {
			$query = new WP_Query(
				array(
					'post_type'              => $post_types,
					'post_status'            => 'publish',
					'posts_per_page'         => 100,
					'paged'                  => $paged,
					'has_password'           => false,
					'ignore_sticky_posts'    => true,
					'no_found_rows'          => false,
					'update_post_meta_cache' => true,
					'update_post_term_cache' => false,
				)
			);

			foreach ( $query->posts as $post ) {
				if ( ! empty( $settings['exclude_protected'] ) && ! empty( $post->post_password ) ) {
					continue;
				}
				if ( ! empty( $settings['exclude_noindex'] ) && $this->is_noindex( $post ) ) {
					continue;
				}

				$entries[] = $this->entry_from_post( $post );
			}

			$max   = (int) $query->max_num_pages;
			$paged++;
			wp_reset_postdata();
		} while ( $paged <= $max );

		return $entries;
	}

	/**
	 * Normalise a single post into a corpus entry.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	private function entry_from_post( $post ) {
		$prose = $this->post_to_prose( $post );

		// Description for the short map: per-page override → excerpt → first sentences of prose.
		$summary = trim( (string) get_post_meta( $post->ID, self::SUMMARY_META, true ) );
		if ( '' === $summary ) {
			$excerpt = has_excerpt( $post ) ? wp_strip_all_tags( get_the_excerpt( $post ) ) : '';
			$summary = '' !== $excerpt ? $excerpt : $this->first_sentences( $prose, 220 );
		}

		return array(
			'id'      => $post->ID,
			'type'    => $post->post_type,
			'title'   => get_the_title( $post ),
			'url'     => get_permalink( $post ),
			'summary' => $this->one_line( $summary ),
			'prose'   => $prose,
		);
	}

	/**
	 * Convert a post's content to clean prose, headings preserved as markdown.
	 * Renders blocks/shortcodes minimally, drops nav/script/style chrome.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	private function post_to_prose( $post ) {
		$html = $post->post_content;

		// Render Gutenberg blocks to HTML; skip the full `the_content` filter
		// chain to avoid other plugins injecting related-posts / share chrome.
		if ( function_exists( 'do_blocks' ) ) {
			$html = do_blocks( $html );
		}
		$html = do_shortcode( $html );

		// Strip scripts/styles entirely (including their contents).
		$html = preg_replace( '#<(script|style|nav|form|noscript)\b[^>]*>.*?</\1>#is', '', $html );

		// Preserve heading structure as markdown-ish prefixes.
		$html = preg_replace_callback(
			'#<h([1-6])\b[^>]*>(.*?)</h\1>#is',
			function ( $m ) {
				$level = (int) $m[1];
				$text  = trim( wp_strip_all_tags( $m[2] ) );
				return "\n\n" . str_repeat( '#', $level ) . ' ' . $text . "\n\n";
			},
			$html
		);

		// Turn list items and paragraph/break boundaries into newlines.
		$html = preg_replace( '#<li\b[^>]*>#i', "\n- ", $html );
		$html = preg_replace( '#</(p|div|tr|h[1-6]|ul|ol|blockquote)>#i', "\n\n", $html );
		$html = preg_replace( '#<br\s*/?>#i', "\n", $html );

		$text = wp_strip_all_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES, get_bloginfo( 'charset' ) );

		// Normalise whitespace: collapse runs of blank lines, trim each line.
		$text = preg_replace( "/[ \t]+/", ' ', $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		$lines = array_map( 'trim', explode( "\n", $text ) );

		return trim( implode( "\n", $lines ) );
	}

	/**
	 * Render the short map (llms.txt): grouped by post type, one blurb per page.
	 *
	 * @param array $entries  Corpus entries.
	 * @param array $settings Plugin settings.
	 * @return string
	 */
	private function render_map( array $entries, array $settings ) {
		$site_name = get_bloginfo( 'name' );
		$tagline   = get_bloginfo( 'description' );

		$out  = '# ' . $site_name . "\n";
		if ( $tagline ) {
			$out .= '> ' . $tagline . "\n";
		}
		$out .= "\n";
		$out .= sprintf(
			"This is the content map for %s. Each entry links a page to a short description. The assistant answers only from these pages.\n\n",
			$site_name
		);

		// Group by post type, in the order the user configured them.
		$groups = array();
		foreach ( $entries as $e ) {
			$groups[ $e['type'] ][] = $e;
		}

		foreach ( $groups as $type => $items ) {
			$out .= '## ' . $this->type_label( $type ) . "\n\n";
			foreach ( $items as $e ) {
				$out .= sprintf( "- [%s](%s)", $e['title'], $e['url'] );
				if ( '' !== $e['summary'] ) {
					$out .= ': ' . $e['summary'];
				}
				$out .= "\n";
			}
			$out .= "\n";
		}

		return rtrim( $out ) . "\n";
	}

	/**
	 * Render the full corpus (llms-full.txt): instruction header, orientation,
	 * per-page prose, FAQ.
	 *
	 * @param array $entries  Corpus entries.
	 * @param array $settings Plugin settings.
	 * @return string
	 */
	private function render_full( array $entries, array $settings ) {
		$site_name   = get_bloginfo( 'name' );
		$contact_url = ! empty( $settings['contact_url'] ) ? $settings['contact_url'] : home_url( '/contact/' );

		$out  = '# ' . $site_name . " — site corpus\n\n";
		$out .= "You are the on-site assistant for {$site_name}. Answer only from the\n";
		$out .= "content below. If a question is not covered, say so plainly and direct\n";
		$out .= "the visitor to {$contact_url}. Do not invent details and do not compare\n";
		$out .= "{$site_name} to competitors.\n\n";
		$out .= "---\n\n";

		if ( ! empty( $settings['orientation'] ) ) {
			$out .= "## Orientation\n\n" . trim( $settings['orientation'] ) . "\n\n---\n\n";
		}

		foreach ( $entries as $e ) {
			$out .= '## ' . $e['title'] . "\n";
			$out .= 'URL: ' . $e['url'] . "\n\n";
			$out .= $e['prose'] . "\n\n---\n\n";
		}

		if ( ! empty( $settings['faq'] ) ) {
			$out .= "## Frequently asked questions\n\n" . trim( $settings['faq'] ) . "\n";
		}

		return rtrim( $out ) . "\n";
	}

	/**
	 * Best-effort noindex detection (Yoast, Rank Math, or core robots meta).
	 *
	 * @param WP_Post $post Post object.
	 * @return bool
	 */
	private function is_noindex( $post ) {
		$yoast = get_post_meta( $post->ID, '_yoast_wpseo_meta-robots-noindex', true );
		if ( '1' === (string) $yoast ) {
			return true;
		}
		$rankmath = get_post_meta( $post->ID, 'rank_math_robots', true );
		if ( is_array( $rankmath ) && in_array( 'noindex', $rankmath, true ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Collapse to a single line.
	 *
	 * @param string $text Input.
	 * @return string
	 */
	private function one_line( $text ) {
		return trim( preg_replace( '/\s+/', ' ', (string) $text ) );
	}

	/**
	 * First N characters of prose, cut on a sentence/word boundary.
	 *
	 * @param string $text  Prose.
	 * @param int    $limit Max characters.
	 * @return string
	 */
	private function first_sentences( $text, $limit ) {
		$text = $this->one_line( $text );
		if ( strlen( $text ) <= $limit ) {
			return $text;
		}
		$cut = substr( $text, 0, $limit );
		$dot = strrpos( $cut, '. ' );
		if ( false !== $dot && $dot > (int) ( $limit * 0.5 ) ) {
			return substr( $cut, 0, $dot + 1 );
		}
		$space = strrpos( $cut, ' ' );
		return ( false !== $space ? substr( $cut, 0, $space ) : $cut ) . '…';
	}

	/**
	 * Human label for a post type.
	 *
	 * @param string $type Post type slug.
	 * @return string
	 */
	private function type_label( $type ) {
		$obj = get_post_type_object( $type );
		if ( $obj && isset( $obj->labels->name ) ) {
			return $obj->labels->name;
		}
		return ucfirst( $type );
	}

	/**
	 * Write a file via WP_Filesystem, falling back to a direct write.
	 *
	 * @param string $path     Absolute path.
	 * @param string $contents File contents.
	 * @return bool
	 */
	private function write_file( $path, $contents ) {
		global $wp_filesystem;
		if ( null === $wp_filesystem ) {
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			WP_Filesystem();
		}
		if ( $wp_filesystem && $wp_filesystem->put_contents( $path, $contents, FS_CHMOD_FILE ) ) {
			return true;
		}
		// Last-resort fallback (e.g. constrained WP_Filesystem during cron).
		return false !== file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}
}
