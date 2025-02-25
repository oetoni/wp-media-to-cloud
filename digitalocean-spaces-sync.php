<?php
/**
 * Plugin Name: DigitalOcean Spaces Sync (Action Scheduler + PHP-Scoper + Advanced DB)
 * Description: Offload & serve WordPress Media from DigitalOcean Spaces, requiring or bundling WooCommerce Action Scheduler, chunked background processing, AWS SDK isolated via PHP-Scoper, advanced DB scanning for .jpg/.jpeg/.png references.
 * Version:     3.0.0
 * Author:      Your Name
 * Text Domain: do-spaces-sync
 */

namespace DigitalOcean_Spaces_Sync;

use WP_Error;
use WP_Post;
use Exception;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * --------------------------------------------------------------------
 * 1) Check or Load Action Scheduler
 * --------------------------------------------------------------------
 *
 * If Action Scheduler is not found, you can:
 *  - load a bundled copy from vendor
 *  - or fail plugin activation with an error message
 */
register_activation_hook( __FILE__, function() {
    // Check if Action Scheduler is already available (e.g. with WooCommerce or a separate plugin).
    if ( ! class_exists( '\ActionScheduler' ) ) {
        // Attempt to load from our vendor (if bundled) – demonstration:
        $maybe_vendor_path = __DIR__ . '/vendor/autoload.php';
        if ( file_exists( $maybe_vendor_path ) ) {
            require_once $maybe_vendor_path;
        }

        // If still not available, fail activation
        if ( ! class_exists( '\ActionScheduler' ) ) {
            deactivate_plugins( plugin_basename( __FILE__ ) );
            wp_die(
                __( 'DigitalOcean Spaces Sync requires WooCommerce Action Scheduler. Please install it or bundle it.', 'do-spaces-sync' ),
                __( 'Plugin Activation Error', 'do-spaces-sync' ),
                [ 'back_link' => true ]
            );
        }
    }
});

/**
 * --------------------------------------------------------------------
 * 2) Attempt to load the (Scoper-isolated) AWS SDK
 * --------------------------------------------------------------------
 *
 * We assume you've run PHP-Scoper to rename "Aws\" to something like "DOSpaces\Aws\"
 * in your final "build" folder or vendor folder.
 */
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    error_log( 'DO Spaces Sync: no vendor/autoload.php found. AWS SDK might not be available.' );
}

/**
 * --------------------------------------------------------------------
 * Main Plugin Class
 * --------------------------------------------------------------------
 */
class DOSpacesSyncPlugin {

    const OPTION_KEY            = 'do_spaces_sync_settings';
    const MIGRATION_OPTION_KEY  = 'do_spaces_sync_migration';
    const LOG_FOLDER_NAME       = 'do-spaces-sync-logs';
    const LOG_FILE_NAME         = 'do-spaces-sync.log';

    // For chunking
    const ATTACHMENTS_PER_CHUNK = 100;

    private static $instance = null;

    private $log_file_path = '';

    public static function get_instance() {
        if ( ! self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->setup_logging();

        // Admin pages
        add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );

        // Intercept new uploads
        add_filter( 'wp_update_attachment_metadata', [ $this, 'upload_attachment_to_spaces' ], 20, 2 );
        // Filter the URL
        add_filter( 'wp_get_attachment_url', [ $this, 'filter_attachment_url' ], 20, 2 );

        // Form submissions
        add_action( 'admin_post_do_spaces_sync_scan_db', [ $this, 'handle_scan_db_request' ] );
        add_action( 'admin_post_do_spaces_sync_start_migration', [ $this, 'handle_start_migration_request' ] );
        add_action( 'wp_ajax_do_spaces_sync_migration_progress', [ $this, 'ajax_migration_progress' ] );

        // Action Scheduler tasks
        add_action( 'do_spaces_sync_process_attachments_chunk', [ $this, 'process_attachments_chunk' ], 10, 2 );

        // Activation/Deactivation
        register_activation_hook( __FILE__, [ $this, 'activate_plugin' ] );
        register_deactivation_hook( __FILE__, [ $this, 'deactivate_plugin' ] );
    }

    // --------------------------------------------------
    // Logging
    // --------------------------------------------------
    private function setup_logging() {
        $upload_dir = wp_upload_dir( null, false );
        if ( ! empty( $upload_dir['basedir'] ) ) {
            $log_folder = trailingslashit( $upload_dir['basedir'] ) . self::LOG_FOLDER_NAME;
            if ( ! file_exists( $log_folder ) ) {
                @mkdir( $log_folder, 0755, true );
            }
            $this->log_file_path = trailingslashit( $log_folder ) . self::LOG_FILE_NAME;
            if ( ! file_exists( $this->log_file_path ) ) {
                @file_put_contents( $this->log_file_path, '' );
            }
        }
    }

