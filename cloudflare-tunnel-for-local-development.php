<?php
/**
 * Plugin Name:     Cloudflare Tunnel for Local Development
 * Plugin URI:      https://github.com/alfiosalanitri/wordpress-plugin-cloudflare-tunnel-for-local-development
 * Description:     A WordPress plugin that exposes your local development site via Cloudflare Tunnel, rewriting URLs on the fly only when requests arrive through the tunnel. Accessing `localhost` directly is never affected.
 * Version:         1.0.0
 * Author:          Alfio Salanitri
 * Author URI:      https://www.alfiosalanitri.it
 * License:         GPL-2.0+
 * Text Domain:     cf-local-dev-tunnel
 */

defined( 'ABSPATH' ) || exit;

define( 'CFLT_OPTION_KEY',  'cflt_tunnel_url' );
define( 'CFLT_ENABLED_KEY', 'cflt_tunnel_enabled' );
define( 'CFLT_MENU_SLUG',   'cflt-local-dev-tunnel' );

// ──────────────────────────────────────────────────────────────
// BOOTSTRAP
// ──────────────────────────────────────────────────────────────
$cflt_enabled    = get_option( CFLT_ENABLED_KEY, '0' );
$cflt_url        = get_option( CFLT_OPTION_KEY,  '' );

if ( '1' === $cflt_enabled && ! empty( $cflt_url ) ) {

	$cflt_url         = rtrim( $cflt_url, '/' );
	$cflt_tunnel_host = wp_parse_url( $cflt_url, PHP_URL_HOST );

	// Current host (ex. "xxxx.trycloudflare.com" or "localhost")
	$cflt_request_host = isset( $_SERVER['HTTP_HOST'] )
		? strtolower( preg_replace( '/:\d+$/', '', $_SERVER['HTTP_HOST'] ) )
		: '';

	// If the request does NOT come from the tunnel → do nothing,
	// localhost works exactly as before.
	if ( $cflt_tunnel_host && $cflt_request_host === strtolower( $cflt_tunnel_host ) ) {

		$cflt_original_url = rtrim( get_option( 'siteurl', 'http://localhost' ), '/' );

		// ── $_SERVER ──────────────────────────────────────────
		$_SERVER['HTTPS']       = 'on';
		$_SERVER['SERVER_PORT'] = '443';

		// ── URL Filters ────────────────────────────────────────
		$cflt_replace_url = function ( $url ) use ( $cflt_url ) {
			$path  = wp_parse_url( $url, PHP_URL_PATH ) ?? '';
			$query = wp_parse_url( $url, PHP_URL_QUERY );
			return $cflt_url . $path . ( $query ? '?' . $query : '' );
		};

		add_filter( 'site_url',                 $cflt_replace_url, 1 );
		add_filter( 'home_url',                 $cflt_replace_url, 1 );
		add_filter( 'network_url',              $cflt_replace_url, 1 );
		add_filter( 'content_url',              $cflt_replace_url, 1 );
		add_filter( 'plugins_url',              $cflt_replace_url, 1 );
		add_filter( 'stylesheet_directory_uri', $cflt_replace_url, 1 );
		add_filter( 'template_directory_uri',   $cflt_replace_url, 1 );
		add_filter( 'theme_root_uri',           $cflt_replace_url, 1 );

		add_filter( 'upload_dir', function ( $dirs ) use ( $cflt_url ) {
			$dirs['url']     = preg_replace( '#^https?://[^/]+#', $cflt_url, $dirs['url'] );
			$dirs['baseurl'] = preg_replace( '#^https?://[^/]+#', $cflt_url, $dirs['baseurl'] );
			return $dirs;
		}, 1 );

		// ── Fix loop redirects ────────────────────────────────
		remove_action( 'template_redirect', 'redirect_canonical' );

		add_filter( 'wp_redirect', function ( $location, $status ) use ( $cflt_url, $cflt_tunnel_host ) {
			if ( empty( $location ) ) return $location;
			$dest = wp_parse_url( $location, PHP_URL_HOST );
			if ( $dest === $cflt_tunnel_host ) return $location;
			$path  = wp_parse_url( $location, PHP_URL_PATH ) ?? '';
			$query = wp_parse_url( $location, PHP_URL_QUERY );
			return $cflt_url . $path . ( $query ? '?' . $query : '' );
		}, 1, 2 );

		// ── Output buffer ─────────────────────────────────────
		// Safety net: rewrites in the final HTML any
		// URL that slipped through the filters above.
		$cflt_ob = function ( $html ) use ( $cflt_original_url, $cflt_url ) {
			if ( empty( $html ) ) return $html;
			$http  = preg_replace( '#^https?://#', 'http://',  $cflt_original_url );
			$https = preg_replace( '#^https?://#', 'https://', $cflt_original_url );
			$search = [
				str_replace( '/', '\/', $https ),
				str_replace( '/', '\/', $http ),
				$https,
				$http,
			];
			$esc = str_replace( '/', '\/', $cflt_url );
			$replace = [ $esc, $esc, $cflt_url, $cflt_url ];
			return str_replace( $search, $replace, $html );
		};

		add_action( 'template_redirect', function () use ( $cflt_ob ) {
			ob_start( $cflt_ob );
		}, 1 );

		add_action( 'admin_init', function () use ( $cflt_ob ) {
			ob_start( $cflt_ob );
		}, 1 );
	}
}

