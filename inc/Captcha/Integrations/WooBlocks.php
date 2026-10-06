<?php
/**
 * WooCommerce block checkout.
 *
 * @package Authlify
 */

namespace Authlify\Captcha\Integrations;

use Authlify\Captcha\Captcha;
use Authlify\Captcha\Honeypot;
use Authlify\Captcha\Integrations;

defined('ABSPATH') || exit;

/**
 * The Checkout block places orders through the Store API
 * (POST /wc/store/v1/checkout), not the classic checkout form, so the classic
 * hook never runs for it.
 *
 * - The widget is printed after the checkout block and moved in front of the
 *   "Place order" row by a small script, which passes the token (and the
 *   honeypot fields) to the Store API as extension data in the "authlify"
 *   namespace.
 * - The check runs when the order is placed (POST only, never on the form's
 *   own PUT updates) for visitors who are not logged in:
 *   - always, when "WooCommerce checkout" is ticked;
 *   - only when the order creates a customer account, when just
 *     "WooCommerce registration" is ticked.
 * - The block checkout has no login form of its own (it links to My Account,
 *   which the "WooCommerce login" switch covers).
 *
 * @since 3.1.0
 */
final class WooBlocks
{
    /**
     * Store API extension namespace.
     */
    const NS = 'authlify';

    /**
     * Wire up.
     */
    public static function init()
    {
        if (did_action('init')) {
            self::register_schema();
        } else {
            add_action('init', array(__CLASS__, 'register_schema'));
        }
        add_filter('render_block_woocommerce/checkout', array(__CLASS__, 'render'), 10, 1);
        add_action('woocommerce_store_api_checkout_update_order_from_request', array(__CLASS__, 'check'), 10, 2);
    }

    /**
     * Declare the extension data the checkout may send.
     */
    public static function register_schema()
    {
        if (!function_exists('woocommerce_store_api_register_endpoint_data') || !class_exists('Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema')) {
            return;
        }

        $field = function ($description) {
            return array(
                'description' => $description,
                'type' => array('string', 'null'),
                'context' => array(),
                'arg_options' => array(
                    'validate_callback' => function ($value) {
                        return null === $value || (is_string($value) && strlen($value) <= 10000);
                    },
                ),
            );
        };

        woocommerce_store_api_register_endpoint_data(array(
            'endpoint' => \Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema::IDENTIFIER,
            'namespace' => self::NS,
            'schema_callback' => function () use ($field) {
                return array(
                    'token' => $field('CAPTCHA response.'),
                    'hp' => $field('Honeypot field (must stay empty).'),
                    'ts' => $field('Honeypot time stamp.'),
                );
            },
            'schema_type' => ARRAY_A,
        ));
    }

    /**
     * The form switch that applies to a guest block checkout right now.
     *
     * @param bool $creates_account Whether the order creates an account (null: it may).
     * @return string Form key, or ''.
     */
    public static function form($creates_account = null)
    {
        if (Captcha::form_enabled('woo_checkout')) {
            return 'woo_checkout';
        }

        if (false !== $creates_account && Captcha::form_enabled('woo_register') && self::registration_enabled()) {
            return 'woo_register';
        }

        return '';
    }

    /**
     * Whether shoppers can (or must) create an account at checkout.
     *
     * @return bool
     */
    private static function registration_enabled()
    {
        return function_exists('WC') && WC()->checkout() && filter_var(WC()->checkout()->is_registration_enabled(), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Print the box after the checkout block (its script moves it in place).
     *
     * @param string $content Block HTML.
     * @return string
     */
    public static function render($content)
    {
        if (is_user_logged_in() || is_admin()) {
            return $content;
        }

        $form = self::form();
        if ('' === $form) {
            return $content;
        }

        $html = Captcha::render($form, array('attrs' => array('wc-blocks' => '1')));
        if ('' === $html) {
            return $content;
        }

        wp_enqueue_script('authlify-captcha-wc-blocks', AUTHLIFY_URL . 'assets/captcha/wc-blocks.js', array('wp-data'), AUTHLIFY_VERSION, array('in_footer' => true));
        wp_localize_script('authlify-captcha-wc-blocks', 'authlifyWcBlocks', array(
            'ns' => self::NS,
            'fields' => self::fields(),
        ));

        return $content . $html;
    }

    /**
     * Names of the inputs the script sends: token field per provider, honeypot.
     *
     * @return array
     */
    private static function fields()
    {
        $tokens = array();
        foreach (Captcha::providers() as $provider) {
            $tokens[] = $provider->field();
        }

        return array('token' => array_values(array_unique($tokens)), 'hp' => Honeypot::FIELD, 'ts' => Honeypot::STAMP);
    }

    /**
     * Whether this Store API request creates a customer account (Woo's own rule).
     *
     * @param \WP_REST_Request $request Request.
     * @return bool
     */
    private static function creates_account($request)
    {
        if (is_user_logged_in() || !self::registration_enabled()) {
            return false;
        }

        if (filter_var(WC()->checkout()->is_registration_required(), FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        return true === filter_var($request['create_account'], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Verify when the order is placed.
     *
     * @param \WC_Order        $order   Order.
     * @param \WP_REST_Request $request Request.
     * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When the check fails.
     */
    public static function check($order, $request)
    {
        if (!$request instanceof \WP_REST_Request || 'POST' !== strtoupper((string) $request->get_method()) || is_user_logged_in()) {
            return;
        }

        $form = self::form(self::creates_account($request));

        /**
         * Filters whether a guest block checkout must pass the CAPTCHA.
         *
         * @param bool             $check   Check it.
         * @param string           $form    woo_checkout, woo_register or '' (none applies).
         * @param \WP_REST_Request $request Store API request.
         * @since 3.1.0
         */
        if ('' === $form || !apply_filters('authlify_woo_blocks_check', true, $form, $request)) {
            return;
        }

        $extensions = $request->get_param('extensions');
        $data = is_array($extensions) && isset($extensions[self::NS]) && is_array($extensions[self::NS]) ? $extensions[self::NS] : array();

        // The checks read the same fields a form post carries.
        $provider = Captcha::active_provider();
        if ($provider) {
            $_POST[$provider->field()] = isset($data['token']) && is_string($data['token']) ? $data['token'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        }
        $_POST[Honeypot::FIELD] = isset($data['hp']) && is_string($data['hp']) ? $data['hp'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $_POST[Honeypot::STAMP] = isset($data['ts']) && is_string($data['ts']) ? $data['ts'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        $email = is_object($order) && method_exists($order, 'get_billing_email') ? (string) $order->get_billing_email() : '';
        $error = Captcha::check($form, $email);
        if (!$error) {
            return;
        }

        if (class_exists('Automattic\WooCommerce\StoreApi\Exceptions\RouteException')) {
            throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException('authlify_captcha', esc_html(Integrations::plain($error)), 400);
        }

        throw new \Exception(esc_html(Integrations::plain($error))); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
    }
}
