<?php

/**
 * SATORI – Site Audit v3.7.4.9 JSON Refresh Patch
 *
 * Ensures "Run Audit Now" updates the stored Audit JSON export
 * and records a last-generated timestamp. Also displays a notice
 * and injects a log entry so you can verify refreshes at a glance.
 *
 * File location (repo structure):
 *   /satori-audit/inc/z-satori-audit-json-refresh.php
 */

if (! defined('ABSPATH')) exit;

if (! class_exists('Satori_Audit_JSON_Refresh')) {

    class Satori_Audit_JSON_Refresh
    {

        /* -------------------------------------------------
     * Options
     * -------------------------------------------------*/
        const OPT_JSON_EXPORT       = 'satori_audit_v3_json_export';
        const OPT_JSON_LAST_UPDATED = 'satori_audit_v3_json_last_generated';

        /* -------------------------------------------------
     * Bootstrap
     * -------------------------------------------------*/
        public static function init()
        {
            // Primary/alternate admin-post handlers (covers common naming)
            add_action('admin_post_satori_audit_run_now',    [__CLASS__, 'handle_run_now']);
            add_action('admin_post_satori_audit_run_audit',  [__CLASS__, 'handle_run_now']);

            // Safety net: catch GET/POST triggers if the action name differs
            add_action('admin_init', [__CLASS__, 'maybe_catch_manual_run']);

            // UI feedback
            add_action('admin_notices', [__CLASS__, 'json_last_generated_notice']);

            // Extend the Audit Log (if your log renderer applies this filter)
            add_filter('satori_audit_v3_log_entries', [__CLASS__, 'inject_log_json_timestamp']);
        }

        /* -------------------------------------------------
     * Handle declared admin-post actions
     * -------------------------------------------------*/
        public static function handle_run_now()
        {
            self::guard_nonce();
            self::refresh_json();
            self::redirect_back();
        }

        /* -------------------------------------------------
     * Fallback catcher (if button uses different params)
     * -------------------------------------------------*/
        public static function maybe_catch_manual_run()
        {
            if (! is_admin() || ! current_user_can('manage_options')) return;

            // Accept either ?satori_audit_run_now=1 or satori_audit_action=run_now
            $run = (isset($_REQUEST['satori_audit_run_now']) && $_REQUEST['satori_audit_run_now'])
                || (isset($_REQUEST['satori_audit_action']) && $_REQUEST['satori_audit_action'] === 'run_now');

            if (! $run) return;

            // Try either nonce name (covers alt handlers)
            $nonce_ok = isset($_REQUEST['_wpnonce']) && (
                wp_verify_nonce($_REQUEST['_wpnonce'], 'satori_audit_run_now') ||
                wp_verify_nonce($_REQUEST['_wpnonce'], 'satori_audit_run_audit')
            );
            if (! $nonce_ok) return;

            self::refresh_json();
            self::redirect_back();
        }

        /* -------------------------------------------------
     * Security guard
     * -------------------------------------------------*/
        protected static function guard_nonce()
        {
            if (! current_user_can('manage_options')) {
                wp_die(__('Permission denied.', 'satori-audit'));
            }
            $nonce = isset($_REQUEST['_wpnonce']) ? $_REQUEST['_wpnonce'] : '';
            $ok = wp_verify_nonce($nonce, 'satori_audit_run_now') || wp_verify_nonce($nonce, 'satori_audit_run_audit');
            if (! $ok) {
                wp_die(__('Nonce check failed for Run Audit.', 'satori-audit'));
            }
        }

        /* -------------------------------------------------
     * Core: build + persist JSON
     * -------------------------------------------------*/
        protected static function refresh_json()
        {
            if (method_exists('Satori_Audit_V373', 'build_report')) {
                $json = Satori_Audit_V373::build_report(true); // true => force/full build (per your wiring)
                if (is_array($json)) {
                    $json = wp_json_encode($json);
                }
                if (! empty($json)) {
                    update_option(self::OPT_JSON_EXPORT, $json);
                    update_option(self::OPT_JSON_LAST_UPDATED, current_time('mysql'));
                    /**
                     * Fire a hook for any other listeners (e.g., webhooks)
                     */
                    do_action('satori_audit_v3_json_refreshed', $json);
                }
            }
        }

        /* -------------------------------------------------
     * UI: admin notices
     * -------------------------------------------------*/
        public static function json_last_generated_notice()
        {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            if (! $screen || strpos($screen->id, 'satori-audit') === false) {
                return;
            }

            $last = get_option(self::OPT_JSON_LAST_UPDATED);
            if ($last) {
                $formatted = wp_date('d M Y H:i', strtotime($last));
                echo '<div class="notice notice-info is-dismissible"><p><strong>SATORI Audit JSON:</strong> Last generated at <em>' . esc_html($formatted) . '</em></p></div>';
            }

            if (isset($_GET['audit_refreshed']) && $_GET['audit_refreshed'] === '1') {
                echo '<div class="notice notice-success is-dismissible"><p>SATORI Audit: JSON export refreshed successfully.</p></div>';
            }
        }

        /* -------------------------------------------------
     * Log injector (if renderer calls this filter)
     * -------------------------------------------------*/
        public static function inject_log_json_timestamp($log)
        {
            $last = get_option(self::OPT_JSON_LAST_UPDATED);
            if ($last && is_array($log)) {
                $log[] = [
                    'event'   => 'Audit JSON refreshed',
                    'details' => 'Manual run stored new JSON export',
                    'time'    => wp_date('d M Y H:i', strtotime($last)),
                ];
            }
            return $log;
        }

        /* -------------------------------------------------
     * Redirect helper
     * -------------------------------------------------*/
        protected static function redirect_back()
        {
            $ref = wp_get_referer();
            if (! $ref) {
                $ref = admin_url('tools.php?page=satori-audit');
            }
            wp_safe_redirect(add_query_arg('audit_refreshed', '1', $ref));
            exit;
        }
    }

    Satori_Audit_JSON_Refresh::init();
}
