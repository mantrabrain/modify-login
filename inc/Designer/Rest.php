<?php
/**
 * Designer REST API.
 *
 * @package Authlify
 */

namespace Authlify\Designer;

use Authlify\Plugin;

defined('ABSPATH') || exit;

/**
 * Routes under authlify/v1/designer, all limited to Plugin::cap():
 *
 * - GET    /designer            the saved design;
 * - POST   /designer            save a design;
 * - POST   /designer/draft      store the user's draft for the preview, returns its CSS;
 * - DELETE /designer/draft      discard the draft;
 * - GET    /designer/templates  the template gallery;
 * - GET    /designer/match-site a design built from the active theme.
 *
 * @since 3.0.0
 */
final class Rest
{
    const NS = 'authlify/v1';

    /**
     * Register routes.
     *
     * @since 3.0.0
     */
    public static function register()
    {
        $perm = array(__CLASS__, 'can');
        $design_arg = array(
            'design' => array(
                'required' => true,
                'type' => 'object',
            ),
        );

        register_rest_route(self::NS, '/designer', array(
            array(
                'methods' => \WP_REST_Server::READABLE,
                'callback' => array(__CLASS__, 'get'),
                'permission_callback' => $perm,
            ),
            array(
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, 'save'),
                'permission_callback' => $perm,
                'args' => $design_arg,
            ),
        ));

        register_rest_route(self::NS, '/designer/draft', array(
            array(
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, 'draft'),
                'permission_callback' => $perm,
                'args' => $design_arg,
            ),
            array(
                'methods' => \WP_REST_Server::DELETABLE,
                'callback' => array(__CLASS__, 'discard'),
                'permission_callback' => $perm,
            ),
        ));

        register_rest_route(self::NS, '/designer/templates', array(
            'methods' => \WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'templates'),
            'permission_callback' => $perm,
        ));

        register_rest_route(self::NS, '/designer/match-site', array(
            'methods' => \WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'match_site'),
            'permission_callback' => $perm,
        ));
    }

    /**
     * Permission check.
     *
     * @return bool
     * @since 3.0.0
     */
    public static function can()
    {
        return current_user_can(Plugin::cap());
    }

    /**
     * GET /designer.
     *
     * @return \WP_REST_Response
     * @since 3.0.0
     */
    public static function get()
    {
        return rest_ensure_response(array('design' => Design::saved()));
    }

    /**
     * POST /designer.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     * @since 3.0.0
     */
    public static function save($request)
    {
        $warnings = array();
        $design = Design::save((array) $request->get_param('design'), $warnings);
        Design::delete_draft(get_current_user_id());

        return rest_ensure_response(array(
            'design' => $design,
            'warnings' => array_values(array_unique($warnings)),
        ));
    }

    /**
     * POST /designer/draft.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     * @since 3.0.0
     */
    public static function draft($request)
    {
        $warnings = array();
        $design = Design::set_draft(get_current_user_id(), (array) $request->get_param('design'), $warnings);

        return rest_ensure_response(array(
            'design' => $design,
            'css' => Compiler::compile($design),
            'warnings' => array_values(array_unique($warnings)),
        ));
    }

    /**
     * DELETE /designer/draft.
     *
     * @return \WP_REST_Response
     * @since 3.0.0
     */
    public static function discard()
    {
        Design::delete_draft(get_current_user_id());

        return rest_ensure_response(array('deleted' => true));
    }

    /**
     * GET /designer/templates.
     *
     * @return \WP_REST_Response
     * @since 3.0.0
     */
    public static function templates()
    {
        return rest_ensure_response(array('templates' => Templates::all()));
    }

    /**
     * GET /designer/match-site.
     *
     * @return \WP_REST_Response
     * @since 3.0.0
     */
    public static function match_site()
    {
        return rest_ensure_response(MatchSite::build());
    }
}
