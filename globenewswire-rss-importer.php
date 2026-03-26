<?php
/**
 * Plugin Name: GlobeNewswire Importer (Press Releases)
 * Description: Import GlobeNewswire RSS into press-releases CPT with Page Links To support
 * Author: Shojib Khan (8Scope)
 * Author URI: https://8scope.com/
 */

if (!defined('ABSPATH')) exit;

class GNW_Importer {

    private $default_feed_url = 'https://www.globenewswire.com/rssfeed/organization/KZKH5l4csK2lD-SAPilmkg==';

    public function __construct() {
        add_action('gnw_import_cron',            [$this, 'run_import']);
        add_action('admin_menu',                 [$this, 'menu']);
        add_action('admin_post_gnw_run_import',  [$this, 'manual_import']);
        add_action('admin_init',                 [$this, 'register_settings']);
        add_filter('cron_schedules',             [$this, 'add_cron_schedules']);
        add_action('update_option_gnw_cron_schedule', [$this, 'reschedule_cron'], 10, 2);
        add_action('admin_post_gnw_clear_log',           [$this, 'clear_log']);
        add_action('admin_init',                         [$this, 'maybe_spawn_cron']);

        $this->maybe_schedule_cron();
    }

    // -------------------------------------------------------------------------
    // HELPERS
    // -------------------------------------------------------------------------

    private function get_feed_url() {
        return get_option('gnw_feed_url', $this->default_feed_url);
    }

    private function get_schedule() {
        return get_option('gnw_cron_schedule', 'hourly');
    }

    private function get_schedule_options() {
        return [
            'hourly'     => 'Every Hour',
            'twicedaily' => 'Twice Daily',
            'daily'      => 'Once Daily',
            'weekly'     => 'Once Weekly',
        ];
    }

    // -------------------------------------------------------------------------
    // CRON
    // -------------------------------------------------------------------------

    public function add_cron_schedules( $schedules ) {
        if ( ! isset( $schedules['weekly'] ) ) {
            $schedules['weekly'] = [
                'interval' => WEEK_IN_SECONDS,
                'display'  => 'Once Weekly',
            ];
        }
        return $schedules;
    }

    private function maybe_schedule_cron() {
        if ( ! wp_next_scheduled('gnw_import_cron') ) {
            wp_schedule_event( time(), $this->get_schedule(), 'gnw_import_cron' );
        }
    }

    /**
     * On every admin page load, tell WordPress to process any due cron events.
     * This is the reliable workaround for low-traffic / local sites where
     * WP-Cron never fires naturally.
     */
    public function maybe_spawn_cron() {
        if ( isset( $_GET['page'] ) && $_GET['page'] === 'gnw-import' ) {
            spawn_cron();
        }
    }

    /** Fires when gnw_cron_schedule option is saved — reschedules at new interval. */
    public function reschedule_cron( $old_value, $new_value ) {
        wp_clear_scheduled_hook('gnw_import_cron');
        wp_schedule_event( time(), $new_value, 'gnw_import_cron' );
    }

    // -------------------------------------------------------------------------
    // SETTINGS
    // -------------------------------------------------------------------------

    public function register_settings() {

        register_setting( 'gnw_settings', 'gnw_feed_url', [
            'sanitize_callback' => 'esc_url_raw',
            'default'           => $this->default_feed_url,
        ] );

        register_setting( 'gnw_settings', 'gnw_cron_schedule', [
            'sanitize_callback' => [$this, 'sanitize_schedule'],
            'default'           => 'hourly',
        ] );

        add_settings_section( 'gnw_main', 'Import Settings', null, 'gnw-import' );

        add_settings_field( 'gnw_feed_url', 'Feed URL',
            [$this, 'field_feed_url'], 'gnw-import', 'gnw_main' );

        add_settings_field( 'gnw_cron_schedule', 'Auto-Import Schedule',
            [$this, 'field_schedule'], 'gnw-import', 'gnw_main' );
    }

    public function sanitize_schedule( $value ) {
        $valid = array_keys( $this->get_schedule_options() );
        return in_array( $value, $valid, true ) ? $value : 'hourly';
    }

    public function field_feed_url() {
        printf(
            '<input type="url" name="gnw_feed_url" value="%s" class="large-text" />',
            esc_attr( $this->get_feed_url() )
        );
    }