    private function log_message( $message, $type = 'INFO' ) {
        $timestamp = date_i18n( 'Y-m-d H:i:s' );
        $line = "[$timestamp] [$type] $message\n";

        if ( $this->log_file_path && is_writable( $this->log_file_path ) ) {
            @file_put_contents( $this->log_file_path, $line, FILE_APPEND );
        } else {
            error_log( "DO Spaces Sync: $line" );
        }
    }

    // --------------------------------------------------
    // Activation / Deactivation
    // --------------------------------------------------

    public function activate_plugin() {
        $this->log_message( 'Plugin activated' );
        // Could do any setup needed
    }

    public function deactivate_plugin() {
        $this->log_message( 'Plugin deactivated' );
        // Possibly cleanup tasks or unschedule
    }

    // --------------------------------------------------
    // Admin Settings
    // --------------------------------------------------

    public function add_settings_page() {
        add_options_page(
            __( 'DO Spaces Sync', 'do-spaces-sync' ),
            __( 'DO Spaces Sync', 'do-spaces-sync' ),
            'manage_options',
            'do-spaces-sync',
            [ $this, 'render_settings_page' ]
        );
    }

    public function register_settings() {
        register_setting( 'do_spaces_sync_settings_group', self::OPTION_KEY );

        add_settings_section(
            'do_spaces_sync_main_section',
            __( 'DigitalOcean Spaces Settings', 'do-spaces-sync' ),
            function() {
                echo '<p>' . esc_html__( 'Configure your DO Spaces credentials and custom domain (if any). The plugin will upload new images automatically and rewrite URLs.', 'do-spaces-sync' ) . '</p>';
            },
            'do-spaces-sync'
        );

        // Access Key
        add_settings_field(
            'do_spaces_access_key',
            __( 'Access Key', 'do-spaces-sync' ),
            [ $this, 'render_text_field' ],
            'do-spaces-sync',
            'do_spaces_sync_main_section',
            [
                'label_for'  => 'do_spaces_access_key',
                'option_key' => self::OPTION_KEY,
                'field_key'  => 'access_key',
                'type'       => 'text',
            ]
        );

        // Secret Key
        add_settings_field(
            'do_spaces_secret_key',
            __( 'Secret Key', 'do-spaces-sync' ),
            [ $this, 'render_text_field' ],
            'do-spaces-sync',
            'do_spaces_sync_main_section',
            [
                'label_for'  => 'do_spaces_secret_key',
                'option_key' => self::OPTION_KEY,
                'field_key'  => 'secret_key',
                'type'       => 'password',
            ]
        );

        // Endpoint
        add_settings_field(
            'do_spaces_endpoint',
            __( 'Endpoint (e.g. nyc3.digitaloceanspaces.com)', 'do-spaces-sync' ),
            [ $this, 'render_text_field' ],
            'do-spaces-sync',
            'do_spaces_sync_main_section',
            [
                'label_for'  => 'do_spaces_endpoint',
                'option_key' => self::OPTION_KEY,
                'field_key'  => 'endpoint',
                'type'       => 'text',
            ]
        );

        // Bucket
        add_settings_field(
            'do_spaces_bucket',
            __( 'Bucket (Space Name)', 'do-spaces-sync' ),
            [ $this, 'render_text_field' ],
            'do-spaces-sync',
            'do_spaces_sync_main_section',
            [
                'label_for'  => 'do_spaces_bucket',
                'option_key' => self::OPTION_KEY,
                'field_key'  => 'bucket',
                'type'       => 'text',
            ]
        );

        // CNAME
        add_settings_field(
            'do_spaces_cname',
            __( 'Custom Domain (Optional)', 'do-spaces-sync' ),
            [ $this, 'render_text_field' ],
            'do-spaces-sync',
            'do_spaces_sync_main_section',
            [
                'label_for'  => 'do_spaces_cname',
                'option_key' => self::OPTION_KEY,
                'field_key'  => 'cname',
                'type'       => 'text',
            ]
        );
    }