// ──────────────────────────────────────────────────────────────
// ADMIN MENU
// ──────────────────────────────────────────────────────────────
add_action( 'admin_menu', function () {
	add_options_page(
		'Tunnel Preview for Cloudflare',
		'CF Tunnel / Local Dev',
		'manage_options',
		CFLT_MENU_SLUG,
		'cflt_render_settings_page'
	);
} );

// ──────────────────────────────────────────────────────────────
// SETTINGS
// ──────────────────────────────────────────────────────────────
add_action( 'admin_init', function () {
	register_setting( 'cflt_settings_group', CFLT_OPTION_KEY, [
		'type'              => 'string',
		'sanitize_callback' => 'cflt_sanitize_url',
		'default'           => '',
	] );
	register_setting( 'cflt_settings_group', CFLT_ENABLED_KEY, [
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => '0',
	] );
} );

function cflt_sanitize_url( $raw ) {
	$url = esc_url_raw( trim( $raw ) );
	if ( ! empty( $url ) && ! preg_match( '#^https://#i', $url ) ) {
		add_settings_error( CFLT_OPTION_KEY, 'invalid_scheme',
			'The tunnel URL must start with <strong>https://</strong>.',
			'error'
		);
		return get_option( CFLT_OPTION_KEY, '' );
	}
	return $url;
}

