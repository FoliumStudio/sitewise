<?php
/**
 * Core bootstrap for Sitewise.
 *
 * Owns the settings array, instantiates the feature modules, and exposes a
 * couple of small helpers the rest of the plugin leans on.
 *
 * @package Sitewise
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Sitewise {

	/**
	 * Singleton instance.
	 *
	 * @var Sitewise|null
	 */
	private static $instance = null;

	/**
	 * @var Sitewise_Corpus
	 */
	public $corpus;

	/**
	 * @var Sitewise_Sync
	 */
	public $sync;

	/**
	 * @var Sitewise_Admin
	 */
	public $admin;

	/**
	 * @var Sitewise_Widget
	 */
	public $widget;

	/**
	 * @var Sitewise_Callback
	 */
	public $callback;

	/**
	 * Get (and lazily create) the singleton.
	 *
	 * @return Sitewise
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire up the modules. Each module registers its own hooks in its
	 * constructor; this keeps the bootstrap file thin.
	 */
	private function __construct() {
		$this->corpus   = new Sitewise_Corpus();
		$this->sync     = new Sitewise_Sync( $this->corpus );
		$this->widget   = new Sitewise_Widget();
		$this->callback = new Sitewise_Callback();

		if ( is_admin() ) {
			$this->admin = new Sitewise_Admin( $this->corpus, $this->sync );
		}
	}

	/**
	 * Default settings shape. Single source of truth — referenced by the
	 * activation seeder and by get_settings() for forward-compatible merges.
	 *
	 * @return array
	 */
	public static function default_settings() {
		return array(
			// Multi-tenancy: 'byo' (self-hosted Worker, free) or 'hosted' (SaaS).
			'mode'              => 'byo',

			// Cloudflare Worker connection.
			'worker_url'       => '',
			'shared_secret'    => '',
			'site_key'         => '',   // used in hosted mode; in BYO it can equal the worker host.

			// Chatbot feature toggle + behaviour.
			'chat_enabled'     => 1,
			'auto_inject'      => 1,    // drop the launcher into wp_footer automatically.
			'brand_colour'     => '#2563eb',
			'launcher_pos'     => 'bottom-right',
			'opening_message'  => __( 'Hi! Ask me anything about this site.', 'wp-call-me-back' ),
			'powered_by'       => 1,
			'contact_url'      => '',
			// When the assistant can't answer, offer an inline call-back form.
			'chat_handoff'     => 1,

			// Corpus build rules.
			'post_types'       => array( 'post', 'page' ),
			'exclude_noindex'  => 1,
			'exclude_protected'=> 1,
			'orientation'      => '',   // hand-written "studio orientation" prepended to the corpus.
			'faq'              => '',   // hand-written FAQ block appended to the corpus.

			// Call-back module (legacy feature, kept for parity).
			'callback_enabled' => 1,
		);
	}

	/**
	 * Seed defaults on activation without clobbering an existing config.
	 */
	public static function seed_default_settings() {
		$existing = get_option( SITEWISE_OPTION, null );
		if ( null === $existing ) {
			add_option( SITEWISE_OPTION, self::default_settings() );
		}
	}

	/**
	 * Read the merged settings (stored values over defaults). Cheap enough to
	 * call repeatedly; WordPress caches the option.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$stored = get_option( SITEWISE_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::default_settings(), $stored );
	}

	/**
	 * Read a single setting with an optional fallback.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Returned if the key is absent.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$settings = self::get_settings();
		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * Persist a partial settings update (merged over current values).
	 *
	 * @param array $patch Keys to overwrite.
	 * @return bool
	 */
	public static function update( array $patch ) {
		$settings = self::get_settings();
		$settings = array_merge( $settings, $patch );
		return update_option( SITEWISE_OPTION, $settings );
	}
}