    public function render_text_field( $args ) {
        $option_key = $args['option_key'];
        $field_key  = $args['field_key'];
        $type       = $args['type'] ?? 'text';

        $options = get_option( $option_key );
        $value   = $options[ $field_key ] ?? '';
        ?>
        <input
            type="<?php echo esc_attr( $type ); ?>"
            id="<?php echo esc_attr( $args['label_for'] ); ?>"
            name="<?php echo esc_attr( $option_key . '[' . $field_key . ']' ); ?>"
            value="<?php echo esc_attr( $value ); ?>"
            class="regular-text"
        />
        <?php
    }

    /**
     * Main settings page
     */
    public function render_settings_page() {
        $migration_data = get_option( self::MIGRATION_OPTION_KEY, [] );
        $scanned_tables = $migration_data['scanned_tables'] ?? [];
        $selected_tables = $migration_data['selected_tables'] ?? [];
        $use_advanced_replace = ! empty( $migration_data['advanced_replace'] );

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'DigitalOcean Spaces Sync Settings', 'do-spaces-sync' ); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'do_spaces_sync_settings_group' );
                do_settings_sections( 'do-spaces-sync' );
                submit_button();
                ?>
            </form>

            <hr>

            <h2><?php esc_html_e( 'Step 1: Scan Database for Image References', 'do-spaces-sync' ); ?></h2>
            <p><?php esc_html_e( 'This will scan non-core tables to see if they contain references to .jpg/.jpeg/.png files in wp-content/uploads. Large DBs may take time.', 'do-spaces-sync' ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                <?php wp_nonce_field( 'do_spaces_sync_scan_db', 'do_spaces_sync_scan_db_nonce' ); ?>
                <input type="hidden" name="action" value="do_spaces_sync_scan_db" />
                <?php submit_button( __( 'Scan Database', 'do-spaces-sync' ), 'secondary' ); ?>
            </form>

            <?php if ( ! empty( $scanned_tables ) ) : ?>
                <hr>
                <h2><?php esc_html_e( 'Step 2: Choose Tables & Method for Search & Replace', 'do-spaces-sync' ); ?></h2>
                <p>
                    <?php esc_html_e( 'Below are the non-core tables where we found references to .jpg/.jpeg/.png in wp-content/uploads. Select which to include in the replacement.', 'do-spaces-sync' ); ?>
                </p>
                <p>
                    <strong><?php esc_html_e( 'Advanced Search/Replace?', 'do-spaces-sync' ); ?></strong><br/>
                    <?php esc_html_e( 'If your data is stored in serialized arrays or JSON, a naive REPLACE() might break them. Check "Use advanced method" to attempt row-by-row replacement that unserializes or decodes JSON. This is slower but safer for complex data.', 'do-spaces-sync' ); ?>
                </p>

                <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                    <?php wp_nonce_field( 'do_spaces_sync_start_migration', 'do_spaces_sync_migrate_nonce' ); ?>
                    <input type="hidden" name="action" value="do_spaces_sync_start_migration" />

                    <table class="widefat">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Include?', 'do-spaces-sync' ); ?></th>
                                <th><?php esc_html_e( 'Table Name', 'do-spaces-sync' ); ?></th>
                                <th><?php esc_html_e( 'Rows with .jpg/.jpeg/.png references', 'do-spaces-sync' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $scanned_tables as $table => $row_count ) : ?>
                            <tr>
                                <td>
                                    <input
                                        type="checkbox"
                                        name="selected_tables[]"
                                        value="<?php echo esc_attr( $table ); ?>"
                                        <?php checked( in_array( $table, $selected_tables, true ) ); ?>
                                    />
                                </td>
                                <td><?php echo esc_html( $table ); ?></td>
                                <td><?php echo esc_html( $row_count ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <p>
                        <label>
                            <input
                                type="checkbox"
                                name="advanced_replace"
                                value="1"
                                <?php checked( $use_advanced_replace ); ?>
                            />
                            <?php esc_html_e( 'Use advanced row-by-row replacement (handles serialized or JSON data)', 'do-spaces-sync' ); ?>
                        </label>
                    </p>

                    <p>
                        <?php submit_button( __( 'Start Media Migration & URL Replacement', 'do-spaces-sync' ), 'primary', 'submit', false ); ?>
                    </p>
                </form>
            <?php endif; ?>

            <hr>

            <h2><?php esc_html_e( 'Migration Progress', 'do-spaces-sync' ); ?></h2>
            <div id="do-spaces-sync-progress-container">
                <p><?php esc_html_e( 'No migration in progress.', 'do-spaces-sync' ); ?></p>
            </div>
            <script>
            (function($){
                function loadProgress() {
                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        data: {
                            action: 'do_spaces_sync_migration_progress'
                        },
                        success: function(response) {
                            $('#do-spaces-sync-progress-container').html(response);
                        }
                    });
                }
                setInterval(loadProgress, 5000);
                loadProgress();
            })(jQuery);
            </script>
        </div>
        <?php
    }

    // --------------------------------------------------
    // Step 1: Scan DB (for .jpg, .jpeg, .png)
    // --------------------------------------------------

    public function handle_scan_db_request() {
        if (
            ! current_user_can( 'manage_options' ) ||
            ! isset( $_POST['do_spaces_sync_scan_db_nonce'] ) ||
            ! wp_verify_nonce( $_POST['do_spaces_sync_scan_db_nonce'], 'do_spaces_sync_scan_db' )
        ) {
            wp_die( __( 'Unauthorized request', 'do-spaces-sync' ) );
        }

        $scanned = $this->scan_db_for_image_references();
        $migration_data = get_option( self::MIGRATION_OPTION_KEY, [] );
        $migration_data['scanned_tables'] = $scanned;
        update_option( self::MIGRATION_OPTION_KEY, $migration_data );

        wp_safe_redirect( admin_url( 'options-general.php?page=do-spaces-sync' ) );
        exit;
    }

    /**
     * Find references to .jpg/.jpeg/.png in WP-Content/Uploads in non-core tables.
     */
    private function scan_db_for_image_references() {
        global $wpdb;
        $results = [];

        $all_tables = $wpdb->get_col( 'SHOW TABLES' );
        if ( ! $all_tables ) {
            return $results;
        }

        // Known WP core tables to skip
        $core_tables = [
            $wpdb->prefix . 'posts',
            $wpdb->prefix . 'postmeta',
            $wpdb->prefix . 'options',
            $wpdb->prefix . 'comments',
            $wpdb->prefix . 'commentmeta',
            $wpdb->prefix . 'users',
            $wpdb->prefix . 'usermeta',
            $wpdb->prefix . 'terms',
            $wpdb->prefix . 'term_taxonomy',
            $wpdb->prefix . 'termmeta',
            $wpdb->prefix . 'term_relationships',
            $wpdb->prefix . 'links',
        ];

        // We look for patterns: wp-content/uploads/... with .jpg/.jpeg/.png
        // For a naive approach, let's do a LIKE '%wp-content/uploads%.jpg%'
        // or for each extension. Or we can do a single REGEXP if supported:
        // But let's keep it simple:
        $like_clauses = [
            "LIKE '%wp-content/uploads%.jpg%'",
            "LIKE '%wp-content/uploads%.jpeg%'",
            "LIKE '%wp-content/uploads%.png%'",
        ];

        foreach ( $all_tables as $table ) {
            if ( in_array( $table, $core_tables, true ) ) {
                continue; 
            }

            $columns = $wpdb->get_results( "SHOW COLUMNS FROM `$table`", ARRAY_A );
            if ( ! $columns ) {
                continue;
            }

            // Build a set of OR clauses for text-based columns
            $where_clauses = [];
            foreach ( $columns as $col ) {
                $col_name = esc_sql( $col['Field'] );
                if ( preg_match( '/(text|char|blob|binary|varchar)/i', $col['Type'] ) ) {
                    // For each extension-based clause
                    foreach ( $like_clauses as $like ) {
                        $where_clauses[] = "`$col_name` $like";
                    }
                }
            }
            if ( ! $where_clauses ) {
                continue;
            }

            $sql = "SELECT COUNT(*) FROM `$table` WHERE " . implode( ' OR ', $where_clauses );
            $count = $wpdb->get_var( $sql );
            if ( $count > 0 ) {
                $results[ $table ] = $count;
            }
        }

        return $results;
    }

    // --------------------------------------------------
    // Step 2: Start Migration (Chunked)
    // --------------------------------------------------

    public function handle_start_migration_request() {
        if (
            ! current_user_can( 'manage_options' ) ||
            ! isset( $_POST['do_spaces_sync_migrate_nonce'] ) ||
            ! wp_verify_nonce( $_POST['do_spaces_sync_migrate_nonce'], 'do_spaces_sync_start_migration' )
        ) {
            wp_die( __( 'Unauthorized request', 'do-spaces-sync' ) );
        }

        $selected_tables = isset( $_POST['selected_tables'] ) ? (array) $_POST['selected_tables'] : [];
        $advanced_replace = ! empty( $_POST['advanced_replace'] );

        // Store user selection
        $migration_data = get_option( self::MIGRATION_OPTION_KEY, [] );
        $migration_data['selected_tables'] = $selected_tables;
        $migration_data['advanced_replace'] = $advanced_replace;
        update_option( self::MIGRATION_OPTION_KEY, $migration_data );

        // Initialize counters
        $attachments = $this->get_all_attachments();
        $total = count( $attachments );
        $migration_data['total']     = $total;
        $migration_data['completed'] = 0;
        $migration_data['errors']    = 0;
        update_option( self::MIGRATION_OPTION_KEY, $migration_data );

        // Chunk the attachments
        $chunks = array_chunk( $attachments, self::ATTACHMENTS_PER_CHUNK );
        $index = 0;
        foreach ( $chunks as $chunk ) {
            $index++;
            // Schedule each chunk
            \as_schedule_single_action(
                time() + ( $index * 10 ), // offset by 10 seconds per chunk to spread out load
                'do_spaces_sync_process_attachments_chunk',
                [ $chunk, $index ],  // pass chunk array, chunk index
                'DO-Spaces-Sync'
            );
        }

        $this->log_message( "Scheduled " . count( $chunks ) . " chunk(s) for migration." );

        wp_safe_redirect( admin_url( 'options-general.php?page=do-spaces-sync' ) );
        exit;
    }

    /**
     * Retrieve all attachment IDs
     */
    private function get_all_attachments() {
        $args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ];
        return get_posts( $args );
    }

    // --------------------------------------------------
    // Action Scheduler Task for each chunk
    // --------------------------------------------------
    public function process_attachments_chunk( $attachment_ids, $chunk_index ) {
        $this->log_message( "Processing chunk #$chunk_index with " . count( $attachment_ids ) . " attachments." );

        foreach ( $attachment_ids as $attachment_id ) {
            $this->process_single_attachment( $attachment_id );
        }
    }

    /**
     * Processes a single attachment: uploads to DO Spaces + DB replacements
     */
    private function process_single_attachment( $attachment_id ) {
        $meta = wp_get_attachment_metadata( $attachment_id );
        if ( ! $meta || ! is_array( $meta ) ) {
            $this->increment_migration_counter( false );
            return;
        }

        $updated_meta = $this->upload_attachment_to_spaces( $meta, $attachment_id );
        if ( is_array( $updated_meta ) ) {
            wp_update_attachment_metadata( $attachment_id, $updated_meta );

            // Replace references in WP core + custom tables
            $local_url = $this->get_local_url( $attachment_id );
            $spaces_url = $this->build_spaces_url_from_local( $local_url );
            if ( $spaces_url && $local_url !== $spaces_url ) {
                $this->replace_in_wp_core_tables( $local_url, $spaces_url );
                $this->replace_in_custom_tables( $local_url, $spaces_url );
            }
            $this->increment_migration_counter( true );
        } else {
            // error
            $this->increment_migration_counter( false );
        }
    }

    // --------------------------------------------------
    // Migration Progress (AJAX)
    // --------------------------------------------------

    public function ajax_migration_progress() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized', 'do-spaces-sync' ) );
        }

        $migration_data = get_option( self::MIGRATION_OPTION_KEY, [] );
        $total     = $migration_data['total']     ?? 0;
        $completed = $migration_data['completed'] ?? 0;
        $errors    = $migration_data['errors']    ?? 0;
        if ( ! $total ) {
            echo '<p>No migration initiated.</p>';
            wp_die();
        }

        $percent = $total ? ( $completed / $total * 100 ) : 0;
        $percent_display = number_format_i18n( $percent, 2 );

        echo '<p>';
        printf(
            __( 'Processed: %d / %d (%.2f%%). Errors: %d.', 'do-spaces-sync' ),
            $completed,
            $total,
            $percent,
            $errors
        );
        echo '</p>';

        echo '<div style="background: #eee; width: 300px; height: 20px; border: 1px solid #ccc; position: relative;">';
        echo '  <div style="background: #007cba; width: ' . esc_attr( $percent ) . '%; height: 100%;"></div>';
        echo '</div>';

        wp_die();
    }

    private function increment_migration_counter( $success ) {
        $migration_data = get_option( self::MIGRATION_OPTION_KEY, [] );
        if ( $success ) {
            $migration_data['completed'] = ( $migration_data['completed'] ?? 0 ) + 1;
        } else {
            $migration_data['errors'] = ( $migration_data['errors'] ?? 0 ) + 1;
            $migration_data['completed'] = ( $migration_data['completed'] ?? 0 ) + 1;
        }
        update_option( self::MIGRATION_OPTION_KEY, $migration_data );
    }

    // --------------------------------------------------
    // File Upload + URL Filters
    // --------------------------------------------------

    public function upload_attachment_to_spaces( $data, $attachment_id ) {
        if ( empty( $data['file'] ) ) {
            return $data;
        }

        $upload_dir = wp_upload_dir();
        $file_path  = path_join( $upload_dir['basedir'], $data['file'] );
        if ( ! file_exists( $file_path ) ) {
            return $data;
        }

        $settings = get_option( self::OPTION_KEY );
        foreach ( [ 'access_key', 'secret_key', 'endpoint', 'bucket' ] as $req ) {
            if ( empty( $settings[ $req ] ) ) {
                $this->log_message( "Missing required setting '$req'. Cannot upload attachment #$attachment_id.", 'ERROR' );
                return $data;
            }
        }

        $result = $this->upload_file_to_spaces( $file_path, $data['file'], $attachment_id );
        if ( is_wp_error( $result ) ) {
            $this->log_message( "Upload error for #$attachment_id: " . $result->get_error_message(), 'ERROR' );
            return $data;
        }

        // Optionally remove local file
        // @unlink( $file_path );

        return $data;
    }

    public function filter_attachment_url( $url, $post_id ) {
        $settings = get_option( self::OPTION_KEY );
        if (
            empty( $settings['access_key'] ) ||
            empty( $settings['secret_key'] ) ||
            empty( $settings['endpoint'] ) ||
            empty( $settings['bucket'] )
        ) {
            return $url; // not configured
        }

        $upload_dir = wp_upload_dir();
        $base_url = $upload_dir['baseurl'];
        if ( strpos( $url, $base_url ) === false ) {
            return $url; // already external or different
        }

        $relative_path = ltrim( str_replace( $base_url, '', $url ), '/' );
        if ( ! empty( $settings['cname'] ) ) {
            return rtrim( $settings['cname'], '/' ) . '/' . $relative_path;
        } else {
            return sprintf( 'https://%s.%s/%s', $settings['bucket'], $settings['endpoint'], $relative_path );
        }
    }

    // --------------------------------------------------
    // URL Replacement
    // --------------------------------------------------

    private function get_local_url( $attachment_id ) {
        $file = get_post_meta( $attachment_id, '_wp_attached_file', true );
        if ( $file ) {
            $upload_dir = wp_upload_dir();
            return trailingslashit( $upload_dir['baseurl'] ) . $file;
        }
        return '';
    }

    private function build_spaces_url_from_local( $local_url ) {
        $settings = get_option( self::OPTION_KEY );
        if (
            empty( $settings['access_key'] ) ||
            empty( $settings['secret_key'] ) ||
            empty( $settings['endpoint'] ) ||
            empty( $settings['bucket'] )
        ) {
            return false;
        }

        $upload_dir = wp_upload_dir();
        $base_url   = $upload_dir['baseurl'];
        $relative   = ltrim( str_replace( $base_url, '', $local_url ), '/' );

        if ( ! empty( $settings['cname'] ) ) {
            return rtrim( $settings['cname'], '/' ) . '/' . $relative;
        } else {
            return sprintf( 'https://%s.%s/%s', $settings['bucket'], $settings['endpoint'], $relative );
        }
    }

    /**
     * Replace references in WP core (posts + postmeta) using REPLACE()
     * or advanced method if requested.
     */
    private function replace_in_wp_core_tables( $old_url, $new_url ) {
        // posts (post_content) + postmeta (meta_value)
        global $wpdb;
        $migration_data = get_option( self::MIGRATION_OPTION_KEY, [] );
        $advanced_replace = ! empty( $migration_data['advanced_replace'] );

        if ( $advanced_replace ) {
            // Advanced row-by-row approach for posts
            $table_posts = $wpdb->prefix . 'posts';
            $this->advanced_table_replace( $table_posts, 'ID', 'post_content', $old_url, $new_url );

            // Advanced for postmeta
            $table_meta = $wpdb->prefix . 'postmeta';
            $this->advanced_table_replace( $table_meta, 'meta_id', 'meta_value', $old_url, $new_url );
        } else {
            // Naive REPLACE
            $old_esc = esc_sql( $old_url );
            $new_esc = esc_sql( $new_url );

            // posts
            $table_posts = $wpdb->prefix . 'posts';
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE $table_posts SET post_content = REPLACE(post_content, %s, %s)",
                    $old_esc,
                    $new_esc
                )
            );
            // postmeta
            $table_meta = $wpdb->prefix . 'postmeta';
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE $table_meta SET meta_value = REPLACE(meta_value, %s, %s)",
                    $old_esc,
                    $new_esc
                )
            );
        }
    }

    /**
     * Replace references in user-selected custom tables, similarly.
     */
    private function replace_in_custom_tables( $old_url, $new_url ) {
        global $wpdb;
        $migration_data = get_option( self::MIGRATION_OPTION_KEY, [] );
        $advanced_replace = ! empty( $migration_data['advanced_replace'] );
        $selected_tables = $migration_data['selected_tables'] ?? [];

        foreach ( $selected_tables as $table ) {
            if ( $advanced_replace ) {
                // We don't necessarily know a primary key or which columns to target.
                // We'll do a naive approach scanning for text columns.
                $this->advanced_replace_for_entire_table( $table, $old_url, $new_url );
            } else {
                // Naive REPLACE across text columns
                $this->naive_replace_for_entire_table( $table, $old_url, $new_url );
            }
        }
    }

    /**
     * NAIVE: Build a set of `col = REPLACE(col, old, new)` for each text column
     */
    private function naive_replace_for_entire_table( $table, $old_url, $new_url ) {
        global $wpdb;
        $columns = $wpdb->get_results( "SHOW COLUMNS FROM `$table`", ARRAY_A );
        if ( ! $columns ) {
            return;
        }

        $old_esc = esc_sql( $old_url );
        $new_esc = esc_sql( $new_url );

        $update_clauses = [];
        foreach ( $columns as $col ) {
            $colname = $col['Field'];
            if ( preg_match( '/(text|char|blob|binary|varchar)/i', $col['Type'] ) ) {
                $update_clauses[] = "`$colname` = REPLACE(`$colname`, '$old_esc', '$new_esc')";
            }
        }

        if ( ! empty( $update_clauses ) ) {
            $sql = "UPDATE `$table` SET " . implode( ', ', $update_clauses );
            $wpdb->query( $sql );
        }
    }

    /**
     * ADVANCED: For an entire table, read row by row, unserialize or JSON-decode if possible,
     * replace, then re-serialize or re-encode. This is extremely naive but can handle many cases.
     */
    private function advanced_replace_for_entire_table( $table, $old_url, $new_url ) {
        global $wpdb;
        $pk_column = $this->get_primary_key( $table );
        if ( ! $pk_column ) {
            // fallback to naive
            $this->naive_replace_for_entire_table( $table, $old_url, $new_url );
            return;
        }

        $columns = $wpdb->get_results( "SHOW COLUMNS FROM `$table`", ARRAY_A );
        if ( ! $columns ) {
            return;
        }

        // We'll get text-based columns
        $text_cols = [];
        foreach ( $columns as $col ) {
            if ( preg_match( '/(text|char|blob|binary|varchar)/i', $col['Type'] ) ) {
                $text_cols[] = $col['Field'];
            }
        }
        if ( ! $text_cols ) {
            return;
        }

        // Retrieve all rows, row by row
        $row_ids = $wpdb->get_col( "SELECT `$pk_column` FROM `$table`" );
        foreach ( $row_ids as $row_id ) {
            // fetch the row
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM `$table` WHERE `$pk_column` = %s LIMIT 1",
                    $row_id
                ),
                ARRAY_A
            );
            if ( ! $row ) {
                continue;
            }

            $changed = false;
            $update_data = [];

            foreach ( $text_cols as $colname ) {
                $orig_value = $row[ $colname ];
                if ( false === strpos( $orig_value, $old_url ) ) {
                    continue; // no reference
                }
                // Attempt advanced replace
                $new_value = $this->advanced_replace_in_value( $orig_value, $old_url, $new_url );
                if ( $new_value !== $orig_value ) {
                    $update_data[ $colname ] = $new_value;
                    $changed = true;
                }
            }

            if ( $changed ) {
                // build update
                $update_where = [ $pk_column => $row_id ];
                $wpdb->update( $table, $update_data, $update_where );
            }
        }
    }

    /**
     * ADVANCED single value replacement: tries direct str_replace,
     * then attempts to unserialize or JSON decode, do a deeper replace, then re-encode.
     */
    private function advanced_replace_in_value( $value, $old_url, $new_url ) {
        // direct str_replace
        $replaced = str_replace( $old_url, $new_url, $value );

        // Attempt unserialize
        $maybe_unserialized = @unserialize( $value );
        if ( false !== $maybe_unserialized || $maybe_unserialized === [] ) {
            // we have a valid unserialized array/object
            $maybe_unserialized = $this->recursive_replace( $maybe_unserialized, $old_url, $new_url );
            $replaced_ser = serialize( $maybe_unserialized );
            // If that changed the data, return it
            if ( $replaced_ser !== $value ) {
                return $replaced_ser;
            }
        }

        // Attempt JSON decode
        $maybe_json = json_decode( $value, true );
        if ( is_array( $maybe_json ) ) {
            $maybe_json = $this->recursive_replace( $maybe_json, $old_url, $new_url );
            $replaced_json = json_encode( $maybe_json );
            if ( $replaced_json && $replaced_json !== $value ) {
                return $replaced_json;
            }
        }

        return $replaced;
    }

    /**
     * Recursively replace $old_url with $new_url in arrays/strings.
     */
    private function recursive_replace( $data, $old_url, $new_url ) {
        if ( is_string( $data ) ) {
            return str_replace( $old_url, $new_url, $data );
        } elseif ( is_array( $data ) ) {
            foreach ( $data as $key => $val ) {
                $data[$key] = $this->recursive_replace( $val, $old_url, $new_url );
            }
            return $data;
        } elseif ( is_object( $data ) ) {
            foreach ( get_object_vars( $data ) as $prop => $val ) {
                $data->$prop = $this->recursive_replace( $val, $old_url, $new_url );
            }
            return $data;
        }
        return $data;
    }

    /**
     * Get primary key for a given table, if any. Return false if unknown.
     */
    private function get_primary_key( $table ) {
        global $wpdb;
        $indexes = $wpdb->get_results( "SHOW INDEX FROM `$table`", ARRAY_A );
        if ( ! $indexes ) {
            return false;
        }
        foreach ( $indexes as $index ) {
            if ( $index['Key_name'] === 'PRIMARY' ) {
                return $index['Column_name'];
            }
        }
        return false;
    }

    // --------------------------------------------------
    // AWS SDK Upload
    // --------------------------------------------------

    private function upload_file_to_spaces( $file_path, $key, $attachment_id ) {
        $settings = get_option( self::OPTION_KEY );
        if ( ! $this->sdk_available() ) {
            return new WP_Error( 'no_sdk', 'AWS SDK (namespaced via PHP-Scoper) not available.' );
        }

        try {
            // Using the "scoped" classes from our vendor
            // e.g. DOSpaces\Aws\S3\S3Client if your scoper rename is DOSpaces\Aws
            $s3 = new \DOSpaces\Aws\S3\S3Client([
                'version'     => 'latest',
                'region'      => 'us-east-1',
                'endpoint'    => 'https://' . $settings['endpoint'],
                'credentials' => [
                    'key'    => $settings['access_key'],
                    'secret' => $settings['secret_key'],
                ],
                'use_path_style_endpoint' => true,
            ]);

            $key = ltrim( $key, '/' );

            $result = $s3->putObject([
                'Bucket'      => $settings['bucket'],
                'Key'         => $key,
                'Body'        => fopen( $file_path, 'rb' ),
                'ACL'         => 'public-read',
                'ContentType' => $this->get_mime_type( $file_path ),
            ]);

            if ( isset( $result['ObjectURL'] ) ) {
                $this->log_message( "Uploaded attachment #$attachment_id to DO Spaces: " . $result['ObjectURL'] );
                return true;
            } else {
                return new WP_Error( 'upload_failed', 'No ObjectURL returned from putObject.' );
            }
        } catch ( Exception $e ) {
            return new WP_Error( 'upload_exception', $e->getMessage() );
        }
    }

    private function sdk_available() {
        // Check for our scoper-based S3Client
        return class_exists( '\DOSpaces\Aws\S3\S3Client' );
    }

    private function get_mime_type( $file_path ) {
        $mime = 'application/octet-stream';
        if ( function_exists( 'finfo_open' ) ) {
            $finfo = finfo_open( FILEINFO_MIME_TYPE );
            $detected = finfo_file( $finfo, $file_path );
            if ( $detected ) {
                $mime = $detected;
            }
            finfo_close( $finfo );
        } elseif ( function_exists( 'mime_content_type' ) ) {
            $detected = mime_content_type( $file_path );
            if ( $detected ) {
                $mime = $detected;
            }
        }
        return $mime;
    }
}

// Instantiate plugin
DOSpacesSyncPlugin::get_instance();
