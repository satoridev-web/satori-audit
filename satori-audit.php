<?php

/**
 * Plugin Name: SATORI – Site Audit
 * Plugin URI:  https://satori.com.au
 * Description: Audit, reports, exports and controls for SATORI-managed sites.
 * Version:     3.7.4.8
 * Author:      SATORI
 * Author URI:  https://satori.com.au
 * License:     GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: satori-audit
 */

if (! defined('ABSPATH')) exit;

/* -------------------------------------------------
 * Bootstrap core + inc files
 * -------------------------------------------------*/
$base = plugin_dir_path(__FILE__) . 'inc/';

require_once $base . 'Satori_Audit_V373.php';
require_once $base . 'satori-restrict-risky-plugins.php';

/* -------------------------------------------------
 * Delta add-ons (optional patches)
 * -------------------------------------------------*/
if (file_exists($base . 'z-satori-audit-v374-delta-fixed.php')) {
    require_once $base . 'z-satori-audit-v374-delta-fixed.php';
}

if (file_exists($base . 'z-satori-audit-json-refresh.php')) {
    require_once $base . 'z-satori-audit-json-refresh.php';
}

/* -------------------------------------------------
 * v3.7.4.8 Enhancements – Run Audit Now, Test Audit,
 * Scheduler, Log, Timezone fix (wp_date)
 * -------------------------------------------------*/

function satori_audit_log_run($type, $user = 'System')
{
    $log = get_option('satori_audit_log', []);
    array_unshift($log, [
        'time' => time(),
        'type' => $type,
        'user' => $user,
    ]);
    $cutoff = time() - (365 * 24 * 60 * 60);
    $log    = array_filter($log, function ($entry) use ($cutoff) {
        return $entry['time'] >= $cutoff;
    });
    $log    = array_slice($log, 0, 500);
    update_option('satori_audit_log', $log, false);
}

// Run Audit Now (persist JSON)
add_action('admin_post_satori_audit_run_now', function () {
    if (! current_user_can('manage_options') || ! check_admin_referer('satori_audit_run_now')) {
        wp_die('Unauthorized');
    }
    if (method_exists('Satori_Audit_V373', 'build_report')) {
        Satori_Audit_V373::build_report(true);
    }
    $user = wp_get_current_user();
    satori_audit_log_run('Full Audit', $user->display_name ?: $user->user_login);
    wp_redirect(add_query_arg('satori_audit_run', 'success', wp_get_referer()));
    exit;
});

// Run Test Audit (no persist)
add_action('admin_post_satori_audit_run_test', function () {
    if (! current_user_can('manage_options') || ! check_admin_referer('satori_audit_run_test')) {
        wp_die('Unauthorized');
    }
    if (method_exists('Satori_Audit_V373', 'build_report')) {
        Satori_Audit_V373::build_report(false);
    }
    $user = wp_get_current_user();
    satori_audit_log_run('Test Audit', $user->display_name ?: $user->user_login);
    wp_redirect(add_query_arg('satori_audit_run', 'test', wp_get_referer()));
    exit;
});

// Cron scheduled audits
add_action('satori_audit_cron_event', function () {
    if (method_exists('Satori_Audit_V373', 'build_report')) {
        Satori_Audit_V373::build_report(true);
    }
    satori_audit_log_run('Scheduled Audit');
});

// Save schedule + summary prefs
add_action('admin_init', function () {
    if (isset($_POST['satori_audit_schedule']) && check_admin_referer('satori_audit_schedule') && current_user_can('manage_options')) {
        $val = sanitize_text_field($_POST['satori_audit_schedule']);
        update_option('satori_audit_schedule', $val);
        wp_clear_scheduled_hook('satori_audit_cron_event');
        if ($val !== 'none') {
            if ($val === 'monthly') {
                add_filter('cron_schedules', function ($schedules) {
                    $schedules['monthly'] = [
                        'interval' => 2592000,
                        'display'  => 'Once Monthly'
                    ];
                    return $schedules;
                });
            }
            wp_schedule_event(time() + 60, $val, 'satori_audit_cron_event');
        }
    }
    if (isset($_POST['satori_audit_summary_pref']) && check_admin_referer('satori_audit_schedule') && current_user_can('manage_options')) {
        update_option('satori_audit_summary_pref', sanitize_text_field($_POST['satori_audit_summary_pref']));
    }
});

