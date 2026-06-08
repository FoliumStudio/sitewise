<?php
/**
 * Front-end chat widget.
 *
 * Registers the [sitewise] shortcode (with a [site_chat_bot] alias), optionally
 * auto-injects the launcher into the footer, and enqueues the vanilla-JS widget.
 * The widget POSTs to the configured Worker's /chat endpoint directly from the
 * browser (CORS-locked on the Worker side per registered origin).
 *
 * @package Sitewise
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Sitewise_Widget {

	/**
	 * Whether the assets have already been enqueued this request.
	 *
	 * @var bool
	 */
	private $enqueued = false;

	public function __construct() {
		add_shortcode( 'sitewise', array( $this, 'shortcode' ) );
		add_shortcode( 'site_chat_bot', array( $this, 'shortcode' ) ); // alias from the concept.
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'wp_footer', array( $this, 'maybe_auto_inject' ) );
	}

	/**
	 * Should the widget run at all on this request?
	 *
	 * @return bool
	 */
	private function is_active() {
		if ( is_admin() ) {
			return false;
		}
		if ( ! Sitewise::get( 'chat_enabled', 1 ) ) {
			return false;
		}
		if ( '' === trim( (string) Sitewise::get( 'worker_url', '' ) ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Register (but do not force-enqueue) the assets.
	 */
	public function register_assets() {
		wp_register_style( 'sitewise-widget', SITEWISE_URL . 'assets/css/widget.css', array(), SITEWISE_VERSION );
		wp_register_script( 'sitewise-widget', SITEWISE_URL . 'assets/js/widget.js', array(), SITEWISE_VERSION, true );
	}

	/**
	 * Enqueue + localise the widget config once.
	 */
	private function enqueue() {
		if ( $this->enqueued ) {
			return;
		}
		$this->enqueued = true;

		wp_enqueue_style( 'sitewise-widget' );
		wp_enqueue_script( 'sitewise-widget' );

		$host    = wp_parse_url( home_url(), PHP_URL_HOST );
		$site_key = trim( (string) Sitewise::get( 'site_key', '' ) );

		wp_localize_script(
			'sitewise-widget',
			'SitewiseConfig',
			array(
				'workerUrl' => trailingslashit( (string) Sitewise::get( 'worker_url', '' ) ) . 'chat',
				'siteKey'   => '' !== $site_key ? $site_key : ( $host ? $host : 'default' ),
				'siteName'  => get_bloginfo( 'name' ),
				'colour'    => Sitewise::get( 'brand_colour', '#2563eb' ),
				'position'  => Sitewise::get( 'launcher_pos', 'bottom-right' ),
				'opening'   => Sitewise::get( 'opening_message', '' ),
				'poweredBy' => (bool) Sitewise::get( 'powered_by', 1 ),
				'contact'   => Sitewise::get( 'contact_url', home_url( '/contact/' ) ),
				'strings'   => array(
					'placeholder' => __( 'Type your question…', 'wp-call-me-back' ),
					'send'        => __( 'Send', 'wp-call-me-back' ),
					'title'       => __( 'Ask the assistant', 'wp-call-me-back' ),
					'error'       => __( 'Something went wrong. Please try again.', 'wp-call-me-back' ),
					'poweredBy'   => __( 'Powered by Sitewise', 'wp-call-me-back' ),
				),
				'handoff'   => $this->handoff_config(),
			)
		);
	}

	/**
	 * Config for the can't-answer → call-back pivot. Enabled only when both the
	 * handoff toggle and the call-back module are on.
	 *
	 * @return array
	 */
	private function handoff_config() {
		$enabled = Sitewise::get( 'chat_handoff', 1 ) && Sitewise::get( 'callback_enabled', 1 );
		if ( ! $enabled ) {
			return array( 'enabled' => false );
		}
		return array(
			'enabled' => true,
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'action'  => Sitewise_Callback::AJAX,
			'nonce'   => wp_create_nonce( Sitewise_Callback::NONCE ),
			'strings' => array(
				'title'   => __( 'Arrange a call back', 'wp-call-me-back' ),
				'name'    => __( 'Your name', 'wp-call-me-back' ),
				'phone'   => __( 'Phone number', 'wp-call-me-back' ),
				'email'   => __( 'Email (optional)', 'wp-call-me-back' ),
				'time'    => __( 'Best time', 'wp-call-me-back' ),
				'submit'  => __( 'Request call back', 'wp-call-me-back' ),
				'thanks'  => __( 'Thanks — we will call you back shortly.', 'wp-call-me-back' ),
				'error'   => __( 'Something went wrong. Please try again.', 'wp-call-me-back' ),
				'or'      => __( 'or', 'wp-call-me-back' ),
				'contact' => __( 'visit our contact page', 'wp-call-me-back' ),
			),
		);
	}

	/**
	 * Shortcode: render an inline mount point and ensure assets are present.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode( $atts ) {
		if ( ! $this->is_active() ) {
			return '';
		}
		$this->enqueue();
		// Inline mode mounts in place; the JS reads data-sitewise-inline.
		return '<div class="sitewise-mount" data-sitewise-inline="1"></div>';
	}

	/**
	 * Auto-inject the floating launcher into the footer when enabled.
	 */
	public function maybe_auto_inject() {
		if ( ! $this->is_active() || ! Sitewise::get( 'auto_inject', 1 ) ) {
			return;
		}
		$this->enqueue();
		echo '<div class="sitewise-mount" data-sitewise-floating="1"></div>';
	}
}
