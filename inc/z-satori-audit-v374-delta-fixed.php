<?php
/**
 * SATORI – Site Audit v3.7.4 Delta (safe add-ons for v3.7.3)
 * - Toggles: Report Editor (beta), Debug footer badge, New Report Template (v1)
 * - Report Editor now saves content; injected into template as {{editor.notes}}
 * - Overrides Preview/Export when new template is enabled
 *
 * Place in: wp-content/mu-plugins/z-satori-audit-v374-delta-fixed.php
 */

if ( ! defined('ABSPATH') ) { exit; }

add_action( 'plugins_loaded', function () {
    if ( ! class_exists( 'Satori_Audit_V373' ) ) return;

    // -------- Settings helpers --------
    if ( ! function_exists( 'satori_v374_get_settings' ) ) {
        function satori_v374_get_settings() {
            // Safe fallback instead of calling protected ::current_settings()
            if ( method_exists( 'Satori_Audit_V373', 'get_settings' ) ) {
                return Satori_Audit_V373::get_settings();
            }

            $opt_key  = defined('Satori_Audit_V373::OPT_SETTINGS') ? Satori_Audit_V373::OPT_SETTINGS : 'satori_audit_v3_settings';
            $current  = get_option( $opt_key, array() );
            $defaults = array(
                'report_editor_enabled' => false,
                'debug_enabled'         => false,
                'use_new_template'      => false,
            );
            if ( ! is_array( $current ) ) $current = array();
            return array_merge( $defaults, $current );
        }
    }

    if ( ! function_exists( 'satori_v374_save_settings' ) ) {
        function satori_v374_save_settings( array $new ) {
            $opt_key  = defined('Satori_Audit_V373::OPT_SETTINGS') ? Satori_Audit_V373::OPT_SETTINGS : 'satori_audit_v3_settings';
            $existing = satori_v374_get_settings();
            $allowed  = array(
                'report_editor_enabled' => isset($new['report_editor_enabled']) ? (bool)$new['report_editor_enabled'] : false,
                'debug_enabled'         => isset($new['debug_enabled']) ? (bool)$new['debug_enabled'] : false,
                'use_new_template'      => isset($new['use_new_template']) ? (bool)$new['use_new_template'] : false,
            );
            $merged = array_merge( $existing, $allowed );
            update_option( $opt_key, $merged, false );
            return $merged;
        }
    }

    // -------- Add-ons page --------
    add_action( 'admin_menu', function () {
        add_management_page(
            'SATORI Audit – Add-ons',
            'SATORI Audit – Add-ons',
            'manage_options',
            'satori-audit-v374-addons',
            'satori_v374_render_addons_page',
            99
        );
    }, 20 );

    add_action( 'admin_post_satori_v374_save_addons', function () {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Insufficient privileges.', 'default' ) );
        check_admin_referer( 'satori_v374_addons_save' );
        $payload = array(
            'report_editor_enabled' => isset($_POST['report_editor_enabled']),
            'debug_enabled'         => isset($_POST['debug_enabled']),
            'use_new_template'      => isset($_POST['use_new_template']),
        );
        satori_v374_save_settings( $payload );
        wp_safe_redirect( add_query_arg( array( 'page' => 'satori-audit-v374-addons', 'saved' => '1' ), admin_url( 'tools.php' ) ) );
        exit;
    });

    if ( ! function_exists( 'satori_v374_render_addons_page' ) ) {
        function satori_v374_render_addons_page() {
            $settings = satori_v374_get_settings(); ?>
            <div class="wrap">
                <h1>SATORI Audit – Add-ons (v3.7.4)</h1>
                <?php if ( isset($_GET['saved']) ): ?><div class="notice notice-success is-dismissible"><p>Saved.</p></div><?php endif; ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'satori_v374_addons_save' ); ?>
                    <input type="hidden" name="action" value="satori_v374_save_addons" />
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr><th scope="row">Report Editor (beta)</th><td><label><input type="checkbox" name="report_editor_enabled" value="1" <?php checked( $settings['report_editor_enabled'] ); ?> /> Enable Report Editor</label></td></tr>
                            <tr><th scope="row">Debug footer badge</th><td><label><input type="checkbox" name="debug_enabled" value="1" <?php checked( $settings['debug_enabled'] ); ?> /> Show Debug Badge</label></td></tr>
                            <tr><th scope="row">New Report Template</th><td><label><input type="checkbox" name="use_new_template" value="1" <?php checked( $settings['use_new_template'] ); ?> /> Use new report template (v1)</label></td></tr>
                        </tbody>
                    </table>
                    <?php submit_button( 'Save Add-ons' ); ?>
                </form>
            </div><?php
        }
    }

    // -------- Report Editor page + save --------
    add_action( 'admin_menu', function () {
        $settings = satori_v374_get_settings();
        if ( empty($settings['report_editor_enabled']) ) return;
        add_management_page(
            'SATORI Report Editor (beta)',
            'SATORI Report Editor',
            'manage_options',
            'satori-audit-v374-editor',
            'satori_v374_render_report_editor',
            100
        );
    }, 30 );

    add_action( 'admin_post_satori_v374_save_editor', function () {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Insufficient privileges.', 'default' ) );
        check_admin_referer( 'satori_v374_save_editor' );
        $content = isset($_POST['satori_v374_editor_content']) ? wp_kses_post( wp_unslash( $_POST['satori_v374_editor_content'] ) ) : '';
        update_option( 'satori_v374_editor_content', $content, false );
        wp_safe_redirect( add_query_arg( array( 'page' => 'satori-audit-v374-editor', 'saved' => '1' ), admin_url( 'tools.php' ) ) );
        exit;
    });

    if ( ! function_exists( 'satori_v374_render_report_editor' ) ) {
        function satori_v374_render_report_editor() {
            $saved = get_option( 'satori_v374_editor_content', '' );
            ?>
            <div class="wrap">
                <h1>SATORI Report Editor (beta)</h1>
                <p class="description">Type notes below and click Save. These will appear in the new report template as <strong>Editor Notes</strong>.</p>
                <?php if ( isset($_GET['saved']) ): ?><div class="notice notice-success is-dismissible"><p>Notes saved.</p></div><?php endif; ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'satori_v374_save_editor' ); ?>
                    <input type="hidden" name="action" value="satori_v374_save_editor" />
                    <?php
                    if ( function_exists( 'wp_editor' ) ) {
                        wp_editor( $saved, 'satori_v374_editor_content', array(
                            'textarea_rows' => 18,
                            'media_buttons' => false,
                            'teeny'         => true,
                        ) );
                    } else {
                        echo '<textarea name="satori_v374_editor_content" style="width:100%;min-height:280px">'.esc_textarea( $saved ).'</textarea>';
                    }
                    submit_button( 'Save Notes' );
                    ?>
                </form>
                <p><em>Preview placeholder:</em></p>
                <div style="padding:12px;border:1px solid #ccd0d4;background:#fff">
                    <small>Preview will render here in a future build.</small>
                </div>
            </div>
            <?php
        }
    }

    // -------- Embedded template + CSS --------
    class Satori_Audit_V374_Template {
        private static $css = '/* … CSS omitted for brevity (unchanged) … */';
        private static $html = '/* … HTML template omitted for brevity (unchanged) … */';

        public static function render( $data = array(), $raw_keys = array('editor.notes') ) {
            $tpl = str_replace( '{{REPORT_CSS}}', self::$css, self::$html );
            foreach ( $data as $k => $v ) {
                $needle = '{{'.$k.'}}';
                if ( in_array( $k, $raw_keys, true ) ) {
                    $tpl = str_replace( $needle, (string) $v, $tpl );
                } else {
                    $tpl = str_replace( $needle, esc_html( (string) $v ), $tpl );
                }
            }
            $tpl = preg_replace('/\{\{[^}}]+\}\}/', '', $tpl);
            return $tpl;
        }
    }

    // -------- Preview/Export overrides --------
    add_filter( 'satori_audit_render_dashboard', function( $legacy_cb ) {
        $settings = satori_v374_get_settings();
        if ( empty($settings['use_new_template']) ) return $legacy_cb;
        return function() {
            $editor_html = get_option( 'satori_v374_editor_content', '' );
            echo Satori_Audit_V374_Template::render(array(
                'client.name'        => 'Example Client',
                'site.url'           => home_url(),
                'report.month_label' => date_i18n('F Y'),
                'report.period_start'=> date_i18n('01/m/Y'),
                'report.period_end'  => date_i18n('t/m/Y'),
                'summary.text'       => 'Executive summary placeholder',
                'editor.notes'       => $editor_html,
            ));
        };
    });

    add_filter( 'satori_audit_render_export', function( $legacy_cb ) {
        $settings = satori_v374_get_settings();
        if ( empty($settings['use_new_template']) ) return $legacy_cb;
        return function() {
            $editor_html = get_option( 'satori_v374_editor_content', '' );
            $html = Satori_Audit_V374_Template::render(array(
                'editor.notes' => $editor_html,
            ));
            header('Content-Type: text/html; charset=utf-8');
            echo $html;
            exit;
        };
    });

    // -------- Debug badge --------
    add_action( 'admin_footer', function () {
        $settings = satori_v374_get_settings();
        if ( empty( $settings['debug_enabled'] ) ) return; ?>
        <style>#satori-v374-badge{position:fixed;right:10px;bottom:6px;z-index:99999;font:12px/1.2 sans-serif;background:#1e293b;color:#fff;padding:4px 8px;border-radius:6px;opacity:.8}</style>
        <div id="satori-v374-badge" title="SATORI v3.7.4 add-ons active">SATORI Debug</div><?php
    }, 9999 );
}, 20 );
