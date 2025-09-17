<?php
/**
 * Plugin Name: SATORI – Restrict Risky Plugins (Staging Only + Admin Toggles + Logs + Kill Switch)
 * Description: Blocks selected plugins on production; allows temporary, expiring overrides via Settings → SATORI Tools, with logs and a global kill switch.
 * Author: SATORI
 * Version: 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ╔════════════════════════════════════════════════════════════════════╗
   ║ SATORI SECTION: CONFIG & CONSTANTS                                  ║
   ╚════════════════════════════════════════════════════════════════════╝ */

define( 'SATORI_RISKY_OPT_KEY',   'satori_risky_plugins_settings' ); // options API key
define( 'SATORI_RISKY_LOG_KEY',   'satori_risky_plugins_logs' );     // logs option key (autoload = no)
define( 'SATORI_RISKY_NONCE',     'satori_risky_plugins_nonce' );
define( 'SATORI_RISKY_LOG_LIMIT', 500 ); // keep last N entries

// Global kill switch (wp-config.php): define('SATORI_LOCKDOWN', true);

/* ╔════════════════════════════════════════════════════════════════════╗
   ║ SATORI SECTION: ENV / SETTINGS                                     ║
   ╚════════════════════════════════════════════════════════════════════╝ */

function satori_env_is_staging(): bool {
	$home = home_url();
	if ( strpos( $home, 'staging' ) !== false ) return true;
	if ( defined('WP_ENV') && WP_ENV === 'staging' ) return true;
	if ( defined('SATORI_ENV') && SATORI_ENV === 'staging' ) return true;
	return false;
}

function satori_risky_defaults(): array {
	return [
		'risky_slugs' => [
			'wp-file-manager/wp-file-manager.php',
			'file-manager-advanced/file-manager-advanced.php',
			'wp-reset/wp-reset.php',
			'duplicator/duplicator.php',
		],
		'overrides'   => [],                                  // [ slug => [ enabled_until, reason, by_user, at ] ]
		'alert_email' => get_option( 'admin_email' ),
		'allow_network_admin_bypass' => false,
	];
}

function satori_risky_get_settings(): array {
	$opts = get_option( SATORI_RISKY_OPT_KEY );
	if ( ! is_array( $opts ) ) $opts = [];
	return wp_parse_args( $opts, satori_risky_defaults() );
}

function satori_risky_save_settings( array $opts ): void {
	update_option( SATORI_RISKY_OPT_KEY, $opts, false );
}

/* ╔════════════════════════════════════════════════════════════════════╗
   ║ SATORI SECTION: LOGGING                                            ║
   ╚════════════════════════════════════════════════════════════════════╝ */

function satori_risky_get_logs(): array {
	$logs = get_option( SATORI_RISKY_LOG_KEY, [] );
	return is_array( $logs ) ? $logs : [];
}

function satori_risky_save_logs( array $logs ): void {
	if ( count( $logs ) > SATORI_RISKY_LOG_LIMIT ) {
		$logs = array_slice( $logs, - SATORI_RISKY_LOG_LIMIT );
	}
	update_option( SATORI_RISKY_LOG_KEY, $logs, false );
}

function satori_risky_log( string $action, string $slug = '', array $extra = [] ): void {
	$user   = wp_get_current_user();
	$logs   = satori_risky_get_logs();
	$logs[] = [
		'time'   => current_time( 'mysql' ),
		'user'   => $user && $user->ID ? sprintf( '%s (#%d)', $user->user_login, $user->ID ) : 'system',
		'ip'     => $_SERVER['REMOTE_ADDR'] ?? '',
		'action' => $action,
		'slug'   => $slug,
		'extra'  => $extra,
	];
	satori_risky_save_logs( $logs );
}

/* ╔════════════════════════════════════════════════════════════════════╗
   ║ SATORI SECTION: ENFORCEMENT (BLOCK ON PROD, ALLOW ON STAGING)      ║
   ╚════════════════════════════════════════════════════════════════════╝ */

