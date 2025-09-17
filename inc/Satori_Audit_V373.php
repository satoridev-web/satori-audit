<?php
/**
 * Plugin Name: SATORI – Site Audit v3.7.3 (PDF layout controls + weekly lines tweak + version prefix + HTML preview)
 * Description: Tools → SATORI Audit. Client-ready PDF/MD/CSV exports, per-asset history (weekly lines on change), LSCWP hints, internal-only notifications (safelist + test email), uploadable DOMPDF with diagnostics, WCAG-AA admin badges/links, access control, AU-format timestamp, PDF page size/orientation controls, and HTML Preview.
 * Version: 3.7.3
 * Author: SATORI
 * License: GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'Satori_Audit_V373' ) ) {

class Satori_Audit_V373 {

	/* -------------------------------------------------
	 * Options / Paths / Hooks
	 * -------------------------------------------------*/
	const OPT_SETTINGS    = 'satori_audit_v3_settings';
	const OPT_HISTORY     = 'satori_audit_v3_history';
	const OPT_EVENTS      = 'satori_audit_v3_events';
	const OPT_ASSET_LOG   = 'satori_audit_v3_asset_log';
	const OPT_PLUGIN_LOG  = 'satori_audit_v3_plugin_log'; // legacy

	const CRON_HOOK_M     = 'satori_audit_v3_monthly_event';
	const CRON_HOOK_D     = 'satori_audit_v3_daily_watch';

	// Library dir for add-ons (e.g., dompdf)
	const LIB_DIR         = 'satori-audit-lib';
	const LIB_DOMPDF_DIR  = 'dompdf'; // inside LIB_DIR

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_pages' ) );

		// Notices + AJAX
		add_action( 'admin_notices', array( __CLASS__, 'maybe_notice_dompdf' ) );
		add_action( 'wp_ajax_satori_audit_v3_dismiss_pdf_notice', array( __CLASS__, 'ajax_dismiss_pdf_notice' ) );

		// Admin-post handlers
		add_action( 'admin_post_satori_audit_v3_download', array( __CLASS__, 'handle_download' ) );
		add_action( 'admin_post_satori_audit_v3_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_satori_audit_v3_test_email', array( __CLASS__, 'handle_test_email' ) );
		add_action( 'admin_post_satori_audit_v3_install_dompdf', array( __CLASS__, 'handle_install_dompdf' ) );
		add_action( 'admin_post_satori_audit_v3_probe_dompdf', array( __CLASS__, 'handle_probe_dompdf' ) );

		// Cron + update hooks
		add_action( 'wp', array( __CLASS__, 'ensure_cron' ) );
		add_action( self::CRON_HOOK_M, array( __CLASS__, 'run_monthly' ) );
		add_action( self::CRON_HOOK_D, array( __CLASS__, 'run_daily_watch' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_update' ), 10, 2 );

		// Auto-update behaviour + email suppression
		add_filter( 'auto_update_plugin', array( __CLASS__, 'maybe_force_auto_updates' ), 10, 2 );
		add_filter( 'auto_plugin_update_send_email', array( __CLASS__, 'suppress_plugin_email' ), 10, 3 );
		add_filter( 'auto_theme_update_send_email',  array( __CLASS__, 'suppress_theme_email'  ), 10, 3 );
		add_filter( 'auto_core_update_send_email',   array( __CLASS__, 'suppress_core_email'   ), 10, 4 );

		// Recipient hardening + backfill
		add_action( 'init', array( __CLASS__, 'harden_recipients' ) );
		add_action( 'init', array( __CLASS__, 'maybe_backfill_weeklies' ) );
	}

	/* -------------------------------------------------
	 * Defaults (Settings)
	 * -------------------------------------------------*/
	protected static function defaults() {
		return array(
			'client'             => 'Client Name',
			'site_name'          => get_bloginfo( 'name' ),
			'site_url'           => home_url( '/' ),
			'managed_by'         => 'SATORI',
			'start_date'         => '',
			'service_notes'      => 'Monthly maintenance: WP/Plugins updates, security check, cache purge, audit.',
			'contact_email'      => get_option( 'admin_email' ),
			'notify_emails'      => '',
			'notify_webhook'     => '',
			'pdf_logo_url'       => '',

			// Safelist
			'enforce_safelist'   => false,
			'safelist_entries'   => '',

			// Automation
			'enable_monthly'       => true,
			'enable_watch'         => true,
			'force_auto_updates'   => false,
			'suppress_auto_emails' => true,

			// Versions weekly lines
			'weekly_lines_core'     => true,
			'weekly_lines_themes'   => true,
			'backfill_on_first_run' => false,

			// Access Control
			'restrict_settings'   => true,
			'restrict_dashboard'  => false,
			'primary_admin_email' => get_option( 'admin_email' ),
			'allowed_admins'      => '',

			'keep_months'        => 12,

			// NEW: PDF output defaults
			'pdf_page_size'      => 'A4',         // A4, Letter, Legal
			'pdf_orientation'    => 'portrait',   // portrait, landscape
		);
	}

	/* -------------------------------------------------
	 * Paths & FS
	 * -------------------------------------------------*/
	protected static function mu_base_dir() {
		return defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_PLUGIN_DIR;
	}
	protected static function uploads_base_dir() {
		if ( function_exists( 'wp_upload_dir' ) ) {
			$u = wp_upload_dir();
			if ( ! empty( $u['basedir'] ) ) { return $u['basedir']; }
		}
		return WP_CONTENT_DIR . '/uploads';
	}
	protected static function lib_base_root() {
		$mu = trailingslashit( self::mu_base_dir() );
		if ( is_dir( $mu ) && is_writable( $mu ) ) {
			return $mu . self::LIB_DIR;
		}
		$up = trailingslashit( self::uploads_base_dir() ) . self::LIB_DIR;
		return $up;
	}
	protected static function lib_base_dir() { return trailingslashit( self::lib_base_root() ); }
	protected static function dompdf_dir_candidates() {
		$c = array();
		$c[] = trailingslashit( self::lib_base_root() ) . self::LIB_DOMPDF_DIR;                          // new
		$c[] = trailingslashit( self::mu_base_dir() ) . self::LIB_DIR . '/' . self::LIB_DOMPDF_DIR;       // legacy
		$c[] = trailingslashit( self::uploads_base_dir() ) . '/' . self::LIB_DIR . '/' . self::LIB_DOMPDF_DIR; // fallback
		return array_unique( $c );
	}
	protected static function ensure_lib_dirs() {
		$base = self::lib_base_dir();
		if ( ! is_dir( $base ) ) { wp_mkdir_p( $base ); }
	}

	/* -------------------------------------------------
	 * Access Control Helpers
	 * -------------------------------------------------*/
	protected static function current_settings() {
		return wp_parse_args( get_option( self::OPT_SETTINGS, array() ), self::defaults() );
	}
	protected static function parse_allowed_admins( $csv ) {
		$emails = array(); $users = array(); $ids = array();
		$pieces = preg_split( '/[,\s]+/', (string) $csv, -1, PREG_SPLIT_NO_EMPTY );
		foreach ( $pieces as $p ) {
			$p = trim( $p ); if ( $p === '' ) { continue; }
			if ( is_numeric( $p ) )          { $ids[ intval( $p ) ] = true; continue; }
			if ( strpos( $p, '@' ) !== false ){ $emails[ strtolower( $p ) ] = true; continue; }
			$users[ strtolower( $p ) ] = true;
		}
		return array( $emails, $users, $ids );
	}
	protected static function user_can_view_settings( $s = null ) {
		if ( ! is_user_logged_in() ) { return false; }
		if ( null === $s ) { $s = self::current_settings(); }
		if ( ! current_user_can( 'manage_options' ) ) { return false; }
		if ( empty( $s['restrict_settings'] ) ) { return true; }
		$user = wp_get_current_user();
		if ( ! $user ) { return false; }
		$main = strtolower( trim( (string) $s['primary_admin_email'] ) );
		if ( $main && strtolower( $user->user_email ) === $main ) { return true; }
		list( $emails, $users, $ids ) = self::parse_allowed_admins( $s['allowed_admins'] );
		if ( isset( $emails[ strtolower( $user->user_email ) ] ) ) { return true; }
		if ( isset( $users[ strtolower( $user->user_login ) ] ) )  { return true; }
		if ( isset( $ids[ intval( $user->ID ) ] ) )                { return true; }
		return false;
	}
	protected static function user_can_view_dashboard( $s = null ) {
		if ( ! is_user_logged_in() ) { return false; }
		if ( null === $s ) { $s = self::current_settings(); }
		if ( ! current_user_can( 'manage_options' ) ) { return false; }
		if ( empty( $s['restrict_dashboard'] ) ) { return true; }
		return self::user_can_view_settings( $s );
	}
	protected static function capability_for_settings() {
		return self::user_can_view_settings() ? 'manage_options' : 'do_not_allow';
	}
	protected static function capability_for_dashboard() {
		return self::user_can_view_dashboard() ? 'manage_options' : 'do_not_allow';
	}
	protected static function enforce_settings_access_or_die() {
		if ( ! self::user_can_view_settings() ) {
			wp_die( esc_html__( 'Access denied.', 'satori-audit' ), 403 );
		}
	}

	/* -------------------------------------------------
	 * Admin Pages + CSS
	 * -------------------------------------------------*/
	public static function register_pages() {
		add_management_page(
			'SATORI Audit',
			'SATORI Audit',
			self::capability_for_dashboard(),
			'satori-audit-v3',
			array( __CLASS__, 'render_dashboard' )
		);
		add_options_page(
			'SATORI Audit Settings',
			'SATORI Audit',
			self::capability_for_settings(),
			'satori-audit-v3-settings',
			array( __CLASS__, 'render_settings' )
		);
	}
	protected static function admin_badge_css() {
		echo '<style>
		.satori-badges .badge{display:inline-block;border-radius:999px;padding:2px 10px;font-weight:600;font-size:12px;line-height:1.8;margin-right:6px}
		.badge-ok{background:#116329;color:#fff}
		.badge-warn{background:#915930;color:#fff}
		.badge-err{background:#8b1111;color:#fff}
		.badge-info{background:#1b4965;color:#fff}
		.satori-kv td{border:none;padding:2px 8px}
		.satori-help{color:#1b4965;text-decoration:underline;font-weight:600}
		.satori-mono{font-family:Menlo,Consolas,monospace}
		</style>';
	}

	/* -------------------------------------------------
	 * Dashboard
	 * -------------------------------------------------*/
	public static function render_dashboard() {
		if ( ! self::user_can_view_dashboard() ) { wp_die( esc_html__( 'Access denied.', 'satori-audit' ), 403 ); }
		self::admin_badge_css();

		$nonce    = wp_create_nonce( 'satori_audit_v3_download' );
		$report   = self::build_report();
		$summary  = self::build_summary( $report );
		$json_pre = wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$s        = self::current_settings();
		$pdf      = self::dompdf_status();

		$preview = $report['stability']['active_plugins'];
		usort( $preview, function( $a, $b ) { return strcasecmp( $a['name'], $b['name'] ); });
		$preview = array_slice( $preview, 0, 20 );

		// Provide full plugin list to JS for dynamic preview (filter, paging)

		$__satori_all_plugins_json = wp_json_encode( $report['stability']['active_plugins'], JSON_UNESCAPED_SLASHES );


		echo '<div class="wrap satori-audit-wrap">';
		echo '<h1>🧰 SATORI Audit</h1>';
		echo '<p class="description">PDF/MD/CSV exports, per-asset history, weekly update lines (only when changed), WCAG badges, and internal-only notifications with safelist.</p>';

		echo '<div class="satori-badges" style="margin:8px 0 16px">';
		echo '<span class="badge '.( $pdf['available'] ? 'badge-ok' : 'badge-warn' ).'" aria-label="PDF engine status">'.( $pdf['available'] ? 'PDF Engine: DOMPDF ready' : 'PDF: fallback to HTML/MD' ).'</span>';
		echo '<span class="badge '.( $s['enable_monthly'] ? 'badge-ok' : 'badge-info' ).'">Monthly: '.( $s['enable_monthly'] ? 'Enabled' : 'Disabled' ).'</span>';
		echo '<span class="badge '.( $s['enable_watch'] ? 'badge-ok' : 'badge-info' ).'">Daily Watch: '.( $s['enable_watch'] ? 'Enabled' : 'Disabled' ).'</span>';
		echo '<span class="badge '.( ! empty($s['enforce_safelist']) ? 'badge-ok' : 'badge-warn' ).'">Safelist: '.( ! empty($s['enforce_safelist']) ? 'Enforced' : 'Open' ).'</span>';
		echo '</div>';

		echo '<h2>Scores</h2>';
		echo '<table class="widefat striped" style="max-width:820px"><tbody>';
		echo '<tr><th>Security</th><td>' . esc_html( $report['scores']['security'] ) . '/10</td></tr>';
		echo '<tr><th>Optimization</th><td>' . esc_html( $report['scores']['optimization'] ) . '/10</td></tr>';
		echo '<tr><th>Speed</th><td>' . esc_html( $report['scores']['speed'] ) . '/10</td></tr>';
		echo '<tr><th>Stability</th><td>' . esc_html( $report['scores']['stability'] ) . '/10</td></tr>';
		echo '<tr><th>Total</th><td><strong>' . esc_html( $report['scores']['total'] ) . '/40</strong></td></tr>';
		echo '</tbody></table>';

		echo '<h2>Key Actions</h2>';
		echo '<ol>';
		foreach ( $summary['key_actions'] as $act ) { echo '<li>' . esc_html( $act ) . '</li>'; }
		echo '</ol>';

		echo '<h2>Exports</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:flex;gap:10px;flex-wrap:wrap">';
		echo '<input type="hidden" name="action" value="satori_audit_v3_download">';
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '">';
		echo '<button class="button button-primary" name="format" value="pdf">Download PDF (Settings)</button>';
		echo '<button class="button" name="format" value="pdf_p">PDF (Portrait)</button>';
		echo '<button class="button" name="format" value="pdf_l">PDF (Landscape)</button>';
		echo '<button class="button" name="format" value="html_preview" formtarget="_blank">Open HTML Preview</button>';
		echo '<button class="button" name="format" value="markdown">Download Markdown</button>';
		echo '<button class="button" name="format" value="json">Download JSON</button>';
		echo '<button class="button" name="format" value="csv_plugins">Download CSV (Plugins)</button>';
		echo '</form>';

		echo '<details style="margin-top:18px"><summary style="cursor:pointer;font-weight:600;color:#2271b1;text-decoration:underline">Preview Plugin List (top 20)</summary>';
		echo '<p class="description">Filter locally; use exports for full data.</p>';
		echo '<div style="display:flex;gap:10px;align-items:center;margin:8px 0 6px;">';

		echo '<label>Show: <select id="satori-plugin-count"><option value="20" selected>20</option><option value="50">50</option><option value="100">100</option><option value="all">All</option></select></label>';

		echo '<label style="margin-left:8px"><input id="satori-plugin-scroll" type="checkbox"> Scrollable table</label>';

		echo '</div>';

		echo '<p><input id="satori-plugin-filter" type="search" placeholder="Type to filter by plugin name…" class="regular-text" style="width:360px"></p>';
		echo '<table class="widefat striped" id="satori-plugin-preview"><thead><tr>';
		echo '<th style="width:28%">Plugin Name</th><th style="width:12%">Version</th><th style="width:50%">Description</th><th style="width:10%">Status</th>';
		echo '</tr></thead><tbody>';
		echo '</tbody></table>';
		printf('<script>(function(){ const all = %s; const table = document.getElementById("satori-plugin-preview"); const tbody = table ? table.querySelector("tbody") : null; const q = document.getElementById("satori-plugin-filter"); const selCount = document.getElementById("satori-plugin-count"); const scrollCk = document.getElementById("satori-plugin-scroll"); if(!tbody) return; function normVer(v){ v = (v||"").trim(); if(!v) return v; return (v[0]==="v"||v[0]==="V")?v:("v"+v); } function escapeHtml(s){ const t = document.createElement("textarea"); t.textContent = s||""; return t.innerHTML; } function render(){  let n = selCount && selCount.value ? selCount.value : "20";  let max = (n==="all") ? all.length : parseInt(n,10)||20;  let kw = (q && q.value ? q.value.toLowerCase() : "").trim();  let list = all.slice().sort((a,b)=> (a.name||"").localeCompare(b.name||""));  if(kw){ list = list.filter(p => (p.name||"").toLowerCase().includes(kw)); }  let rows = list.slice(0, max).map(p => {   const name = (p.name||""); const ver = normVer(p.version||""); const desc = (p.description_short||"");   return "<tr><td>"+escapeHtml(name)+"</td><td>"+(name.match(/pro|premium/i)? "PREMIUM" : "FREE/FREEMIUM")+"</td><td>"+escapeHtml(ver)+"</td><td>"+escapeHtml(desc)+"</td><td>Active</td></tr>";  }).join("");  tbody.innerHTML = rows || "<tr><td colspan=\"5\"><em>No matches</em></td></tr>";  if(scrollCk){ table.parentElement.style.maxHeight = scrollCk.checked ? "420px" : ""; table.parentElement.style.overflow = scrollCk.checked ? "auto" : ""; } } if(q) q.addEventListener("input", render); if(selCount) selCount.addEventListener("change", render); if(scrollCk) scrollCk.addEventListener("change", render); render(); })();</script>', $__satori_all_plugins_json );

		echo '</details>';

		echo '<h2>Audit JSON</h2>';
		echo '<textarea style="width:100%;height:280px;font-family:Menlo,Consolas,monospace;">' . esc_textarea( $json_pre ) . '</textarea>';
		echo '</div>';
	}

	/* -------------------------------------------------
	 * Settings (adds PDF Output controls)
	 * -------------------------------------------------*/
	public static function render_settings() {
		if ( ! self::user_can_view_settings() ) { wp_die( esc_html__( 'Access denied.', 'satori-audit' ), 403 ); }
		self::admin_badge_css();

		$s          = self::current_settings();
		$nonce      = wp_create_nonce( 'satori_audit_v3_save' );
		$test_nonce = wp_create_nonce( 'satori_audit_v3_test' );
		$inst_nonce = wp_create_nonce( 'satori_audit_v3_install_dompdf' );
		$probe_nonce= wp_create_nonce( 'satori_audit_v3_probe_dompdf' );
		$pdf        = self::dompdf_status();

		if ( isset( $_GET['satori_test'] ) ) {
			$me   = isset($_GET['me']) ? sanitize_text_field( wp_unslash( $_GET['me'] ) ) : '';
			$list = isset($_GET['recips']) ? base64_decode( sanitize_text_field( wp_unslash( $_GET['recips'] ) ) ) : '';
			echo '<div class="notice notice-success"><p><strong>Test email sent to:</strong> '.esc_html($me).'</p><p><strong>Would send to (after safelist):</strong> '.( $list ? esc_html($list) : '<em>none (blocked)</em>' ).'</p></div>';
		}
		if ( isset( $_GET['satori_probe'] ) ) {
			$ok = intval( $_GET['ok'] );
			$msg= isset( $_GET['msg'] ) ? sanitize_text_field( wp_unslash( $_GET['msg'] ) ) : '';
			echo '<div class="notice '.( $ok ? 'notice-success' : 'notice-error' ).'"><p><strong>DOMPDF probe: '
				.( $ok ? 'Success' : 'Failed' ).'</strong>'.( $msg ? ' – '.esc_html($msg) : '' ).'</p></div>';
		}
		if ( isset($_GET['pdf_install']) ) {
			$ok  = intval($_GET['ok']);
			$msg = isset($_GET['msg']) ? sanitize_text_field( wp_unslash($_GET['msg']) ) : '';
			echo '<div class="notice ' . ( $ok ? 'notice-success' : 'notice-error' ) . '"><p><strong>DOMPDF upload '
				. ( $ok ? 'succeeded' : 'failed' ) . '.</strong>' . ( $msg ? ' – ' . esc_html($msg) : '' ) . '</p></div>';
		}

		echo '<div class="wrap">';
		echo '<h1 id="top">⚙️ SATORI Audit – Settings</h1>';
		echo '<p class="description">This audit is an internal process unless you explicitly add recipients below. Badges indicate current status and link to the relevant settings.</p>';

		/* ========= MAIN SETTINGS FORM ========= */
		echo '<form id="satori-settings-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:960px">';
		echo '<input type="hidden" name="action" value="satori_audit_v3_save_settings">';
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '">';

		// Service Details
		echo '<h2>Service Details</h2>';
		echo '<table class="form-table satori-kv">';
		echo '<tr><th>Client</th><td><input name="client" value="' . esc_attr( $s['client'] ) . '" class="regular-text"></td></tr>';
		echo '<tr><th>Site Name</th><td><input name="site_name" value="' . esc_attr( $s['site_name'] ) . '" class="regular-text"></td></tr>';
		echo '<tr><th>Site URL</th><td><input name="site_url" value="' . esc_attr( $s['site_url'] ) . '" class="regular-text"></td></tr>';
		echo '<tr><th>Managed by</th><td><input name="managed_by" value="' . esc_attr( $s['managed_by'] ) . '" class="regular-text"></td></tr>';
		echo '<tr><th>Start Date</th><td><input name="start_date" value="' . esc_attr( $s['start_date'] ) . '" class="regular-text" placeholder="e.g. March 2023"></td></tr>';
		echo '<tr><th>Notes</th><td><textarea name="service_notes" class="large-text" rows="3">' . esc_textarea( $s['service_notes'] ) . '</textarea></td></tr>';
		echo '<tr><th>PDF Header Logo URL</th><td><input name="pdf_logo_url" value="' . esc_attr( $s['pdf_logo_url'] ) . '" class="regular-text" placeholder="https://.../logo.png"></td></tr>';
		echo '</table>';

		// Notifications
		echo '<h2>Notifications</h2>';
		echo '<table class="form-table satori-kv">';
		echo '<tr><th>From Email</th><td><input name="contact_email" value="' . esc_attr( $s['contact_email'] ) . '" class="regular-text"></td></tr>';
		echo '<tr><th>Send Reports To</th><td><input name="notify_emails" value="' . esc_attr( $s['notify_emails'] ) . '" class="regular-text" placeholder="alice@example.com, ops@example.org"></td></tr>';
		echo '<tr><th>Webhook (optional)</th><td><input name="notify_webhook" value="' . esc_attr( $s['notify_webhook'] ) . '" class="regular-text" placeholder="Slack/Teams webhook URL"></td></tr>';
		echo '<tr><th>Suppress WP auto-update emails</th><td><label><input type="checkbox" name="suppress_auto_emails" ' . checked( $s['suppress_auto_emails'], true, false ) . '> Don’t email site admins about core/plugin/theme auto-updates</label></td></tr>';
		echo '</table>';

		// Safelist
		echo '<h2 id="safelist">Recipient Safelist</h2>';
		echo '<div class="satori-badges" style="margin:6px 0 10px">';
		echo '<span class="badge '.( ! empty($s['enforce_safelist']) ? 'badge-ok' : 'badge-warn' ).'">Safelist: '.( ! empty($s['enforce_safelist']) ? 'Enforced' : 'Open' ).'</span>';
		echo '</div>';
		echo '<p class="description">When enforced, emails are sent <strong>only</strong> to addresses matching entries here. Use <code>@example.com</code> or <code>user@example.com</code>. Multiple entries may be comma/space separated.</p>';
		echo '<table class="form-table satori-kv">';
		echo '<tr><th>Enforce Safelist</th><td><label><input type="checkbox" name="enforce_safelist" ' . checked( $s['enforce_safelist'], true, false ) . '> Only send to recipients that match the safelist</label></td></tr>';
		echo '<tr><th>Safelist Entries</th><td><input name="safelist_entries" value="' . esc_attr( $s['safelist_entries'] ) . '" class="regular-text" placeholder="@yourdomain.com, ops@partner.org"></td></tr>';
		echo '</table>';

		// Access Control
		echo '<h2 id="access">Access Control</h2>';
		echo '<p class="description">Restrict who can view SATORI Audit pages. Non-allowed admins will not see these menus.</p>';
		echo '<table class="form-table satori-kv">';
		echo '<tr><th>Restrict Settings page</th><td><label><input type="checkbox" name="restrict_settings" ' . checked( $s['restrict_settings'], true, false ) . '> Only visible to the main admin and/or selected admins</label></td></tr>';
		echo '<tr><th>Restrict Dashboard page</th><td><label><input type="checkbox" name="restrict_dashboard" ' . checked( $s['restrict_dashboard'], true, false ) . '> Also restrict Tools → SATORI Audit</label></td></tr>';
		echo '<tr><th>Main Administrator Email</th><td><input name="primary_admin_email" value="' . esc_attr( $s['primary_admin_email'] ) . '" class="regular-text" placeholder="owner@example.com"><p class="description">This email is always allowed.</p></td></tr>';
		echo '<tr><th>Allowed Admins (CSV)</th><td><input name="allowed_admins" value="' . esc_attr( $s['allowed_admins'] ) . '" class="regular-text" placeholder="jane@example.com, bob, 12"><p class="description">Emails, usernames, or numeric user IDs.</p></td></tr>';
		echo '</table>';

		// Automation
		echo '<h2>Automation</h2>';
		echo '<table class="form-table satori-kv">';
		echo '<tr><th>Monthly PDF Email</th><td><label><input type="checkbox" name="enable_monthly" ' . checked( $s['enable_monthly'], true, false ) . '> Enable</label></td></tr>';
		echo '<tr><th>Daily Watch & Alerts</th><td><label><input type="checkbox" name="enable_watch" ' . checked( $s['enable_watch'], true, false ) . '> Enable</label></td></tr>';
		echo '<tr><th>Force Auto-Updates (plugins)</th><td><label><input type="checkbox" name="force_auto_updates" ' . checked( $s['force_auto_updates'], true, false ) . '> Enable (use with care)</label></td></tr>';
		echo '<tr><th>History Retention</th><td><input name="keep_months" type="number" min="3" max="24" value="' . esc_attr( $s['keep_months'] ) . '"> months</td></tr>';
		echo '</table>';

		// Display + PDF Output
		echo '<h2>Display & PDF Output</h2>';
		echo '<table class="form-table satori-kv">';
		echo '<tr><th>Weekly lines for Core</th><td><label><input type="checkbox" name="weekly_lines_core" ' . checked( $s['weekly_lines_core'], true, false ) . '> Show in Versions & Update Dates</label></td></tr>';
		echo '<tr><th>Weekly lines for Themes</th><td><label><input type="checkbox" name="weekly_lines_themes" ' . checked( $s['weekly_lines_themes'], true, false ) . '> Show for Child/Parent themes</label></td></tr>';
		echo '<tr><th>Backfill weekly lines on first run</th><td><label><input type="checkbox" name="backfill_on_first_run" ' . checked( $s['backfill_on_first_run'], true, false ) . '> Seed last 4 Mondays with current versions</label></td></tr>';
		echo '<tr><th>PDF Page Size</th><td><select name="pdf_page_size">';
		foreach ( array('A4','Letter','Legal') as $size ) {
			echo '<option value="'.$size.'"'.selected($s['pdf_page_size'],$size,false).'>'.$size.'</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th>PDF Orientation</th><td><select name="pdf_orientation">';
		foreach ( array('portrait','landscape') as $o ) {
			echo '<option value="'.$o.'"'.selected($s['pdf_orientation'],$o,false).'>'.ucfirst($o).'</option>';
		}
		echo '</select> <span class="description">Use Landscape for wide plugin tables.</span></td></tr>';
		echo '</table>';

		echo '<p><button class="button button-primary">Save Settings</button></p>';
		echo '</form>'; // END MAIN

		/* ========= PDF ENGINE & DIAGNOSTICS ========= */
		echo '<h2 id="pdf">PDF Engine</h2>';
		$gd_ok      = extension_loaded('gd');
		$mb_ok      = extension_loaded('mbstring');
		$intl_ok    = extension_loaded('intl');
		$imagick_ok = extension_loaded('imagick');
		echo '<div class="satori-badges" style="margin:6px 0 10px">';
		echo '<span class="badge '.( $pdf['available'] ? 'badge-ok' : 'badge-warn' ).'">'.( $pdf['available'] ? 'DOMPDF ready' : 'DOMPDF not found (using HTML/MD)' ).'</span>';
		echo '<span class="badge '.( $gd_ok ? 'badge-ok' : 'badge-err' ).'">GD '.( $gd_ok ? 'enabled' : 'missing' ).'</span>';
		echo '<span class="badge '.( $mb_ok ? 'badge-ok' : 'badge-err' ).'">mbstring '.( $mb_ok ? 'enabled' : 'missing' ).'</span>';
		echo '<span class="badge '.( $intl_ok ? 'badge-ok' : 'badge-info' ).'">intl '.( $intl_ok ? 'enabled' : 'optional' ).'</span>';
		echo '<span class="badge '.( $imagick_ok ? 'badge-ok' : 'badge-info' ).'">Imagick '.( $imagick_ok ? 'enabled' : 'optional' ).'</span>';
		echo '</div>';

		echo '<p class="description">Upload the official <em>packaged</em> DOMPDF ZIP (it contains <code>autoload.inc.php</code>). <strong>Do not use “Source code” zips</strong> from GitHub — those will not work.</p>';
		$max_upload = function_exists('size_format') ? size_format( wp_max_upload_size() ) : ini_get('upload_max_filesize');
		$php_ul = ini_get('upload_max_filesize'); $php_post = ini_get('post_max_size');
		echo '<form id="satori-dompdf-form" method="post" enctype="multipart/form-data" action="' . esc_url( admin_url('admin-post.php') ) . '" style="margin:8px 0 18px">';
		echo '<input type="hidden" name="action" value="satori_audit_v3_install_dompdf">';
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( $inst_nonce ) . '">';
		echo '<input type="file" name="dompdf_zip" accept=".zip,application/zip" required />';
		echo ' <button type="submit" class="button button-primary">Upload DOMPDF ZIP</button> ';
		echo '<a class="button button-link satori-help" target="_blank" rel="noopener" href="https://github.com/dompdf/dompdf/releases/latest">Get the official ZIP</a>';
		echo '</form>';
		echo '<p class="description">Max upload size (WP): <strong>' . esc_html($max_upload) . '</strong> • PHP <code>upload_max_filesize</code>: ' . esc_html($php_ul) . ' • <code>post_max_size</code>: ' . esc_html($php_post) . '</p>';

		// Diagnostics
		$cands = self::dompdf_dir_candidates();
		echo '<h3>Diagnostics</h3>';
		echo '<table class="widefat striped"><thead><tr><th>Checked Path</th><th>autoload.inc.php</th></tr></thead><tbody>';
		foreach ( $cands as $path ) {
			$auto = trailingslashit( $path ) . 'autoload.inc.php';
			$ok   = is_readable( $auto );
			echo '<tr><td class="satori-mono">'.esc_html( $path ).'</td><td>'.( $ok ? '✔︎ found' : '—' ).'</td></tr>';
		}
		echo '</tbody></table>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:10px">';
		echo '<input type="hidden" name="action" value="satori_audit_v3_probe_dompdf">';
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( $probe_nonce ) . '">';
		echo '<button class="button">Probe DOMPDF</button>';
		echo '</form>';

		echo '</div>';
	}

	/* -------------------------------------------------
	 * Dismissible PDF notice
	 * -------------------------------------------------*/
	public static function maybe_notice_dompdf() {
		if ( ! self::user_can_view_settings() ) { return; }
		$pdf = self::dompdf_status();
		if ( ! empty( $pdf['available'] ) ) { return; }
		$uid = get_current_user_id();
		if ( $uid && get_user_meta( $uid, 'satori_audit_v3_hide_pdf_notice', true ) ) { return; }
		$nonce = wp_create_nonce( 'satori_audit_v3_dismiss_pdf_notice' );
		$link  = esc_url( admin_url( 'options-general.php?page=satori-audit-v3-settings#pdf' ) );
		echo '<div class="notice notice-warning is-dismissible satori-dompdf-notice">'
		   . '<p><strong>SATORI Audit:</strong> Native PDF export isn’t enabled yet. '
		   . 'Upload the official DOMPDF ZIP in <a href="'.$link.'">Settings → PDF Engine</a>.</p>'
		   . '<p class="description">Tip: Enable <code>mbstring</code> and <code>gd</code> in your PHP extensions.</p>'
		   . '</div>';
		echo "<script>
		(function(){
			var n=document.querySelector('.satori-dompdf-notice');if(!n)return;
			n.addEventListener('click',function(e){
				if(!e.target.classList.contains('notice-dismiss'))return;
				fetch(ajaxurl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=satori_audit_v3_dismiss_pdf_notice&_wpnonce={$nonce}'});
			});
		})();</script>";
	}
	public static function ajax_dismiss_pdf_notice() {
		check_ajax_referer( 'satori_audit_v3_dismiss_pdf_notice' );
		$uid = get_current_user_id();
		if ( $uid ) { update_user_meta( $uid, 'satori_audit_v3_hide_pdf_notice', 1 ); }
		wp_send_json_success();
	}

	/* -------------------------------------------------
	 * Settings Save (hardened)
	 * -------------------------------------------------*/
	public static function save_settings() {
		self::enforce_settings_access_or_die();
		check_admin_referer( 'satori_audit_v3_save' );

		$in = wp_unslash( $_POST );
		$s  = self::current_settings();

		$bools = array(
			'enable_monthly','enable_watch','force_auto_updates','suppress_auto_emails',
			'weekly_lines_core','weekly_lines_themes','backfill_on_first_run',
			'enforce_safelist','restrict_settings','restrict_dashboard'
		);

		foreach ( array_keys( self::defaults() ) as $k ) {
			if ( array_key_exists( $k, $in ) ) {
				if ( in_array( $k, $bools, true ) ) {
					$s[ $k ] = (bool) $in[ $k ];
				} elseif ( 'keep_months' === $k ) {
					$s[ $k ] = max( 3, min( 24, (int) $in[ $k ] ) );
				} elseif ( 'safelist_entries' === $k ) {
					$raw = is_string( $in[$k] ) ? $in[$k] : '';
					$raw = str_replace( array("\r\n","\r"), "\n", $raw );
					$raw = preg_replace( '/\s+/', ' ', $raw );
					$s[ $k ] = trim( sanitize_textarea_field( $raw ) );
				} elseif ( in_array( $k, array( 'pdf_page_size','pdf_orientation' ), true ) ) {
					$s[ $k ] = sanitize_text_field( $in[$k] );
				} else {
					$s[ $k ] = is_string( $in[$k] ) ? sanitize_text_field( $in[$k] ) : $in[$k];
				}
			} else {
				if ( in_array( $k, $bools, true ) ) { $s[ $k ] = false; }
			}
		}

		update_option( self::OPT_SETTINGS, $s, false );
		wp_safe_redirect( admin_url( 'options-general.php?page=satori-audit-v3-settings&updated=1' ) );
		exit;
	}

	/* -------------------------------------------------
	 * DOMPDF installer / probe (unchanged from 3.7.2)
	 * -------------------------------------------------*/
	public static function handle_install_dompdf() {
		self::enforce_settings_access_or_die();
		check_admin_referer( 'satori_audit_v3_install_dompdf' );
		if ( empty($_FILES['dompdf_zip']) || empty($_FILES['dompdf_zip']['name']) ) {
			wp_safe_redirect( admin_url('options-general.php?page=satori-audit-v3-settings&pdf_install=1&ok=0&msg=' . rawurlencode('No file selected')) . '#pdf' );
			exit;
		}
		$err = intval($_FILES['dompdf_zip']['error']);
		if ( $err ) {
			$map = array(
				1 => 'The uploaded file exceeds php.ini upload_max_filesize',
				2 => 'The uploaded file exceeds the MAX_FILE_SIZE directive',
				3 => 'The uploaded file was only partially uploaded',
				4 => 'No file was uploaded',
				6 => 'Missing a temporary folder',
				7 => 'Failed to write file to disk',
				8 => 'A PHP extension stopped the file upload'
			);
			$msg = isset($map[$err]) ? $map[$err] : 'Upload error';
			wp_safe_redirect( admin_url('options-general.php?page=satori-audit-v3-settings&pdf_install=1&ok=0&msg=' . rawurlencode($msg)) . '#pdf' );
			exit;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$overrides = array( 'test_form' => false, 'mimes' => array( 'zip' => 'application/zip' ) );
		$file = wp_handle_upload( $_FILES['dompdf_zip'], $overrides );
		if ( empty($file['file']) || !empty($file['error']) ) {
			$msg = !empty($file['error']) ? $file['error'] : 'Upload failed';
			wp_safe_redirect( admin_url('options-general.php?page=satori-audit-v3-settings&pdf_install=1&ok=0&msg=' . rawurlencode($msg)) . '#pdf' );
			exit;
		}

		self::ensure_lib_dirs();
		$dest = trailingslashit( self::lib_base_dir() ) . self::LIB_DOMPDF_DIR;

		// Clean any old copy
		if ( is_dir( $dest ) ) { self::rrmdir( $dest ); }

		// Unzip into temp
		$tmp_dir = trailingslashit( self::lib_base_dir() ) . 'tmp_' . wp_generate_password( 8, false );
		wp_mkdir_p( $tmp_dir );
		$result = unzip_file( $file['file'], $tmp_dir );
		@unlink( $file['file'] );
		if ( is_wp_error( $result ) ) {
			self::rrmdir( $tmp_dir );
			wp_safe_redirect( admin_url( 'options-general.php?page=satori-audit-v3-settings#pdf' ) );
			exit;
		}

		$autoload = self::find_file_recursive( $tmp_dir, 'autoload.inc.php' );
		if ( ! $autoload ) {
			self::rrmdir( $tmp_dir );
			wp_safe_redirect( admin_url( 'options-general.php?page=satori-audit-v3-settings&pdf_install=1&ok=0&msg=' . rawurlencode('ZIP did not contain autoload.inc.php (use packaged release, not source code)') . '#pdf' ) );
			exit;
		}
		$root = dirname( $autoload );
		if ( basename( $root ) !== 'dompdf' && is_dir( dirname( $root ) . '/dompdf' ) && is_readable( dirname( $root ) . '/dompdf/autoload.inc.php' ) ) {
			$root = dirname( $root ) . '/dompdf';
		}

		wp_mkdir_p( $dest );
		self::copy_dir_recursive( $root, $dest );
		self::rrmdir( $tmp_dir );

		self::ensure_dompdf_loaded();

		wp_safe_redirect( admin_url( 'options-general.php?page=satori-audit-v3-settings&pdf_install=1&ok=1&msg=' . rawurlencode('Installed to ' . $dest) ) . '#pdf' );
		exit;
	}
	public static function handle_probe_dompdf() {
		self::enforce_settings_access_or_die();
		check_admin_referer( 'satori_audit_v3_probe_dompdf' );
		$msg = ''; $ok = 0;

		if ( ! self::ensure_dompdf_loaded() ) {
			$msg = 'autoload.inc.php not found or Dompdf class unavailable';
		} else {
			try {
				$options = new \Dompdf\Options();
				$options->set( 'isRemoteEnabled', false );
				$dompdf = new \Dompdf\Dompdf( $options );
				$dompdf->loadHtml( '<html><meta charset="utf-8"><body><p>DOMPDF OK</p></body></html>' );
				$dompdf->render();
				$pdf = $dompdf->output();
				if ( $pdf && strlen( $pdf ) > 500 ) { $ok = 1; $msg = 'rendered sample PDF'; }
				else { $msg = 'render produced empty/too small output'; }
			} catch ( \Throwable $e ) {
				$msg = 'exception: ' . $e->getMessage();
			}
		}
		$q = array( 'page'=>'satori-audit-v3-settings','satori_probe'=>1,'ok'=>$ok,'msg'=>substr( $msg, 0, 180 ) );
		wp_safe_redirect( admin_url( 'options-general.php?' . http_build_query( $q ) . '#pdf' ) );
		exit;
	}
	protected static function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) { return; }
		$items = scandir( $dir );
		foreach ( $items as $it ) {
			if ( $it === '.' || $it === '..' ) { continue; }
			$path = $dir . '/' . $it;
			if ( is_dir( $path ) ) { self::rrmdir( $path ); } else { @unlink( $path ); }
		}
		@rmdir( $dir );
	}
	protected static function copy_dir_recursive( $src, $dst ) {
		$src = rtrim( $src, '/' ); $dst = rtrim( $dst, '/' );
		if ( ! is_dir( $src ) ) { return; }
		if ( ! is_dir( $dst ) ) { wp_mkdir_p( $dst ); }
		$dir = opendir( $src );
		while ( false !== ( $file = readdir( $dir ) ) ) {
			if ( $file === '.' || $file === '..' ) { continue; }
			$s = $src . '/' . $file; $d = $dst . '/' . $file;
			if ( is_dir( $s ) ) { self::copy_dir_recursive( $s, $d ); }
			else { @copy( $s, $d ); }
		}
		closedir( $dir );
	}
	protected static function find_file_recursive( $dir, $needle ) {
		$rii = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $rii as $file ) {
			if ( $file->getFilename() === $needle ) { return $file->getPathname(); }
		}
		return false;
	}

	/* -------------------------------------------------
	 * Cron & Email (unchanged)
	 * -------------------------------------------------*/
	public static function ensure_cron() {
		$settings = self::current_settings();
		add_filter( 'cron_schedules', function( $s ) {
			$s['satori_monthly'] = array( 'interval' => 30 * DAY_IN_SECONDS, 'display' => 'Satori Monthly' );
			return $s;
		});
		if ( $settings['enable_monthly'] && ! wp_next_scheduled( self::CRON_HOOK_M ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'satori_monthly', self::CRON_HOOK_M );
		}
		if ( $settings['enable_watch'] && ! wp_next_scheduled( self::CRON_HOOK_D ) ) {
			wp_schedule_event( time() + 2*HOUR_IN_SECONDS, 'daily', self::CRON_HOOK_D );
		}
	}
	public static function run_monthly() {
		$report = self::build_report( true );
		self::email_report( $report, 'monthly' );
		self::trim_history();
	}
	public static function run_daily_watch() {
		$report = self::build_report( true );
		$has_high = false;
		if ( ! empty( $report['bottlenecks'] ) ) {
			foreach ( $report['bottlenecks'] as $b ) {
				if ( isset( $b['severity'] ) && 'HIGH' === $b['severity'] ) { $has_high = true; break; }
			}
		}
		if ( $has_high ) { self::email_report( $report, 'alert' ); }
	}
	public static function after_update( $upgrader, $hook_extra ) {
		self::update_asset_log( $hook_extra );
		if ( empty( $hook_extra['action'] ) || 'update' !== $hook_extra['action'] ) { return; }
		$events = get_option( self::OPT_EVENTS, array() );
		$events[] = array( 'when' => current_time( 'mysql' ), 'what' => $hook_extra );
		update_option( self::OPT_EVENTS, array_slice( $events, -50 ) );
		$report = self::build_report( true );
		$send = false;
		if ( ! empty( $report['bottlenecks'] ) ) {
			foreach ( $report['bottlenecks'] as $b ) {
				if ( in_array( $b['type'], array( 'dup_cache','file_manager' ), true ) || 'HIGH' === $b['severity'] ) { $send = true; break; }
			}
		}
		if ( $send ) { self::email_report( $report, 'post-update' ); }
	}
	public static function maybe_force_auto_updates( $should, $item ) {
		$s = self::current_settings();
		return $s['force_auto_updates'] ? true : $should;
	}
	public static function suppress_plugin_email( $send, $plugin, $result ) {
		$s = self::current_settings();
		return $s['suppress_auto_emails'] ? false : $send;
	}
	public static function suppress_theme_email( $send, $theme, $result ) {
		$s = self::current_settings();
		return $s['suppress_auto_emails'] ? false : $send;
	}
	public static function suppress_core_email( $send, $type, $core_update, $result ) {
		$s = self::current_settings();
		return $s['suppress_auto_emails'] ? false : $send;
	}

	/* -------------------------------------------------
	 * Build Report + helpers
	 * -------------------------------------------------*/
	public static function build_report( $persist = false ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$s   = self::current_settings();
		$server_soft  = isset( $_SERVER['SERVER_SOFTWARE'] ) ? (string) $_SERVER['SERVER_SOFTWARE'] : '';
		$is_litespeed = stripos( $server_soft, 'litespeed' ) !== false;
		$theme        = wp_get_theme();
		$parent       = $theme && $theme->parent() ? $theme->parent() : null;
		$permalinks   = get_option( 'permalink_structure' );

		$active_plugins = (array) get_option( 'active_plugins', array() );
		$all_plugins    = function_exists( 'get_plugins' ) ? get_plugins() : array();
		$active_list    = array();
		foreach ( $active_plugins as $path ) {
			$label  = isset( $all_plugins[ $path ]['Name'] ) ? $all_plugins[ $path ]['Name'] : $path;
			$ver    = isset( $all_plugins[ $path ]['Version'] ) ? $all_plugins[ $path ]['Version'] : '';
			$desc   = isset( $all_plugins[ $path ]['Description'] ) ? $all_plugins[ $path ]['Description'] : '';
			$active_list[] = array(
				'slug' => dirname( $path ),
				'path' => $path,
				'name' => $label,
				'version' => $ver,
				'description' => $desc,
				'description_short' => self::short_desc( $desc ),
			);
		}

		$htaccess_lscache = false;
		$ht = ABSPATH . '.htaccess';
		if ( is_readable( $ht ) ) {
			$raw = @file_get_contents( $ht );
			if ( $raw && preg_match( '/#\s*BEGIN\s*LSCACHE(.+?)#\s*END\s*LSCACHE/s', (string) $raw ) ) { $htaccess_lscache = true; }
		}

		$lsc_conf = get_option( 'litespeed.conf', array() );
		$lsc = array(
			'active'         => is_plugin_active( 'litespeed-cache/litespeed-cache.php' ) || class_exists( 'LiteSpeed\\Core' ),
			'is_litespeed'   => $is_litespeed,
			'htaccess_block' => $htaccess_lscache,
			'css_min'        => (bool) ( isset( $lsc_conf['css_min'] ) ? $lsc_conf['css_min'] : false ),
			'css_combine'    => (bool) ( isset( $lsc_conf['css_combine'] ) ? $lsc_conf['css_combine'] : false ),
			'css_ucss'       => (bool) ( isset( $lsc_conf['css_ucss'] ) ? $lsc_conf['css_ucss'] : false ),
			'js_min'         => (bool) ( isset( $lsc_conf['js_min'] ) ? $lsc_conf['js_min'] : false ),
			'js_defer'       => (bool) ( isset( $lsc_conf['js_defer'] ) ? $lsc_conf['js_defer'] : false ),
			'js_delay'       => (bool) ( isset( $lsc_conf['js_defer_js'] ) ? $lsc_conf['js_defer_js'] : false ),
			'object_cache'   => (bool) ( isset( $lsc_conf['object'] ) ? $lsc_conf['object'] : false ),
			'crawler'        => (bool) ( isset( $lsc_conf['crawler'] ) ? $lsc_conf['crawler'] : false ),
			'img_optm'       => (bool) ( isset( $lsc_conf['img_optm'] ) ? $lsc_conf['img_optm'] : false ),
			'img_webp'       => (bool) ( isset( $lsc_conf['img_webp'] ) ? $lsc_conf['img_webp'] : false ),
		);

		$headers   = array();
		$cache_hdr = null;
		$cdn_hint  = null;
		$res = wp_remote_get( home_url( '/' ), array( 'timeout' => 8, 'redirection' => 2 ) );
		if ( ! is_wp_error( $res ) ) {
			$headers   = array_change_key_case( (array) wp_remote_retrieve_headers( $res ), CASE_LOWER );
			$cache_hdr = isset( $headers['x-litespeed-cache'] ) ? $headers['x-litespeed-cache'] : null;
			if ( isset( $headers['cf-cache-status'] ) ) { $cdn_hint = 'cloudflare'; }
			if ( isset( $headers['x-qc-pop'] ) || isset( $headers['x-qc-cache'] ) ) { $cdn_hint = 'quic.cloud'; }
		}

		$users_count = function_exists( 'count_users' ) ? count_users() : array( 'avail_roles' => array() );
		$admins      = (int) ( isset( $users_count['avail_roles']['administrator'] ) ? $users_count['avail_roles']['administrator'] : 0 );
		$xmlrpc_on   = (bool) apply_filters( 'xmlrpc_enabled', true );
		$file_edit   = defined( 'DISALLOW_FILE_EDIT' ) ? (bool) DISALLOW_FILE_EDIT : false;

		$updates = array( 'plugins' => 0, 'themes' => 0, 'core' => null );
		$up_plugins = get_site_transient( 'update_plugins' );
		if ( ! empty( $up_plugins->response ) ) { $updates['plugins'] = count( $up_plugins->response ); }
		$up_themes = get_site_transient( 'update_themes' );
		if ( ! empty( $up_themes->response ) ) { $updates['themes'] = count( $up_themes->response ); }

		$bottlenecks = self::detect_bottlenecks( $active_list, $lsc, $headers );
		if ( empty( $permalinks ) ) {
			$bottlenecks[] = array( 'type'=>'permalinks_plain', 'severity'=>'HIGH', 'msg'=>'Permalinks are “Plain”. Use /%postname%/.' );
		} elseif ( strpos( $permalinks, '%postname%' ) === false ) {
			$bottlenecks[] = array( 'type'=>'permalinks_nonpostname', 'severity'=>'LOW', 'msg'=>'Consider /%postname%/ for SEO-friendly URLs.' );
		}

		$suggestions = self::suggestions( $lsc, $bottlenecks, $cdn_hint );
		$scores = self::scores( array(
			'admins' => $admins,
			'xmlrpc_on' => $xmlrpc_on,
			'file_edit' => $file_edit,
			'updates' => $updates,
			'cache_hdr' => $cache_hdr,
			'lsc_flags' => $lsc,
			'bottlenecks' => $bottlenecks,
		));

		$core_version   = get_bloginfo( 'version' );
		$theme_obj      = wp_get_theme();
		$child_slug     = $theme_obj ? $theme_obj->get_stylesheet() : '';
		$child_version  = $theme_obj ? $theme_obj->get( 'Version' ) : '';
		$child_name     = $theme_obj ? $theme_obj->get( 'Name' ) : 'n/a';
		$parent_obj     = $theme_obj && $theme_obj->parent() ? $theme_obj->parent() : null;
		$parent_slug    = $parent_obj ? $parent_obj->get_template() : '';
		$parent_version = $parent_obj ? $parent_obj->get( 'Version' ) : '';
		$parent_name    = $parent_obj ? $parent_obj->get( 'Name' ) : 'n/a';

		$asset_log = get_option( self::OPT_ASSET_LOG, array() );
		$versions = array(
			'core'   => array( 'version' => $core_version,   'updated_on' => self::asset_last_updated_human( 'core', 'wordpress', $asset_log ) ),
			'child'  => array( 'name' => $child_name,  'slug' => $child_slug,  'version' => $child_version,  'updated_on' => self::asset_last_updated_human( 'theme', $child_slug, $asset_log ) ),
			'parent' => array( 'name' => $parent_name, 'slug' => $parent_slug, 'version' => $parent_version, 'updated_on' => self::asset_last_updated_human( 'theme', $parent_slug, $asset_log ) ),
		);

		$vuln_summary = apply_filters( 'satori_audit_v3_vuln_summary', null );
		if ( null === $vuln_summary ) { $vuln_summary = apply_filters( 'satori_audit_v2_vuln_summary', 'Not integrated' ); }
		$vuln_last = apply_filters( 'satori_audit_v3_vuln_last_run', null );
		if ( null === $vuln_last ) { $vuln_last = apply_filters( 'satori_audit_v2_vuln_last_run', null ); }

		$report = array(
			'meta' => array(
				'generated_at'   => gmdate( 'c' ),
				'plugin_version' => '3.7.3',
			),
			'service_details' => array(
				'client'      => $s['client'],
				'site_name'   => $s['site_name'],
				'site_url'    => $s['site_url'],
				'managed_by'  => $s['managed_by'],
				'start_date'  => $s['start_date'],
				'service_date'=> date_i18n( 'F Y' ),
				'notes'       => $s['service_notes'],
			),
			'overview' => array(
				'wordpress'  => $core_version,
				'php'        => PHP_VERSION,
				'server'     => $server_soft,
				'https'      => is_ssl(),
				'permalinks' => $permalinks,
				'theme'      => array( 'name' => $child_name, 'version' => $child_version ),
			),
			'versions' => $versions,
			'security' => array(
				'admins'              => $admins,
				'disallow_file_edit'  => $file_edit,
				'xmlrpc_enabled'      => $xmlrpc_on,
				'htaccess_lscache'    => $htaccess_lscache,
				'vuln_scan_summary'   => $vuln_summary,
				'vuln_scan_last_run'  => $vuln_last,
			),
			'optimization' => array(
				'litespeed'     => $lsc,
				'cdn'           => $cdn_hint,
				'object_cache'  => function_exists('wp_using_ext_object_cache') ? wp_using_ext_object_cache() : false,
			),
			'speed' => array(
				'x_litespeed_cache' => $cache_hdr,
				'http3_hint'        => ( isset( $headers['alt-svc'] ) && stripos( $headers['alt-svc'], 'h3' ) !== false ),
			),
			'stability' => array(
				'updates'        => $updates,
				'active_plugins' => $active_list,
			),
			'bottlenecks'    => $bottlenecks,
			'suggestions'    => self::suggestions( $lsc, $bottlenecks, $cdn_hint ),
			'scores'         => $scores,
			'asset_log'      => $asset_log
		);

		$month   = date_i18n( 'Y-m' );
		$history = get_option( self::OPT_HISTORY, array() );
		$prev    = self::latest_history_before( $history, $month );
		$report['plugin_diffs'] = self::plugin_diffs( isset( $prev['plugins'] ) ? $prev['plugins'] : array(), $active_list );

		if ( $persist ) {
			$history[ $month ] = array(
				'created' => current_time( 'mysql' ),
				'scores'  => $scores,
				'plugins' => self::plugins_map( $active_list ),
				'overview'=> $report['overview'],
			);
			update_option( self::OPT_HISTORY, $history );
		}

		if ( empty( $asset_log ) ) {
			$legacy = get_option( self::OPT_PLUGIN_LOG, array() );
			if ( ! empty( $legacy ) ) { $report['legacy_plugin_log'] = $legacy; }
		}

		return $report;
	}

	protected static function detect_bottlenecks( $active_list, $lsc, $headers ) {
		$slugs = array(); $names = array();
		foreach ( $active_list as $p ) { $slugs[] = strtolower( $p['slug'] ); $names[] = strtolower( $p['name'] ); }
		$has = function( $needle ) use ( $slugs, $names ) {
			foreach ( $slugs as $s ) { if ( false !== stripos( $s, $needle ) ) { return true; } }
			foreach ( $names as $n ) { if ( false !== stripos( $n, $needle ) ) { return true; } }
			return false;
		};

		$issues = array();
		$seo    = array( 'yoast','wordpress-seo','aioseo','rank-math','seopress' );
		$caches = array( 'litespeed','wp-rocket','w3-total-cache','wp-super-cache','wp-optimize','hummingbird' );
		$imgs   = array( 'imagify','shortpixel','ewww','smush' );

		$seo_c = 0; foreach ( $seo as $k ) { if ( $has($k) ) { $seo_c++; } }
		if ( $seo_c > 1 ) { $issues[] = array( 'type'=>'dup_seo', 'severity'=>'MEDIUM', 'msg'=>'Multiple SEO plugins. Keep exactly one.' ); }

		$cache_c = 0; foreach ( $caches as $k ) { if ( $has($k) ) { $cache_c++; } }
		if ( $cache_c > 1 ) { $issues[] = array( 'type'=>'dup_cache', 'severity'=>'HIGH', 'msg'=>'Multiple cache/optimizer plugins. Use LiteSpeed alone.' ); }

		$img_c = 0; foreach ( $imgs as $k ) { if ( $has($k) ) { $img_c++; } }
		if ( $img_c >= 1 && ! empty( $lsc['img_optm'] ) ) {
			$issues[] = array( 'type'=>'dup_image_opt', 'severity'=>'MEDIUM', 'msg'=>'External image optimizer + LSCWP present. Choose one.' );
		}

		if ( $has('file-manager') || $has('file manager advanced') ) {
			$issues[] = array( 'type'=>'file_manager', 'severity'=>'HIGH', 'msg'=>'File Manager active on production. Remove or strictly limit.' );
		}
		if ( isset( $headers['cf-cache-status'] ) && ! empty( $lsc['active'] ) ) {
			$issues[] = array( 'type'=>'cdn_double', 'severity'=>'LOW', 'msg'=>'Cloudflare + LSCWP: ensure rules don’t double-optimize HTML.' );
		}

		return $issues;
	}
	protected static function suggestions( $lsc, $issues, $cdn_hint ) {
		$s = array();
		if ( ! empty( $lsc['active'] ) ) {
			if ( ! $lsc['css_min'] )   { $s[] = 'Enable CSS Minify (LSCWP).'; }
			if ( $lsc['css_combine'] ) { $s[] = 'Disable CSS Combine (HTTP/2+).'; }
			if ( ! $lsc['js_min'] )    { $s[] = 'Enable JS Minify; also Defer + Delay where safe.'; }
			if ( ! $lsc['css_ucss'] )  { $s[] = 'Enable UCSS (Critical CSS via QUIC.cloud).'; }
			if ( ! $lsc['object_cache'] ) { $s[] = 'Enable Object Cache (Redis) if available.'; }
			if ( ! $lsc['img_optm'] )  { $s[] = 'Enable Image Optimization in LSCWP (or remove overlap).'; }
			if ( ! $lsc['img_webp'] )  { $s[] = 'Serve WebP/AVIF via LSCWP rewrites.'; }
			if ( $lsc['crawler'] )     { $s[] = 'Disable the Crawler unless on a strong server.'; }
		} else {
			$s[] = 'Install/activate LiteSpeed Cache and enable page caching.';
		}
		if ( ! empty( $issues ) ) {
			foreach ( $issues as $i ) {
				if ( 'dup_seo' === $i['type'] )   { $s[] = 'Deactivate extra SEO plugin(s).'; }
				if ( 'dup_cache' === $i['type'] ) { $s[] = 'Deactivate non-LiteSpeed cache/optimizer plugins.'; }
				if ( 'dup_image_opt' === $i['type'] ) { $s[] = 'Use ONE image optimizer only.'; }
				if ( 'file_manager' === $i['type'] )  { $s[] = 'Remove File Manager; use SFTP/host panel.'; }
				if ( 'cdn_double' === $i['type'] )    { $s[] = 'Check CDN page rules vs LSCWP headers.'; }
			}
		}
		if ( 'quic.cloud' === $cdn_hint ) { $s[] = 'Ensure HTTP/3 is enabled; avoid duplicate optimizations across layers.'; }
		$u = array(); foreach ( $s as $line ) { $u[ $line ] = true; } return array_keys( $u );
	}
	protected static function scores( $ctx ) {
		$sec  = 0; $sec += $ctx['admins'] <= 3 ? 2 : ( $ctx['admins'] <= 5 ? 1 : 0 );
		$sec += $ctx['file_edit'] ? 2 : 0; $sec += $ctx['xmlrpc_on'] ? 0 : 2; $sec += 2; $sec = min( 10, $sec );
		$opt  = 0;
		$opt += ! empty( $ctx['lsc_flags']['active'] ) ? 2 : 0;
		$opt += ! empty( $ctx['lsc_flags']['object_cache'] ) ? 2 : 0;
		$opt += ! empty( $ctx['lsc_flags']['css_min'] ) ? 1 : 0;
		$opt += ! empty( $ctx['lsc_flags']['js_min'] ) ? 1 : 0;
		$opt += ! empty( $ctx['lsc_flags']['css_ucss'] ) ? 2 : 0;
		$opt += ( ! empty( $ctx['lsc_flags']['js_defer'] ) || ! empty( $ctx['lsc_flags']['js_delay'] ) ) ? 2 : 0;
		$opt = min( 10, $opt );
		$speed  = 0;
		$speed += ( $ctx['cache_hdr'] === 'hit' ) ? 4 : ( $ctx['cache_hdr'] ? 2 : 0 );
		$speed += ! empty( $ctx['lsc_flags']['img_webp'] ) ? 2 : 0;
		$speed += ! empty( $ctx['lsc_flags']['img_optm'] ) ? 2 : 0;
		$speed += ! empty( $ctx['lsc_flags']['css_ucss'] ) ? 2 : 0;
		$speed = min( 10, $speed );
		$stab  = 10; $stab -= (int) ( $ctx['updates']['plugins'] > 0 ) * 2; $stab -= (int) ( $ctx['updates']['themes'] > 0 ) * 1;
		if ( ! empty( $ctx['bottlenecks'] ) ) { foreach ( $ctx['bottlenecks'] as $b ) { if ( 'HIGH' === $b['severity'] ) { $stab -= 3; } if ( 'MEDIUM' === $b['severity'] ) { $stab -= 1; } } }
		$stab = max( 0, min( 10, $stab ) );
		return array( 'security'=>$sec, 'optimization'=>$opt, 'speed'=>$speed, 'stability'=>$stab, 'total'=>$sec+$opt+$speed+$stab );
	}
	protected static function build_summary( $r ) { return array( 'key_actions' => array_slice( $r['suggestions'], 0, 8 ) ); }
	protected static function plugins_map( $list ) { $m = array(); foreach ( $list as $p ) { $m[ $p['slug'] ] = $p['version']; } return $m; }
	protected static function latest_history_before( $history, $month_ym ) { if ( empty( $history ) ) { return array(); } krsort( $history ); foreach ( $history as $ym => $e ) { if ( $ym < $month_ym ) { return $e; } } return array(); }
	protected static function plugin_diffs( $prev_map, $curr_list ) {
		$diffs=array(); $curr_map=self::plugins_map($curr_list); $seen=array();
		foreach ( $curr_map as $slug=>$ver ) { $seen[$slug]=true; if ( ! isset($prev_map[$slug]) ) { $diffs[]=array('slug'=>$slug,'change'=>'NEW','from'=>null,'to'=>$ver); } elseif ( $prev_map[$slug]!==$ver ) { $diffs[]=array('slug'=>$slug,'change'=>'UPDATED','from'=>$prev_map[$slug],'to'=>$ver); } }
		foreach ( $prev_map as $slug=>$ver ) { if ( ! isset($seen[$slug]) ) { $diffs[]=array('slug'=>$slug,'change'=>'DELETED','from'=>$ver,'to'=>null); } }
		return $diffs;
	}

	// Version prefix helper
	protected static function format_version( $v ) {
		$v = trim( (string) $v );
		if ( '' === $v ) { return $v; }
		if ( $v[0] === 'v' || $v[0] === 'V' ) { return $v; }
		return 'v' . $v;
	}

	protected static function update_asset_log( $hook_extra ) {
		$log  = get_option( self::OPT_ASSET_LOG, array() );
		$keep = (int) ( self::current_settings()['keep_months'] );
		$today = date_i18n( 'Y-m-d' );
		$log += array( 'plugins'=>array(), 'themes'=>array(), 'core'=>array() );
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$type = isset( $hook_extra['type'] ) ? $hook_extra['type'] : '';
		if ( 'plugin' === $type ) {
			$targets = array();
			if ( ! empty( $hook_extra['plugin'] ) )  { $targets[] = $hook_extra['plugin']; }
			if ( ! empty( $hook_extra['plugins'] ) ) { $targets = array_merge( $targets, $hook_extra['plugins'] ); }
			$all = function_exists( 'get_plugins' ) ? get_plugins() : array();
			foreach ( array_unique( $targets ) as $path ) {
				if ( empty( $all[ $path ] ) ) { continue; }
				$slug = dirname( $path ); $ver = isset( $all[ $path ]['Version'] ) ? $all[ $path ]['Version'] : '';
				if ( empty( $log['plugins'][ $slug ] ) ) { $log['plugins'][ $slug ] = array( 'last_updated'=>$today, 'history'=>array() ); }
				$log['plugins'][ $slug ]['last_updated'] = $today;
				$log['plugins'][ $slug ]['history'][]    = array( 'date'=>$today, 'to_version'=>$ver );
				if ( count( $log['plugins'][ $slug ]['history'] ) > $keep ) { $log['plugins'][ $slug ]['history'] = array_slice( $log['plugins'][ $slug ]['history'], -$keep ); }
			}
		} elseif ( 'theme' === $type ) {
			$targets = array();
			if ( ! empty( $hook_extra['theme'] ) )  { $targets[] = $hook_extra['theme'] ; }
			if ( ! empty( $hook_extra['themes'] ) ) { $targets = array_merge( $targets, $hook_extra['themes'] ); }
			foreach ( array_unique( $targets ) as $slug ) {
				$th = wp_get_theme( $slug ); if ( ! $th || ! $th->exists() ) { continue; }
				$ver = $th->get( 'Version' );
				if ( empty( $log['themes'][ $slug ] ) ) { $log['themes'][ $slug ] = array( 'last_updated'=>$today, 'history'=>array() ); }
				$log['themes'][ $slug ]['last_updated'] = $today;
				$log['themes'][ $slug ]['history'][]    = array( 'date'=>$today, 'to_version'=>$ver );
				if ( count( $log['themes'][ $slug ]['history'] ) > $keep ) { $log['themes'][ $slug ]['history'] = array_slice( $log['themes'][ $slug ]['history'], -$keep ); }
			}
		} elseif ( 'core' === $type ) {
			$ver = get_bloginfo( 'version' );
			if ( empty( $log['core']['wordpress'] ) ) { $log['core']['wordpress'] = array( 'last_updated'=>$today, 'history'=>array() ); }
			$log['core']['wordpress']['last_updated'] = $today;
			$log['core']['wordpress']['history'][]    = array( 'date'=>$today, 'to_version'=>$ver );
			if ( count( $log['core']['wordpress']['history'] ) > $keep ) { $log['core']['wordpress']['history'] = array_slice( $log['core']['wordpress']['history'], -$keep ); }
		}
		update_option( self::OPT_ASSET_LOG, $log, false );
	}
	protected static function asset_last_updated_human( $type, $slug, $asset_log = null ) {
		if ( null === $asset_log ) { $asset_log = get_option( self::OPT_ASSET_LOG, array() ); }
		$asset_log += array( 'plugins'=>array(), 'themes'=>array(), 'core'=>array() );
		if ( 'plugin' === $type && isset( $asset_log['plugins'][ $slug ]['last_updated'] ) ) {
			$t = strtotime( $asset_log['plugins'][ $slug ]['last_updated'] ); return $t ? date_i18n( 'd/m/Y', $t ) : '';
		}
		if ( 'theme' === $type && isset( $asset_log['themes'][ $slug ]['last_updated'] ) ) {
			$t = strtotime( $asset_log['themes'][ $slug ]['last_updated'] ); return $t ? date_i18n( 'd/m/Y', $t ) : '';
		}
		if ( 'core' === $type && isset( $asset_log['core']['wordpress']['last_updated'] ) ) {
			$t = strtotime( $asset_log['core']['wordpress']['last_updated'] ); return $t ? date_i18n( 'd/m/Y', $t ) : '';
		}
		if ( 'plugin' === $type ) {
			$legacy = get_option( self::OPT_PLUGIN_LOG, array() );
			if ( ! empty( $legacy[ $slug ]['last_updated'] ) ) { $t = strtotime( $legacy[ $slug ]['last_updated'] ); return $t ? date_i18n( 'd/m/Y', $t ) : ''; }
		}
		return '';
	}
	protected static function get_asset_history( $type, $slug_or_key, $asset_log ) {
		$asset_log += array( 'plugins'=>array(), 'themes'=>array(), 'core'=>array() );
		if ( 'plugin' === $type )      { $hist = isset( $asset_log['plugins'][ $slug_or_key ]['history'] ) ? $asset_log['plugins'][ $slug_or_key ]['history'] : array(); }
		elseif ( 'theme' === $type )   { $hist = isset( $asset_log['themes'][ $slug_or_key ]['history'] ) ? $asset_log['themes'][ $slug_or_key ]['history'] : array(); }
		elseif ( 'core' === $type )    { $hist = isset( $asset_log['core'][ $slug_or_key ]['history'] ) ? $asset_log['core'][ $slug_or_key ]['history'] : array(); }
		else { $hist = array(); }
		$out = array();
		foreach ( $hist as $h ) {
			$d = isset( $h['date'] ) ? $h['date'] : ''; $v = isset( $h['to_version'] ) ? $h['to_version'] : '';
			if ( '' === $d ) { continue; }
			$ts = strtotime( $d . ' 00:00:00' ); if ( ! $ts ) { continue; }
			$out[] = array( 'date'=>$d, 'ts'=>$ts, 'to_version'=>$v );
		}
		usort( $out, function( $a, $b ) { if ( $a['ts'] === $b['ts'] ) { return 0; } return ( $a['ts'] < $b['ts'] ) ? -1 : 1; });
		return $out;
	}
	protected static function version_delta_label( $prev, $curr ) {
		if ( ! $prev || ! $curr ) { return ''; }
		$pa = self::parse_semver( $prev ); $ca = self::parse_semver( $curr );
		if ( empty( $pa ) || empty( $ca ) ) { return ''; }
		if ( $pa['major'] === $ca['major'] && $pa['minor'] === $ca['minor'] ) {
			$diff = $ca['patch'] - $pa['patch']; if ( $diff > 0 ) { return ' (+' . $diff . ')'; }
		}
		return '';
	}
	protected static function parse_semver( $v ) {
		$v = trim( (string) $v ); if ( $v === '' ) { return null; }
		$parts = preg_split( '/[^\d]+/', $v );
		if ( ! $parts || ! is_array( $parts ) ) { return null; }
		return array( 'major'=>isset($parts[0])?(int)$parts[0]:0, 'minor'=>isset($parts[1])?(int)$parts[1]:0, 'patch'=>isset($parts[2])?(int)$parts[2]:0 );
	}
	protected static function asset_weekly_lines_any( $type, $slug_or_key, $max_lines = 5, $asset_log = null ) {
		if ( null === $asset_log ) { $asset_log = get_option( self::OPT_ASSET_LOG, array() ); }
		$hist_all = self::get_asset_history( $type, $slug_or_key, $asset_log );
		if ( empty( $hist_all ) ) { return array(); }
		$changes = array(); $last_ver = null;
		foreach ( $hist_all as $e ) {
			$ver = $e['to_version'];
			if ( $last_ver === null || $ver !== $last_ver ) { $changes[] = array( 'ts'=>$e['ts'], 'date'=>$e['date'], 'to_version'=>$ver, 'prev'=>$last_ver ); $last_ver = $ver; }
		}
		if ( empty( $changes ) ) { return array(); }
		$since_ts = strtotime( '-35 days', current_time( 'timestamp' ) );
		$by_week = array();
		foreach ( $changes as $c ) {
			if ( $c['ts'] < $since_ts ) { continue; }
			$weekday = (int) date_i18n( 'N', $c['ts'] );
			$wstart  = $c['ts'] - ( ( $weekday - 1 ) * DAY_IN_SECONDS );
			$key     = date_i18n( 'Y-m-d', $wstart );
			if ( empty( $by_week[ $key ] ) || $by_week[ $key ]['ts'] < $c['ts'] ) { $by_week[ $key ] = $c; }
		}
		if ( empty( $by_week ) ) { return array(); }

		// If only one week had an update, return empty to let the UI show a plain date (your request)
		if ( count( $by_week ) <= 1 ) { return array(); }

		krsort( $by_week );
		$lines = array();
		foreach ( $by_week as $week_start => $c ) {
			$wk    = date_i18n( 'd M', strtotime( $week_start ) );
			$when  = date_i18n( 'd/m', $c['ts'] );
			$delta = self::version_delta_label( $c['prev'], $c['to_version'] );
			$lines[] = sprintf( 'Wk of %s: %s%s (%s)', $wk, self::format_version($c['to_version']), $delta, $when );
			if ( count( $lines ) >= $max_lines ) { break; }
		}
		return $lines;
	}

	/* -------------------------------------------------
	 * Export & Email
	 * -------------------------------------------------*/
	public static function handle_download() {
		if ( ! self::user_can_view_dashboard() ) { wp_die( esc_html__( 'Access denied.', 'satori-audit' ), 403 ); }
		check_admin_referer( 'satori_audit_v3_download' );
		$format = sanitize_text_field( isset( $_POST['format'] ) ? $_POST['format'] : 'pdf' );
		$report = self::build_report();

		$s = self::current_settings();
		$page_size   = in_array( $s['pdf_page_size'], array('A4','Letter','Legal'), true ) ? $s['pdf_page_size'] : 'A4';
		$orientation = in_array( $s['pdf_orientation'], array('portrait','landscape'), true ) ? $s['pdf_orientation'] : 'portrait';

		// Per-download overrides
		if ( $format === 'pdf_p' ) { $orientation = 'portrait'; $format = 'pdf'; }
		if ( $format === 'pdf_l' ) { $orientation = 'landscape'; $format = 'pdf'; }

		switch ( $format ) {
			case 'json':
				nocache_headers(); header( 'Content-Type: application/json; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename="satori-audit-'.date('Ymd').'.json"' );
				echo wp_json_encode( $report, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ); exit;
			case 'csv_plugins':
				$csv = self::csv_plugins( $report );
				nocache_headers(); header( 'Content-Type: text/csv; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename="satori-plugins-'.date('Ymd').'.csv"' );
				echo $csv; exit;
			case 'markdown':
				$md = self::markdown( $report );
				nocache_headers(); header( 'Content-Type: text/markdown; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename="satori-audit-'.date('Ymd').'.md"' );
				echo $md; exit;
			case 'html_preview':
				$html = self::html_report( $report, $page_size, $orientation );
				nocache_headers(); header( 'Content-Type: text/html; charset=utf-8' );
				// Inline preview (no attachment)
				echo $html; exit;
			default: // pdf
				$html = self::html_report( $report, $page_size, $orientation );
				$pdf  = self::html_to_pdf( $html, $page_size, $orientation );
				if ( $pdf ) {
					nocache_headers(); header( 'Content-Type: application/pdf' );
					header( 'Content-Disposition: attachment; filename="satori-audit-'.date('Ymd').'.pdf"' );
					echo $pdf; exit;
				} else {
					nocache_headers(); header( 'Content-Type: text/html; charset=utf-8' );
					header( 'Content-Disposition: attachment; filename="satori-audit-'.date('Ymd').'.html"' );
					echo $html; exit;
				}
		}
	}
	public static function mail_content_type_plain() { return 'text/plain'; }
	protected static function email_report( $report, $reason = 'monthly' ) {
		$s = self::current_settings();
		if ( empty( $s['notify_emails'] ) ) { return; }
		$to   = array_filter( array_map( 'trim', explode( ',', $s['notify_emails'] ) ) );
		if ( empty( $to ) ) { return; }
		$to = self::safelist_filter_emails( $to, $s );
		if ( empty( $to ) ) { return; }

		$subj = sprintf( '[SATORI Audit] %s – %s', $report['service_details']['site_name'], ucfirst( $reason ) );
		$body = "Attached: service log (PDF/MD/JSON).\n\nSummary:\n- Total score: ".$report['scores']['total']."/40\n- Key actions:\n  • ".implode( "\n  • ", array_slice( $report['suggestions'], 0, 6 ) );

		// Use settings for default export layout
		$page_size   = in_array( $s['pdf_page_size'], array('A4','Letter','Legal'), true ) ? $s['pdf_page_size'] : 'A4';
		$orientation = in_array( $s['pdf_orientation'], array('portrait','landscape'), true ) ? $s['pdf_orientation'] : 'portrait';

		$html = self::html_report( $report, $page_size, $orientation );
		$pdf  = self::html_to_pdf( $html, $page_size, $orientation );
		$tmp  = array();
		if ( $pdf ) { $path = wp_tempnam( 'satori-audit.pdf' ); file_put_contents( $path, $pdf ); $tmp[] = $path; }
		$md = self::markdown( $report ); $mdp = wp_tempnam( 'satori-audit.md' ); file_put_contents( $mdp, $md ); $tmp[] = $mdp;
		$js = wp_json_encode( $report, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ); $jsp = wp_tempnam( 'satori-audit.json' ); file_put_contents( $jsp, $js ); $tmp[] = $jsp;

		$headers = array( 'From: '.$s['contact_email'] );
		add_filter( 'wp_mail_content_type', array( __CLASS__, 'mail_content_type_plain' ) );
		wp_mail( $to, $subj, $body, $headers, $tmp );
		remove_filter( 'wp_mail_content_type', array( __CLASS__, 'mail_content_type_plain' ) );

		if ( ! empty( $s['notify_webhook'] ) ) {
			wp_remote_post( $s['notify_webhook'], array(
				'timeout' => 5,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array(
					'text'  => $subj."\n".$body,
					'score' => $report['scores']['total'],
					'site'  => $report['service_details']['site_url'],
					'reason'=> $reason,
				) ),
			) );
		}
		foreach ( $tmp as $p ) { @unlink( $p ); }
	}
	protected static function html_to_pdf( $html, $page_size = 'A4', $orientation = 'portrait' ) {
		if ( ! self::ensure_dompdf_loaded() ) { return false; }
		try {
			$options = new \Dompdf\Options();
			$options->set( 'isRemoteEnabled', true );
			$options->set( 'isHtml5ParserEnabled', true );
			$dompdf = new \Dompdf\Dompdf( $options );
			$dompdf->loadHtml( $html, 'UTF-8' );
			$dompdf->setPaper( $page_size, $orientation );
			$dompdf->render();
			return $dompdf->output();
		} catch ( \Throwable $e ) { return false; }
	}

	// Load DOMPDF from any viable location
	protected static function ensure_dompdf_loaded() {
		if ( class_exists( '\\Dompdf\\Dompdf' ) ) { return true; }
		foreach ( self::dompdf_dir_candidates() as $base ) {
			$autoload = trailingslashit( $base ) . 'autoload.inc.php';
			if ( is_readable( $autoload ) ) {
				require_once $autoload;
				if ( class_exists( '\\Dompdf\\Autoloader' ) && method_exists( '\\Dompdf\\Autoloader', 'register' ) ) {
					\Dompdf\Autoloader::register();
				}
				if ( class_exists( '\\Dompdf\\Dompdf' ) ) { return true; }
			}
		}
		if ( class_exists( '\\Dompdf\\Dompdf' ) ) { return true; }
		return false;
	}
	protected static function dompdf_status() {
		$src = 'none'; $avail = false;
		if ( class_exists( '\\Dompdf\\Dompdf' ) ) { $avail = true; $src = 'composer'; }
		foreach ( self::dompdf_dir_candidates() as $base ) {
			if ( is_readable( trailingslashit( $base ) . 'autoload.inc.php' ) ) {
				$avail = true; $src = 'bundled ('. $base .')'; break;
			}
		}
		return array( 'available' => (bool) $avail, 'source' => $src );
	}

	/* -------------------------------------------------
	 * Renderers (AU timestamp + layout controls + v-prefix)
	 * -------------------------------------------------*/
	protected static function html_report( $r, $page_size = 'A4', $orientation = 'portrait' ) {
		$s    = self::current_settings();
		$logo = $s['pdf_logo_url'] ? '<img src="'.esc_url( $s['pdf_logo_url'] ).'" style="height:40px;vertical-align:middle;margin-right:12px">' : '';
		$legend = '<span style="margin-right:16px">! NEW</span><span style="margin-right:16px">✓ UPDATED</span><span>! DELETED</span>';
		$diff_map = array(); if ( ! empty( $r['plugin_diffs'] ) ) { foreach ( $r['plugin_diffs'] as $d ) { $diff_map[ $d['slug'] ] = $d; } }
		$rows = ''; $today = date_i18n('d/m/Y');

		foreach ( $r['stability']['active_plugins'] as $p ) {
			$slug  = $p['slug'];
			$type  = ( stripos( $p['name'], 'pro' ) !== false || stripos( $p['name'], 'premium' ) !== false ) ? 'PREMIUM' : 'FREE/FREEMIUM';
			$mark  = ''; if ( isset( $diff_map[ $slug ] ) ) { $c = $diff_map[ $slug ]['change']; $mark = ( 'UPDATED' === $c ) ? '✓ UPDATED' : ( 'NEW' === $c ? '! NEW' : '' ); }
			$lines = self::asset_weekly_lines_any( 'plugin', $slug, 5, $r['asset_log'] );
			if ( ! empty( $lines ) ) { $updated_cell = '<div style="line-height:1.3">'. esc_html( implode( "\n", $lines ) ) .'</div>'; $updated_cell = str_replace( "\n", '<br>', $updated_cell ); }
			else { $updated_cell = esc_html( self::asset_last_updated_human( 'plugin', $slug, $r['asset_log'] ) ); }
			$rows .= '<tr><td>'.esc_html($p['name']).'</td><td>'.$type.'</td><td>'.esc_html(self::format_version($p['version'])).'</td><td style="white-space:normal">'.esc_html($p['description_short']).'</td><td>Active</td><td>'.$today.'</td><td>'.$updated_cell.'</td><td>'.$mark.'</td><td></td></tr>';
		}

		$removed_rows = '';
		if ( ! empty( $r['plugin_diffs'] ) ) {
			foreach ( $r['plugin_diffs'] as $d ) { if ( 'DELETED' === $d['change'] ) { $removed_rows .= '<tr><td>'.esc_html( $d['slug'] ).'</td><td>'.esc_html( self::format_version($d['from']) ).'</td><td>Removed</td></tr>'; } }
		}

		$bl = ''; if ( ! empty( $r['bottlenecks'] ) ) { foreach ( $r['bottlenecks'] as $b ) { $bl .= '<li>['.esc_html( $b['severity'] ).'] '.esc_html( $b['msg'] ).'</li>'; } }

		$v = $r['versions'];
		$core_lines   = $s['weekly_lines_core'] ? self::asset_weekly_lines_any( 'core',  'wordpress',       5, $r['asset_log'] ) : array();
		$child_lines  = ( $s['weekly_lines_themes'] && ! empty( $v['child']['slug'] ) )  ? self::asset_weekly_lines_any( 'theme', $v['child']['slug'],  5, $r['asset_log'] ) : array();
		$parent_lines = ( $s['weekly_lines_themes'] && ! empty( $v['parent']['slug'] ) ) ? self::asset_weekly_lines_any( 'theme', $v['parent']['slug'], 5, $r['asset_log'] ) : array();
		$u = function( $date, $lines ) { if ( ! empty( $lines ) ) { $h = esc_html( implode( "\n", $lines ) ); return '<div style="line-height:1.3">'.str_replace("\n", "<br>", $h).'</div>'; } return esc_html( $date ); };

		$ver_rows  = '<tr><td>WordPress Core</td><td>'.esc_html(self::format_version($v['core']['version'])).'</td><td>'.$u($v['core']['updated_on'],   $core_lines ).'</td></tr>';
		$ver_rows .= '<tr><td>Child Theme: '.esc_html($v['child']['name']).'</td><td>'.esc_html(self::format_version($v['child']['version'])).'</td><td>'.$u($v['child']['updated_on'], $child_lines).'</td></tr>';
		if ( ! empty( $v['parent']['slug'] ) ) { $ver_rows .= '<tr><td>Parent Theme: '.esc_html($v['parent']['name']).'</td><td>'.esc_html(self::format_version($v['parent']['version'])).'</td><td>'.$u($v['parent']['updated_on'], $parent_lines).'</td></tr>'; }

		ob_start(); ?>
		<!doctype html><html><meta charset="utf-8">
		<style>
		@page { size: <?php echo esc_html($page_size.' '.$orientation); ?>; margin: 12mm; }
		body{font-family:Helvetica,Arial,sans-serif;color:#111;margin:24px}
		h1,h2{margin:0 0 8px}
		table{width:100%;border-collapse:collapse;margin:8px 0 18px;table-layout:fixed}
		th,td{border:1px solid #ddd;padding:6px 8px;font-size:11px;vertical-align:top;word-wrap:break-word}
		th{background:#f5f5f5;text-align:left}
		.small{color:#666;font-size:12px}
		.kv td{border:none;padding:2px 8px}
		.legend{font-size:12px;color:#444}
		.section{margin-top:12px}
		</style>
		<body>
		<h1><?php echo $logo; ?>WEB SITE SERVICE LOG</h1>
		<p class="small">SATORI</p>

		<h2>Service Details</h2>
		<table class="kv">
			<tr><td><strong>Site Name:</strong> <?php echo esc_html($r['service_details']['site_name']); ?></td><td><strong>Site URL:</strong> <?php echo esc_html($r['service_details']['site_url']); ?></td></tr>
		</table>
		<table class="kv">
			<tr><td><strong>Site Manager:</strong> <?php echo esc_html($r['service_details']['managed_by']); ?></td><td><strong>Service Date:</strong> <?php echo esc_html($r['service_details']['service_date']); ?></td></tr>
			<tr><td><strong>Start Date:</strong> <?php echo esc_html($r['service_details']['start_date']); ?></td><td><strong>End Date:</strong> ACTIVE</td></tr>
			<tr><td colspan="2"><strong>Legend:</strong> <span class="legend"><?php echo $legend; ?></span></td></tr>
			<tr><td colspan="2"><strong>Service(s):</strong> <?php echo esc_html($r['service_details']['notes']); ?></td></tr>
		</table>

		<h2>Security – Site Scan</h2>
		<p class="small">Last scan: <?php echo esc_html($r['security']['vuln_scan_last_run'] ? $r['security']['vuln_scan_last_run'] : 'n/a'); ?> – <?php echo esc_html($r['security']['vuln_scan_summary']); ?></p>

		<h2>Overview</h2>
		<table>
			<tr><th>WordPress</th><th>PHP</th><th>Server</th><th>HTTPS</th><th>Theme</th><th>Permalinks</th></tr>
			<tr>
				<td><?php echo esc_html(self::format_version($r['overview']['wordpress'])); ?></td>
				<td><?php echo esc_html(self::format_version($r['overview']['php'])); ?></td>
				<td><?php echo esc_html($r['overview']['server']); ?></td>
				<td><?php echo $r['overview']['https'] ? 'Yes' : 'No'; ?></td>
				<td><?php echo esc_html( $r['overview']['theme']['name'] . ' ' . self::format_version($r['overview']['theme']['version']) ); ?></td>
				<td><?php echo esc_html( $r['overview']['permalinks'] ? $r['overview']['permalinks'] : 'plain' ); ?></td>
			</tr>
		</table>

		<h2>Versions &amp; Update Dates</h2>
		<table>
			<tr><th>Asset</th><th>Version</th><th>Updated On</th></tr>
			<?php echo $ver_rows; ?>
		</table>

		<h2>Scores</h2>
		<table>
			<tr><th>Security</th><th>Optimization</th><th>Speed</th><th>Stability</th><th>Total</th></tr>
			<tr><td><?php echo $r['scores']['security']; ?>/10</td><td><?php echo $r['scores']['optimization']; ?>/10</td><td><?php echo $r['scores']['speed']; ?>/10</td><td><?php echo $r['scores']['stability']; ?>/10</td><td><strong><?php echo $r['scores']['total']; ?>/40</strong></td></tr>
		</table>

		<h2>Bottlenecks</h2>
		<ul><?php echo $bl ? $bl : '<li>None detected</li>'; ?></ul>

		<h2>Plugin List (combined with recent weekly updates)</h2>
		<table>
			<tr>
				<th>Plugin Name</th>
				<th>Plugin Type</th>
				<th>Plugin Version</th>
				<th>Description</th>
				<th>Plugin Status</th>
				<th>Last Checked</th>
				<th>Updated On (last ~5 wks)</th>
				<th>Updated</th>
				<th>Comments</th>
			</tr>
			<?php echo $rows; ?>
		</table>

		<?php if ( $removed_rows ) : ?>
		<h2>Removed Plugins (since last month)</h2>
		<table>
			<tr><th>Plugin (slug)</th><th>Previous Version</th><th>Status</th></tr>
			<?php echo $removed_rows; ?>
		</table>
		<?php endif; ?>

		<p class="small">Generated: <?php echo esc_html( date_i18n('d/m/Y H:i') ); ?> • SATORI Audit v<?php echo esc_html($r['meta']['plugin_version']); ?></p>
		</body></html>
		<?php
		return ob_get_clean();
	}
	protected static function markdown( $r ) {
		$lines = array();
		$lines[] = '# WEB SITE SERVICE LOG – SATORI';
		$lines[] = '**Site:** '.$r['service_details']['site_name'].'  ';
		$lines[] = '**URL:** '.$r['service_details']['site_url'].'  ';
		$lines[] = '**Service Date:** '.$r['service_details']['service_date'];
		$lines[] = '';
		$lines[] = '## Versions & Update Dates';
		$lines[] = '- WordPress: '.self::format_version($r['versions']['core']['version']).' (Updated: '.$r['versions']['core']['updated_on'].')';
		$lines[] = '- Child Theme: '.$r['versions']['child']['name'].' '.self::format_version($r['versions']['child']['version']).' (Updated: '.$r['versions']['child']['updated_on'].')';
		if ( ! empty( $r['versions']['parent']['slug'] ) ) {
			$lines[] = '- Parent Theme: '.$r['versions']['parent']['name'].' '.self::format_version($r['versions']['parent']['version']).' (Updated: '.$r['versions']['parent']['updated_on'].')';
		}
		$lines[] = '';
		$lines[] = '## Scores';
		$lines[] = '- Security: '.$r['scores']['security'].'/10';
		$lines[] = '- Optimization: '.$r['scores']['optimization'].'/10';
		$lines[] = '- Speed: '.$r['scores']['speed'].'/10';
		$lines[] = '- Stability: '.$r['scores']['stability'].'/10';
		$lines[] = '- **Total: '.$r['scores']['total'].'/40**';
		$lines[] = '';
		$lines[] = '## Bottlenecks';
		if ( empty( $r['bottlenecks'] ) ) { $lines[] = '- None detected'; }
		foreach ( $r['bottlenecks'] as $b ) { $lines[] = '- ['.$b['severity'].'] '.$b['msg']; }
		return implode( "\n", $lines );
	}
	protected static function csv_plugins( $r ) {
		$cols = array( 'Plugin Name','Plugin Type','Plugin Version','Description','Plugin Status','Last Checked','Updated On (last ~5 wks)','Updated','Comments' );
		$out  = fopen( 'php://temp', 'w+' ); fputcsv( $out, $cols );
		$today = date_i18n( 'd/m/Y' );
		$diff_map = array(); if ( ! empty( $r['plugin_diffs'] ) ) { foreach ( $r['plugin_diffs'] as $d ) { $diff_map[ $d['slug'] ] = $d; } }
		foreach ( $r['stability']['active_plugins'] as $p ) {
			$type       = ( stripos( $p['name'], 'pro' ) !== false || stripos( $p['name'], 'premium' ) !== false ) ? 'PREMIUM' : 'FREE/FREEMIUM';
			$mark       = isset( $diff_map[ $p['slug'] ] ) ? ( $diff_map[ $p['slug'] ]['change'] === 'UPDATED' ? '✓ UPDATED' : ( $diff_map[ $p['slug'] ]['change'] === 'NEW' ? '! NEW' : '' ) ) : '';
			$lines      = self::asset_weekly_lines_any( 'plugin', $p['slug'], 5, $r['asset_log'] );
			$updated_on = ! empty( $lines ) ? implode( ' | ', $lines ) : self::asset_last_updated_human( 'plugin', $p['slug'], $r['asset_log'] );
			fputcsv( $out, array( $p['name'], $type, self::format_version($p['version']), self::short_desc( $p['description'] ), 'Active', $today, $updated_on, $mark, '' ) );
		}
		rewind( $out ); return stream_get_contents( $out );
	}

	/* -------------------------------------------------
	 * Test Email (dry run)
	 * -------------------------------------------------*/
	public static function handle_test_email() {
		self::enforce_settings_access_or_die();
		check_admin_referer( 'satori_audit_v3_test' );
		$s = self::current_settings();
		$candidates = array_filter( array_map( 'trim', explode( ',', (string)$s['notify_emails'] ) ) );
		$would_send = self::safelist_filter_emails( $candidates, $s );
		$preview_str = implode( ', ', $would_send );
		$user = wp_get_current_user(); $me = $user && $user->user_email ? $user->user_email : get_option( 'admin_email' );
		$subj = '[SATORI Audit] Test email (preview recipients)';
		$body = "Hello!\n\nThis is a test message from SATORI Audit.\n\nIf a real report were sent right now, it would go to (after safelist):\n" . ( $preview_str ? $preview_str : '[none – blocked by safelist or no recipients configured]' ) . "\n\nNo client emails were contacted.";
		$headers = array( 'From: '.$s['contact_email'] );
		add_filter( 'wp_mail_content_type', array( __CLASS__, 'mail_content_type_plain' ) );
		wp_mail( $me, $subj, $body, $headers );
		remove_filter( 'wp_mail_content_type', array( __CLASS__, 'mail_content_type_plain' ) );
		$q = array( 'page'=>'satori-audit-v3-settings','satori_test'=>1,'me'=>$me,'recips'=>base64_encode( $preview_str ) );
		wp_safe_redirect( admin_url( 'options-general.php?' . http_build_query( $q ) ) );
		exit;
	}

	/* -------------------------------------------------
	 * Safelist helpers + utilities + backfill
	 * -------------------------------------------------*/
	protected static function parse_safelist( $csv ) {
		$domains = array(); $emails  = array();
		$raw = preg_split( '/[,\s]+/', (string) $csv, -1, PREG_SPLIT_NO_EMPTY ); if ( ! $raw ) { return array( $domains, $emails ); }
		foreach ( $raw as $item ) {
			$item = trim( strtolower( $item ) ); if ( '' === $item ) { continue; }
			if ( $item[0] === '@' && strlen( $item ) > 1 ) { $domains[ substr( $item, 1 ) ] = true; }
			elseif ( strpos( $item, '@' ) !== false ) { $emails[ $item ] = true; }
		}
		return array( $domains, $emails );
	}
	protected static function safelist_filter_emails( $candidates, $settings ) {
		if ( empty( $settings['enforce_safelist'] ) ) { return $candidates; }
		list( $domains, $emails ) = self::parse_safelist( isset($settings['safelist_entries']) ? $settings['safelist_entries'] : '' );
		if ( empty( $domains ) && empty( $emails ) ) { return array(); }
		$out = array();
		foreach ( $candidates as $addr ) {
			$addr_l = strtolower( trim( $addr ) );
			if ( isset( $emails[ $addr_l ] ) ) { $out[] = $addr; continue; }
			$at = strrpos( $addr_l, '@' );
			if ( false !== $at ) { $dom = substr( $addr_l, $at + 1 ); if ( isset( $domains[ $dom ] ) ) { $out[] = $addr; continue; } }
		}
		return $out;
	}
	protected static function short_desc( $text, $limit = 140 ) {
		$t = wp_strip_all_tags( (string) $text ); $t = trim( preg_replace( '/\s+/', ' ', $t ) );
		if ( function_exists( 'mb_substr' ) ) { return ( mb_strlen( $t ) > $limit ) ? mb_substr( $t, 0, $limit - 1 ) . '…' : $t; }
		return ( strlen( $t ) > $limit ) ? substr( $t, 0, $limit - 1 ) . '…' : $t;
	}
	protected static function trim_history() {
		$s = self::current_settings(); $keep = (int) $s['keep_months'];
		$h = get_option( self::OPT_HISTORY, array() ); if ( empty( $h ) ) { return; }
		krsort( $h ); $h = array_slice( $h, 0, $keep, true ); update_option( self::OPT_HISTORY, $h );
	}
	public static function maybe_backfill_weeklies() {
		$s = self::current_settings();
		if ( empty( $s['backfill_on_first_run'] ) ) { return; }
		if ( get_option( 'satori_audit_v3_backfilled' ) ) { return; }

		$asset_log = get_option( self::OPT_ASSET_LOG, array() ); $asset_log += array( 'plugins'=>array(), 'themes'=>array(), 'core'=>array() );
		$today = date_i18n( 'Y-m-d' );

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$active_plugins = (array) get_option( 'active_plugins', array() );
		$all_plugins    = function_exists( 'get_plugins' ) ? get_plugins() : array();
		$theme          = wp_get_theme();
		$parent         = $theme && $theme->parent() ? $theme->parent() : null;
		$core_ver       = get_bloginfo( 'version' );

		$weeks = array();
		for ( $i = 4; $i >= 1; $i-- ) { $weeks[] = date_i18n( 'Y-m-d', strtotime( 'monday -'.$i.' week', current_time( 'timestamp' ) ) ); }

		foreach ( $active_plugins as $path ) {
			if ( empty( $all_plugins[$path] ) ) { continue; }
			$slug = dirname( $path ); $ver = isset( $all_plugins[$path]['Version'] ) ? $all_plugins[$path]['Version'] : '';
			if ( empty( $asset_log['plugins'][$slug] ) ) { $asset_log['plugins'][$slug] = array( 'last_updated' => $today, 'history' => array() ); }
			foreach ( $weeks as $w ) { $asset_log['plugins'][$slug]['history'][] = array( 'date' => $w, 'to_version' => $ver ); }
		}
		if ( $theme && $theme->exists() ) {
			$cslug = $theme->get_stylesheet(); $cver = $theme->get('Version');
			if ( empty( $asset_log['themes'][$cslug] ) ) { $asset_log['themes'][$cslug] = array( 'last_updated' => $today, 'history' => array() ); }
			foreach ( $weeks as $w ) { $asset_log['themes'][$cslug]['history'][] = array( 'date' => $w, 'to_version' => $cver ); }
		}
		if ( $parent && $parent->exists() ) {
			$pslug = $parent->get_template(); $pver = $parent->get('Version');
			if ( empty( $asset_log['themes'][$pslug] ) ) { $asset_log['themes'][$pslug] = array( 'last_updated' => $today, 'history' => array() ); }
			foreach ( $weeks as $w ) { $asset_log['themes'][$pslug]['history'][] = array( 'date' => $w, 'to_version' => $pver ); }
		}
		if ( empty( $asset_log['core']['wordpress'] ) ) { $asset_log['core']['wordpress'] = array( 'last_updated' => $today, 'history' => array() ); }
		foreach ( $weeks as $w ) { $asset_log['core']['wordpress']['history'][] = array( 'date' => $w, 'to_version' => $core_ver ); }

		update_option( self::OPT_ASSET_LOG, $asset_log, false );
		update_option( 'satori_audit_v3_backfilled', 1, false );
	}

	/* -------------------------------------------------
	 * Harden recipients
	 * -------------------------------------------------*/
	public static function harden_recipients() {
		$s = self::current_settings();
		$admin = get_option( 'admin_email' );
		if ( isset( $s['notify_emails'] ) && is_string( $s['notify_emails'] ) && trim( $s['notify_emails'] ) === trim( $admin ) ) {
			$s['notify_emails'] = '';
			update_option( self::OPT_SETTINGS, $s );
		}
	}
}

Satori_Audit_V373::init();

} // end guard<?php
/**
 * Plugin Name: SATORI – Site Audit v3.7.3 (PDF layout controls + weekly lines tweak + version prefix + HTML preview)
 * Description: Tools → SATORI Audit. Client-ready PDF/MD/CSV exports, per-asset history (weekly lines on change), LSCWP hints, internal-only notifications (safelist + test email), uploadable DOMPDF with diagnostics, WCAG-AA admin badges/links, access control, AU-format timestamp, PDF page size/orientation controls, and HTML Preview.
 * Version: 3.7.3
 * Author: SATORI
 * License: GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'Satori_Audit_V373' ) ) {

class Satori_Audit_V373 {

	/* -------------------------------------------------
	 * Options / Paths / Hooks
	 * -------------------------------------------------*/
	const OPT_SETTINGS    = 'satori_audit_v3_settings';
	const OPT_HISTORY     = 'satori_audit_v3_history';
	const OPT_EVENTS      = 'satori_audit_v3_events';
	const OPT_ASSET_LOG   = 'satori_audit_v3_asset_log';
	const OPT_PLUGIN_LOG  = 'satori_audit_v3_plugin_log'; // legacy

	const CRON_HOOK_M     = 'satori_audit_v3_monthly_event';
	const CRON_HOOK_D     = 'satori_audit_v3_daily_watch';

	// Library dir for add-ons (e.g., dompdf)
	const LIB_DIR         = 'satori-audit-lib';
	const LIB_DOMPDF_DIR  = 'dompdf'; // inside LIB_DIR

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_pages' ) );

		// Notices + AJAX
		add_action( 'admin_notices', array( __CLASS__, 'maybe_notice_dompdf' ) );
		add_action( 'wp_ajax_satori_audit_v3_dismiss_pdf_notice', array( __CLASS__, 'ajax_dismiss_pdf_notice' ) );

		// Admin-post handlers
		add_action( 'admin_post_satori_audit_v3_download', array( __CLASS__, 'handle_download' ) );
		add_action( 'admin_post_satori_audit_v3_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_satori_audit_v3_test_email', array( __CLASS__, 'handle_test_email' ) );
		add_action( 'admin_post_satori_audit_v3_install_dompdf', array( __CLASS__, 'handle_install_dompdf' ) );
		add_action( 'admin_post_satori_audit_v3_probe_dompdf', array( __CLASS__, 'handle_probe_dompdf' ) );

		// Cron + update hooks
		add_action( 'wp', array( __CLASS__, 'ensure_cron' ) );
		add_action( self::CRON_HOOK_M, array( __CLASS__, 'run_monthly' ) );
		add_action( self::CRON_HOOK_D, array( __CLASS__, 'run_daily_watch' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_update' ), 10, 2 );

		// Auto-update behaviour + email suppression
		add_filter( 'auto_update_plugin', array( __CLASS__, 'maybe_force_auto_updates' ), 10, 2 );
		add_filter( 'auto_plugin_update_send_email', array( __CLASS__, 'suppress_plugin_email' ), 10, 3 );
		add_filter( 'auto_theme_update_send_email',  array( __CLASS__, 'suppress_theme_email'  ), 10, 3 );
		add_filter( 'auto_core_update_send_email',   array( __CLASS__, 'suppress_core_email'   ), 10, 4 );

		// Recipient hardening + backfill
		add_action( 'init', array( __CLASS__, 'harden_recipients' ) );
		add_action( 'init', array( __CLASS__, 'maybe_backfill_weeklies' ) );
	}

	/* -------------------------------------------------
	 * Defaults (Settings)
	 * -------------------------------------------------*/
	protected static function defaults() {
		return array(
			'client'             => 'Client Name',
			'site_name'          => get_bloginfo( 'name' ),
			'site_url'           => home_url( '/' ),
			'managed_by'         => 'SATORI',
			'start_date'         => '',
			'service_notes'      => 'Monthly maintenance: WP/Plugins updates, security check, cache purge, audit.',
			'contact_email'      => get_option( 'admin_email' ),
			'notify_emails'      => '',
			'notify_webhook'     => '',
			'pdf_logo_url'       => '',

			// Safelist
			'enforce_safelist'   => false,
			'safelist_entries'   => '',

			// Automation
			'enable_monthly'       => true,
			'enable_watch'         => true,
			'force_auto_updates'   => false,
			'suppress_auto_emails' => true,

			// Versions weekly lines
			'weekly_lines_core'     => true,
			'weekly_lines_themes'   => true,
			'backfill_on_first_run' => false,

			// Access Control
			'restrict_settings'   => true,
			'restrict_dashboard'  => false,
			'primary_admin_email' => get_option( 'admin_email' ),
			'allowed_admins'      => '',

			'keep_months'        => 12,

			// NEW: PDF output defaults
			'pdf_page_size'      => 'A4',         // A4, Letter, Legal
			'pdf_orientation'    => 'portrait',   // portrait, landscape
		);
	}

	/* -------------------------------------------------
	 * Paths & FS
	 * -------------------------------------------------*/
	protected static function mu_base_dir() {
		return defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_PLUGIN_DIR;
	}
	protected static function uploads_base_dir() {
		if ( function_exists( 'wp_upload_dir' ) ) {
			$u = wp_upload_dir();
			if ( ! empty( $u['basedir'] ) ) { return $u['basedir']; }
		}
		return WP_CONTENT_DIR . '/uploads';
	}
	protected static function lib_base_root() {
		$mu = trailingslashit( self::mu_base_dir() );
		if ( is_dir( $mu ) && is_writable( $mu ) ) {
			return $mu . self::LIB_DIR;
		}
		$up = trailingslashit( self::uploads_base_dir() ) . self::LIB_DIR;
		return $up;
	}
	protected static function lib_base_dir() { return trailingslashit( self::lib_base_root() ); }
	protected static function dompdf_dir_candidates() {
		$c = array();
		$c[] = trailingslashit( self::lib_base_root() ) . self::LIB_DOMPDF_DIR;                          // new
		$c[] = trailingslashit( self::mu_base_dir() ) . self::LIB_DIR . '/' . self::LIB_DOMPDF_DIR;       // legacy
		$c[] = trailingslashit( self::uploads_base_dir() ) . '/' . self::LIB_DIR . '/' . self::LIB_DOMPDF_DIR; // fallback
		return array_unique( $c );
	}
	protected static function ensure_lib_dirs() {
		$base = self::lib_base_dir();
		if ( ! is_dir( $base ) ) { wp_mkdir_p( $base ); }
	}

	/* -------------------------------------------------
	 * Access Control Helpers
	 * -------------------------------------------------*/
	protected static function current_settings() {
		return wp_parse_args( get_option( self::OPT_SETTINGS, array() ), self::defaults() );
	}
	protected static function parse_allowed_admins( $csv ) {
		$emails = array(); $users = array(); $ids = array();
		$pieces = preg_split( '/[,\s]+/', (string) $csv, -1, PREG_SPLIT_NO_EMPTY );
		foreach ( $pieces as $p ) {
			$p = trim( $p ); if ( $p === '' ) { continue; }
			if ( is_numeric( $p ) )          { $ids[ intval( $p ) ] = true; continue; }
			if ( strpos( $p, '@' ) !== false ){ $emails[ strtolower( $p ) ] = true; continue; }
			$users[ strtolower( $p ) ] = true;
		}
		return array( $emails, $users, $ids );
	}
	protected static function user_can_view_settings( $s = null ) {
		if ( ! is_user_logged_in() ) { return false; }
		if ( null === $s ) { $s = self::current_settings(); }
		if ( ! current_user_can( 'manage_options' ) ) { return false; }
		if ( empty( $s['restrict_settings'] ) ) { return true; }
		$user = wp_get_current_user();
		if ( ! $user ) { return false; }
		$main = strtolower( trim( (string) $s['primary_admin_email'] ) );
		if ( $main && strtolower( $user->user_email ) === $main ) { return true; }
		list( $emails, $users, $ids ) = self::parse_allowed_admins( $s['allowed_admins'] );
		if ( isset( $emails[ strtolower( $user->user_email ) ] ) ) { return true; }
		if ( isset( $users[ strtolower( $user->user_login ) ] ) )  { return true; }
		if ( isset( $ids[ intval( $user->ID ) ] ) )                { return true; }
		return false;
	}
	protected static function user_can_view_dashboard( $s = null ) {
		if ( ! is_user_logged_in() ) { return false; }
		if ( null === $s ) { $s = self::current_settings(); }
		if ( ! current_user_can( 'manage_options' ) ) { return false; }
		if ( empty( $s['restrict_dashboard'] ) ) { return true; }
		return self::user_can_view_settings( $s );
	}
	protected static function capability_for_settings() {
		return self::user_can_view_settings() ? 'manage_options' : 'do_not_allow';
	}
	protected static function capability_for_dashboard() {
		return self::user_can_view_dashboard() ? 'manage_options' : 'do_not_allow';
	}
	protected static function enforce_settings_access_or_die() {
		if ( ! self::user_can_view_settings() ) {
			wp_die( esc_html__( 'Access denied.', 'satori-audit' ), 403 );
		}
	}

	/* -------------------------------------------------
	 * Admin Pages + CSS
	 * -------------------------------------------------*/
	public static function register_pages() {
		add_management_page(
			'SATORI Audit',
			'SATORI Audit',
			self::capability_for_dashboard(),
			'satori-audit-v3',
			array( __CLASS__, 'render_dashboard' )
		);
		add_options_page(
			'SATORI Audit Settings',
			'SATORI Audit',
			self::capability_for_settings(),
			'satori-audit-v3-settings',
			array( __CLASS__, 'render_settings' )
		);
	}
	protected static function admin_badge_css() {
		echo '<style>
		.satori-badges .badge{display:inline-block;border-radius:999px;padding:2px 10px;font-weight:600;font-size:12px;line-height:1.8;margin-right:6px}
		.badge-ok{background:#116329;color:#fff}
		.badge-warn{background:#915930;color:#fff}
		.badge-err{background:#8b1111;color:#fff}
		.badge-info{background:#1b4965;color:#fff}
		.satori-kv td{border:none;padding:2px 8px}
		.satori-help{color:#1b4965;text-decoration:underline;font-weight:600}
		.satori-mono{font-family:Menlo,Consolas,monospace}
		</style>';
	}

	/* -------------------------------------------------
	 * Dashboard
	 * -------------------------------------------------*/
	public static function render_dashboard() {
		if ( ! self::user_can_view_dashboard() ) { wp_die( esc_html__( 'Access denied.', 'satori-audit' ), 403 ); }
		self::admin_badge_css();

		$nonce    = wp_create_nonce( 'satori_audit_v3_download' );
		$report   = self::build_report();
		$summary  = self::build_summary( $report );
		$json_pre = wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$s        = self::current_settings();
		$pdf      = self::dompdf_status();

		$preview = $report['stability']['active_plugins'];
		usort( $preview, function( $a, $b ) { return strcasecmp( $a['name'], $b['name'] ); });
		$preview = array_slice( $preview, 0, 20 );

		// Provide full plugin list to JS for dynamic preview (filter, paging)

		$__satori_all_plugins_json = wp_json_encode( $report['stability']['active_plugins'], JSON_UNESCAPED_SLASHES );


		echo '<div class="wrap satori-audit-wrap">';
		echo '<h1>🧰 SATORI Audit</h1>';
		echo '<p class="description">PDF/MD/CSV exports, per-asset history, weekly update lines (only when changed), WCAG badges, and internal-only notifications with safelist.</p>';

		echo '<div class="satori-badges" style="margin:8px 0 16px">';
		echo '<span class="badge '.( $pdf['available'] ? 'badge-ok' : 'badge-warn' ).'" aria-label="PDF engine status">'.( $pdf['available'] ? 'PDF Engine: DOMPDF ready' : 'PDF: fallback to HTML/MD' ).'</span>';
		echo '<span class="badge '.( $s['enable_monthly'] ? 'badge-ok' : 'badge-info' ).'">Monthly: '.( $s['enable_monthly'] ? 'Enabled' : 'Disabled' ).'</span>';
		echo '<span class="badge '.( $s['enable_watch'] ? 'badge-ok' : 'badge-info' ).'">Daily Watch: '.( $s['enable_watch'] ? 'Enabled' : 'Disabled' ).'</span>';
		echo '<span class="badge '.( ! empty($s['enforce_safelist']) ? 'badge-ok' : 'badge-warn' ).'">Safelist: '.( ! empty($s['enforce_safelist']) ? 'Enforced' : 'Open' ).'</span>';
		echo '</div>';

		echo '<h2>Scores</h2>';
		echo '<table class="widefat striped" style="max-width:820px"><tbody>';
		echo '<tr><th>Security</th><td>' . esc_html( $report['scores']['security'] ) . '/10</td></tr>';
		echo '<tr><th>Optimization</th><td>' . esc_html( $report['scores']['optimization'] ) . '/10</td></tr>';
		echo '<tr><th>Speed</th><td>' . esc_html( $report['scores']['speed'] ) . '/10</td></tr>';
		echo '<tr><th>Stability</th><td>' . esc_html( $report['scores']['stability'] ) . '/10</td></tr>';
		echo '<tr><th>Total</th><td><strong>' . esc_html( $report['scores']['total'] ) . '/40</strong></td></tr>';
		echo '</tbody></table>';

		echo '<h2>Key Actions</h2>';
		echo '<ol>';
		foreach ( $summary['key_actions'] as $act ) { echo '<li>' . esc_html( $act ) . '</li>'; }
		echo '</ol>';

		echo '<h2>Exports</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:flex;gap:10px;flex-wrap:wrap">';
		echo '<input type="hidden" name="action" value="satori_audit_v3_download">';
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '">';
		echo '<button class="button button-primary" name="format" value="pdf">Download PDF (Settings)</button>';
		echo '<button class="button" name="format" value="pdf_p">PDF (Portrait)</button>';
		echo '<button class="button" name="format" value="pdf_l">PDF (Landscape)</button>';
		echo '<button class="button" name="format" value="html_preview" formtarget="_blank">Open HTML Preview</button>';
		echo '<button class="button" name="format" value="markdown">Download Markdown</button>';
		echo '<button class="button" name="format" value="json">Download JSON</button>';
		echo '<button class="button" name="format" value="csv_plugins">Download CSV (Plugins)</button>';
		echo '</form>';

		echo '<details style="margin-top:18px"><summary style="cursor:pointer;font-weight:600;color:#2271b1;text-decoration:underline">Preview Plugin List (top 20)</summary>';
		echo '<p class="description">Filter locally; use exports for full data.</p>';
		echo '<div style="display:flex;gap:10px;align-items:center;margin:8px 0 6px;">';

		echo '<label>Show: <select id="satori-plugin-count"><option value="20" selected>20</option><option value="50">50</option><option value="100">100</option><option value="all">All</option></select></label>';

		echo '<label style="margin-left:8px"><input id="satori-plugin-scroll" type="checkbox"> Scrollable table</label>';

		echo '</div>';

		echo '<p><input id="satori-plugin-filter" type="search" placeholder="Type to filter by plugin name…" class="regular-text" style="width:360px"></p>';
		echo '<table class="widefat striped" id="satori-plugin-preview"><thead><tr>';
		echo '<th style="width:28%">Plugin Name</th><th style="width:12%">Version</th><th style="width:50%">Description</th><th style="width:10%">Status</th>';
		echo '</tr></thead><tbody>';
		echo '</tbody></table>';
		printf('<script>(function(){ const all = %s; const table = document.getElementById("satori-plugin-preview"); const tbody = table ? table.querySelector("tbody") : null; const q = document.getElementById("satori-plugin-filter"); const selCount = document.getElementById("satori-plugin-count"); const scrollCk = document.getElementById("satori-plugin-scroll"); if(!tbody) return; function normVer(v){ v = (v||"").trim(); if(!v) return v; return (v[0]==="v"||v[0]==="V")?v:("v"+v); } function escapeHtml(s){ const t = document.createElement("textarea"); t.textContent = s||""; return t.innerHTML; } function render(){  let n = selCount && selCount.value ? selCount.value : "20";  let max = (n==="all") ? all.length : parseInt(n,10)||20;  let kw = (q && q.value ? q.value.toLowerCase() : "").trim();  let list = all.slice().sort((a,b)=> (a.name||"").localeCompare(b.name||""));  if(kw){ list = list.filter(p => (p.name||"").toLowerCase().includes(kw)); }  let rows = list.slice(0, max).map(p => {   const name = (p.name||""); const ver = normVer(p.version||""); const desc = (p.description_short||"");   return "<tr><td>"+escapeHtml(name)+"</td><td>"+(name.match(/pro|premium/i)? "PREMIUM" : "FREE/FREEMIUM")+"</td><td>"+escapeHtml(ver)+"</td><td>"+escapeHtml(desc)+"</td><td>Active</td></tr>";  }).join("");  tbody.innerHTML = rows || "<tr><td colspan=\"5\"><em>No matches</em></td></tr>";  if(scrollCk){ table.parentElement.style.maxHeight = scrollCk.checked ? "420px" : ""; table.parentElement.style.overflow = scrollCk.checked ? "auto" : ""; } } if(q) q.addEventListener("input", render); if(selCount) selCount.addEventListener("change", render); if(scrollCk) scrollCk.addEventListener("change", render); render(); })();</script>', $__satori_all_plugins_json );

		echo '</details>';

		echo '<h2>Audit JSON</h2>';
		echo '<textarea style="width:100%;height:280px;font-family:Menlo,Consolas,monospace;">' . esc_textarea( $json_pre ) . '</textarea>';
		echo '</div>';
	}

	/* -------------------------------------------------
	 * Settings (adds PDF Output controls)
	 * -------------------------------------------------*/
	public static function render_settings() {
		if ( ! self::user_can_view_settings() ) { wp_die( esc_html__( 'Access denied.', 'satori-audit' ), 403 ); }
		self::admin_badge_css();

		$s          = self::current_settings();
		$nonce      = wp_create_nonce( 'satori_audit_v3_save' );
		$test_nonce = wp_create_nonce( 'satori_audit_v3_test' );
		$inst_nonce = wp_create_nonce( 'satori_audit_v3_install_dompdf' );
		$probe_nonce= wp_create_nonce( 'satori_audit_v3_probe_dompdf' );
		$pdf        = self::dompdf_status();

		if ( isset( $_GET['satori_test'] ) ) {
			$me   = isset($_GET['me']) ? sanitize_text_field( wp_unslash( $_GET['me'] ) ) : '';
			$list = isset($_GET['recips']) ? base64_decode( sanitize_text_field( wp_unslash( $_GET['recips'] ) ) ) : '';
			echo '<div class="notice notice-success"><p><strong>Test email sent to:</strong> '.esc_html($me).'</p><p><strong>Would send to (after safelist):</strong> '.( $list ? esc_html($list) : '<em>none (blocked)</em>' ).'</p></div>';
		}
		if ( isset( $_GET['satori_probe'] ) ) {
			$ok = intval( $_GET['ok'] );
			$msg= isset( $_GET['msg'] ) ? sanitize_text_field( wp_unslash( $_GET['msg'] ) ) : '';
			echo '<div class="notice '.( $ok ? 'notice-success' : 'notice-error' ).'"><p><strong>DOMPDF probe: '
				.( $ok ? 'Success' : 'Failed' ).'</strong>'.( $msg ? ' – '.esc_html($msg) : '' ).'</p></div>';
		}
		if ( isset($_GET['pdf_install']) ) {
			$ok  = intval($_GET['ok']);
			$msg = isset($_GET['msg']) ? sanitize_text_field( wp_unslash($_GET['msg']) ) : '';
			echo '<div class="notice ' . ( $ok ? 'notice-success' : 'notice-error' ) . '"><p><strong>DOMPDF upload '
				. ( $ok ? 'succeeded' : 'failed' ) . '.</strong>' . ( $msg ? ' – ' . esc_html($msg) : '' ) . '</p></div>';
		}

		echo '<div class="wrap">';
		echo '<h1 id="top">⚙️ SATORI Audit – Settings</h1>';
		echo '<p class="description">This audit is an internal process unless you explicitly add recipients below. Badges indicate current status and link to the relevant settings.</p>';

		/* ========= MAIN SETTINGS FORM ========= */
		echo '<form id="satori-settings-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:960px">';
		echo '<input type="hidden" name="action" value="satori_audit_v3_save_settings">';
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '">';

		// Service Details
		echo '<h2>Service Details</h2>';
		echo '<table class="form-table satori-kv">';
		echo '<tr><th>Client</th><td><input name="client" value="' . esc_attr( $s['client'] ) . '" class="regular-text"></td></tr>';
		echo '<tr><th>Site Name</th><td><input name="site_name" value="' . esc_attr( $s['site_name'] ) . '" class="regular-text"></td></tr>';
		echo '<tr><th>Site URL</th><td><input name="site_url" value="' . esc_attr( $s['site_url'] ) . '" class="regular-text"></td></tr>';
		echo '<tr><th>Managed by</th><td><input name="managed_by" value="' . esc_attr( $s['managed_by'] ) . '" class="regular-text"></td></tr>';
		echo '<tr><th>Start Date</th><td><input name="start_date" value="' . esc_attr( $s['start_date'] ) . '" class="regular-text" placeholder="e.g. March 2023"></td></tr>';
		echo '<tr><th>Notes</th><td><textarea name="service_notes" class="large-text" rows="3">' . esc_textarea( $s['service_notes'] ) . '</textarea></td></tr>';
		echo '<tr><th>PDF Header Logo URL</th><td><input name="pdf_logo_url" value="' . esc_attr( $s['pdf_logo_url'] ) . '" class="regular-text" placeholder="https://.../logo.png"></td></tr>';
		echo '</table>';

		// Notifications
		echo '<h2>Notifications</h2>';
		echo '<table class="form-table satori-kv">';
		echo '<tr><th>From Email</th><td><input name="contact_email" value="' . esc_attr( $s['contact_email'] ) . '" class="regular-text"></td></tr>';
		echo '<tr><th>Send Reports To</th><td><input name="notify_emails" value="' . esc_attr( $s['notify_emails'] ) . '" class="regular-text" placeholder="alice@example.com, ops@example.org"></td></tr>';
		echo '<tr><th>Webhook (optional)</th><td><input name="notify_webhook" value="' . esc_attr( $s['notify_webhook'] ) . '" class="regular-text" placeholder="Slack/Teams webhook URL"></td></tr>';
		echo '<tr><th>Suppress WP auto-update emails</th><td><label><input type="checkbox" name="suppress_auto_emails" ' . checked( $s['suppress_auto_emails'], true, false ) . '> Don’t email site admins about core/plugin/theme auto-updates</label></td></tr>';
		echo '</table>';

		// Safelist
		echo '<h2 id="safelist">Recipient Safelist</h2>';
		echo '<div class="satori-badges" style="margin:6px 0 10px">';
		echo '<span class="badge '.( ! empty($s['enforce_safelist']) ? 'badge-ok' : 'badge-warn' ).'">Safelist: '.( ! empty($s['enforce_safelist']) ? 'Enforced' : 'Open' ).'</span>';
		echo '</div>';
		echo '<p class="description">When enforced, emails are sent <strong>only</strong> to addresses matching entries here. Use <code>@example.com</code> or <code>user@example.com</code>. Multiple entries may be comma/space separated.</p>';
		echo '<table class="form-table satori-kv">';
		echo '<tr><th>Enforce Safelist</th><td><label><input type="checkbox" name="enforce_safelist" ' . checked( $s['enforce_safelist'], true, false ) . '> Only send to recipients that match the safelist</label></td></tr>';
		echo '<tr><th>Safelist Entries</th><td><input name="safelist_entries" value="' . esc_attr( $s['safelist_entries'] ) . '" class="regular-text" placeholder="@yourdomain.com, ops@partner.org"></td></tr>';
		echo '</table>';

		// Access Control
		echo '<h2 id="access">Access Control</h2>';
		echo '<p class="description">Restrict who can view SATORI Audit pages. Non-allowed admins will not see these menus.</p>';
		echo '<table class="form-table satori-kv">';
		echo '<tr><th>Restrict Settings page</th><td><label><input type="checkbox" name="restrict_settings" ' . checked( $s['restrict_settings'], true, false ) . '> Only visible to the main admin and/or selected admins</label></td></tr>';
		echo '<tr><th>Restrict Dashboard page</th><td><label><input type="checkbox" name="restrict_dashboard" ' . checked( $s['restrict_dashboard'], true, false ) . '> Also restrict Tools → SATORI Audit</label></td></tr>';
		echo '<tr><th>Main Administrator Email</th><td><input name="primary_admin_email" value="' . esc_attr( $s['primary_admin_email'] ) . '" class="regular-text" placeholder="owner@example.com"><p class="description">This email is always allowed.</p></td></tr>';
		echo '<tr><th>Allowed Admins (CSV)</th><td><input name="allowed_admins" value="' . esc_attr( $s['allowed_admins'] ) . '" class="regular-text" placeholder="jane@example.com, bob, 12"><p class="description">Emails, usernames, or numeric user IDs.</p></td></tr>';
		echo '</table>';

		// Automation
		echo '<h2>Automation</h2>';
		echo '<table class="form-table satori-kv">';
		echo '<tr><th>Monthly PDF Email</th><td><label><input type="checkbox" name="enable_monthly" ' . checked( $s['enable_monthly'], true, false ) . '> Enable</label></td></tr>';
		echo '<tr><th>Daily Watch & Alerts</th><td><label><input type="checkbox" name="enable_watch" ' . checked( $s['enable_watch'], true, false ) . '> Enable</label></td></tr>';
		echo '<tr><th>Force Auto-Updates (plugins)</th><td><label><input type="checkbox" name="force_auto_updates" ' . checked( $s['force_auto_updates'], true, false ) . '> Enable (use with care)</label></td></tr>';
		echo '<tr><th>History Retention</th><td><input name="keep_months" type="number" min="3" max="24" value="' . esc_attr( $s['keep_months'] ) . '"> months</td></tr>';
		echo '</table>';

		// Display + PDF Output
		echo '<h2>Display & PDF Output</h2>';
		echo '<table class="form-table satori-kv">';
		echo '<tr><th>Weekly lines for Core</th><td><label><input type="checkbox" name="weekly_lines_core" ' . checked( $s['weekly_lines_core'], true, false ) . '> Show in Versions & Update Dates</label></td></tr>';
		echo '<tr><th>Weekly lines for Themes</th><td><label><input type="checkbox" name="weekly_lines_themes" ' . checked( $s['weekly_lines_themes'], true, false ) . '> Show for Child/Parent themes</label></td></tr>';
		echo '<tr><th>Backfill weekly lines on first run</th><td><label><input type="checkbox" name="backfill_on_first_run" ' . checked( $s['backfill_on_first_run'], true, false ) . '> Seed last 4 Mondays with current versions</label></td></tr>';
		echo '<tr><th>PDF Page Size</th><td><select name="pdf_page_size">';
		foreach ( array('A4','Letter','Legal') as $size ) {
			echo '<option value="'.$size.'"'.selected($s['pdf_page_size'],$size,false).'>'.$size.'</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th>PDF Orientation</th><td><select name="pdf_orientation">';
		foreach ( array('portrait','landscape') as $o ) {
			echo '<option value="'.$o.'"'.selected($s['pdf_orientation'],$o,false).'>'.ucfirst($o).'</option>';
		}
		echo '</select> <span class="description">Use Landscape for wide plugin tables.</span></td></tr>';
		echo '</table>';

		echo '<p><button class="button button-primary">Save Settings</button></p>';
		echo '</form>'; // END MAIN

		/* ========= PDF ENGINE & DIAGNOSTICS ========= */
		echo '<h2 id="pdf">PDF Engine</h2>';
		$gd_ok      = extension_loaded('gd');
		$mb_ok      = extension_loaded('mbstring');
		$intl_ok    = extension_loaded('intl');
		$imagick_ok = extension_loaded('imagick');
		echo '<div class="satori-badges" style="margin:6px 0 10px">';
		echo '<span class="badge '.( $pdf['available'] ? 'badge-ok' : 'badge-warn' ).'">'.( $pdf['available'] ? 'DOMPDF ready' : 'DOMPDF not found (using HTML/MD)' ).'</span>';
		echo '<span class="badge '.( $gd_ok ? 'badge-ok' : 'badge-err' ).'">GD '.( $gd_ok ? 'enabled' : 'missing' ).'</span>';
		echo '<span class="badge '.( $mb_ok ? 'badge-ok' : 'badge-err' ).'">mbstring '.( $mb_ok ? 'enabled' : 'missing' ).'</span>';
		echo '<span class="badge '.( $intl_ok ? 'badge-ok' : 'badge-info' ).'">intl '.( $intl_ok ? 'enabled' : 'optional' ).'</span>';
		echo '<span class="badge '.( $imagick_ok ? 'badge-ok' : 'badge-info' ).'">Imagick '.( $imagick_ok ? 'enabled' : 'optional' ).'</span>';
		echo '</div>';

		echo '<p class="description">Upload the official <em>packaged</em> DOMPDF ZIP (it contains <code>autoload.inc.php</code>). <strong>Do not use “Source code” zips</strong> from GitHub — those will not work.</p>';
		$max_upload = function_exists('size_format') ? size_format( wp_max_upload_size() ) : ini_get('upload_max_filesize');
		$php_ul = ini_get('upload_max_filesize'); $php_post = ini_get('post_max_size');
		echo '<form id="satori-dompdf-form" method="post" enctype="multipart/form-data" action="' . esc_url( admin_url('admin-post.php') ) . '" style="margin:8px 0 18px">';
		echo '<input type="hidden" name="action" value="satori_audit_v3_install_dompdf">';
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( $inst_nonce ) . '">';
		echo '<input type="file" name="dompdf_zip" accept=".zip,application/zip" required />';
		echo ' <button type="submit" class="button button-primary">Upload DOMPDF ZIP</button> ';
		echo '<a class="button button-link satori-help" target="_blank" rel="noopener" href="https://github.com/dompdf/dompdf/releases/latest">Get the official ZIP</a>';
		echo '</form>';
		echo '<p class="description">Max upload size (WP): <strong>' . esc_html($max_upload) . '</strong> • PHP <code>upload_max_filesize</code>: ' . esc_html($php_ul) . ' • <code>post_max_size</code>: ' . esc_html($php_post) . '</p>';

		// Diagnostics
		$cands = self::dompdf_dir_candidates();
		echo '<h3>Diagnostics</h3>';
		echo '<table class="widefat striped"><thead><tr><th>Checked Path</th><th>autoload.inc.php</th></tr></thead><tbody>';
		foreach ( $cands as $path ) {
			$auto = trailingslashit( $path ) . 'autoload.inc.php';
			$ok   = is_readable( $auto );
			echo '<tr><td class="satori-mono">'.esc_html( $path ).'</td><td>'.( $ok ? '✔︎ found' : '—' ).'</td></tr>';
		}
		echo '</tbody></table>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:10px">';
		echo '<input type="hidden" name="action" value="satori_audit_v3_probe_dompdf">';
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( $probe_nonce ) . '">';
		echo '<button class="button">Probe DOMPDF</button>';
		echo '</form>';

		echo '</div>';
	}

	/* -------------------------------------------------
	 * Dismissible PDF notice
	 * -------------------------------------------------*/
	public static function maybe_notice_dompdf() {
		if ( ! self::user_can_view_settings() ) { return; }
		$pdf = self::dompdf_status();
		if ( ! empty( $pdf['available'] ) ) { return; }
		$uid = get_current_user_id();
		if ( $uid && get_user_meta( $uid, 'satori_audit_v3_hide_pdf_notice', true ) ) { return; }
		$nonce = wp_create_nonce( 'satori_audit_v3_dismiss_pdf_notice' );
		$link  = esc_url( admin_url( 'options-general.php?page=satori-audit-v3-settings#pdf' ) );
		echo '<div class="notice notice-warning is-dismissible satori-dompdf-notice">'
		   . '<p><strong>SATORI Audit:</strong> Native PDF export isn’t enabled yet. '
		   . 'Upload the official DOMPDF ZIP in <a href="'.$link.'">Settings → PDF Engine</a>.</p>'
		   . '<p class="description">Tip: Enable <code>mbstring</code> and <code>gd</code> in your PHP extensions.</p>'
		   . '</div>';
		echo "<script>
		(function(){
			var n=document.querySelector('.satori-dompdf-notice');if(!n)return;
			n.addEventListener('click',function(e){
				if(!e.target.classList.contains('notice-dismiss'))return;
				fetch(ajaxurl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=satori_audit_v3_dismiss_pdf_notice&_wpnonce={$nonce}'});
			});
		})();</script>";
	}
	public static function ajax_dismiss_pdf_notice() {
		check_ajax_referer( 'satori_audit_v3_dismiss_pdf_notice' );
		$uid = get_current_user_id();
		if ( $uid ) { update_user_meta( $uid, 'satori_audit_v3_hide_pdf_notice', 1 ); }
		wp_send_json_success();
	}

	/* -------------------------------------------------
	 * Settings Save (hardened)
	 * -------------------------------------------------*/
	public static function save_settings() {
		self::enforce_settings_access_or_die();
		check_admin_referer( 'satori_audit_v3_save' );

		$in = wp_unslash( $_POST );
		$s  = self::current_settings();

		$bools = array(
			'enable_monthly','enable_watch','force_auto_updates','suppress_auto_emails',
			'weekly_lines_core','weekly_lines_themes','backfill_on_first_run',
			'enforce_safelist','restrict_settings','restrict_dashboard'
		);

		foreach ( array_keys( self::defaults() ) as $k ) {
			if ( array_key_exists( $k, $in ) ) {
				if ( in_array( $k, $bools, true ) ) {
					$s[ $k ] = (bool) $in[ $k ];
				} elseif ( 'keep_months' === $k ) {
					$s[ $k ] = max( 3, min( 24, (int) $in[ $k ] ) );
				} elseif ( 'safelist_entries' === $k ) {
					$raw = is_string( $in[$k] ) ? $in[$k] : '';
					$raw = str_replace( array("\r\n","\r"), "\n", $raw );
					$raw = preg_replace( '/\s+/', ' ', $raw );
					$s[ $k ] = trim( sanitize_textarea_field( $raw ) );
				} elseif ( in_array( $k, array( 'pdf_page_size','pdf_orientation' ), true ) ) {
					$s[ $k ] = sanitize_text_field( $in[$k] );
				} else {
					$s[ $k ] = is_string( $in[$k] ) ? sanitize_text_field( $in[$k] ) : $in[$k];
				}
			} else {
				if ( in_array( $k, $bools, true ) ) { $s[ $k ] = false; }
			}
		}

		update_option( self::OPT_SETTINGS, $s, false );
		wp_safe_redirect( admin_url( 'options-general.php?page=satori-audit-v3-settings&updated=1' ) );
		exit;
	}

	/* -------------------------------------------------
	 * DOMPDF installer / probe (unchanged from 3.7.2)
	 * -------------------------------------------------*/
	public static function handle_install_dompdf() {
		self::enforce_settings_access_or_die();
		check_admin_referer( 'satori_audit_v3_install_dompdf' );
		if ( empty($_FILES['dompdf_zip']) || empty($_FILES['dompdf_zip']['name']) ) {
			wp_safe_redirect( admin_url('options-general.php?page=satori-audit-v3-settings&pdf_install=1&ok=0&msg=' . rawurlencode('No file selected')) . '#pdf' );
			exit;
		}
		$err = intval($_FILES['dompdf_zip']['error']);
		if ( $err ) {
			$map = array(
				1 => 'The uploaded file exceeds php.ini upload_max_filesize',
				2 => 'The uploaded file exceeds the MAX_FILE_SIZE directive',
				3 => 'The uploaded file was only partially uploaded',
				4 => 'No file was uploaded',
				6 => 'Missing a temporary folder',
				7 => 'Failed to write file to disk',
				8 => 'A PHP extension stopped the file upload'
			);
			$msg = isset($map[$err]) ? $map[$err] : 'Upload error';
			wp_safe_redirect( admin_url('options-general.php?page=satori-audit-v3-settings&pdf_install=1&ok=0&msg=' . rawurlencode($msg)) . '#pdf' );
			exit;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$overrides = array( 'test_form' => false, 'mimes' => array( 'zip' => 'application/zip' ) );
		$file = wp_handle_upload( $_FILES['dompdf_zip'], $overrides );
		if ( empty($file['file']) || !empty($file['error']) ) {
			$msg = !empty($file['error']) ? $file['error'] : 'Upload failed';
			wp_safe_redirect( admin_url('options-general.php?page=satori-audit-v3-settings&pdf_install=1&ok=0&msg=' . rawurlencode($msg)) . '#pdf' );
			exit;
		}

		self::ensure_lib_dirs();
		$dest = trailingslashit( self::lib_base_dir() ) . self::LIB_DOMPDF_DIR;

		// Clean any old copy
		if ( is_dir( $dest ) ) { self::rrmdir( $dest ); }

		// Unzip into temp
		$tmp_dir = trailingslashit( self::lib_base_dir() ) . 'tmp_' . wp_generate_password( 8, false );
		wp_mkdir_p( $tmp_dir );
		$result = unzip_file( $file['file'], $tmp_dir );
		@unlink( $file['file'] );
		if ( is_wp_error( $result ) ) {
			self::rrmdir( $tmp_dir );
			wp_safe_redirect( admin_url( 'options-general.php?page=satori-audit-v3-settings#pdf' ) );
			exit;
		}

		$autoload = self::find_file_recursive( $tmp_dir, 'autoload.inc.php' );
		if ( ! $autoload ) {
			self::rrmdir( $tmp_dir );
			wp_safe_redirect( admin_url( 'options-general.php?page=satori-audit-v3-settings&pdf_install=1&ok=0&msg=' . rawurlencode('ZIP did not contain autoload.inc.php (use packaged release, not source code)') . '#pdf' ) );
			exit;
		}
		$root = dirname( $autoload );
		if ( basename( $root ) !== 'dompdf' && is_dir( dirname( $root ) . '/dompdf' ) && is_readable( dirname( $root ) . '/dompdf/autoload.inc.php' ) ) {
			$root = dirname( $root ) . '/dompdf';
		}

		wp_mkdir_p( $dest );
		self::copy_dir_recursive( $root, $dest );
		self::rrmdir( $tmp_dir );

		self::ensure_dompdf_loaded();

		wp_safe_redirect( admin_url( 'options-general.php?page=satori-audit-v3-settings&pdf_install=1&ok=1&msg=' . rawurlencode('Installed to ' . $dest) ) . '#pdf' );
		exit;
	}
	public static function handle_probe_dompdf() {
		self::enforce_settings_access_or_die();
		check_admin_referer( 'satori_audit_v3_probe_dompdf' );
		$msg = ''; $ok = 0;

		if ( ! self::ensure_dompdf_loaded() ) {
			$msg = 'autoload.inc.php not found or Dompdf class unavailable';
		} else {
			try {
				$options = new \Dompdf\Options();
				$options->set( 'isRemoteEnabled', false );
				$dompdf = new \Dompdf\Dompdf( $options );
				$dompdf->loadHtml( '<html><meta charset="utf-8"><body><p>DOMPDF OK</p></body></html>' );
				$dompdf->render();
				$pdf = $dompdf->output();
				if ( $pdf && strlen( $pdf ) > 500 ) { $ok = 1; $msg = 'rendered sample PDF'; }
				else { $msg = 'render produced empty/too small output'; }
			} catch ( \Throwable $e ) {
				$msg = 'exception: ' . $e->getMessage();
			}
		}
		$q = array( 'page'=>'satori-audit-v3-settings','satori_probe'=>1,'ok'=>$ok,'msg'=>substr( $msg, 0, 180 ) );
		wp_safe_redirect( admin_url( 'options-general.php?' . http_build_query( $q ) . '#pdf' ) );
		exit;
	}
	protected static function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) { return; }
		$items = scandir( $dir );
		foreach ( $items as $it ) {
			if ( $it === '.' || $it === '..' ) { continue; }
			$path = $dir . '/' . $it;
			if ( is_dir( $path ) ) { self::rrmdir( $path ); } else { @unlink( $path ); }
		}
		@rmdir( $dir );
	}
	protected static function copy_dir_recursive( $src, $dst ) {
		$src = rtrim( $src, '/' ); $dst = rtrim( $dst, '/' );
		if ( ! is_dir( $src ) ) { return; }
		if ( ! is_dir( $dst ) ) { wp_mkdir_p( $dst ); }
		$dir = opendir( $src );
		while ( false !== ( $file = readdir( $dir ) ) ) {
			if ( $file === '.' || $file === '..' ) { continue; }
			$s = $src . '/' . $file; $d = $dst . '/' . $file;
			if ( is_dir( $s ) ) { self::copy_dir_recursive( $s, $d ); }
			else { @copy( $s, $d ); }
		}
		closedir( $dir );
	}
	protected static function find_file_recursive( $dir, $needle ) {
		$rii = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $rii as $file ) {
			if ( $file->getFilename() === $needle ) { return $file->getPathname(); }
		}
		return false;
	}

	/* -------------------------------------------------
	 * Cron & Email (unchanged)
	 * -------------------------------------------------*/
	public static function ensure_cron() {
		$settings = self::current_settings();
		add_filter( 'cron_schedules', function( $s ) {
			$s['satori_monthly'] = array( 'interval' => 30 * DAY_IN_SECONDS, 'display' => 'Satori Monthly' );
			return $s;
		});
		if ( $settings['enable_monthly'] && ! wp_next_scheduled( self::CRON_HOOK_M ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'satori_monthly', self::CRON_HOOK_M );
		}
		if ( $settings['enable_watch'] && ! wp_next_scheduled( self::CRON_HOOK_D ) ) {
			wp_schedule_event( time() + 2*HOUR_IN_SECONDS, 'daily', self::CRON_HOOK_D );
		}
	}
	public static function run_monthly() {
		$report = self::build_report( true );
		self::email_report( $report, 'monthly' );
		self::trim_history();
	}
	public static function run_daily_watch() {
		$report = self::build_report( true );
		$has_high = false;
		if ( ! empty( $report['bottlenecks'] ) ) {
			foreach ( $report['bottlenecks'] as $b ) {
				if ( isset( $b['severity'] ) && 'HIGH' === $b['severity'] ) { $has_high = true; break; }
			}
		}
		if ( $has_high ) { self::email_report( $report, 'alert' ); }
	}
	public static function after_update( $upgrader, $hook_extra ) {
		self::update_asset_log( $hook_extra );
		if ( empty( $hook_extra['action'] ) || 'update' !== $hook_extra['action'] ) { return; }
		$events = get_option( self::OPT_EVENTS, array() );
		$events[] = array( 'when' => current_time( 'mysql' ), 'what' => $hook_extra );
		update_option( self::OPT_EVENTS, array_slice( $events, -50 ) );
		$report = self::build_report( true );
		$send = false;
		if ( ! empty( $report['bottlenecks'] ) ) {
			foreach ( $report['bottlenecks'] as $b ) {
				if ( in_array( $b['type'], array( 'dup_cache','file_manager' ), true ) || 'HIGH' === $b['severity'] ) { $send = true; break; }
			}
		}
		if ( $send ) { self::email_report( $report, 'post-update' ); }
	}
	public static function maybe_force_auto_updates( $should, $item ) {
		$s = self::current_settings();
		return $s['force_auto_updates'] ? true : $should;
	}
	public static function suppress_plugin_email( $send, $plugin, $result ) {
		$s = self::current_settings();
		return $s['suppress_auto_emails'] ? false : $send;
	}
	public static function suppress_theme_email( $send, $theme, $result ) {
		$s = self::current_settings();
		return $s['suppress_auto_emails'] ? false : $send;
	}
	public static function suppress_core_email( $send, $type, $core_update, $result ) {
		$s = self::current_settings();
		return $s['suppress_auto_emails'] ? false : $send;
	}

	/* -------------------------------------------------
	 * Build Report + helpers
	 * -------------------------------------------------*/
	public static function build_report( $persist = false ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$s   = self::current_settings();
		$server_soft  = isset( $_SERVER['SERVER_SOFTWARE'] ) ? (string) $_SERVER['SERVER_SOFTWARE'] : '';
		$is_litespeed = stripos( $server_soft, 'litespeed' ) !== false;
		$theme        = wp_get_theme();
		$parent       = $theme && $theme->parent() ? $theme->parent() : null;
		$permalinks   = get_option( 'permalink_structure' );

		$active_plugins = (array) get_option( 'active_plugins', array() );
		$all_plugins    = function_exists( 'get_plugins' ) ? get_plugins() : array();
		$active_list    = array();
		foreach ( $active_plugins as $path ) {
			$label  = isset( $all_plugins[ $path ]['Name'] ) ? $all_plugins[ $path ]['Name'] : $path;
			$ver    = isset( $all_plugins[ $path ]['Version'] ) ? $all_plugins[ $path ]['Version'] : '';
			$desc   = isset( $all_plugins[ $path ]['Description'] ) ? $all_plugins[ $path ]['Description'] : '';
			$active_list[] = array(
				'slug' => dirname( $path ),
				'path' => $path,
				'name' => $label,
				'version' => $ver,
				'description' => $desc,
				'description_short' => self::short_desc( $desc ),
			);
		}

		$htaccess_lscache = false;
		$ht = ABSPATH . '.htaccess';
		if ( is_readable( $ht ) ) {
			$raw = @file_get_contents( $ht );
			if ( $raw && preg_match( '/#\s*BEGIN\s*LSCACHE(.+?)#\s*END\s*LSCACHE/s', (string) $raw ) ) { $htaccess_lscache = true; }
		}

		$lsc_conf = get_option( 'litespeed.conf', array() );
		$lsc = array(
			'active'         => is_plugin_active( 'litespeed-cache/litespeed-cache.php' ) || class_exists( 'LiteSpeed\\Core' ),
			'is_litespeed'   => $is_litespeed,
			'htaccess_block' => $htaccess_lscache,
			'css_min'        => (bool) ( isset( $lsc_conf['css_min'] ) ? $lsc_conf['css_min'] : false ),
			'css_combine'    => (bool) ( isset( $lsc_conf['css_combine'] ) ? $lsc_conf['css_combine'] : false ),
			'css_ucss'       => (bool) ( isset( $lsc_conf['css_ucss'] ) ? $lsc_conf['css_ucss'] : false ),
			'js_min'         => (bool) ( isset( $lsc_conf['js_min'] ) ? $lsc_conf['js_min'] : false ),
			'js_defer'       => (bool) ( isset( $lsc_conf['js_defer'] ) ? $lsc_conf['js_defer'] : false ),
			'js_delay'       => (bool) ( isset( $lsc_conf['js_defer_js'] ) ? $lsc_conf['js_defer_js'] : false ),
			'object_cache'   => (bool) ( isset( $lsc_conf['object'] ) ? $lsc_conf['object'] : false ),
			'crawler'        => (bool) ( isset( $lsc_conf['crawler'] ) ? $lsc_conf['crawler'] : false ),
			'img_optm'       => (bool) ( isset( $lsc_conf['img_optm'] ) ? $lsc_conf['img_optm'] : false ),
			'img_webp'       => (bool) ( isset( $lsc_conf['img_webp'] ) ? $lsc_conf['img_webp'] : false ),
		);

		$headers   = array();
		$cache_hdr = null;
		$cdn_hint  = null;
		$res = wp_remote_get( home_url( '/' ), array( 'timeout' => 8, 'redirection' => 2 ) );
		if ( ! is_wp_error( $res ) ) {
			$headers   = array_change_key_case( (array) wp_remote_retrieve_headers( $res ), CASE_LOWER );
			$cache_hdr = isset( $headers['x-litespeed-cache'] ) ? $headers['x-litespeed-cache'] : null;
			if ( isset( $headers['cf-cache-status'] ) ) { $cdn_hint = 'cloudflare'; }
			if ( isset( $headers['x-qc-pop'] ) || isset( $headers['x-qc-cache'] ) ) { $cdn_hint = 'quic.cloud'; }
		}

		$users_count = function_exists( 'count_users' ) ? count_users() : array( 'avail_roles' => array() );
		$admins      = (int) ( isset( $users_count['avail_roles']['administrator'] ) ? $users_count['avail_roles']['administrator'] : 0 );
		$xmlrpc_on   = (bool) apply_filters( 'xmlrpc_enabled', true );
		$file_edit   = defined( 'DISALLOW_FILE_EDIT' ) ? (bool) DISALLOW_FILE_EDIT : false;

		$updates = array( 'plugins' => 0, 'themes' => 0, 'core' => null );
		$up_plugins = get_site_transient( 'update_plugins' );
		if ( ! empty( $up_plugins->response ) ) { $updates['plugins'] = count( $up_plugins->response ); }
		$up_themes = get_site_transient( 'update_themes' );
		if ( ! empty( $up_themes->response ) ) { $updates['themes'] = count( $up_themes->response ); }

		$bottlenecks = self::detect_bottlenecks( $active_list, $lsc, $headers );
		if ( empty( $permalinks ) ) {
			$bottlenecks[] = array( 'type'=>'permalinks_plain', 'severity'=>'HIGH', 'msg'=>'Permalinks are “Plain”. Use /%postname%/.' );
		} elseif ( strpos( $permalinks, '%postname%' ) === false ) {
			$bottlenecks[] = array( 'type'=>'permalinks_nonpostname', 'severity'=>'LOW', 'msg'=>'Consider /%postname%/ for SEO-friendly URLs.' );
		}

		$suggestions = self::suggestions( $lsc, $bottlenecks, $cdn_hint );
		$scores = self::scores( array(
			'admins' => $admins,
			'xmlrpc_on' => $xmlrpc_on,
			'file_edit' => $file_edit,
			'updates' => $updates,
			'cache_hdr' => $cache_hdr,
			'lsc_flags' => $lsc,
			'bottlenecks' => $bottlenecks,
		));

		$core_version   = get_bloginfo( 'version' );
		$theme_obj      = wp_get_theme();
		$child_slug     = $theme_obj ? $theme_obj->get_stylesheet() : '';
		$child_version  = $theme_obj ? $theme_obj->get( 'Version' ) : '';
		$child_name     = $theme_obj ? $theme_obj->get( 'Name' ) : 'n/a';
		$parent_obj     = $theme_obj && $theme_obj->parent() ? $theme_obj->parent() : null;
		$parent_slug    = $parent_obj ? $parent_obj->get_template() : '';
		$parent_version = $parent_obj ? $parent_obj->get( 'Version' ) : '';
		$parent_name    = $parent_obj ? $parent_obj->get( 'Name' ) : 'n/a';

		$asset_log = get_option( self::OPT_ASSET_LOG, array() );
		$versions = array(
			'core'   => array( 'version' => $core_version,   'updated_on' => self::asset_last_updated_human( 'core', 'wordpress', $asset_log ) ),
			'child'  => array( 'name' => $child_name,  'slug' => $child_slug,  'version' => $child_version,  'updated_on' => self::asset_last_updated_human( 'theme', $child_slug, $asset_log ) ),
			'parent' => array( 'name' => $parent_name, 'slug' => $parent_slug, 'version' => $parent_version, 'updated_on' => self::asset_last_updated_human( 'theme', $parent_slug, $asset_log ) ),
		);

		$vuln_summary = apply_filters( 'satori_audit_v3_vuln_summary', null );
		if ( null === $vuln_summary ) { $vuln_summary = apply_filters( 'satori_audit_v2_vuln_summary', 'Not integrated' ); }
		$vuln_last = apply_filters( 'satori_audit_v3_vuln_last_run', null );
		if ( null === $vuln_last ) { $vuln_last = apply_filters( 'satori_audit_v2_vuln_last_run', null ); }

		$report = array(
			'meta' => array(
				'generated_at'   => gmdate( 'c' ),
				'plugin_version' => '3.7.3',
			),
			'service_details' => array(
				'client'      => $s['client'],
				'site_name'   => $s['site_name'],
				'site_url'    => $s['site_url'],
				'managed_by'  => $s['managed_by'],
				'start_date'  => $s['start_date'],
				'service_date'=> date_i18n( 'F Y' ),
				'notes'       => $s['service_notes'],
			),
			'overview' => array(
				'wordpress'  => $core_version,
				'php'        => PHP_VERSION,
				'server'     => $server_soft,
				'https'      => is_ssl(),
				'permalinks' => $permalinks,
				'theme'      => array( 'name' => $child_name, 'version' => $child_version ),
			),
			'versions' => $versions,
			'security' => array(
				'admins'              => $admins,
				'disallow_file_edit'  => $file_edit,
				'xmlrpc_enabled'      => $xmlrpc_on,
				'htaccess_lscache'    => $htaccess_lscache,
				'vuln_scan_summary'   => $vuln_summary,
				'vuln_scan_last_run'  => $vuln_last,
			),
			'optimization' => array(
				'litespeed'     => $lsc,
				'cdn'           => $cdn_hint,
				'object_cache'  => function_exists('wp_using_ext_object_cache') ? wp_using_ext_object_cache() : false,
			),
			'speed' => array(
				'x_litespeed_cache' => $cache_hdr,
				'http3_hint'        => ( isset( $headers['alt-svc'] ) && stripos( $headers['alt-svc'], 'h3' ) !== false ),
			),
			'stability' => array(
				'updates'        => $updates,
				'active_plugins' => $active_list,
			),
			'bottlenecks'    => $bottlenecks,
			'suggestions'    => self::suggestions( $lsc, $bottlenecks, $cdn_hint ),
			'scores'         => $scores,
			'asset_log'      => $asset_log
		);

		$month   = date_i18n( 'Y-m' );
		$history = get_option( self::OPT_HISTORY, array() );
		$prev    = self::latest_history_before( $history, $month );
		$report['plugin_diffs'] = self::plugin_diffs( isset( $prev['plugins'] ) ? $prev['plugins'] : array(), $active_list );

		if ( $persist ) {
			$history[ $month ] = array(
				'created' => current_time( 'mysql' ),
				'scores'  => $scores,
				'plugins' => self::plugins_map( $active_list ),
				'overview'=> $report['overview'],
			);
			update_option( self::OPT_HISTORY, $history );
		}

		if ( empty( $asset_log ) ) {
			$legacy = get_option( self::OPT_PLUGIN_LOG, array() );
			if ( ! empty( $legacy ) ) { $report['legacy_plugin_log'] = $legacy; }
		}

		return $report;
	}

	protected static function detect_bottlenecks( $active_list, $lsc, $headers ) {
		$slugs = array(); $names = array();
		foreach ( $active_list as $p ) { $slugs[] = strtolower( $p['slug'] ); $names[] = strtolower( $p['name'] ); }
		$has = function( $needle ) use ( $slugs, $names ) {
			foreach ( $slugs as $s ) { if ( false !== stripos( $s, $needle ) ) { return true; } }
			foreach ( $names as $n ) { if ( false !== stripos( $n, $needle ) ) { return true; } }
			return false;
		};

		$issues = array();
		$seo    = array( 'yoast','wordpress-seo','aioseo','rank-math','seopress' );
		$caches = array( 'litespeed','wp-rocket','w3-total-cache','wp-super-cache','wp-optimize','hummingbird' );
		$imgs   = array( 'imagify','shortpixel','ewww','smush' );

		$seo_c = 0; foreach ( $seo as $k ) { if ( $has($k) ) { $seo_c++; } }
		if ( $seo_c > 1 ) { $issues[] = array( 'type'=>'dup_seo', 'severity'=>'MEDIUM', 'msg'=>'Multiple SEO plugins. Keep exactly one.' ); }

		$cache_c = 0; foreach ( $caches as $k ) { if ( $has($k) ) { $cache_c++; } }
		if ( $cache_c > 1 ) { $issues[] = array( 'type'=>'dup_cache', 'severity'=>'HIGH', 'msg'=>'Multiple cache/optimizer plugins. Use LiteSpeed alone.' ); }

		$img_c = 0; foreach ( $imgs as $k ) { if ( $has($k) ) { $img_c++; } }
		if ( $img_c >= 1 && ! empty( $lsc['img_optm'] ) ) {
			$issues[] = array( 'type'=>'dup_image_opt', 'severity'=>'MEDIUM', 'msg'=>'External image optimizer + LSCWP present. Choose one.' );
		}

		if ( $has('file-manager') || $has('file manager advanced') ) {
			$issues[] = array( 'type'=>'file_manager', 'severity'=>'HIGH', 'msg'=>'File Manager active on production. Remove or strictly limit.' );
		}
		if ( isset( $headers['cf-cache-status'] ) && ! empty( $lsc['active'] ) ) {
			$issues[] = array( 'type'=>'cdn_double', 'severity'=>'LOW', 'msg'=>'Cloudflare + LSCWP: ensure rules don’t double-optimize HTML.' );
		}

		return $issues;
	}
	protected static function suggestions( $lsc, $issues, $cdn_hint ) {
		$s = array();
		if ( ! empty( $lsc['active'] ) ) {
			if ( ! $lsc['css_min'] )   { $s[] = 'Enable CSS Minify (LSCWP).'; }
			if ( $lsc['css_combine'] ) { $s[] = 'Disable CSS Combine (HTTP/2+).'; }
			if ( ! $lsc['js_min'] )    { $s[] = 'Enable JS Minify; also Defer + Delay where safe.'; }
			if ( ! $lsc['css_ucss'] )  { $s[] = 'Enable UCSS (Critical CSS via QUIC.cloud).'; }
			if ( ! $lsc['object_cache'] ) { $s[] = 'Enable Object Cache (Redis) if available.'; }
			if ( ! $lsc['img_optm'] )  { $s[] = 'Enable Image Optimization in LSCWP (or remove overlap).'; }
			if ( ! $lsc['img_webp'] )  { $s[] = 'Serve WebP/AVIF via LSCWP rewrites.'; }
			if ( $lsc['crawler'] )     { $s[] = 'Disable the Crawler unless on a strong server.'; }
		} else {
			$s[] = 'Install/activate LiteSpeed Cache and enable page caching.';
		}
		if ( ! empty( $issues ) ) {
			foreach ( $issues as $i ) {
				if ( 'dup_seo' === $i['type'] )   { $s[] = 'Deactivate extra SEO plugin(s).'; }
				if ( 'dup_cache' === $i['type'] ) { $s[] = 'Deactivate non-LiteSpeed cache/optimizer plugins.'; }
				if ( 'dup_image_opt' === $i['type'] ) { $s[] = 'Use ONE image optimizer only.'; }
				if ( 'file_manager' === $i['type'] )  { $s[] = 'Remove File Manager; use SFTP/host panel.'; }
				if ( 'cdn_double' === $i['type'] )    { $s[] = 'Check CDN page rules vs LSCWP headers.'; }
			}
		}
		if ( 'quic.cloud' === $cdn_hint ) { $s[] = 'Ensure HTTP/3 is enabled; avoid duplicate optimizations across layers.'; }
		$u = array(); foreach ( $s as $line ) { $u[ $line ] = true; } return array_keys( $u );
	}
	protected static function scores( $ctx ) {
		$sec  = 0; $sec += $ctx['admins'] <= 3 ? 2 : ( $ctx['admins'] <= 5 ? 1 : 0 );
		$sec += $ctx['file_edit'] ? 2 : 0; $sec += $ctx['xmlrpc_on'] ? 0 : 2; $sec += 2; $sec = min( 10, $sec );
		$opt  = 0;
		$opt += ! empty( $ctx['lsc_flags']['active'] ) ? 2 : 0;
		$opt += ! empty( $ctx['lsc_flags']['object_cache'] ) ? 2 : 0;
		$opt += ! empty( $ctx['lsc_flags']['css_min'] ) ? 1 : 0;
		$opt += ! empty( $ctx['lsc_flags']['js_min'] ) ? 1 : 0;
		$opt += ! empty( $ctx['lsc_flags']['css_ucss'] ) ? 2 : 0;
		$opt += ( ! empty( $ctx['lsc_flags']['js_defer'] ) || ! empty( $ctx['lsc_flags']['js_delay'] ) ) ? 2 : 0;
		$opt = min( 10, $opt );
		$speed  = 0;
		$speed += ( $ctx['cache_hdr'] === 'hit' ) ? 4 : ( $ctx['cache_hdr'] ? 2 : 0 );
		$speed += ! empty( $ctx['lsc_flags']['img_webp'] ) ? 2 : 0;
		$speed += ! empty( $ctx['lsc_flags']['img_optm'] ) ? 2 : 0;
		$speed += ! empty( $ctx['lsc_flags']['css_ucss'] ) ? 2 : 0;
		$speed = min( 10, $speed );
		$stab  = 10; $stab -= (int) ( $ctx['updates']['plugins'] > 0 ) * 2; $stab -= (int) ( $ctx['updates']['themes'] > 0 ) * 1;
		if ( ! empty( $ctx['bottlenecks'] ) ) { foreach ( $ctx['bottlenecks'] as $b ) { if ( 'HIGH' === $b['severity'] ) { $stab -= 3; } if ( 'MEDIUM' === $b['severity'] ) { $stab -= 1; } } }
		$stab = max( 0, min( 10, $stab ) );
		return array( 'security'=>$sec, 'optimization'=>$opt, 'speed'=>$speed, 'stability'=>$stab, 'total'=>$sec+$opt+$speed+$stab );
	}
	protected static function build_summary( $r ) { return array( 'key_actions' => array_slice( $r['suggestions'], 0, 8 ) ); }
	protected static function plugins_map( $list ) { $m = array(); foreach ( $list as $p ) { $m[ $p['slug'] ] = $p['version']; } return $m; }
	protected static function latest_history_before( $history, $month_ym ) { if ( empty( $history ) ) { return array(); } krsort( $history ); foreach ( $history as $ym => $e ) { if ( $ym < $month_ym ) { return $e; } } return array(); }
	protected static function plugin_diffs( $prev_map, $curr_list ) {
		$diffs=array(); $curr_map=self::plugins_map($curr_list); $seen=array();
		foreach ( $curr_map as $slug=>$ver ) { $seen[$slug]=true; if ( ! isset($prev_map[$slug]) ) { $diffs[]=array('slug'=>$slug,'change'=>'NEW','from'=>null,'to'=>$ver); } elseif ( $prev_map[$slug]!==$ver ) { $diffs[]=array('slug'=>$slug,'change'=>'UPDATED','from'=>$prev_map[$slug],'to'=>$ver); } }
		foreach ( $prev_map as $slug=>$ver ) { if ( ! isset($seen[$slug]) ) { $diffs[]=array('slug'=>$slug,'change'=>'DELETED','from'=>$ver,'to'=>null); } }
		return $diffs;
	}

	// Version prefix helper
	protected static function format_version( $v ) {
		$v = trim( (string) $v );
		if ( '' === $v ) { return $v; }
		if ( $v[0] === 'v' || $v[0] === 'V' ) { return $v; }
		return 'v' . $v;
	}

	protected static function update_asset_log( $hook_extra ) {
		$log  = get_option( self::OPT_ASSET_LOG, array() );
		$keep = (int) ( self::current_settings()['keep_months'] );
		$today = date_i18n( 'Y-m-d' );
		$log += array( 'plugins'=>array(), 'themes'=>array(), 'core'=>array() );
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$type = isset( $hook_extra['type'] ) ? $hook_extra['type'] : '';
		if ( 'plugin' === $type ) {
			$targets = array();
			if ( ! empty( $hook_extra['plugin'] ) )  { $targets[] = $hook_extra['plugin']; }
			if ( ! empty( $hook_extra['plugins'] ) ) { $targets = array_merge( $targets, $hook_extra['plugins'] ); }
			$all = function_exists( 'get_plugins' ) ? get_plugins() : array();
			foreach ( array_unique( $targets ) as $path ) {
				if ( empty( $all[ $path ] ) ) { continue; }
				$slug = dirname( $path ); $ver = isset( $all[ $path ]['Version'] ) ? $all[ $path ]['Version'] : '';
				if ( empty( $log['plugins'][ $slug ] ) ) { $log['plugins'][ $slug ] = array( 'last_updated'=>$today, 'history'=>array() ); }
				$log['plugins'][ $slug ]['last_updated'] = $today;
				$log['plugins'][ $slug ]['history'][]    = array( 'date'=>$today, 'to_version'=>$ver );
				if ( count( $log['plugins'][ $slug ]['history'] ) > $keep ) { $log['plugins'][ $slug ]['history'] = array_slice( $log['plugins'][ $slug ]['history'], -$keep ); }
			}
		} elseif ( 'theme' === $type ) {
			$targets = array();
			if ( ! empty( $hook_extra['theme'] ) )  { $targets[] = $hook_extra['theme'] ; }
			if ( ! empty( $hook_extra['themes'] ) ) { $targets = array_merge( $targets, $hook_extra['themes'] ); }
			foreach ( array_unique( $targets ) as $slug ) {
				$th = wp_get_theme( $slug ); if ( ! $th || ! $th->exists() ) { continue; }
				$ver = $th->get( 'Version' );
				if ( empty( $log['themes'][ $slug ] ) ) { $log['themes'][ $slug ] = array( 'last_updated'=>$today, 'history'=>array() ); }
				$log['themes'][ $slug ]['last_updated'] = $today;
				$log['themes'][ $slug ]['history'][]    = array( 'date'=>$today, 'to_version'=>$ver );
				if ( count( $log['themes'][ $slug ]['history'] ) > $keep ) { $log['themes'][ $slug ]['history'] = array_slice( $log['themes'][ $slug ]['history'], -$keep ); }
			}
		} elseif ( 'core' === $type ) {
			$ver = get_bloginfo( 'version' );
			if ( empty( $log['core']['wordpress'] ) ) { $log['core']['wordpress'] = array( 'last_updated'=>$today, 'history'=>array() ); }
			$log['core']['wordpress']['last_updated'] = $today;
			$log['core']['wordpress']['history'][]    = array( 'date'=>$today, 'to_version'=>$ver );
			if ( count( $log['core']['wordpress']['history'] ) > $keep ) { $log['core']['wordpress']['history'] = array_slice( $log['core']['wordpress']['history'], -$keep ); }
		}
		update_option( self::OPT_ASSET_LOG, $log, false );
	}
	protected static function asset_last_updated_human( $type, $slug, $asset_log = null ) {
		if ( null === $asset_log ) { $asset_log = get_option( self::OPT_ASSET_LOG, array() ); }
		$asset_log += array( 'plugins'=>array(), 'themes'=>array(), 'core'=>array() );
		if ( 'plugin' === $type && isset( $asset_log['plugins'][ $slug ]['last_updated'] ) ) {
			$t = strtotime( $asset_log['plugins'][ $slug ]['last_updated'] ); return $t ? date_i18n( 'd/m/Y', $t ) : '';
		}
		if ( 'theme' === $type && isset( $asset_log['themes'][ $slug ]['last_updated'] ) ) {
			$t = strtotime( $asset_log['themes'][ $slug ]['last_updated'] ); return $t ? date_i18n( 'd/m/Y', $t ) : '';
		}
		if ( 'core' === $type && isset( $asset_log['core']['wordpress']['last_updated'] ) ) {
			$t = strtotime( $asset_log['core']['wordpress']['last_updated'] ); return $t ? date_i18n( 'd/m/Y', $t ) : '';
		}
		if ( 'plugin' === $type ) {
			$legacy = get_option( self::OPT_PLUGIN_LOG, array() );
			if ( ! empty( $legacy[ $slug ]['last_updated'] ) ) { $t = strtotime( $legacy[ $slug ]['last_updated'] ); return $t ? date_i18n( 'd/m/Y', $t ) : ''; }
		}
		return '';
	}
	protected static function get_asset_history( $type, $slug_or_key, $asset_log ) {
		$asset_log += array( 'plugins'=>array(), 'themes'=>array(), 'core'=>array() );
		if ( 'plugin' === $type )      { $hist = isset( $asset_log['plugins'][ $slug_or_key ]['history'] ) ? $asset_log['plugins'][ $slug_or_key ]['history'] : array(); }
		elseif ( 'theme' === $type )   { $hist = isset( $asset_log['themes'][ $slug_or_key ]['history'] ) ? $asset_log['themes'][ $slug_or_key ]['history'] : array(); }
		elseif ( 'core' === $type )    { $hist = isset( $asset_log['core'][ $slug_or_key ]['history'] ) ? $asset_log['core'][ $slug_or_key ]['history'] : array(); }
		else { $hist = array(); }
		$out = array();
		foreach ( $hist as $h ) {
			$d = isset( $h['date'] ) ? $h['date'] : ''; $v = isset( $h['to_version'] ) ? $h['to_version'] : '';
			if ( '' === $d ) { continue; }
			$ts = strtotime( $d . ' 00:00:00' ); if ( ! $ts ) { continue; }
			$out[] = array( 'date'=>$d, 'ts'=>$ts, 'to_version'=>$v );
		}
		usort( $out, function( $a, $b ) { if ( $a['ts'] === $b['ts'] ) { return 0; } return ( $a['ts'] < $b['ts'] ) ? -1 : 1; });
		return $out;
	}
	protected static function version_delta_label( $prev, $curr ) {
		if ( ! $prev || ! $curr ) { return ''; }
		$pa = self::parse_semver( $prev ); $ca = self::parse_semver( $curr );
		if ( empty( $pa ) || empty( $ca ) ) { return ''; }
		if ( $pa['major'] === $ca['major'] && $pa['minor'] === $ca['minor'] ) {
			$diff = $ca['patch'] - $pa['patch']; if ( $diff > 0 ) { return ' (+' . $diff . ')'; }
		}
		return '';
	}
	protected static function parse_semver( $v ) {
		$v = trim( (string) $v ); if ( $v === '' ) { return null; }
		$parts = preg_split( '/[^\d]+/', $v );
		if ( ! $parts || ! is_array( $parts ) ) { return null; }
		return array( 'major'=>isset($parts[0])?(int)$parts[0]:0, 'minor'=>isset($parts[1])?(int)$parts[1]:0, 'patch'=>isset($parts[2])?(int)$parts[2]:0 );
	}
	protected static function asset_weekly_lines_any( $type, $slug_or_key, $max_lines = 5, $asset_log = null ) {
		if ( null === $asset_log ) { $asset_log = get_option( self::OPT_ASSET_LOG, array() ); }
		$hist_all = self::get_asset_history( $type, $slug_or_key, $asset_log );
		if ( empty( $hist_all ) ) { return array(); }
		$changes = array(); $last_ver = null;
		foreach ( $hist_all as $e ) {
			$ver = $e['to_version'];
			if ( $last_ver === null || $ver !== $last_ver ) { $changes[] = array( 'ts'=>$e['ts'], 'date'=>$e['date'], 'to_version'=>$ver, 'prev'=>$last_ver ); $last_ver = $ver; }
		}
		if ( empty( $changes ) ) { return array(); }
		$since_ts = strtotime( '-35 days', current_time( 'timestamp' ) );
		$by_week = array();
		foreach ( $changes as $c ) {
			if ( $c['ts'] < $since_ts ) { continue; }
			$weekday = (int) date_i18n( 'N', $c['ts'] );
			$wstart  = $c['ts'] - ( ( $weekday - 1 ) * DAY_IN_SECONDS );
			$key     = date_i18n( 'Y-m-d', $wstart );
			if ( empty( $by_week[ $key ] ) || $by_week[ $key ]['ts'] < $c['ts'] ) { $by_week[ $key ] = $c; }
		}
		if ( empty( $by_week ) ) { return array(); }

		// If only one week had an update, return empty to let the UI show a plain date (your request)
		if ( count( $by_week ) <= 1 ) { return array(); }

		krsort( $by_week );
		$lines = array();
		foreach ( $by_week as $week_start => $c ) {
			$wk    = date_i18n( 'd M', strtotime( $week_start ) );
			$when  = date_i18n( 'd/m', $c['ts'] );
			$delta = self::version_delta_label( $c['prev'], $c['to_version'] );
			$lines[] = sprintf( 'Wk of %s: %s%s (%s)', $wk, self::format_version($c['to_version']), $delta, $when );
			if ( count( $lines ) >= $max_lines ) { break; }
		}
		return $lines;
	}

	/* -------------------------------------------------
	 * Export & Email
	 * -------------------------------------------------*/
	public static function handle_download() {
		if ( ! self::user_can_view_dashboard() ) { wp_die( esc_html__( 'Access denied.', 'satori-audit' ), 403 ); }
		check_admin_referer( 'satori_audit_v3_download' );
		$format = sanitize_text_field( isset( $_POST['format'] ) ? $_POST['format'] : 'pdf' );
		$report = self::build_report();

		$s = self::current_settings();
		$page_size   = in_array( $s['pdf_page_size'], array('A4','Letter','Legal'), true ) ? $s['pdf_page_size'] : 'A4';
		$orientation = in_array( $s['pdf_orientation'], array('portrait','landscape'), true ) ? $s['pdf_orientation'] : 'portrait';

		// Per-download overrides
		if ( $format === 'pdf_p' ) { $orientation = 'portrait'; $format = 'pdf'; }
		if ( $format === 'pdf_l' ) { $orientation = 'landscape'; $format = 'pdf'; }

		switch ( $format ) {
			case 'json':
				nocache_headers(); header( 'Content-Type: application/json; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename="satori-audit-'.date('Ymd').'.json"' );
				echo wp_json_encode( $report, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ); exit;
			case 'csv_plugins':
				$csv = self::csv_plugins( $report );
				nocache_headers(); header( 'Content-Type: text/csv; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename="satori-plugins-'.date('Ymd').'.csv"' );
				echo $csv; exit;
			case 'markdown':
				$md = self::markdown( $report );
				nocache_headers(); header( 'Content-Type: text/markdown; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename="satori-audit-'.date('Ymd').'.md"' );
				echo $md; exit;
			case 'html_preview':
				$html = self::html_report( $report, $page_size, $orientation );
				nocache_headers(); header( 'Content-Type: text/html; charset=utf-8' );
				// Inline preview (no attachment)
				echo $html; exit;
			default: // pdf
				$html = self::html_report( $report, $page_size, $orientation );
				$pdf  = self::html_to_pdf( $html, $page_size, $orientation );
				if ( $pdf ) {
					nocache_headers(); header( 'Content-Type: application/pdf' );
					header( 'Content-Disposition: attachment; filename="satori-audit-'.date('Ymd').'.pdf"' );
					echo $pdf; exit;
				} else {
					nocache_headers(); header( 'Content-Type: text/html; charset=utf-8' );
					header( 'Content-Disposition: attachment; filename="satori-audit-'.date('Ymd').'.html"' );
					echo $html; exit;
				}
		}
	}
	public static function mail_content_type_plain() { return 'text/plain'; }
	protected static function email_report( $report, $reason = 'monthly' ) {
		$s = self::current_settings();
		if ( empty( $s['notify_emails'] ) ) { return; }
		$to   = array_filter( array_map( 'trim', explode( ',', $s['notify_emails'] ) ) );
		if ( empty( $to ) ) { return; }
		$to = self::safelist_filter_emails( $to, $s );
		if ( empty( $to ) ) { return; }

		$subj = sprintf( '[SATORI Audit] %s – %s', $report['service_details']['site_name'], ucfirst( $reason ) );
		$body = "Attached: service log (PDF/MD/JSON).\n\nSummary:\n- Total score: ".$report['scores']['total']."/40\n- Key actions:\n  • ".implode( "\n  • ", array_slice( $report['suggestions'], 0, 6 ) );

		// Use settings for default export layout
		$page_size   = in_array( $s['pdf_page_size'], array('A4','Letter','Legal'), true ) ? $s['pdf_page_size'] : 'A4';
		$orientation = in_array( $s['pdf_orientation'], array('portrait','landscape'), true ) ? $s['pdf_orientation'] : 'portrait';

		$html = self::html_report( $report, $page_size, $orientation );
		$pdf  = self::html_to_pdf( $html, $page_size, $orientation );
		$tmp  = array();
		if ( $pdf ) { $path = wp_tempnam( 'satori-audit.pdf' ); file_put_contents( $path, $pdf ); $tmp[] = $path; }
		$md = self::markdown( $report ); $mdp = wp_tempnam( 'satori-audit.md' ); file_put_contents( $mdp, $md ); $tmp[] = $mdp;
		$js = wp_json_encode( $report, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ); $jsp = wp_tempnam( 'satori-audit.json' ); file_put_contents( $jsp, $js ); $tmp[] = $jsp;

		$headers = array( 'From: '.$s['contact_email'] );
		add_filter( 'wp_mail_content_type', array( __CLASS__, 'mail_content_type_plain' ) );
		wp_mail( $to, $subj, $body, $headers, $tmp );
		remove_filter( 'wp_mail_content_type', array( __CLASS__, 'mail_content_type_plain' ) );

		if ( ! empty( $s['notify_webhook'] ) ) {
			wp_remote_post( $s['notify_webhook'], array(
				'timeout' => 5,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array(
					'text'  => $subj."\n".$body,
					'score' => $report['scores']['total'],
					'site'  => $report['service_details']['site_url'],
					'reason'=> $reason,
				) ),
			) );
		}
		foreach ( $tmp as $p ) { @unlink( $p ); }
	}
	protected static function html_to_pdf( $html, $page_size = 'A4', $orientation = 'portrait' ) {
		if ( ! self::ensure_dompdf_loaded() ) { return false; }
		try {
			$options = new \Dompdf\Options();
			$options->set( 'isRemoteEnabled', true );
			$options->set( 'isHtml5ParserEnabled', true );
			$dompdf = new \Dompdf\Dompdf( $options );
			$dompdf->loadHtml( $html, 'UTF-8' );
			$dompdf->setPaper( $page_size, $orientation );
			$dompdf->render();
			return $dompdf->output();
		} catch ( \Throwable $e ) { return false; }
	}

	// Load DOMPDF from any viable location
	protected static function ensure_dompdf_loaded() {
		if ( class_exists( '\\Dompdf\\Dompdf' ) ) { return true; }
		foreach ( self::dompdf_dir_candidates() as $base ) {
			$autoload = trailingslashit( $base ) . 'autoload.inc.php';
			if ( is_readable( $autoload ) ) {
				require_once $autoload;
				if ( class_exists( '\\Dompdf\\Autoloader' ) && method_exists( '\\Dompdf\\Autoloader', 'register' ) ) {
					\Dompdf\Autoloader::register();
				}
				if ( class_exists( '\\Dompdf\\Dompdf' ) ) { return true; }
			}
		}
		if ( class_exists( '\\Dompdf\\Dompdf' ) ) { return true; }
		return false;
	}
	protected static function dompdf_status() {
		$src = 'none'; $avail = false;
		if ( class_exists( '\\Dompdf\\Dompdf' ) ) { $avail = true; $src = 'composer'; }
		foreach ( self::dompdf_dir_candidates() as $base ) {
			if ( is_readable( trailingslashit( $base ) . 'autoload.inc.php' ) ) {
				$avail = true; $src = 'bundled ('. $base .')'; break;
			}
		}
		return array( 'available' => (bool) $avail, 'source' => $src );
	}

	/* -------------------------------------------------
	 * Renderers (AU timestamp + layout controls + v-prefix)
	 * -------------------------------------------------*/
	protected static function html_report( $r, $page_size = 'A4', $orientation = 'portrait' ) {
		$s    = self::current_settings();
		$logo = $s['pdf_logo_url'] ? '<img src="'.esc_url( $s['pdf_logo_url'] ).'" style="height:40px;vertical-align:middle;margin-right:12px">' : '';
		$legend = '<span style="margin-right:16px">! NEW</span><span style="margin-right:16px">✓ UPDATED</span><span>! DELETED</span>';
		$diff_map = array(); if ( ! empty( $r['plugin_diffs'] ) ) { foreach ( $r['plugin_diffs'] as $d ) { $diff_map[ $d['slug'] ] = $d; } }
		$rows = ''; $today = date_i18n('d/m/Y');

		foreach ( $r['stability']['active_plugins'] as $p ) {
			$slug  = $p['slug'];
			$type  = ( stripos( $p['name'], 'pro' ) !== false || stripos( $p['name'], 'premium' ) !== false ) ? 'PREMIUM' : 'FREE/FREEMIUM';
			$mark  = ''; if ( isset( $diff_map[ $slug ] ) ) { $c = $diff_map[ $slug ]['change']; $mark = ( 'UPDATED' === $c ) ? '✓ UPDATED' : ( 'NEW' === $c ? '! NEW' : '' ); }
			$lines = self::asset_weekly_lines_any( 'plugin', $slug, 5, $r['asset_log'] );
			if ( ! empty( $lines ) ) { $updated_cell = '<div style="line-height:1.3">'. esc_html( implode( "\n", $lines ) ) .'</div>'; $updated_cell = str_replace( "\n", '<br>', $updated_cell ); }
			else { $updated_cell = esc_html( self::asset_last_updated_human( 'plugin', $slug, $r['asset_log'] ) ); }
			$rows .= '<tr><td>'.esc_html($p['name']).'</td><td>'.$type.'</td><td>'.esc_html(self::format_version($p['version'])).'</td><td style="white-space:normal">'.esc_html($p['description_short']).'</td><td>Active</td><td>'.$today.'</td><td>'.$updated_cell.'</td><td>'.$mark.'</td><td></td></tr>';
		}

		$removed_rows = '';
		if ( ! empty( $r['plugin_diffs'] ) ) {
			foreach ( $r['plugin_diffs'] as $d ) { if ( 'DELETED' === $d['change'] ) { $removed_rows .= '<tr><td>'.esc_html( $d['slug'] ).'</td><td>'.esc_html( self::format_version($d['from']) ).'</td><td>Removed</td></tr>'; } }
		}

		$bl = ''; if ( ! empty( $r['bottlenecks'] ) ) { foreach ( $r['bottlenecks'] as $b ) { $bl .= '<li>['.esc_html( $b['severity'] ).'] '.esc_html( $b['msg'] ).'</li>'; } }

		$v = $r['versions'];
		$core_lines   = $s['weekly_lines_core'] ? self::asset_weekly_lines_any( 'core',  'wordpress',       5, $r['asset_log'] ) : array();
		$child_lines  = ( $s['weekly_lines_themes'] && ! empty( $v['child']['slug'] ) )  ? self::asset_weekly_lines_any( 'theme', $v['child']['slug'],  5, $r['asset_log'] ) : array();
		$parent_lines = ( $s['weekly_lines_themes'] && ! empty( $v['parent']['slug'] ) ) ? self::asset_weekly_lines_any( 'theme', $v['parent']['slug'], 5, $r['asset_log'] ) : array();
		$u = function( $date, $lines ) { if ( ! empty( $lines ) ) { $h = esc_html( implode( "\n", $lines ) ); return '<div style="line-height:1.3">'.str_replace("\n", "<br>", $h).'</div>'; } return esc_html( $date ); };

		$ver_rows  = '<tr><td>WordPress Core</td><td>'.esc_html(self::format_version($v['core']['version'])).'</td><td>'.$u($v['core']['updated_on'],   $core_lines ).'</td></tr>';
		$ver_rows .= '<tr><td>Child Theme: '.esc_html($v['child']['name']).'</td><td>'.esc_html(self::format_version($v['child']['version'])).'</td><td>'.$u($v['child']['updated_on'], $child_lines).'</td></tr>';
		if ( ! empty( $v['parent']['slug'] ) ) { $ver_rows .= '<tr><td>Parent Theme: '.esc_html($v['parent']['name']).'</td><td>'.esc_html(self::format_version($v['parent']['version'])).'</td><td>'.$u($v['parent']['updated_on'], $parent_lines).'</td></tr>'; }

		ob_start(); ?>
		<!doctype html><html><meta charset="utf-8">
		<style>
		@page { size: <?php echo esc_html($page_size.' '.$orientation); ?>; margin: 12mm; }
		body{font-family:Helvetica,Arial,sans-serif;color:#111;margin:24px}
		h1,h2{margin:0 0 8px}
		table{width:100%;border-collapse:collapse;margin:8px 0 18px;table-layout:fixed}
		th,td{border:1px solid #ddd;padding:6px 8px;font-size:11px;vertical-align:top;word-wrap:break-word}
		th{background:#f5f5f5;text-align:left}
		.small{color:#666;font-size:12px}
		.kv td{border:none;padding:2px 8px}
		.legend{font-size:12px;color:#444}
		.section{margin-top:12px}
		</style>
		<body>
		<h1><?php echo $logo; ?>WEB SITE SERVICE LOG</h1>
		<p class="small">SATORI</p>

		<h2>Service Details</h2>
		<table class="kv">
			<tr><td><strong>Site Name:</strong> <?php echo esc_html($r['service_details']['site_name']); ?></td><td><strong>Site URL:</strong> <?php echo esc_html($r['service_details']['site_url']); ?></td></tr>
		</table>
		<table class="kv">
			<tr><td><strong>Site Manager:</strong> <?php echo esc_html($r['service_details']['managed_by']); ?></td><td><strong>Service Date:</strong> <?php echo esc_html($r['service_details']['service_date']); ?></td></tr>
			<tr><td><strong>Start Date:</strong> <?php echo esc_html($r['service_details']['start_date']); ?></td><td><strong>End Date:</strong> ACTIVE</td></tr>
			<tr><td colspan="2"><strong>Legend:</strong> <span class="legend"><?php echo $legend; ?></span></td></tr>
			<tr><td colspan="2"><strong>Service(s):</strong> <?php echo esc_html($r['service_details']['notes']); ?></td></tr>
		</table>

		<h2>Security – Site Scan</h2>
		<p class="small">Last scan: <?php echo esc_html($r['security']['vuln_scan_last_run'] ? $r['security']['vuln_scan_last_run'] : 'n/a'); ?> – <?php echo esc_html($r['security']['vuln_scan_summary']); ?></p>

		<h2>Overview</h2>
		<table>
			<tr><th>WordPress</th><th>PHP</th><th>Server</th><th>HTTPS</th><th>Theme</th><th>Permalinks</th></tr>
			<tr>
				<td><?php echo esc_html(self::format_version($r['overview']['wordpress'])); ?></td>
				<td><?php echo esc_html(self::format_version($r['overview']['php'])); ?></td>
				<td><?php echo esc_html($r['overview']['server']); ?></td>
				<td><?php echo $r['overview']['https'] ? 'Yes' : 'No'; ?></td>
				<td><?php echo esc_html( $r['overview']['theme']['name'] . ' ' . self::format_version($r['overview']['theme']['version']) ); ?></td>
				<td><?php echo esc_html( $r['overview']['permalinks'] ? $r['overview']['permalinks'] : 'plain' ); ?></td>
			</tr>
		</table>

		<h2>Versions &amp; Update Dates</h2>
		<table>
			<tr><th>Asset</th><th>Version</th><th>Updated On</th></tr>
			<?php echo $ver_rows; ?>
		</table>

		<h2>Scores</h2>
		<table>
			<tr><th>Security</th><th>Optimization</th><th>Speed</th><th>Stability</th><th>Total</th></tr>
			<tr><td><?php echo $r['scores']['security']; ?>/10</td><td><?php echo $r['scores']['optimization']; ?>/10</td><td><?php echo $r['scores']['speed']; ?>/10</td><td><?php echo $r['scores']['stability']; ?>/10</td><td><strong><?php echo $r['scores']['total']; ?>/40</strong></td></tr>
		</table>

		<h2>Bottlenecks</h2>
		<ul><?php echo $bl ? $bl : '<li>None detected</li>'; ?></ul>

		<h2>Plugin List (combined with recent weekly updates)</h2>
		<table>
			<tr>
				<th>Plugin Name</th>
				<th>Plugin Type</th>
				<th>Plugin Version</th>
				<th>Description</th>
				<th>Plugin Status</th>
				<th>Last Checked</th>
				<th>Updated On (last ~5 wks)</th>
				<th>Updated</th>
				<th>Comments</th>
			</tr>
			<?php echo $rows; ?>
		</table>

		<?php if ( $removed_rows ) : ?>
		<h2>Removed Plugins (since last month)</h2>
		<table>
			<tr><th>Plugin (slug)</th><th>Previous Version</th><th>Status</th></tr>
			<?php echo $removed_rows; ?>
		</table>
		<?php endif; ?>

		<p class="small">Generated: <?php echo esc_html( date_i18n('d/m/Y H:i') ); ?> • SATORI Audit v<?php echo esc_html($r['meta']['plugin_version']); ?></p>
		</body></html>
		<?php
		return ob_get_clean();
	}
	protected static function markdown( $r ) {
		$lines = array();
		$lines[] = '# WEB SITE SERVICE LOG – SATORI';
		$lines[] = '**Site:** '.$r['service_details']['site_name'].'  ';
		$lines[] = '**URL:** '.$r['service_details']['site_url'].'  ';
		$lines[] = '**Service Date:** '.$r['service_details']['service_date'];
		$lines[] = '';
		$lines[] = '## Versions & Update Dates';
		$lines[] = '- WordPress: '.self::format_version($r['versions']['core']['version']).' (Updated: '.$r['versions']['core']['updated_on'].')';
		$lines[] = '- Child Theme: '.$r['versions']['child']['name'].' '.self::format_version($r['versions']['child']['version']).' (Updated: '.$r['versions']['child']['updated_on'].')';
		if ( ! empty( $r['versions']['parent']['slug'] ) ) {
			$lines[] = '- Parent Theme: '.$r['versions']['parent']['name'].' '.self::format_version($r['versions']['parent']['version']).' (Updated: '.$r['versions']['parent']['updated_on'].')';
		}
		$lines[] = '';
		$lines[] = '## Scores';
		$lines[] = '- Security: '.$r['scores']['security'].'/10';
		$lines[] = '- Optimization: '.$r['scores']['optimization'].'/10';
		$lines[] = '- Speed: '.$r['scores']['speed'].'/10';
		$lines[] = '- Stability: '.$r['scores']['stability'].'/10';
		$lines[] = '- **Total: '.$r['scores']['total'].'/40**';
		$lines[] = '';
		$lines[] = '## Bottlenecks';
		if ( empty( $r['bottlenecks'] ) ) { $lines[] = '- None detected'; }
		foreach ( $r['bottlenecks'] as $b ) { $lines[] = '- ['.$b['severity'].'] '.$b['msg']; }
		return implode( "\n", $lines );
	}
	protected static function csv_plugins( $r ) {
		$cols = array( 'Plugin Name','Plugin Type','Plugin Version','Description','Plugin Status','Last Checked','Updated On (last ~5 wks)','Updated','Comments' );
		$out  = fopen( 'php://temp', 'w+' ); fputcsv( $out, $cols );
		$today = date_i18n( 'd/m/Y' );
		$diff_map = array(); if ( ! empty( $r['plugin_diffs'] ) ) { foreach ( $r['plugin_diffs'] as $d ) { $diff_map[ $d['slug'] ] = $d; } }
		foreach ( $r['stability']['active_plugins'] as $p ) {
			$type       = ( stripos( $p['name'], 'pro' ) !== false || stripos( $p['name'], 'premium' ) !== false ) ? 'PREMIUM' : 'FREE/FREEMIUM';
			$mark       = isset( $diff_map[ $p['slug'] ] ) ? ( $diff_map[ $p['slug'] ]['change'] === 'UPDATED' ? '✓ UPDATED' : ( $diff_map[ $p['slug'] ]['change'] === 'NEW' ? '! NEW' : '' ) ) : '';
			$lines      = self::asset_weekly_lines_any( 'plugin', $p['slug'], 5, $r['asset_log'] );
			$updated_on = ! empty( $lines ) ? implode( ' | ', $lines ) : self::asset_last_updated_human( 'plugin', $p['slug'], $r['asset_log'] );
			fputcsv( $out, array( $p['name'], $type, self::format_version($p['version']), self::short_desc( $p['description'] ), 'Active', $today, $updated_on, $mark, '' ) );
		}
		rewind( $out ); return stream_get_contents( $out );
	}

	/* -------------------------------------------------
	 * Test Email (dry run)
	 * -------------------------------------------------*/
	public static function handle_test_email() {
		self::enforce_settings_access_or_die();
		check_admin_referer( 'satori_audit_v3_test' );
		$s = self::current_settings();
		$candidates = array_filter( array_map( 'trim', explode( ',', (string)$s['notify_emails'] ) ) );
		$would_send = self::safelist_filter_emails( $candidates, $s );
		$preview_str = implode( ', ', $would_send );
		$user = wp_get_current_user(); $me = $user && $user->user_email ? $user->user_email : get_option( 'admin_email' );
		$subj = '[SATORI Audit] Test email (preview recipients)';
		$body = "Hello!\n\nThis is a test message from SATORI Audit.\n\nIf a real report were sent right now, it would go to (after safelist):\n" . ( $preview_str ? $preview_str : '[none – blocked by safelist or no recipients configured]' ) . "\n\nNo client emails were contacted.";
		$headers = array( 'From: '.$s['contact_email'] );
		add_filter( 'wp_mail_content_type', array( __CLASS__, 'mail_content_type_plain' ) );
		wp_mail( $me, $subj, $body, $headers );
		remove_filter( 'wp_mail_content_type', array( __CLASS__, 'mail_content_type_plain' ) );
		$q = array( 'page'=>'satori-audit-v3-settings','satori_test'=>1,'me'=>$me,'recips'=>base64_encode( $preview_str ) );
		wp_safe_redirect( admin_url( 'options-general.php?' . http_build_query( $q ) ) );
		exit;
	}

	/* -------------------------------------------------
	 * Safelist helpers + utilities + backfill
	 * -------------------------------------------------*/
	protected static function parse_safelist( $csv ) {
		$domains = array(); $emails  = array();
		$raw = preg_split( '/[,\s]+/', (string) $csv, -1, PREG_SPLIT_NO_EMPTY ); if ( ! $raw ) { return array( $domains, $emails ); }
		foreach ( $raw as $item ) {
			$item = trim( strtolower( $item ) ); if ( '' === $item ) { continue; }
			if ( $item[0] === '@' && strlen( $item ) > 1 ) { $domains[ substr( $item, 1 ) ] = true; }
			elseif ( strpos( $item, '@' ) !== false ) { $emails[ $item ] = true; }
		}
		return array( $domains, $emails );
	}
	protected static function safelist_filter_emails( $candidates, $settings ) {
		if ( empty( $settings['enforce_safelist'] ) ) { return $candidates; }
		list( $domains, $emails ) = self::parse_safelist( isset($settings['safelist_entries']) ? $settings['safelist_entries'] : '' );
		if ( empty( $domains ) && empty( $emails ) ) { return array(); }
		$out = array();
		foreach ( $candidates as $addr ) {
			$addr_l = strtolower( trim( $addr ) );
			if ( isset( $emails[ $addr_l ] ) ) { $out[] = $addr; continue; }
			$at = strrpos( $addr_l, '@' );
			if ( false !== $at ) { $dom = substr( $addr_l, $at + 1 ); if ( isset( $domains[ $dom ] ) ) { $out[] = $addr; continue; } }
		}
		return $out;
	}
	protected static function short_desc( $text, $limit = 140 ) {
		$t = wp_strip_all_tags( (string) $text ); $t = trim( preg_replace( '/\s+/', ' ', $t ) );
		if ( function_exists( 'mb_substr' ) ) { return ( mb_strlen( $t ) > $limit ) ? mb_substr( $t, 0, $limit - 1 ) . '…' : $t; }
		return ( strlen( $t ) > $limit ) ? substr( $t, 0, $limit - 1 ) . '…' : $t;
	}
	protected static function trim_history() {
		$s = self::current_settings(); $keep = (int) $s['keep_months'];
		$h = get_option( self::OPT_HISTORY, array() ); if ( empty( $h ) ) { return; }
		krsort( $h ); $h = array_slice( $h, 0, $keep, true ); update_option( self::OPT_HISTORY, $h );
	}
	public static function maybe_backfill_weeklies() {
		$s = self::current_settings();
		if ( empty( $s['backfill_on_first_run'] ) ) { return; }
		if ( get_option( 'satori_audit_v3_backfilled' ) ) { return; }

		$asset_log = get_option( self::OPT_ASSET_LOG, array() ); $asset_log += array( 'plugins'=>array(), 'themes'=>array(), 'core'=>array() );
		$today = date_i18n( 'Y-m-d' );

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$active_plugins = (array) get_option( 'active_plugins', array() );
		$all_plugins    = function_exists( 'get_plugins' ) ? get_plugins() : array();
		$theme          = wp_get_theme();
		$parent         = $theme && $theme->parent() ? $theme->parent() : null;
		$core_ver       = get_bloginfo( 'version' );

		$weeks = array();
		for ( $i = 4; $i >= 1; $i-- ) { $weeks[] = date_i18n( 'Y-m-d', strtotime( 'monday -'.$i.' week', current_time( 'timestamp' ) ) ); }

		foreach ( $active_plugins as $path ) {
			if ( empty( $all_plugins[$path] ) ) { continue; }
			$slug = dirname( $path ); $ver = isset( $all_plugins[$path]['Version'] ) ? $all_plugins[$path]['Version'] : '';
			if ( empty( $asset_log['plugins'][$slug] ) ) { $asset_log['plugins'][$slug] = array( 'last_updated' => $today, 'history' => array() ); }
			foreach ( $weeks as $w ) { $asset_log['plugins'][$slug]['history'][] = array( 'date' => $w, 'to_version' => $ver ); }
		}
		if ( $theme && $theme->exists() ) {
			$cslug = $theme->get_stylesheet(); $cver = $theme->get('Version');
			if ( empty( $asset_log['themes'][$cslug] ) ) { $asset_log['themes'][$cslug] = array( 'last_updated' => $today, 'history' => array() ); }
			foreach ( $weeks as $w ) { $asset_log['themes'][$cslug]['history'][] = array( 'date' => $w, 'to_version' => $cver ); }
		}
		if ( $parent && $parent->exists() ) {
			$pslug = $parent->get_template(); $pver = $parent->get('Version');
			if ( empty( $asset_log['themes'][$pslug] ) ) { $asset_log['themes'][$pslug] = array( 'last_updated' => $today, 'history' => array() ); }
			foreach ( $weeks as $w ) { $asset_log['themes'][$pslug]['history'][] = array( 'date' => $w, 'to_version' => $pver ); }
		}
		if ( empty( $asset_log['core']['wordpress'] ) ) { $asset_log['core']['wordpress'] = array( 'last_updated' => $today, 'history' => array() ); }
		foreach ( $weeks as $w ) { $asset_log['core']['wordpress']['history'][] = array( 'date' => $w, 'to_version' => $core_ver ); }

		update_option( self::OPT_ASSET_LOG, $asset_log, false );
		update_option( 'satori_audit_v3_backfilled', 1, false );
	}

	/* -------------------------------------------------
	 * Harden recipients
	 * -------------------------------------------------*/
	public static function harden_recipients() {
		$s = self::current_settings();
		$admin = get_option( 'admin_email' );
		if ( isset( $s['notify_emails'] ) && is_string( $s['notify_emails'] ) && trim( $s['notify_emails'] ) === trim( $admin ) ) {
			$s['notify_emails'] = '';
			update_option( self::OPT_SETTINGS, $s );
		}
	}
}

Satori_Audit_V373::init();

} // end guard