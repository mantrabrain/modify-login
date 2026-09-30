<?php
/**
 * REST routes for the profile screen.
 *
 * @package Authlify
 */

namespace Authlify\TwoFactor;

defined('ABSPATH') || exit;

/**
 * Routes under authlify/v1/twofactor. Cookie-authenticated with the wp_rest nonce.
 *
 * Users manage only their own methods. Anyone who may edit a user (edit_users)
 * can see which methods that user has and reset them, but never read secrets.
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
        $self = array(__CLASS__, 'can_manage_self');

        register_rest_route(self::NS, '/twofactor/status', array(
            'methods' => \WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'status'),
            'permission_callback' => array(__CLASS__, 'can_view'),
            'args' => array('user_id' => array('type' => 'integer', 'default' => 0)),
        ));

        register_rest_route(self::NS, '/twofactor/totp/setup', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'totp_setup'),
            'permission_callback' => $self,
        ));

        register_rest_route(self::NS, '/twofactor/totp/verify', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'totp_verify'),
            'permission_callback' => $self,
            'args' => array('code' => array('type' => 'string', 'required' => true)),
        ));

        register_rest_route(self::NS, '/twofactor/totp', array(
            'methods' => \WP_REST_Server::DELETABLE,
            'callback' => array(__CLASS__, 'totp_remove'),
            'permission_callback' => $self,
        ));

        register_rest_route(self::NS, '/twofactor/backup-codes', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'backup_generate'),
            'permission_callback' => $self,
        ));

        register_rest_route(self::NS, '/twofactor/passkeys/options', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'passkey_options'),
            'permission_callback' => $self,
        ));

        register_rest_route(self::NS, '/twofactor/passkeys', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'passkey_register'),
            'permission_callback' => $self,
        ));

        register_rest_route(self::NS, '/twofactor/passkeys/(?P<id>\d+)', array(
            array(
                'methods' => \WP_REST_Server::EDITABLE,
                'callback' => array(__CLASS__, 'passkey_rename'),
                'permission_callback' => $self,
                'args' => array('name' => array('type' => 'string', 'required' => true)),
            ),
            array(
                'methods' => \WP_REST_Server::DELETABLE,
                'callback' => array(__CLASS__, 'passkey_delete'),
                'permission_callback' => $self,
            ),
        ));

        register_rest_route(self::NS, '/twofactor/reset', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'reset'),
            'permission_callback' => array(__CLASS__, 'can_reset'),
            'args' => array('user_id' => array('type' => 'integer', 'required' => true)),
        ));
    }

    /**
     * Setting up methods: logged in, our flow is on, for yourself only.
     *
     * @return bool|\WP_Error
     */
    public static function can_manage_self()
    {
        if (!is_user_logged_in()) {
            return new \WP_Error('rest_forbidden', __('Please sign in.', 'modify-login'), array('status' => 401));
        }
        if (self::is_api_credential()) {
            return self::api_credential_error();
        }
        if (TwoFactor::disabled() || '' !== TwoFactor::other_provider()) {
            return new \WP_Error('authlify_2fa_off', __('Two-factor login is turned off on this site.', 'modify-login'), array('status' => 403));
        }

        return true;
    }

    /**
     * Viewing status: yourself, or a user you may edit.
     *
     * @param \WP_REST_Request $request Request.
     * @return bool
     */
    public static function can_view($request)
    {
        $user_id = (int) $request['user_id'];

        return is_user_logged_in() && (!$user_id || get_current_user_id() === $user_id || current_user_can('edit_user', $user_id));
    }

    /**
     * Resetting: yourself, or a user you may edit (edit_users).
     *
     * @param \WP_REST_Request $request Request.
     * @return bool
     */
    public static function can_reset($request)
    {
        $user_id = (int) $request['user_id'];
        if (!$user_id || !get_userdata($user_id)) {
            return false;
        }

        if (self::is_api_credential()) {
            return self::api_credential_error();
        }

        return get_current_user_id() === $user_id || (current_user_can('edit_users') && current_user_can('edit_user', $user_id));
    }

    /**
     * Whether this request is authenticated by an application password or
     * other non-browser credential (no login session). Such credentials must
     * never be able to change two-factor settings: that would turn a leaked
     * API token into a full interactive login.
     *
     * @return bool
     */
    private static function is_api_credential()
    {
        return did_action('application_password_did_authenticate') || '' === (string) wp_get_session_token();
    }

    /**
     * Error for non-browser credentials.
     *
     * @return \WP_Error
     */
    private static function api_credential_error()
    {
        return new \WP_Error('authlify_2fa_session_required', __('Two-factor settings can only be changed from a signed-in browser session, not with an application password.', 'modify-login'), array('status' => 403));
    }

    /**
     * Status of a user's methods (no secrets).
     *
     * @param int $user_id User ID.
     * @return array
     * @since 3.0.0
     */
    public static function user_status($user_id)
    {
        $passkeys = array();
        foreach (Passkeys::for_user($user_id) as $row) {
            $passkeys[] = array(
                'id' => (int) $row->id,
                'name' => $row->name,
                'created' => self::date($row->created_at),
                'lastUsed' => $row->last_used_at ? self::date($row->last_used_at) : '',
            );
        }

        return array(
            'active' => TwoFactor::is_active_for($user_id),
            'required' => TwoFactor::is_required(get_userdata($user_id)),
            'totp' => Totp::is_configured($user_id),
            'backup' => BackupCodes::remaining($user_id),
            'backupWarn' => BackupCodes::WARN_AT,
            'passkeys' => $passkeys,
            'primary' => self::primary_count($user_id),
            'offered' => TwoFactor::offered(),
            'unavailable' => TwoFactor::unavailable_for($user_id),
        );
    }

    /**
     * Local date for display.
     *
     * @param string $gmt GMT datetime.
     * @return string
     */
    private static function date($gmt)
    {
        return get_date_from_gmt((string) $gmt, get_option('date_format') . ' ' . get_option('time_format'));
    }

    /**
     * GET status.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public static function status($request)
    {
        $user_id = (int) $request['user_id'] ? (int) $request['user_id'] : get_current_user_id();

        return rest_ensure_response(self::user_status($user_id));
    }

    /**
     * Refuse methods the admin does not offer.
     *
     * @param string $method Method.
     * @return \WP_Error|null
     */
    private static function not_offered($method)
    {
        if (in_array($method, TwoFactor::offered(), true)) {
            return null;
        }

        return new \WP_Error('authlify_2fa_method_off', __('This method is not offered on this site.', 'modify-login'), array('status' => 403));
    }

    /**
     * Ask add-ons whether a sensitive change may go ahead now. Authlify Pro's
     * "confirm before sensitive changes" answers with a 403 error
     * (authlify_sudo_required) when the sign-in is not recent.
     *
     * @param string $reason  Reason key.
     * @param int    $user_id User the change is about.
     * @return \WP_Error|null
     */
    private static function confirm($reason, $user_id)
    {
        /**
         * Filters whether a sensitive two-factor change may go ahead now.
         * Return a WP_Error to stop it (Authlify Pro asks the person to confirm
         * it is them first).
         *
         * @param true|\WP_Error $ok      True to allow.
         * @param string         $reason  twofa_reset, twofa_remove_last, passkey_add, totp_setup (a new or replacement authenticator app) or backup_regenerate.
         * @param int            $user_id User the change is about.
         * @since 3.0.0
         */
        $ok = apply_filters('authlify_confirm_identity', true, $reason, (int) $user_id);

        return is_wp_error($ok) ? $ok : null;
    }

    /**
     * Primary methods a user has, passkeys counted one each.
     *
     * @param int $user_id User ID.
     * @return int
     * @since 3.0.0
     */
    public static function primary_count($user_id)
    {
        $all = TwoFactor::methods();
        $count = Passkeys::count_for_user($user_id);
        foreach (TwoFactor::user_methods($user_id) as $method) {
            if ('passkey' !== $method && !empty($all[$method]['primary'])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Refuse removing the last primary method when 2FA is required.
     *
     * @param int    $user_id  User ID.
     * @param string $removing totp or passkey.
     * @return \WP_Error|null
     */
    private static function guard_required($user_id, $removing)
    {
        if (!TwoFactor::is_required(get_userdata($user_id))) {
            return null;
        }

        // Passkeys count one each; every other primary method (TOTP, add-on methods such as email codes) counts once.
        $left = Passkeys::count_for_user($user_id) - ('passkey' === $removing ? 1 : 0);
        foreach (TwoFactor::user_methods($user_id) as $method) {
            if (!in_array($method, array('passkey', 'backup', $removing), true)) {
                $left++;
            }
        }
        if ($left > 0) {
            return null;
        }

        return new \WP_Error('authlify_2fa_required', __('Two-factor login is required for your account. Add another method before removing this one.', 'modify-login'), array('status' => 403));
    }

    /**
     * POST totp/setup.
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public static function totp_setup()
    {
        $off = self::not_offered('totp');
        if ($off) {
            return $off;
        }

        $user = wp_get_current_user();

        // A new authenticator app is a durable way to pass the second step
        // (and replacing one locks out the old app): confirm it is the owner,
        // as for adding a passkey.
        $confirm = self::confirm('totp_setup', $user->ID);
        if ($confirm) {
            return $confirm;
        }

        $secret = Totp::begin_setup($user->ID);

        return rest_ensure_response(array(
            'secret' => $secret,
            'uri' => Totp::uri($secret, $user),
        ));
    }

    /**
     * POST totp/verify: finish setup. Creates backup codes the first time.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response|\WP_Error
     */
    public static function totp_verify($request)
    {
        $user_id = get_current_user_id();

        // Replacing an existing app: the setup must have been started after a
        // confirmation (a pending secret from an older, unconfirmed setup is not enough).
        if (Totp::is_configured($user_id)) {
            $confirm = self::confirm('totp_setup', $user_id);
            if ($confirm) {
                return $confirm;
            }
        }

        $result = Totp::finish_setup($user_id, (string) $request['code']);

        if (is_wp_error($result)) {
            $result->add_data(array('status' => 400));

            return $result;
        }

        $codes = array();
        if (!BackupCodes::is_configured($user_id) && !self::not_offered('backup')) {
            $codes = BackupCodes::generate($user_id);
        }

        return rest_ensure_response(array('status' => self::user_status($user_id), 'codes' => $codes));
    }

    /**
     * DELETE totp.
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public static function totp_remove()
    {
        $user_id = get_current_user_id();
        $guard = self::guard_required($user_id, 'totp');
        if ($guard) {
            return $guard;
        }
        $confirm = self::primary_count($user_id) <= 1 ? self::confirm('twofa_remove_last', $user_id) : null;
        if ($confirm) {
            return $confirm;
        }

        Totp::remove($user_id);
        TwoFactor::sync_flag($user_id);
        TwoFactor::log_change($user_id, 'twofa_disabled', array('method' => 'totp', 'still_on' => TwoFactor::is_active_for($user_id)));

        return rest_ensure_response(array('status' => self::user_status($user_id)));
    }

    /**
     * POST backup-codes: new set (old codes stop working).
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public static function backup_generate()
    {
        $off = self::not_offered('backup');
        if ($off) {
            return $off;
        }

        $user_id = get_current_user_id();

        // New codes pass the second step and void the old ones: confirm first.
        $confirm = self::confirm('backup_regenerate', $user_id);
        if ($confirm) {
            return $confirm;
        }

        $codes = BackupCodes::generate($user_id);

        return rest_ensure_response(array('status' => self::user_status($user_id), 'codes' => $codes));
    }

    /**
     * POST passkeys/options.
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public static function passkey_options()
    {
        $off = self::not_offered('passkey');
        if ($off) {
            return $off;
        }

        // A passkey signs in without the password, so adding one is sensitive.
        $confirm = self::confirm('passkey_add', get_current_user_id());
        if ($confirm) {
            return $confirm;
        }

        $options = Passkeys::registration_options(wp_get_current_user());
        if (is_wp_error($options)) {
            $options->add_data(array('status' => 500));

            return $options;
        }

        return rest_ensure_response(array('options' => $options));
    }

    /**
     * POST passkeys: save the new passkey.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response|\WP_Error
     */
    public static function passkey_register($request)
    {
        $off = self::not_offered('passkey');
        if ($off) {
            return $off;
        }

        $user = wp_get_current_user();
        $was_on = TwoFactor::is_active_for($user->ID);
        $result = Passkeys::register($user, array(
            'clientDataJSON' => (string) $request['clientDataJSON'],
            'attestationObject' => (string) $request['attestationObject'],
            'transports' => (array) $request['transports'],
            'name' => (string) $request['name'],
        ));

        if (is_wp_error($result)) {
            $result->add_data(array('status' => 400));

            return $result;
        }

        if (!$was_on) {
            TwoFactor::log_change($user->ID, 'twofa_enabled', array('method' => 'passkey', 'first' => true));
        }

        // Like the authenticator app: the first method comes with backup
        // codes, so losing the device is not the end of the account.
        $codes = array();
        if (!BackupCodes::is_configured($user->ID) && !self::not_offered('backup')) {
            $codes = BackupCodes::generate($user->ID);
        }

        return rest_ensure_response(array('status' => self::user_status($user->ID), 'codes' => $codes));
    }

    /**
     * POST passkeys/{id}: rename.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response|\WP_Error
     */
    public static function passkey_rename($request)
    {
        $user_id = get_current_user_id();
        if (!Passkeys::rename($user_id, (int) $request['id'], (string) $request['name'])) {
            return new \WP_Error('authlify_passkey_rename', __('The passkey could not be renamed.', 'modify-login'), array('status' => 400));
        }

        return rest_ensure_response(array('status' => self::user_status($user_id)));
    }

    /**
     * DELETE passkeys/{id}.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response|\WP_Error
     */
    public static function passkey_delete($request)
    {
        $user_id = get_current_user_id();
        $guard = self::guard_required($user_id, 'passkey');
        if ($guard) {
            return $guard;
        }
        $confirm = self::primary_count($user_id) <= 1 ? self::confirm('twofa_remove_last', $user_id) : null;
        if ($confirm) {
            return $confirm;
        }

        if (!Passkeys::delete($user_id, (int) $request['id'])) {
            return new \WP_Error('authlify_passkey_missing', __('That passkey was not found.', 'modify-login'), array('status' => 404));
        }

        if (!TwoFactor::is_active_for($user_id)) {
            TwoFactor::log_change($user_id, 'twofa_disabled', array('method' => 'passkey'));
        }

        return rest_ensure_response(array('status' => self::user_status($user_id)));
    }

    /**
     * POST reset: remove every method of a user.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response|\WP_Error
     */
    public static function reset($request)
    {
        $user_id = (int) $request['user_id'];
        if (get_current_user_id() === $user_id && TwoFactor::is_required(get_userdata($user_id))) {
            return new \WP_Error('authlify_2fa_required', __('Two-factor login is required for your account, so it cannot be turned off.', 'modify-login'), array('status' => 403));
        }
        $confirm = self::confirm('twofa_reset', $user_id);
        if ($confirm) {
            return $confirm;
        }

        TwoFactor::reset($user_id);

        return rest_ensure_response(array('status' => self::user_status($user_id)));
    }
}