add_action( 'plugins_loaded', function () {
	$is_staging = satori_env_is_staging();
	$settings   = satori_risky_get_settings();
	$now        = time();

	$changed = false;
	foreach ( $settings['overrides'] as $slug => $data ) {
		if ( empty( $data['enabled_until'] ) || $now > (int) $data['enabled_until'] ) {
			unset( $settings['overrides'][ $slug ] );
			$changed = true;
			satori_risky_log( 'override_expired', $slug );
		}
	}
	if ( $changed ) satori_risky_save_settings( $settings );

	if ( $is_staging ) return;

	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$lockdown = defined('SATORI_LOCKDOWN') && SATORI_LOCKDOWN;

	foreach ( (array) $settings['risky_slugs'] as $slug ) {
		$has_override = isset( $settings['overrides'][ $slug ] ) && ! $lockdown;

		if ( is_multisite() && is_super_admin() && ! empty( $settings['allow_network_admin_bypass'] ) && ! $lockdown ) {
			continue;
		}

		if ( $has_override ) continue;

		if ( is_plugin_active( $slug ) ) {
			deactivate_plugins( $slug, true );
			satori_risky_log( 'deactivated_on_prod', $slug );
		}

		add_action( 'admin_menu', function () {
			remove_menu_page( 'wp-file-manager' );
			remove_menu_page( 'file-manager-advanced' );
			remove_menu_page( 'tools.php?page=wp-reset' );
			remove_menu_page( 'duplicator' );
		}, 999 );

		add_action( 'admin_init', function () {
			$page = isset( $_GET['page'] ) ? sanitize_text_field( $_GET['page'] ) : '';
			$blocked = [ 'wp-file-manager', 'file-manager-advanced', 'wp-reset', 'duplicator' ];
			if ( in_array( $page, $blocked, true ) ) {
				satori_risky_log( 'blocked_access', '', [ 'page' => $page ] );
				wp_die( '🚫 This tool is disabled on production by SATORI.', 'SATORI', 403 );
			}
		} );
	}
} );

/* ╔════════════════════════════════════════════════════════════════════╗
   ║ SATORI SECTION: ADMIN UI (Settings → SATORI Tools with Logs)       ║
   ╚════════════════════════════════════════════════════════════════════╝ */

add_action( 'admin_menu', function () {
	add_options_page( 'SATORI Tools', 'SATORI Tools', 'manage_options', 'satori-tools', 'satori_tools_screen' );
} );

