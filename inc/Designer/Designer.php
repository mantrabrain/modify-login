<?php
/**
 * Login page designer module.
 *
 * @package Authlify
 */

namespace Authlify\Designer;

defined('ABSPATH') || exit;

/**
 * Boots the designer: the login-screen output, the REST API, the admin page,
 * the 2.x migration, the Tools export/import and the LoginPress and Colorlib
 * importers.
 *
 * Nothing changes on the login page until an admin applies a design:
 * fresh installs and upgraded sites without a 2.x design keep the WordPress look.
 *
 * @since 3.0.0
 */
final class Designer
{
    /**
     * Wire up.
     *
     * @since 3.0.0
     */
    public static function init()
    {
        Frontend::init();

        add_action('rest_api_init', array(Rest::class, 'register'));
        add_action('authlify_upgraded', array(Migration::class, 'upgraded'), 20, 2);
        add_filter('authlify_export_data', array(Migration::class, 'export'));
        add_action('authlify_imported', array(Migration::class, 'imported'));
        add_filter('authlify_importers', array(Migration::class, 'importers'));

        if (is_admin()) {
            Admin::init();
        }
    }
}
