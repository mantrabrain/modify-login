<?php
/**
 * Modify_Login setup
 *
 * @package Modify_Login
 * @since   1.0.0
 */

defined('ABSPATH') || exit;

/**
 * Main Modify_Login Class.
 *
 * @class Modify_Login
 */
final class Modify_Login
{

    /**
     * Modify_Login version.
     *
     * @var string
     */
    public $version = '1.1';

    /**
     * The single instance of the class.
     *
     * @var Modify_Login
     * @since 2.1
     */
    protected static $_instance = null;

    /**
     * Main Modify_Login Instance.
     *
     * Ensures only one instance of Modify_Login is loaded or can be loaded.
     *
     * @return Modify_Login - Main instance.
     * @see sikshya()
     * @since 1.0.0
     * @static
     */
    public static function instance()
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Cloning is forbidden.
     *
     * @since 1.0.0
     */
    public function __clone()
    {
        _doing_it_wrong(__FUNCTION__, __('Cloning is forbidden.', 'sikshya'), '1.0.0');
    }

    /**
     * Unserializing instances of this class is forbidden.
     *
     * @since 2.1
     */
    public function __wakeup()
    {
        _doing_it_wrong(__FUNCTION__, __('Unserializing instances of this class is forbidden.', 'sikshya'), '1.0.0');
    }


    /**
     * Modify_Login Constructor.
     */
    public function __construct()
    {
        $this->init_hooks();
    }


    /**
     * Hook into actions and filters.
     *
     * @since 2.3
     */
    private function init_hooks()
    {
        register_activation_hook(MODIFY_LOGIN_FILE, array($this, 'activate'));

        add_action('login_init', array($this, 'login_head'), 1);
        add_action('login_form', array($this, 'login_form_field'));
        add_action('init', array($this, 'hide_login_init'));
        add_filter('lostpassword_url', array($this, 'hide_login_lostpassword'), 10, 0);

        add_action('lostpassword_form', array($this, 'login_form_field'));

        add_filter('lostpassword_redirect', array($this, 'login_lostpassword_redirect'), 100, 1);

        add_action('admin_menu', array($this, 'plugin_admin_menu'));

        $plugin = plugin_basename(MODIFY_LOGIN_FILE);

        add_filter("plugin_action_links_$plugin", array($this, 'setting'));


    }

    public function setting($links)
    {
        $settings_link = '<a href="options-general.php?page=modify-login">' . __('Settings', 'modify-login') . '</a>';

        array_unshift($links, $settings_link);

        return $links;
    }

    public function plugin_admin_menu()
    {
        add_options_page('Modify Login', 'Modify Login', 'manage_options', 'modify-login', array($this, 'options'));

    }

    private function use_trailing_slashes()
    {

        return ('/' === substr(get_option('permalink_structure'), -1, 1));

    }

    function options()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'modify-login'));
        }

        if (isset($_POST['login_endpoint'])) {

            $login_endpoint = sanitize_text_field($_POST['login_endpoint']);

            $this->update_login_endpoint($login_endpoint);
        }
        if (isset($_POST['redirect_url'])) {

            $redirect_url = sanitize_text_field($_POST['redirect_url']);

            $this->update_redirect_url($redirect_url);
        }


        $nonce = wp_create_nonce('modify-login');
        ?>

        <div class="wrap">
            <h1><?php echo __('Modify Login Setting', 'modify-login'); ?></h1>


            <form action="options-general.php?page=modify-login&_wpnonce=<?php echo $nonce; ?>" method="POST">

                <table class="form-table">

                    <tbody>
                    <tr>
                        <th scope="row"><label
                                    for="blogname"><?php echo __('Login endpoint', 'modify-login'); ?></label>
                        </th>
                        <td>

                            <?php
                            echo '<code>' . trailingslashit(home_url()) . '?</code>';

                            ?>
                            <input name="login_endpoint" type="text" id="login_endpoint"
                                   value="<?php echo esc_attr($this->get_login_endpoint()); ?>" class="regular-text">
                            <p><?php echo __('Secure your website by altering the login URL and restricting entry to the wp-login.php page and wp-admin directory for non-authenticated users!', 'modify-login'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="blogname"><?php echo __('Login URL', 'modify-login'); ?></label>
                        </th>
                        <td>
                            <code><?php echo esc_url(home_url()) . '?' . esc_html($this->get_login_endpoint()) ?></code>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label
                                    for="blogname"><?php echo __('Redirect URL', 'modify-login'); ?></label>
                        </th>
                        <td>

                            <input name="redirect_url" type="text" id="redirect_url"
                                   value="<?php echo esc_attr($this->get_redirect_url()); ?>" class="regular-text">
                            <p><?php echo __('Redirect the URL for unauthorized attempts to access the wp-login.php page and the wp-admin directory!', 'modify-login') ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label
                                    for="blogname"><?php echo __('Like this plugin ? ', 'modify-login'); ?></label></th>
                        <td><label><a href="https://wordpress.org/support/plugin/modify-login/reviews?rate=5#new-post"
                                      target="_blank"><?php echo __('Give it a 5
                                    star rating', 'modify-login'); ?></a></label></td>
                    </tr>


                    </tbody>
                </table>


                <p class="submit"><input type="submit" name="submit" id="submit" class="button button-primary"
                                         value="Save Changes"></p></form>

        </div>

        <?php
    }

    function lostpassword_redirect($lostpassword_redirect)
    {
        $endpoint = get_option('mb_login_endpoint');

        return 'wp-login.php?checkemail=confirm&redirect=false&' . $endpoint;
    }

    public function hide_login_lostpassword()
    {
        $endpoint = get_option('mb_login_endpoint');

        return site_url("wp-login.php?action=lostpassword&{$endpoint}&redirect=false");

    }

    private function get_login_endpoint()
    {

        return get_option('mb_login_endpoint', 'setup');


    }

    private function get_redirect_url()
    {

        return get_option('mb_redirect_url', home_url('404'));


    }

    private function update_login_endpoint($value)
    {

        if (!empty($value)) {

            update_option('mb_login_endpoint', $value);
        }
    }

    private function update_redirect_url($value)
    {

        if (!empty($value)) {

            update_option('mb_redirect_url', $value);
        }
    }

    public function hide_login_init()
    {

        $endpoint = $this->get_login_endpoint();

        if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY) == $endpoint) {
            wp_safe_redirect(home_url("wp-login.php?{$endpoint}&redirect=false"));
            exit();

        }
    }

    public function login_form_field()
    {
        $endpoint = get_option('mb_login_endpoint', '');
        ?>
        <input type="hidden" name="redirect_login_endpoint" value="<?php echo esc_attr($endpoint) ?>"/>
        <?php
    }

    public function login_head()
    {
        $endpoint = $this->get_login_endpoint();

        if (isset($_POST['redirect_login_endpoint']) && $_POST['redirect_login_endpoint'] == $endpoint) {
            return false;
        }

        if (strpos($_SERVER['REQUEST_URI'], 'action=logout') !== false) {
            check_admin_referer('log-out');
            $user = wp_get_current_user();
            wp_logout();
            wp_safe_redirect(home_url(), 302);
            die;
        }


        if ((strpos($_SERVER['REQUEST_URI'], $endpoint) === false) &&
            (strpos($_SERVER['REQUEST_URI'], 'wp-login.php') !== false)) {


            wp_safe_redirect($this->get_redirect_url(), 302);
            exit();

        }
    }

    public function activate()
    {
        add_option('mb_login_endpoint', 'setup', '', 'yes');

    }

}