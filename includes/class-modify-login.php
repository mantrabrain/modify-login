<?php
/**
 * Main Modify Login Class
 *
 * @package ModifyLogin
 * @since 2.0.0
 */

namespace ModifyLogin\Core;

defined('ABSPATH') || exit;

/**
 * Main Modify_Login Class
 */
final class Modify_Login
{
    /**
     * The single instance of the class.
     *
     * @var Modify_Login
     */
    protected static $instance = null;

    /**
     * Plugin version.
     *
     * @var string
     */
    public $version = '2.0.0';

    /**
     * Admin instance.
     *
     * @var ModifyLogin\Admin\Modify_Login_Admin
     */
    public $admin = null;

    /**
     * Frontend instance.
     *
     * @var ModifyLogin\Frontend\Modify_Login_Frontend
     */
    public $frontend = null;

    /**
     * Main Modify_Login Instance.
     *
     * Ensures only one instance of Modify_Login is loaded or can be loaded.
     *
     * @return Modify_Login - Main instance.
     */
    public static function instance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Modify_Login Constructor.
     */
    public function __construct()
    {
        $this->define_constants();
        $this->init_hooks();
        $this->includes();
    }

    /**
     * Define Constants.
     */
    private function define_constants()
    {
        $this->define('MODIFY_LOGIN_ABSPATH', dirname(MODIFY_LOGIN_FILE) . '/');
        $this->define('MODIFY_LOGIN_PLUGIN_BASENAME', plugin_basename(MODIFY_LOGIN_FILE));
    }

    /**
     * Define constant if not already set.
     *
     * @param string $name  Constant name.
     * @param string|bool $value Constant value.
     */
    private function define($name, $value)
    {
        if (!defined($name)) {
            define($name, $value);
        }
    }

    /**
     * Include required core files.
     */
    public function includes()
    {
        // Core classes
        include_once MODIFY_LOGIN_ABSPATH . 'includes/class-modify-login-loader.php';
        include_once MODIFY_LOGIN_ABSPATH . 'includes/class-modify-login-install.php';

        // Admin classes
        if (is_admin()) {
            require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/admin/class-modify-login-admin.php';
            $this->admin = new \ModifyLogin\Admin\Modify_Login_Admin('modify-login', $this->version);
        }

        // Frontend classes
        include_once MODIFY_LOGIN_ABSPATH . 'includes/frontend/class-modify-login-frontend.php';
        $this->frontend = new \ModifyLogin\Frontend\Modify_Login_Frontend('modify-login', $this->version);
    }

    /**
     * Hook into actions and filters.
     */
    private function init_hooks()
    {
        register_activation_hook(MODIFY_LOGIN_FILE, array('ModifyLogin\Core\Modify_Login_Install', 'install'));
        
        add_action('init', array($this, 'init'), 0);
        add_action('init', array($this, 'load_plugin_textdomain'));
        
        // Admin hooks
        if (is_admin()) {
            add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
        }
        
        // Frontend hooks
        add_action('wp_enqueue_scripts', array($this, 'frontend_scripts'));
    }

    /**
     * Init Modify Login when WordPress Initialises.
     */
    public function init()
    {
        // Before init action
        do_action('before_modify_login_init');

        // Set up localisation
        $this->load_plugin_textdomain();

        // Init action
        do_action('modify_login_init');
    }

    /**
     * Load Localisation files.
     */
    public function load_plugin_textdomain()
    {
        load_plugin_textdomain('modify-login', false, dirname(MODIFY_LOGIN_PLUGIN_BASENAME) . '/languages/');
    }

    /**
     * Register admin scripts and styles.
     */
    public function admin_scripts()
    {
        // Register settings CSS
        wp_register_style(
            'modify-login-settings',
            MODIFY_LOGIN_URL . 'src/admin/css/settings.css',
            array(),
            $this->version
        );

        // Register logs CSS
        wp_register_style(
            'modify-login-logs',
            MODIFY_LOGIN_URL . 'src/admin/css/login-logs.css',
            array(),
            $this->version
        );
    }

    /**
     * Register frontend scripts and styles.
     */
    public function frontend_scripts()
    {
        // No frontend styles needed
    }
}