function satori_tools_screen() {
	if ( ! current_user_can( 'manage_options' ) ) return;

	$settings = satori_risky_get_settings();
	$is_staging = satori_env_is_staging();
	$lockdown = defined('SATORI_LOCKDOWN') && SATORI_LOCKDOWN;

	if ( isset( $_POST['satori_risky_action'] ) && check_admin_referer( SATORI_RISKY_NONCE ) ) {
		$action = sanitize_text_field( $_POST['satori_risky_action'] );

		if ( $action === 'save_list' ) {
			$raw  = (string) ( $_POST['risky_slugs'] ?? '' );
			$list = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', wp_unslash( $raw ) ) ) );
			$settings['risky_slugs'] = array_values( $list );
			$settings['alert_email'] = sanitize_email( $_POST['alert_email'] ?? '' );
			$settings['allow_network_admin_bypass'] = ! empty( $_POST['allow_network_admin_bypass'] );

			satori_risky_save_settings( $settings );
			add_settings_error( 'satori', 'saved', 'Settings saved.', 'updated' );
			satori_risky_log( 'settings_saved', '', [ 'count' => count( $list ) ] );
		}

		if ( $action === 'temp_enable' && ! $lockdown && ! $is_staging ) {
			$slug   = sanitize_text_field( $_POST['plugin_slug'] ?? '' );
			$hours  = max( 1, min( 72, intval( $_POST['duration_hours'] ?? 1 ) ) );
			$reason = sanitize_text_field( $_POST['reason'] ?? '' );
			$until  = time() + ( $hours * HOUR_IN_SECONDS );

			$settings['overrides'][ $slug ] = [
				'enabled_until' => $until,
				'reason'        => $reason,
				'by_user'       => get_current_user_id(),
				'at'            => current_time('mysql'),
			];
			satori_risky_save_settings( $settings );

			$to = $settings['alert_email'] ?: get_option( 'admin_email' );
			wp_mail(
				$to,
				'⚠️ SATORI: Risky plugin temporarily ENABLED on production',
				"Plugin: {$slug}\nSite: " . home_url() . "\nUntil: " . date_i18n( 'Y-m-d H:i', $until ) . "\nReason: {$reason}\nBy: " . wp_get_current_user()->user_login
			);
			error_log( '[SATORI] Override enabled for ' . $slug . ' until ' . gmdate( 'c', $until ) );
			add_settings_error( 'satori', 'temp', 'Temporary enablement recorded.', 'updated' );
			satori_risky_log( 'override_enabled', $slug, [ 'until' => $until, 'reason' => $reason ] );
		}

		if ( $action === 'revoke' ) {
			$slug = sanitize_text_field( $_POST['plugin_slug'] ?? '' );
			unset( $settings['overrides'][ $slug ] );
			satori_risky_save_settings( $settings );
			add_settings_error( 'satori', 'revoked', 'Override revoked.', 'updated' );
			satori_risky_log( 'override_revoked', $slug );
		}

		if ( $action === 'clear_logs' ) {
			satori_risky_save_logs( [] );
			add_settings_error( 'satori', 'logs_cleared', 'Logs cleared.', 'updated' );
			satori_risky_log( 'logs_cleared' );
		}
	}

	settings_errors( 'satori' );

	$risky_slugs_text = implode( "\n", (array) $settings['risky_slugs'] );
	$logs = array_reverse( satori_risky_get_logs() );
	?>
	<div class="wrap">
		<h1>SATORI Tools — Restrict Risky Plugins</h1>
		<p><strong>Environment:</strong> <?php echo $is_staging ? 'Staging (all allowed)' : 'Production (restricted)'; ?>
		<?php if ( $lockdown ): ?>
			<span style="margin-left:10px;padding:3px 8px;background:#c62828;color:#fff;border-radius:6px;">KILL SWITCH ACTIVE</span>
		<?php endif; ?>
		</p>

		<h2 class="title">1) Risky Plugins List</h2>
		<form method="post">
			<?php wp_nonce_field( SATORI_RISKY_NONCE ); ?>
			<input type="hidden" name="satori_risky_action" value="save_list" />
			<p>Enter one plugin main file per line (e.g. <code>folder/plugin.php</code>):</p>
			<textarea name="risky_slugs" rows="8" style="width: 100%; font-family: monospace;"><?php echo esc_textarea( $risky_slugs_text ); ?></textarea>
			<p>
				<label>Email alerts to: <input type="email" name="alert_email" value="<?php echo esc_attr( $settings['alert_email'] ); ?>" style="width: 300px;"></label>
			</p>
			<p>
				<label><input type="checkbox" name="allow_network_admin_bypass" <?php checked( ! empty( $settings['allow_network_admin_bypass'] ) ); ?> />
					Allow Network Admin (multisite) to bypass restrictions
				</label>
			</p>
			<p><button class="button button-primary">Save Settings</button></p>
		</form>

		<h2 class="title">2) Temporary Enable (Production only)</h2>
		<?php if ( $lockdown ): ?>
			<p style="color:#c62828;"><strong>Kill switch is active.</strong> Overrides are disabled until <code>SATORI_LOCKDOWN</code> is removed or set to <code>false</code> in <code>wp-config.php</code>.</p>
		<?php endif; ?>
		<p>Use only when absolutely necessary. Overrides expire automatically.</p>
		<table class="widefat striped">
			<thead><tr><th>Plugin</th><th>Status</th><th>Override</th></tr></thead>
			<tbody>
			<?php foreach ( (array) $settings['risky_slugs'] as $slug ):
				$ov  = $settings['overrides'][ $slug ] ?? null;
				$status = $ov
					? 'TEMP ALLOWED until ' . esc_html( date_i18n( 'Y-m-d H:i', (int) $ov['enabled_until'] ) )
					: ( $is_staging ? 'Allowed (Staging)' : 'Restricted (Prod)' );
			?>
				<tr>
					<td><code><?php echo esc_html( $slug ); ?></code></td>
					<td><?php echo esc_html( $status ); ?></td>
					<td>
						<?php if ( ! $is_staging && ! $lockdown ): ?>
						<form method="post" style="display:inline-block;margin-right:10px;">
							<?php wp_nonce_field( SATORI_RISKY_NONCE ); ?>
							<input type="hidden" name="satori_risky_action" value="temp_enable" />
							<input type="hidden" name="plugin_slug" value="<?php echo esc_attr( $slug ); ?>" />
							Duration:
							<select name="duration_hours">
								<option value="1">1 hr</option><option value="2">2 hrs</option>
								<option value="4">4 hrs</option><option value="8">8 hrs</option>
								<option value="24">24 hrs</option><option value="48">48 hrs</option>
								<option value="72">72 hrs</option>
							</select>
							Reason:
							<input type="text" name="reason" placeholder="Why?" style="width:180px;" />
							<button class="button">Enable Temporarily</button>
						</form>
						<?php endif; ?>

						<?php if ( $ov ): ?>
						<form method="post" style="display:inline-block;">
							<?php wp_nonce_field( SATORI_RISKY_NONCE ); ?>
							<input type="hidden" name="satori_risky_action" value="revoke" />
							<input type="hidden" name="plugin_slug" value="<?php echo esc_attr( $slug ); ?>" />
							<button class="button button-secondary">Revoke Now</button>
						</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<h2 class="title" style="margin-top:18px;">3) Audit Log</h2>
		<form method="post" style="margin-bottom:8px;">
			<?php wp_nonce_field( SATORI_RISKY_NONCE ); ?>
			<input type="hidden" name="satori_risky_action" value="clear_logs" />
			<button class="button" onclick="return confirm('Clear all SATORI logs?')">Clear Logs</button>
		</form>

		<table class="widefat striped">
			<thead>
				<tr><th>When</th><th>User</th><th>IP</th><th>Action</th><th>Plugin</th><th>Details</th></tr>
			</thead>
			<tbody>
			<?php if ( empty( $logs ) ): ?>
				<tr><td colspan="6">No log entries yet.</td></tr>
			<?php else: foreach ( $logs as $row ): ?>
				<tr>
					<td><?php echo esc_html( $row['time'] ?? '' ); ?></td>
					<td><?php echo esc_html( $row['user'] ?? '' ); ?></td>
					<td><?php echo esc_html( $row['ip'] ?? '' ); ?></td>
					<td><?php echo esc_html( $row['action'] ?? '' ); ?></td>
					<td><code><?php echo esc_html( $row['slug'] ?? '' ); ?></code></td>
					<td><small><?php echo esc_html( json_encode( $row['extra'] ?? [] ) ); ?></small></td>
				</tr>
			<?php endforeach; endif; ?>
			</tbody>
		</table>

		<p style="margin-top:12px;color:#666;">SATORI: Logs capped to last <?php echo (int) SATORI_RISKY_LOG_LIMIT; ?> entries. Actions are nonce-protected and capability-gated.</p>
	</div>
	<?php
}

/* ╔════════════════════════════════════════════════════════════════════╗
   ║ SATORI SECTION: SMALL DEBUG BADGE                                  ║
   ╚════════════════════════════════════════════════════════════════════╝ */

add_action( 'wp_footer', function () {
	if ( ! current_user_can( 'manage_options' ) ) return;

    $is_staging = satori_env_is_staging();
    $lockdown   = defined('SATORI_LOCKDOWN') && SATORI_LOCKDOWN;

    $env_label  = $is_staging ? 'STAGING' : 'PRODUCTION';
    $env_color  = $is_staging ? '#2e7d32' : '#c62828'; // green vs red
    $lock_text  = $lockdown ? ' — KILL SWITCH ACTIVE' : '';

    printf(
        '<div style="padding:10px; background:%s; color:#fff; font-weight:bold; font-size:14px; text-align:center; margin-bottom:15px;">
         SATORI MODE: %s%s
        </div>',
        esc_attr( $env_color ),
        esc_html( $env_label ),
        esc_html( $lock_text )
    );
});

