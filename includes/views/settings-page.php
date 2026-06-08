<?php
/**
 * Settings page template.
 *
 * Rendered by Sitewise_Admin::render_page(). Available vars:
 *   $s      array  merged settings
 *   $stats  array  last corpus build stats
 *   $urls   array  corpus file URLs (map/full)
 *   $health array|false  last health-check result
 *
 * @package Sitewise
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

$status   = isset( $_GET['sitewise_status'] ) ? sanitize_key( wp_unslash( $_GET['sitewise_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$action   = esc_url( admin_url( 'admin-post.php' ) );
$pub_types = get_post_types( array( 'public' => true ), 'objects' );
$switcher  = class_exists( 'Folium_UI' ) ? Folium_UI::render_switcher( 'sitewise' ) : '';
?>
<div class="wrap fl-root sitewise-admin">

	<div class="sitewise-topbar">
		<?php echo $switcher; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from internal data in Folium_UI. ?>
		<span class="fl-meta sitewise-ver">v<?php echo esc_html( SITEWISE_VERSION ); ?></span>
	</div>

	<header class="sitewise-hero">
		<span class="fl-eyebrow"><span class="fl-num">01</span> &nbsp;GROUNDED ASSISTANT</span>
		<h1 class="fl-display" style="font-size:34px;margin-top:10px;">
			<?php esc_html_e( 'Answers from your', 'wp-call-me-back' ); ?> <span class="fl-ital"><?php esc_html_e( 'own', 'wp-call-me-back' ); ?></span> <?php esc_html_e( 'content.', 'wp-call-me-back' ); ?>
		</h1>
		<p class="fl-lead" style="margin-top:10px;max-width:560px;">
			<?php esc_html_e( 'A grounded on-page assistant that answers only from your pages — and when it can\'t, it offers a call back. No hallucinations, no SaaS lock-in.', 'wp-call-me-back' ); ?>
		</p>
	</header>

	<?php if ( 'saved' === $status ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'wp-call-me-back' ); ?></p></div>
	<?php elseif ( 'rebuilt' === $status ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Corpus rebuilt and pushed.', 'wp-call-me-back' ); ?></p></div>
	<?php elseif ( 'tested' === $status && is_array( $health ) ) : ?>
		<div class="notice notice-<?php echo $health['ok'] ? 'success' : 'error'; ?> is-dismissible"><p><?php echo esc_html( $health['message'] ); ?></p></div>
	<?php endif; ?>

	<?php
	// Corpus status panel.
	$built_at = ! empty( $stats['built_at'] ) ? wp_date( 'Y-m-d H:i', $stats['built_at'] ) : __( 'never', 'wp-call-me-back' );
	?>
	<div class="fl-card sitewise-status">
		<div class="fl-card-head">
			<div class="fl-card-title">
				<span class="fl-eyebrow">CORPUS</span>
				<h2 class="fl-h2"><?php esc_html_e( 'Knowledge status', 'wp-call-me-back' ); ?></h2>
			</div>
			<div class="sitewise-actions">
				<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url above. ?>" style="display:inline;">
					<?php wp_nonce_field( Sitewise_Admin::NONCE ); ?>
					<input type="hidden" name="action" value="sitewise_test" />
					<button type="submit" class="fl-btn fl-btn--sm"><span class="fl-i" data-ic="plug"></span> <?php esc_html_e( 'Test connection', 'wp-call-me-back' ); ?></button>
				</form>
				<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" style="display:inline;">
					<?php wp_nonce_field( Sitewise_Admin::NONCE ); ?>
					<input type="hidden" name="action" value="sitewise_rebuild" />
					<button type="submit" class="fl-btn fl-btn--primary fl-btn--sm"><span class="fl-i" data-ic="refresh"></span> <?php esc_html_e( 'Rebuild now', 'wp-call-me-back' ); ?></button>
				</form>
			</div>
		</div>
		<div class="fl-card-pad">
			<div class="sitewise-metrics">
				<div class="sitewise-metric"><span class="fl-meta"><?php esc_html_e( 'PAGES', 'wp-call-me-back' ); ?></span><b><?php echo (int) ( $stats['pages'] ?? 0 ); ?></b></div>
				<div class="sitewise-metric"><span class="fl-meta"><?php esc_html_e( 'SIZE', 'wp-call-me-back' ); ?></span><b><?php echo esc_html( size_format( (int) ( $stats['bytes'] ?? 0 ) ) ); ?></b></div>
				<div class="sitewise-metric"><span class="fl-meta"><?php esc_html_e( 'EST. TOKENS', 'wp-call-me-back' ); ?></span><b><?php echo esc_html( number_format_i18n( (int) ( $stats['tokens'] ?? 0 ) ) ); ?></b></div>
				<div class="sitewise-metric"><span class="fl-meta"><?php esc_html_e( 'LAST BUILT', 'wp-call-me-back' ); ?></span><b><?php echo esc_html( $built_at ); ?></b></div>
			</div>
			<?php if ( ! empty( $stats['tokens'] ) && $stats['tokens'] > Sitewise_Corpus::RAG_TOKEN_THRESHOLD ) : ?>
				<p class="fl-pill fl-pill--warn" style="margin-top:14px;display:inline-flex;"><span class="fl-i" data-ic="warn"></span> <?php esc_html_e( 'Large corpus — RAG retrieval recommended (a later release).', 'wp-call-me-back' ); ?></p>
			<?php endif; ?>
			<p class="fl-meta" style="margin-top:14px;">
				<a class="fl-link" href="<?php echo esc_url( $urls['map'] ); ?>" target="_blank" rel="noopener">llms.txt</a> &nbsp;·&nbsp;
				<a class="fl-link" href="<?php echo esc_url( $urls['full'] ); ?>" target="_blank" rel="noopener">llms-full.txt</a>
			</p>
		</div>
	</div>

	<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">
		<?php wp_nonce_field( Sitewise_Admin::NONCE ); ?>
		<input type="hidden" name="action" value="sitewise_save" />

		<h2 class="title"><?php esc_html_e( 'Connection', 'wp-call-me-back' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Hosting mode', 'wp-call-me-back' ); ?></th>
				<td>
					<label><input type="radio" name="mode" value="byo" <?php checked( $s['mode'], 'byo' ); ?> /> <?php esc_html_e( 'BYO Cloudflare (free — you host the Worker)', 'wp-call-me-back' ); ?></label><br />
					<label><input type="radio" name="mode" value="hosted" <?php checked( $s['mode'], 'hosted' ); ?> /> <?php esc_html_e( 'Hosted (paste a site key from your Sitewise account)', 'wp-call-me-back' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="worker_url"><?php esc_html_e( 'Worker URL', 'wp-call-me-back' ); ?></label></th>
				<td><input name="worker_url" id="worker_url" type="url" class="regular-text code" value="<?php echo esc_attr( $s['worker_url'] ); ?>" placeholder="https://sitewise.yoursubdomain.workers.dev" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="shared_secret"><?php esc_html_e( 'Shared secret', 'wp-call-me-back' ); ?></label></th>
				<td><input name="shared_secret" id="shared_secret" type="text" class="regular-text code" value="<?php echo esc_attr( $s['shared_secret'] ); ?>" autocomplete="off" />
				<p class="description"><?php esc_html_e( 'Must match the SITEWISE_SECRET set on your Worker.', 'wp-call-me-back' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><label for="site_key"><?php esc_html_e( 'Site key', 'wp-call-me-back' ); ?></label></th>
				<td><input name="site_key" id="site_key" type="text" class="regular-text code" value="<?php echo esc_attr( $s['site_key'] ); ?>" />
				<p class="description"><?php esc_html_e( 'Hosted mode only. Leave blank in BYO mode (derived from your domain).', 'wp-call-me-back' ); ?></p></td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Assistant', 'wp-call-me-back' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable chatbot', 'wp-call-me-back' ); ?></th>
				<td>
					<label><input type="checkbox" name="chat_enabled" value="1" <?php checked( $s['chat_enabled'], 1 ); ?> /> <?php esc_html_e( 'Show the assistant on the site', 'wp-call-me-back' ); ?></label><br />
					<label><input type="checkbox" name="auto_inject" value="1" <?php checked( $s['auto_inject'], 1 ); ?> /> <?php esc_html_e( 'Auto-add the launcher to every page (otherwise use the [sitewise] shortcode)', 'wp-call-me-back' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="brand_colour"><?php esc_html_e( 'Brand colour', 'wp-call-me-back' ); ?></label></th>
				<td><input name="brand_colour" id="brand_colour" type="text" class="regular-text" value="<?php echo esc_attr( $s['brand_colour'] ); ?>" placeholder="#2563eb" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="launcher_pos"><?php esc_html_e( 'Launcher position', 'wp-call-me-back' ); ?></label></th>
				<td>
					<select name="launcher_pos" id="launcher_pos">
						<option value="bottom-right" <?php selected( $s['launcher_pos'], 'bottom-right' ); ?>><?php esc_html_e( 'Bottom right', 'wp-call-me-back' ); ?></option>
						<option value="bottom-left" <?php selected( $s['launcher_pos'], 'bottom-left' ); ?>><?php esc_html_e( 'Bottom left', 'wp-call-me-back' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="opening_message"><?php esc_html_e( 'Opening message', 'wp-call-me-back' ); ?></label></th>
				<td><input name="opening_message" id="opening_message" type="text" class="large-text" value="<?php echo esc_attr( $s['opening_message'] ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="contact_url"><?php esc_html_e( 'Contact URL', 'wp-call-me-back' ); ?></label></th>
				<td><input name="contact_url" id="contact_url" type="url" class="regular-text" value="<?php echo esc_attr( $s['contact_url'] ); ?>" placeholder="<?php echo esc_attr( home_url( '/contact/' ) ); ?>" />
				<p class="description"><?php esc_html_e( 'Where the assistant sends visitors when it cannot answer.', 'wp-call-me-back' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Can\'t-answer pivot', 'wp-call-me-back' ); ?></th>
				<td><label><input type="checkbox" name="chat_handoff" value="1" <?php checked( $s['chat_handoff'], 1 ); ?> /> <?php esc_html_e( 'When the assistant can\'t answer, offer an inline call-back form', 'wp-call-me-back' ); ?></label>
				<p class="description"><?php esc_html_e( 'Turns a dead end into a lead. Requires the call-back widget (below) to be enabled.', 'wp-call-me-back' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Attribution', 'wp-call-me-back' ); ?></th>
				<td><label><input type="checkbox" name="powered_by" value="1" <?php checked( $s['powered_by'], 1 ); ?> /> <?php esc_html_e( 'Show a small "powered by Sitewise" line', 'wp-call-me-back' ); ?></label></td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Corpus rules', 'wp-call-me-back' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Include post types', 'wp-call-me-back' ); ?></th>
				<td>
					<?php foreach ( $pub_types as $type => $obj ) : ?>
						<label style="display:inline-block;margin-right:14px;">
							<input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $type ); ?>" <?php checked( in_array( $type, (array) $s['post_types'], true ) ); ?> />
							<?php echo esc_html( $obj->labels->name ); ?>
						</label>
					<?php endforeach; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Exclusions', 'wp-call-me-back' ); ?></th>
				<td>
					<label><input type="checkbox" name="exclude_noindex" value="1" <?php checked( $s['exclude_noindex'], 1 ); ?> /> <?php esc_html_e( 'Skip pages marked noindex (Yoast / Rank Math)', 'wp-call-me-back' ); ?></label><br />
					<label><input type="checkbox" name="exclude_protected" value="1" <?php checked( $s['exclude_protected'], 1 ); ?> /> <?php esc_html_e( 'Skip password-protected pages', 'wp-call-me-back' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="orientation"><?php esc_html_e( 'Orientation block', 'wp-call-me-back' ); ?></label></th>
				<td><textarea name="orientation" id="orientation" rows="5" class="large-text"><?php echo esc_textarea( $s['orientation'] ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Hand-written intro prepended to the corpus — who you are, what you do, tone. The single most valuable field for answer quality.', 'wp-call-me-back' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><label for="faq"><?php esc_html_e( 'FAQ block', 'wp-call-me-back' ); ?></label></th>
				<td><textarea name="faq" id="faq" rows="6" class="large-text"><?php echo esc_textarea( $s['faq'] ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Questions and answers that may not live on any page. Appended to the corpus.', 'wp-call-me-back' ); ?></p></td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Call-back widget', 'wp-call-me-back' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Call-back requests', 'wp-call-me-back' ); ?></th>
				<td><label><input type="checkbox" name="callback_enabled" value="1" <?php checked( $s['callback_enabled'], 1 ); ?> /> <?php esc_html_e( 'Enable the call-back request form ([sitewise_callback] shortcode + widget)', 'wp-call-me-back' ); ?></label>
				<p class="description"><?php esc_html_e( 'The original Call-Me-Back feature, carried forward. Submissions are emailed to the site admin.', 'wp-call-me-back' ); ?></p></td>
			</tr>
		</table>

		<?php submit_button( __( 'Save settings', 'wp-call-me-back' ) ); ?>
	</form>
</div>
