<?php
/**
 * Call-back request module (the original Call-Me-Back feature, rewritten clean).
 *
 * Parity goal: a visitor can request a call back (name, phone, optional email,
 * preferred time, message); the admin receives it. The legacy 3.x version stored
 * these in bespoke DB tables with WP_List_Table screens and an embedded
 * recaptchalib. This rewrite drops all of that: submissions are a private custom
 * post type (reviewable in wp-admin), delivered by email, protected by a nonce +
 * honeypot. Exposed via the [sitewise_callback] shortcode and a sidebar widget.
 *
 * @package Sitewise
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Sitewise_Callback {

	const CPT      = 'sitewise_callback';
	const AJAX     = 'sitewise_callback_submit';
	const NONCE    = 'sitewise_callback';

	public function __construct() {
		add_action( 'init', array( $this, 'register_cpt' ) );
		add_shortcode( 'sitewise_callback', array( $this, 'shortcode' ) );
		add_shortcode( 'wpgcallmeback', array( $this, 'shortcode' ) ); // legacy alias.

		add_action( 'wp_ajax_' . self::AJAX, array( $this, 'handle_submit' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX, array( $this, 'handle_submit' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'widgets_init', array( $this, 'register_widget' ) );
	}

	/**
	 * Is the call-back feature enabled?
	 *
	 * @return bool
	 */
	private function enabled() {
		return (bool) Sitewise::get( 'callback_enabled', 1 );
	}

	/**
	 * Register the private CPT used to store submissions.
	 */
	public function register_cpt() {
		register_post_type(
			self::CPT,
			array(
				'labels'       => array(
					'name'          => __( 'Call-back requests', 'wp-call-me-back' ),
					'singular_name' => __( 'Call-back request', 'wp-call-me-back' ),
					'menu_name'     => __( 'Call-backs', 'wp-call-me-back' ),
				),
				'public'       => false,
				'show_ui'      => true,
				// Keep the sidebar clean: not shown as its own menu item. The
				// list is reached from within the Sitewise app (and is still
				// routable at edit.php?post_type=sitewise_callback).
				'show_in_menu' => false,
				'capability_type' => 'post',
				'capabilities' => array( 'create_posts' => 'do_not_allow' ), // only the form creates them.
				'map_meta_cap' => true,
				'supports'     => array( 'title' ),
				'has_archive'  => false,
				'rewrite'      => false,
			)
		);
	}

	/**
	 * Register front-end assets (enqueued on demand by the shortcode/widget).
	 */
	public function register_assets() {
		// Version constant in release, filemtime in dev (see Sitewise::asset_ver).
		wp_register_style( 'sitewise-callback', SITEWISE_URL . 'assets/css/callback.css', array(), Sitewise::asset_ver( SITEWISE_DIR . 'assets/css/callback.css' ) );
		wp_register_script( 'sitewise-callback', SITEWISE_URL . 'assets/js/callback.js', array(), Sitewise::asset_ver( SITEWISE_DIR . 'assets/js/callback.js' ), true );
	}

	/**
	 * Enqueue + localise once.
	 */
	private function enqueue() {
		wp_enqueue_style( 'sitewise-callback' );
		wp_enqueue_script( 'sitewise-callback' );
		wp_localize_script(
			'sitewise-callback',
			'SitewiseCallback',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX,
				'nonce'   => wp_create_nonce( self::NONCE ),
				'strings' => array(
					'sending' => __( 'Sending…', 'wp-call-me-back' ),
					'thanks'  => __( 'Thanks — we will call you back shortly.', 'wp-call-me-back' ),
					'error'   => __( 'Sorry, something went wrong. Please try again.', 'wp-call-me-back' ),
				),
			)
		);
	}

	/**
	 * Render the call-back form.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode( $atts = array() ) {
		if ( ! $this->enabled() ) {
			return '';
		}
		$this->enqueue();

		$atts = shortcode_atts(
			array( 'title' => __( 'Request a call back', 'wp-call-me-back' ) ),
			$atts,
			'sitewise_callback'
		);

		ob_start();
		?>
		<form class="sitewise-cb-form" novalidate>
			<?php if ( $atts['title'] ) : ?>
				<h3 class="sitewise-cb-title"><?php echo esc_html( $atts['title'] ); ?></h3>
			<?php endif; ?>
			<div class="sitewise-cb-row">
				<label><?php esc_html_e( 'Your name', 'wp-call-me-back' ); ?>
					<input type="text" name="cb_name" required />
				</label>
			</div>
			<div class="sitewise-cb-row">
				<label><?php esc_html_e( 'Phone number', 'wp-call-me-back' ); ?>
					<input type="tel" name="cb_phone" required />
				</label>
			</div>
			<div class="sitewise-cb-row">
				<label><?php esc_html_e( 'Email (optional)', 'wp-call-me-back' ); ?>
					<input type="email" name="cb_email" />
				</label>
			</div>
			<div class="sitewise-cb-row">
				<label><?php esc_html_e( 'Best time to call', 'wp-call-me-back' ); ?>
					<input type="text" name="cb_time" placeholder="<?php esc_attr_e( 'e.g. weekday mornings', 'wp-call-me-back' ); ?>" />
				</label>
			</div>
			<div class="sitewise-cb-row">
				<label><?php esc_html_e( 'Message (optional)', 'wp-call-me-back' ); ?>
					<textarea name="cb_message" rows="3"></textarea>
				</label>
			</div>
			<?php // Honeypot: real users leave this empty; bots tend to fill it. ?>
			<div class="sitewise-cb-hp" aria-hidden="true">
				<label>Leave this empty<input type="text" name="cb_website" tabindex="-1" autocomplete="off" /></label>
			</div>
			<button type="submit" class="sitewise-cb-submit"><?php esc_html_e( 'Request call back', 'wp-call-me-back' ); ?></button>
			<p class="sitewise-cb-status" role="status" aria-live="polite"></p>
		</form>
		<?php
		return ob_get_clean();
	}

	/**
	 * AJAX submit handler.
	 */
	public function handle_submit() {
		if ( ! $this->enabled() ) {
			wp_send_json_error( array( 'message' => __( 'Call-backs are disabled.', 'wp-call-me-back' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );

		// Honeypot: a filled "website" field means a bot.
		if ( ! empty( $_POST['cb_website'] ) ) {
			wp_send_json_success( array( 'message' => __( 'Thanks.', 'wp-call-me-back' ) ) ); // silently accept, drop.
		}

		$name    = isset( $_POST['cb_name'] ) ? sanitize_text_field( wp_unslash( $_POST['cb_name'] ) ) : '';
		$phone   = isset( $_POST['cb_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['cb_phone'] ) ) : '';
		$email   = isset( $_POST['cb_email'] ) ? sanitize_email( wp_unslash( $_POST['cb_email'] ) ) : '';
		$time    = isset( $_POST['cb_time'] ) ? sanitize_text_field( wp_unslash( $_POST['cb_time'] ) ) : '';
		$message = isset( $_POST['cb_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['cb_message'] ) ) : '';

		if ( '' === $name || '' === $phone ) {
			wp_send_json_error( array( 'message' => __( 'Please provide your name and phone number.', 'wp-call-me-back' ) ), 400 );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'private',
				/* translators: %s: requester name */
				'post_title'  => sprintf( __( 'Call-back: %s', 'wp-call-me-back' ), $name ),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not save your request.', 'wp-call-me-back' ) ), 500 );
		}

		update_post_meta( $post_id, '_cb_name', $name );
		update_post_meta( $post_id, '_cb_phone', $phone );
		update_post_meta( $post_id, '_cb_email', $email );
		update_post_meta( $post_id, '_cb_time', $time );
		update_post_meta( $post_id, '_cb_message', $message );
		update_post_meta( $post_id, '_cb_source', esc_url_raw( wp_get_referer() ) );

		$this->notify_admin( compact( 'name', 'phone', 'email', 'time', 'message' ) );

		wp_send_json_success( array( 'message' => __( 'Thanks — we will call you back shortly.', 'wp-call-me-back' ) ) );
	}

	/**
	 * Email the site admin a new request.
	 *
	 * @param array $data Submission fields.
	 */
	private function notify_admin( $data ) {
		$to      = get_option( 'admin_email' );
		$subject = sprintf(
			/* translators: 1: site name, 2: requester name */
			__( '[%1$s] Call-back request from %2$s', 'wp-call-me-back' ),
			get_bloginfo( 'name' ),
			$data['name']
		);

		$lines = array(
			__( 'Name:', 'wp-call-me-back' ) . ' ' . $data['name'],
			__( 'Phone:', 'wp-call-me-back' ) . ' ' . $data['phone'],
			__( 'Email:', 'wp-call-me-back' ) . ' ' . ( $data['email'] ? $data['email'] : '—' ),
			__( 'Best time:', 'wp-call-me-back' ) . ' ' . ( $data['time'] ? $data['time'] : '—' ),
			'',
			__( 'Message:', 'wp-call-me-back' ),
			$data['message'] ? $data['message'] : '—',
		);

		$headers = array();
		if ( $data['email'] ) {
			$headers[] = 'Reply-To: ' . $data['name'] . ' <' . $data['email'] . '>';
		}

		wp_mail( $to, $subject, implode( "\n", $lines ), $headers );
	}

	/**
	 * Register the sidebar widget for parity with 3.x.
	 */
	public function register_widget() {
		register_widget( 'Sitewise_Callback_Widget' );
	}
}

/**
 * Sidebar widget wrapping the call-back form.
 */
class Sitewise_Callback_Widget extends WP_Widget {

	public function __construct() {
		parent::__construct(
			'sitewise_callback_widget',
			__( 'Sitewise: Call-back form', 'wp-call-me-back' ),
			array( 'description' => __( 'A request-a-call-back form for your sidebar.', 'wp-call-me-back' ) )
		);
	}

	/**
	 * @param array $args     Widget args.
	 * @param array $instance Widget instance settings.
	 */
	public function widget( $args, $instance ) {
		$title = ! empty( $instance['title'] ) ? $instance['title'] : __( 'Request a call back', 'wp-call-me-back' );
		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- theme-provided wrapper.
		echo do_shortcode( '[sitewise_callback title="' . esc_attr( $title ) . '"]' );
		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * @param array $instance Current settings.
	 */
	public function form( $instance ) {
		$title = isset( $instance['title'] ) ? $instance['title'] : __( 'Request a call back', 'wp-call-me-back' );
		printf(
			'<p><label for="%1$s">%2$s</label><input class="widefat" id="%1$s" name="%3$s" type="text" value="%4$s" /></p>',
			esc_attr( $this->get_field_id( 'title' ) ),
			esc_html__( 'Title:', 'wp-call-me-back' ),
			esc_attr( $this->get_field_name( 'title' ) ),
			esc_attr( $title )
		);
	}

	/**
	 * @param array $new New settings.
	 * @param array $old Old settings.
	 * @return array
	 */
	public function update( $new, $old ) {
		$instance          = $old;
		$instance['title'] = sanitize_text_field( $new['title'] ?? '' );
		return $instance;
	}
}
