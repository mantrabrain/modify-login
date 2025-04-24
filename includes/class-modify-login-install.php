<?php
/**
 * Fired during plugin activation
 *
 * @package ModifyLogin
 * @since 2.0.0
 */

namespace ModifyLogin\Core;

defined('ABSPATH') || exit;

/**
 * Fired during plugin activation.
 */
class Modify_Login_Install {
    /**
     * Install the plugin.
     */
    public static function install() {
        self::create_tables();
        self::create_options();
        self::create_roles();
        self::create_files();
        self::update_version();
        
        // Flush rewrite rules and reset the flag to ensure custom endpoints work
        update_option('modify_login_rewrite_rules_flushed', false);
        flush_rewrite_rules();
    }

    /**
     * Create database tables.
     */
    public static function create_tables() {
        global $wpdb;

        $wpdb->hide_errors();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        // Check if table already exists
        $table_name = $wpdb->prefix . 'modify_login_logs';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'");

        if (!$table_exists) {
            // Login logs table
            $sql = "CREATE TABLE {$table_name} (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                user_id bigint(20) NOT NULL,
                ip_address varchar(45) NOT NULL,
                user_agent text NOT NULL,
                status varchar(20) NOT NULL,
                attempted_username varchar(255) DEFAULT NULL,
                country varchar(100) DEFAULT NULL,
                city varchar(100) DEFAULT NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY user_id (user_id),
                KEY ip_address (ip_address),
                KEY status (status)
            ) $charset_collate;";

            dbDelta($sql);
        } else {
            self::update_tables();
        }
    }

    /**
     * Check and create tables if needed.
     */
    public static function maybe_create_tables() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'modify_login_logs';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'");
        
        if (!$table_exists) {
            self::create_tables();
        }
    }

    /**
     * Update existing tables.
     */
    private static function update_tables() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'modify_login_logs';
        
        // Check if attempted_username column exists
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM {$table_name} LIKE 'attempted_username'");
        
        if (!$column_exists) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN attempted_username varchar(255) DEFAULT NULL AFTER status");
        }

        // Check if country column exists
        $country_exists = $wpdb->get_var("SHOW COLUMNS FROM {$table_name} LIKE 'country'");
        if (!$country_exists) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN country varchar(100) DEFAULT NULL AFTER attempted_username");
        }

        // Check if city column exists
        $city_exists = $wpdb->get_var("SHOW COLUMNS FROM {$table_name} LIKE 'city'");
        if (!$city_exists) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN city varchar(100) DEFAULT NULL AFTER country");
        }
    }

    /**
     * Create default options.
     */
    private static function create_options() {
        // General settings
        add_option('modify_login_version', MODIFY_LOGIN_VERSION);
        //add_option('modify_login_login_endpoint', 'setup');
        // add_option('modify_login_redirect_url', home_url('404'));
        
        // // Design settings
        // add_option('modify_login_background_color', '#ffffff');
        // add_option('modify_login_text_color', '#333333');
        // add_option('modify_login_button_color', '#0073aa');
        // add_option('modify_login_button_text_color', '#ffffff');
        // add_option('modify_login_logo_url', '');
        // add_option('modify_login_background_image', '');
        
        // // Security settings
        // add_option('modify_login_enable_recaptcha', 'no');
        // add_option('modify_login_recaptcha_site_key', '');
        // add_option('modify_login_recaptcha_secret_key', '');
        // add_option('modify_login_max_login_attempts', 5);
        // add_option('modify_login_lockout_time', 30);
        
        // // Social login settings
        // add_option('modify_login_enable_social_login', 'no');
        // add_option('modify_login_google_client_id', '');
        // add_option('modify_login_google_client_secret', '');
        // add_option('modify_login_facebook_app_id', '');
        // add_option('modify_login_facebook_app_secret', '');
        
        // // Two-factor authentication settings
        // add_option('modify_login_enable_2fa', 'no');
        // add_option('modify_login_2fa_method', 'email');
        
        // // Email settings
        // add_option('modify_login_email_from_name', get_bloginfo('name'));
        // add_option('modify_login_email_from_address', get_bloginfo('admin_email'));
    }

    /**
     * Create custom roles.
     */
    private static function create_roles() {
        // Add custom capabilities to administrator role
        $admin_role = get_role('administrator');
        if ($admin_role) {
            $admin_role->add_cap('manage_modify_login');
        }
    }

    /**
     * Create files and directories.
     */
    private static function create_files() {
        // Create upload directory
        $upload_dir = wp_upload_dir();
        $files = array(
            array(
                'base'    => $upload_dir['basedir'] . '/modify-login',
                'file'    => 'index.html',
                'content' => '',
            ),
        );

        foreach ($files as $file) {
            if (wp_mkdir_p($file['base']) && !file_exists(trailingslashit($file['base']) . $file['file'])) {
                $file_handle = @fopen(trailingslashit($file['base']) . $file['file'], 'w');
                if ($file_handle) {
                    fwrite($file_handle, $file['content']);
                    fclose($file_handle);
                }
            }
        }
    }

    /**
     * Update plugin version.
     */
    private static function update_version() {
        update_option('modify_login_version', MODIFY_LOGIN_VERSION);
    }
} 