// ──────────────────────────────────────────────────────────────
// SETTINS PAGE
// ──────────────────────────────────────────────────────────────
function cflt_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) return;

	$enabled      = get_option( CFLT_ENABLED_KEY, '0' );
	$tunnel_url   = get_option( CFLT_OPTION_KEY,  '' );
	global $wpdb;
	$orig_url = rtrim( $wpdb->get_var( "SELECT option_value FROM $wpdb->options WHERE option_name = 'siteurl' LIMIT 1" ), '/' );
	$tunnel_host  = $tunnel_url ? wp_parse_url( $tunnel_url, PHP_URL_HOST ) : '';
	$request_host = strtolower( preg_replace( '/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '' ) );
	$is_tunneled  = $tunnel_host && ( $request_host === strtolower( $tunnel_host ) );
	?>
	<style>
        .cft-wrap { max-width: 680px; }
        .cft-card { background:#fff; border:1px solid #ddd; border-radius:8px; padding:24px 28px; margin-top:20px; }
        .cft-card h2 { margin-top:0; font-size:1rem; color:#1d2327; }
        .cft-badge { display:inline-block; padding:2px 10px; border-radius:20px; font-size:12px; font-weight:600; }
        .cft-badge.on     { background:#d1fae5; color:#065f46; }
        .cft-badge.off    { background:#fee2e2; color:#991b1b; }
        .cft-badge.tunnel { background:#dbeafe; color:#1e40af; }
        .cft-badge.local  { background:#f3f4f6; color:#374151; }
        .cft-mono { background:#f6f7f7; border:1px solid #ddd; border-radius:4px; padding:8px 12px; font-family:monospace; font-size:13px; word-break:break-all; margin-top:6px; }
        .cft-input { width:100%; padding:8px 10px; font-size:14px; border:1px solid #8c8f94; border-radius:4px; box-sizing:border-box; }
        .cft-tip { background:#fff8e5; border-left:4px solid #f0b429; padding:10px 14px; border-radius:4px; font-size:13px; margin-top:16px; }
        .cft-tip code { background:#fef3c7; padding:1px 5px; border-radius:3px; }
	</style>

	<div class="wrap cft-wrap">
		<h1 style="display:flex;align-items:center;gap:10px;"><svg width="60" height="45" viewBox="0 0 15.874999 11.90625" xmlns="http://www.w3.org/2000/svg"><g transform="matrix(0.72382964,0,0,0.72382964,0.21920019,-2.8469326)"><path d="m 14.270355,16.515537 0.100013,-0.351684 c 0.121417,-0.416296 0.07617,-0.803884 -0.126207,-1.086194 -0.185711,-0.2608 -0.495247,-0.413888 -0.871458,-0.433017 l -7.1192754,-0.09091 c -0.047599,-0.0024 -0.08808,-0.02395 -0.1118923,-0.05985 -0.023813,-0.03588 -0.030956,-0.08374 -0.014261,-0.129196 0.023786,-0.06937 0.092842,-0.12438 0.1642798,-0.126788 l 7.1835169,-0.09091 c 0.852434,-0.03829 1.773898,-0.734483 2.097722,-1.581441 l 0.409522,-1.076616 c 0.01191,-0.02871 0.01667,-0.0598 0.01667,-0.09091 0,-0.01675 -0.0024,-0.0335 -0.0048,-0.05024 -0.461909,-2.1029929 -2.331032,-3.6748586 -4.562051,-3.6748586 -2.0571884,0 -3.8048671,1.3350081 -4.4310829,3.1891896 -0.404786,-0.303848 -0.9214643,-0.46654 -1.4786238,-0.411533 -0.9881129,0.09811 -1.7809897,0.897202 -1.878621,1.890077 -0.026194,0.258392 -0.00476,0.504799 0.054742,0.739273 -1.6119368,0.04786 -2.90483463,1.375674 -2.90483463,3.004978 0,0.148326 0.0119052,0.291862 0.0309534,0.435425 0.009524,0.0694 0.0690499,0.119618 0.13809953,0.119618 l 13.1408654,0.0024 c 0.0024,0 0.0024,0 0.0048,0 0.07382,-0.0024 0.140467,-0.05263 0.161898,-0.126815 z" fill="#f6821f" style="stroke-width:0.264583" /><path d="m 16.641948,11.568279 c -0.06665,0 -0.130969,0.0024 -0.197618,0.0048 -0.01191,0 -0.02143,0.0024 -0.03096,0.0072 -0.03334,0.01196 -0.06191,0.04067 -0.07141,0.07657 l -0.280961,0.971339 c -0.12147,0.416295 -0.0762,0.803857 0.12618,1.086167 0.185711,0.2608 0.495247,0.413914 0.871458,0.43307 l 1.516724,0.09091 c 0.04522,0.0024 0.08334,0.02392 0.10713,0.0598 0.02619,0.0359 0.03096,0.08374 0.01667,0.129222 -0.02381,0.06935 -0.09287,0.124381 -0.164279,0.126789 l -1.576256,0.09091 c -0.854789,0.04067 -1.778634,0.734484 -2.102458,1.581441 l -0.114273,0.299032 c -0.02143,0.05506 0.01905,0.112475 0.07379,0.114856 0.0024,0 0.0024,0 0.0048,0 h 5.426366 c 0.06429,0 0.121417,-0.04307 0.140493,-0.105251 0.09522,-0.33737 0.14523,-0.691436 0.14523,-1.059868 0,-2.15564 -1.742916,-3.906943 -3.890618,-3.906943 z" fill="#fbad41" style="stroke-width:0.264583" /></g></svg> Cloudflare Tunnel for Local Development</h1>
		<?php settings_errors( CFLT_OPTION_KEY ); ?>

		<div class="cft-card">
			<h2>Current status</h2>
			<table style="border-collapse:collapse; width:100%">
				<tr>
					<td style="padding:5px 0; width:180px; color:#555">Plugin enabled</td>
					<td><span class="cft-badge <?php echo '1' === $enabled ? 'on' : 'off'; ?>">
                        <?php echo '1' === $enabled ? 'SÌ' : 'NO'; ?>
                    </span></td>
				</tr>
				<tr>
					<td style="padding:5px 0; color:#555">You are browsing from</td>
					<td><span class="cft-badge <?php echo $is_tunneled ? 'tunnel' : 'local'; ?>">
                        <?php echo $is_tunneled ? 'Cloudflare Tunnel' : 'localhost'; ?>
                    </span></td>
				</tr>
				<tr>
					<td style="padding:5px 0; color:#555">URL rewriting active</td>
					<td><span class="cft-badge <?php echo ( '1' === $enabled && $is_tunneled ) ? 'on' : 'off'; ?>">
                        <?php echo ( '1' === $enabled && $is_tunneled ) ? 'SÌ' : 'NO'; ?>
                    </span></td>
				</tr>
			</table>
			<p style="margin-bottom:4px; margin-top:14px"><strong>Original URL (DB / localhost):</strong></p>
			<div class="cft-mono"><?php echo esc_html( $orig_url ); ?></div>
			<?php if ( $tunnel_url ) : ?>
				<p style="margin-bottom:4px; margin-top:10px"><strong>Tunnel URL:</strong></p>
				<div class="cft-mono"><?php echo esc_html( $tunnel_url ); ?></div>
			<?php endif; ?>
		</div>

		<div class="cft-card">
			<form method="post" action="options.php">
				<?php settings_fields( 'cflt_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cflt_tunnel_url">Cloudflare Tunnel URL</label></th>
						<td>
							<input type="url" id="cflt_tunnel_url"
							       name="<?php echo esc_attr( CFLT_OPTION_KEY ); ?>"
							       value="<?php echo esc_attr( $tunnel_url ); ?>"
							       class="cft-input"
							       placeholder="https://xxxx.trycloudflare.com" />
							<p class="description">Must start with <strong>https://</strong>. No trailing slash.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Enable tunnel</th>
						<td>
							<label>
								<input type="checkbox"
								       name="<?php echo esc_attr( CFLT_ENABLED_KEY ); ?>"
								       value="1"
									<?php checked( '1', $enabled ); ?> />
								Enable URL rewriting for requests from the tunnel
							</label>
							<p class="description">
								Access from <strong>localhost</strong> is never modified,
								regardless of this setting.
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Save settings' ); ?>
			</form>
		</div>

		<div class="cft-tip">
			<strong>💡 How to activate the tunnel</strong><br>
			Install <a href="https://developers.cloudflare.com/tunnel/downloads/" target="_blank">Cloudflare Tunnel</a> on your development machine and start it
			with the command <code>cloudflared tunnel --url http://localhost:8001</code>
		</div>

		<div class="cft-tip">
			<strong>💡 What the plugin does</strong><br>
			URL rewriting activates <em>only</em> when the request comes from the tunnel host.
			You can keep the plugin always enabled: from <code>localhost</code> WordPress works normally,
			from <code>https://xxxx.trycloudflare.com</code> all URLs are rewritten on the fly.
		</div>
	</div>
	<?php
}


// ──────────────────────────────────────────────────────────────
// CLEANUP: deactivation and uninstallation
// ──────────────────────────────────────────────────────────────

// Removes the options when the plugin is deactivated
register_deactivation_hook( __FILE__, function () {
	delete_option( CFLT_OPTION_KEY );
	delete_option( CFLT_ENABLED_KEY );
} );

// register_uninstall_hook requires a static function (not a closure)
register_uninstall_hook( __FILE__, 'cflt_uninstall' );

function cflt_uninstall() {
	delete_option( 'cflt_tunnel_url' );
	delete_option( 'cflt_tunnel_enabled' );
}

// ──────────────────────────────────────────────────────────────
// "Settings" link in the plugin list
// ──────────────────────────────────────────────────────────────
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	array_unshift( $links, sprintf(
		'<a href="%s">Settings</a>',
		admin_url( 'options-general.php?page=' . CFLT_MENU_SLUG )
	) );
	return $links;
} );