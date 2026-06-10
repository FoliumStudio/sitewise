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

		// Call-back mode (the default until the chatbot Worker is live): the
		// floating widget is the request-a-call-back form and needs no Worker.
		if ( 'chat' !== Sitewise::get( 'frontend_mode', 'callback' ) ) {
			return (bool) Sitewise::get( 'callback_enabled', 1 );
		}

		// Chat mode: needs the bot enabled and a Worker to talk to.
		if ( ! Sitewise::get( 'chat_enabled', 1 ) ) {
			return false;
		}
		if ( '' === trim( (string) Sitewise::get( 'worker_url', '' ) ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Register the assets, and enqueue + localise them on this (canonical) hook
	 * when the widget is needed. Enqueuing lazily from the shortcode during
	 * `the_content` can drop the localized config in block themes (the config
	 * script goes missing and the widget never mounts), so the decision is made
	 * here instead: auto-inject = every page; otherwise only when the current
	 * singular content actually uses the shortcode.
	 */
	public function register_assets() {
		// Version constant in release, filemtime in dev (see Sitewise::asset_ver).
		wp_register_style( 'sitewise-widget', SITEWISE_URL . 'assets/css/widget.css', array(), Sitewise::asset_ver( SITEWISE_DIR . 'assets/css/widget.css' ) );
		wp_register_script( 'sitewise-widget', SITEWISE_URL . 'assets/js/widget.js', array(), Sitewise::asset_ver( SITEWISE_DIR . 'assets/js/widget.js' ), true );

		if ( ! $this->is_active() ) {
			return;
		}
		if ( Sitewise::get( 'auto_inject', 1 ) || $this->content_has_shortcode() ) {
			$this->enqueue();
		}
	}

	/**
	 * Does the current singular content use the widget shortcode?
	 *
	 * @return bool
	 */
	private function content_has_shortcode() {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_post();
		return ( $post instanceof WP_Post )
			&& ( has_shortcode( $post->post_content, 'sitewise' ) || has_shortcode( $post->post_content, 'site_chat_bot' ) );
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
	}

	/**
	 * Build the front-end widget config.
	 *
	 * Delivered to the JS via a `data-sitewise-config` attribute on the mount
	 * node (see mount_div), NOT wp_localize_script: the localized inline script
	 * is unreliable in block themes when the widget is enqueued for a shortcode
	 * page — it can be dropped, leaving the widget with no config and never
	 * mounting. Riding the config with the mount markup is immune to that.
	 *
	 * @return array
	 */
	private function widget_config() {
		$host     = wp_parse_url( home_url(), PHP_URL_HOST );
		$site_key = trim( (string) Sitewise::get( 'site_key', '' ) );
		$mode     = ( 'chat' === Sitewise::get( 'frontend_mode', 'callback' ) ) ? 'chat' : 'callback';
		$worker   = trim( (string) Sitewise::get( 'worker_url', '' ) );

		return array(
			'mode'      => $mode,
			'workerUrl' => '' !== $worker ? trailingslashit( $worker ) . 'chat' : '',
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
			'handoff'   => $this->handoff_config( $mode ),
		);
	}

	/**
	 * A mount node carrying the widget config as a data attribute.
	 *
	 * @param string $type_attr Mode attribute, e.g. `data-sitewise-inline="1"`.
	 * @return string
	 */
	private function mount_div( $type_attr ) {
		return sprintf(
			'<div class="sitewise-mount" %s data-sitewise-config="%s"></div>',
			$type_attr,
			esc_attr( wp_json_encode( $this->widget_config() ) )
		);
	}

	/**
	 * Config for the call-back form. In call-back mode it is the widget's whole
	 * purpose, so it follows the call-back module toggle. In chat mode it is the
	 * can't-answer pivot, gated additionally by the handoff toggle.
	 *
	 * @param string $mode Front-end mode ('callback' or 'chat').
	 * @return array
	 */
	private function handoff_config( $mode = 'callback' ) {
		$enabled = ( 'chat' === $mode )
			? ( Sitewise::get( 'chat_handoff', 1 ) && Sitewise::get( 'callback_enabled', 1 ) )
			: (bool) Sitewise::get( 'callback_enabled', 1 );
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
				'intro'   => __( 'Leave your details and we will call you back.', 'wp-call-me-back' ),
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
		return $this->mount_div( 'data-sitewise-inline="1"' );
	}

	/**
	 * Auto-inject the floating launcher into the footer when enabled.
	 */
	public function maybe_auto_inject() {
		if ( ! $this->is_active() || ! Sitewise::get( 'auto_inject', 1 ) ) {
			return;
		}
		$this->enqueue();
		// esc_attr() is applied to the dynamic config inside mount_div().
		echo $this->mount_div( 'data-sitewise-floating="1"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