    public function field_schedule() {
        $current = $this->get_schedule();
        echo '<select name="gnw_cron_schedule">';
        foreach ( $this->get_schedule_options() as $key => $label ) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr( $key ),
                selected( $current, $key, false ),
                esc_html( $label )
            );
        }
        echo '</select>';

        $next = wp_next_scheduled('gnw_import_cron');
        if ( $next ) {
            printf(
                '<p class="description">Next scheduled run: %s</p>',
                esc_html( wp_date( 'Y-m-d H:i:s', $next ) )
            );
        }
    }

    // -------------------------------------------------------------------------
    // LOGGING
    // -------------------------------------------------------------------------

    private function write_log( $status, $message, $imported = 0, $skipped = 0 ) {
        $logs = get_option('gnw_import_log', []);
        array_unshift( $logs, [
            'time'     => time(),
            'status'   => $status,   // 'success' | 'error'
            'message'  => $message,
            'imported' => (int) $imported,
            'skipped'  => (int) $skipped,
            'feed'     => $this->get_feed_url(),
        ]);
        update_option( 'gnw_import_log', array_slice( $logs, 0, 50 ), false );
    }

    // -------------------------------------------------------------------------
    // MAIN IMPORT
    // -------------------------------------------------------------------------

    public function run_import() {

        include_once ABSPATH . WPINC . '/feed.php';

        $rss = fetch_feed( $this->get_feed_url() );

        if ( is_wp_error($rss) ) {
            $this->write_log( 'error', 'Feed fetch failed: ' . $rss->get_error_message() );
            return;
        }

        $imported = 0;
        $skipped  = 0;

        foreach ( $rss->get_items() as $item ) {

            $title   = $item->get_title();
            $link    = $item->get_link();
            $guid    = $item->get_id();
            $date    = $item->get_date('Y-m-d H:i:s');
            $content = $item->get_content();

            // Remove <pre> blocks
            $content = preg_replace('/<pre.*?>.*?<\/pre>/s', '', $content);

            // Skip if already imported or manually added with this URL
            if ( $this->exists($guid, $link, wp_strip_all_tags($title)) ) {
                $skipped++;
                continue;
            }

            $post_id = wp_insert_post([
                'post_title'   => wp_strip_all_tags($title),
                'post_content' => $content,
                'post_status'  => 'publish',
                'post_date'    => $date,
                'post_type'    => 'press-releases',
            ]);

            if ( $post_id ) {
                update_post_meta( $post_id, '_gnw_guid',       $guid );
                update_post_meta( $post_id, '_gnw_source_url', $link );

                // Page Links To integration
                update_post_meta( $post_id, '_links_to',                $link );
                update_post_meta( $post_id, '_links_to_target',         'custom' );
                update_post_meta( $post_id, '_links_to_target_new_window', '1' );

                $imported++;
            }
        }

        $this->write_log(
            'success',
            sprintf( 'Import complete. %d new, %d skipped (duplicate).', $imported, $skipped ),
            $imported,
            $skipped
        );
    }

    // -------------------------------------------------------------------------
    // DUPLICATE CHECK
    // Covers: posts imported by this plugin (_gnw_guid / _gnw_source_url),
    //         manually-added posts linked via Page Links To (_links_to),
    //         and any post with an identical title.
    // -------------------------------------------------------------------------

    private function exists( $guid, $link, $title = '' ) {

        $meta_query = new WP_Query([
            'post_type'      => 'press-releases',
            'post_status'    => 'any',
            'meta_query'     => [
                'relation' => 'OR',
                [ 'key' => '_gnw_guid',       'value' => $guid ],
                [ 'key' => '_gnw_source_url', 'value' => $link ],
                [ 'key' => '_links_to',       'value' => $link ],
            ],
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
        ]);

        if ( ! empty( $meta_query->posts ) ) {
            return true;
        }

        // Fallback: match by exact post title (catches manually-added posts
        // that have no meta keys set by this plugin).
        if ( $title ) {
            $title_query = new WP_Query([
                'post_type'      => 'press-releases',
                'post_status'    => 'any',
                'title'          => $title,
                'fields'         => 'ids',
                'posts_per_page' => 1,
                'no_found_rows'  => true,
            ]);

            if ( ! empty( $title_query->posts ) ) {
                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // ADMIN UI
    // -------------------------------------------------------------------------

    public function menu() {
        add_submenu_page(
            'tools.php',
            'GlobeNewswire Import',
            'GlobeNewswire Import',
            'manage_options',
            'gnw-import',
            [$this, 'page']
        );
    }

    public function page() {

        if ( isset($_GET['done']) ) {
            echo '<div class="notice notice-success is-dismissible"><p>Import complete.</p></div>';
        }
        if ( isset($_GET['cleared']) ) {
            echo '<div class="notice notice-success is-dismissible"><p>Log cleared.</p></div>';
        }
        ?>
        <div class="wrap">
            <h1>GlobeNewswire Importer</h1>

            <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                <input type="hidden" name="action" value="gnw_run_import">
                <?php wp_nonce_field('gnw_run_import'); ?>
                <?php submit_button('Run Import Now', 'secondary'); ?>
            </form>

            <hr>

            <form method="post" action="<?php echo esc_url( admin_url('options.php') ); ?>">
                <?php
                settings_fields('gnw_settings');
                do_settings_sections('gnw-import');
                submit_button('Save Settings');
                ?>
            </form>

            <hr>

            <h2>Cron Health</h2>
            <?php
            $cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
            $next_ts       = wp_next_scheduled('gnw_import_cron');
            $now           = time();

            if ( $cron_disabled ) :
            ?>
                <div class="notice notice-error inline"><p>
                    <strong>DISABLE_WP_CRON is set to true in wp-config.php.</strong><br>
                    WordPress will never run scheduled events automatically. You must trigger
                    <code>wp-cron.php</code> via a real system cron job (see below).
                </p></div>
            <?php elseif ( ! $next_ts ) : ?>
                <div class="notice notice-error inline"><p>
                    <strong>No cron event is scheduled.</strong> Save Settings to reschedule.
                </p></div>
            <?php elseif ( $next_ts < $now ) :
                $overdue_mins = round( ( $now - $next_ts ) / 60 );
            ?>
                <div class="notice notice-warning inline"><p>
                    <strong>Cron event is overdue by <?php echo (int) $overdue_mins; ?> minute(s).</strong><br>
                    WP-Cron only fires when someone visits this site — on a low-traffic or local site it may
                    never trigger automatically. Loading this admin page calls <code>spawn_cron()</code> to
                    kick it off, but for reliable scheduling you should add a real system cron (see below).
                </p></div>
            <?php else :
                $in_mins = round( ( $next_ts - $now ) / 60 );
            ?>
                <div class="notice notice-success inline"><p>
                    Cron is scheduled and on time. Next run in approximately <strong><?php echo (int) $in_mins; ?> minute(s)</strong>
                    (<?php echo esc_html( wp_date( 'Y-m-d H:i:s', $next_ts ) ); ?>).
                </p></div>
            <?php endif; ?>

            <details style="margin-top:12px;">
                <summary style="cursor:pointer;font-weight:600;">Real system cron setup (recommended for production &amp; local)</summary>
                <div style="background:#f6f7f7;border:1px solid #ddd;padding:12px 16px;margin-top:8px;max-width:800px;">
                    <p>Add one of these to your system scheduler to replace WP-Cron with a reliable trigger:</p>
                    <p><strong>Linux / macOS (crontab -e):</strong></p>
                    <code>*/15 * * * * curl -s <?php echo esc_url( site_url('wp-cron.php?doing_wp_cron') ); ?> &gt; /dev/null 2&gt;&amp;1</code>
                    <p style="margin-top:10px;"><strong>Windows Task Scheduler:</strong> Create a task that runs every 15 minutes executing:</p>
                    <code>curl -s "<?php echo esc_url( site_url('wp-cron.php?doing_wp_cron') ); ?>"</code>
                    <p style="margin-top:10px;">Also add this line to your <code>wp-config.php</code> to prevent WP from spawning its own cron on page load (optional but cleaner):</p>
                    <code>define( 'DISABLE_WP_CRON', true );</code>
                </div>
            </details>

            <hr>

            <h2>Import Log <span style="font-size:13px;font-weight:400;color:#666;">(last 50 runs)</span></h2>

            <?php
            $logs = get_option('gnw_import_log', []);
            if ( empty($logs) ) :
            ?>
                <p>No log entries yet. Run an import to see results here.</p>
            <?php else : ?>

                <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="margin-bottom:10px;">
                    <input type="hidden" name="action" value="gnw_clear_log">
                    <?php wp_nonce_field('gnw_clear_log'); ?>
                    <?php submit_button('Clear Log', 'delete small', 'submit', false); ?>
                </form>

                <table class="widefat striped" style="max-width:900px;">
                    <thead>
                        <tr>
                            <th style="width:160px;">Date / Time</th>
                            <th style="width:70px;">Status</th>
                            <th style="width:80px;">Imported</th>
                            <th style="width:80px;">Skipped</th>
                            <th>Message</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $logs as $entry ) :
                        $color = ( $entry['status'] === 'error' ) ? '#c0392b' : '#27ae60';
                    ?>
                        <tr>
                            <td><?php echo esc_html( wp_date( 'Y-m-d H:i:s', $entry['time'] ) ); ?></td>
                            <td><span style="color:<?php echo esc_attr($color); ?>;font-weight:600;"><?php echo esc_html( ucfirst( $entry['status'] ) ); ?></span></td>
                            <td><?php echo ( $entry['status'] === 'success' ) ? (int) $entry['imported'] : '&mdash;'; ?></td>
                            <td><?php echo ( $entry['status'] === 'success' ) ? (int) $entry['skipped']  : '&mdash;'; ?></td>
                            <td><?php echo esc_html( $entry['message'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

            <?php endif; ?>

        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // MANUAL IMPORT (admin-post handler)
    // -------------------------------------------------------------------------

    public function manual_import() {

        if ( ! current_user_can('manage_options') ) {
            wp_die('Unauthorized', 403);
        }

        check_admin_referer('gnw_run_import');

        $this->run_import();

        wp_redirect( admin_url('tools.php?page=gnw-import&done=1') );
        exit;
    }

    // -------------------------------------------------------------------------
    // CLEAR LOG (admin-post handler)
    // -------------------------------------------------------------------------

    public function clear_log() {

        if ( ! current_user_can('manage_options') ) {
            wp_die('Unauthorized', 403);
        }

        check_admin_referer('gnw_clear_log');

        delete_option('gnw_import_log');

        wp_redirect( admin_url('tools.php?page=gnw-import&cleared=1') );
        exit;
    }
}

new GNW_Importer();