// Inject controls (Tools → SATORI Audit page)
add_action('in_admin_header', function () {
    $screen = get_current_screen();
    if (! $screen || strpos($screen->id, 'tools_page_satori') === false) {
        return;
    }

    $pref = get_option('satori_audit_summary_pref', 'any');
    $log  = get_option('satori_audit_log', []);
    $entry = null;
    if ($log) {
        foreach ($log as $e) {
            if (
                $pref === 'any' ||
                ($pref === 'full' && $e['type'] === 'Full Audit') ||
                ($pref === 'test' && $e['type'] === 'Test Audit') ||
                ($pref === 'scheduled' && $e['type'] === 'Scheduled Audit')
            ) {
                $entry = $e;
                break;
            }
        }
    }

    echo '<div class="notice notice-info" style="padding:15px;">';
    echo '<h2>SATORI Audit v3.7.4.8 active</h2>';

    if ($entry) {
        echo '<p><strong>Last Audit Run:</strong> ' . $entry['type'] . ' – ' . wp_date(get_option('date_format') . ' @ ' . get_option('time_format'), $entry['time']) . ' – by ' . $entry['user'] . '</p>';
    } else {
        echo '<p><strong>Last Audit Run:</strong> No matching audit run recorded.</p>';
    }

    // Run buttons
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:10px;">';
    wp_nonce_field('satori_audit_run_now');
    echo '<input type="hidden" name="action" value="satori_audit_run_now">';
    echo '<button type="submit" class="button button-primary">Run Audit Now</button></form>';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;">';
    wp_nonce_field('satori_audit_run_test');
    echo '<input type="hidden" name="action" value="satori_audit_run_test">';
    echo '<button type="submit" class="button">Run Test Audit</button></form>';

    // Schedule + Summary Preference
    $current = get_option('satori_audit_schedule', 'none');
    $pref    = get_option('satori_audit_summary_pref', 'any');
    echo '<form method="post" style="margin-top:10px;">';
    wp_nonce_field('satori_audit_schedule');
    echo '<label><strong>Audit Schedule:</strong> </label>';
    echo '<select name="satori_audit_schedule">';
    foreach (["none" => "None", "daily" => "Daily", "weekly" => "Weekly", "monthly" => "Monthly"] as $val => $label) {
        $sel = ($current == $val) ? 'selected' : '';
        echo "<option value='$val' $sel>$label</option>";
    }
    echo '</select> ';
    echo '<label><strong>Summary Preference:</strong> </label>';
    echo '<select name="satori_audit_summary_pref">';
    foreach (["any" => "Most Recent", "full" => "Full Only", "scheduled" => "Scheduled Only", "test" => "Test Only"] as $val => $label) {
        $sel = ($pref == $val) ? 'selected' : '';
        echo "<option value='$val' $sel>$label</option>";
    }
    echo '</select> ';
    echo '<button type="submit" class="button">Save</button>';
    echo '</form>';

    // Short log
    echo '<div style="margin-top:15px;"><strong>Last Audit Runs:</strong><ul>';
    $short = array_slice($log, 0, 5);
    if ($short) {
        foreach ($short as $e) {
            echo '<li>' . $e['type'] . ' – ' . wp_date(get_option('date_format') . ' @ ' . get_option('time_format'), $e['time']) . ' – by ' . $e['user'] . '</li>';
        }
    } else {
        echo '<li>No audit runs recorded yet.</li>';
    }
    echo '</ul></div>';

    // Full log if >5
    if (count($log) > 5) {
        echo '<details><summary>View full log</summary>';
        echo '<div style="max-height:250px;overflow:auto;border:1px solid #ccc;padding:5px;margin-top:10px;"><table class="widefat striped"><thead><tr><th>Type</th><th>Date</th><th>User</th></tr></thead><tbody>';
        foreach ($log as $e) {
            echo '<tr><td>' . $e['type'] . '</td><td>' . wp_date(get_option('date_format') . ' @ ' . get_option('time_format'), $e['time']) . '</td><td>' . $e['user'] . '</td></tr>';
        }
        echo '</tbody></table></div></details>';
    }

    echo '</div>';
});
