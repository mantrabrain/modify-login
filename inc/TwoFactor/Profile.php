<?php
/**
 * "Two-factor login" section on the profile screen.
 *
 * @package Authlify
 */

namespace Authlify\TwoFactor;

defined('ABSPATH') || exit;

/**
 * On profile.php users set up an authenticator app, backup codes and
 * passkeys. On user-edit.php, people who may edit the user see which methods
 * are on and can reset them; secrets are never shown to anyone else.
 *
 * The section is driven by assets/twofactor/profile.js through the REST
 * routes in Rest. Inputs have no name attribute, so nothing here is posted
 * with the profile form.
 *
 * @since 3.0.0
 */
final class Profile
{
    /**
     * Wire up.
     *
     * @since 3.0.0
     */
    public static function init()
    {
        add_action('show_user_profile', array(__CLASS__, 'render'), 5);
        add_action('edit_user_profile', array(__CLASS__, 'render'), 5);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
    }

    /**
     * Enqueue on the profile screens.
     *
     * @param string $hook Screen hook.
     */
    public static function assets($hook)
    {
        if (!in_array($hook, array('profile.php', 'user-edit.php'), true) || TwoFactor::disabled()) {
            return;
        }

        $user_id = 'profile.php' === $hook ? get_current_user_id() : (isset($_GET['user_id']) ? absint($_GET['user_id']) : 0); // phpcs:ignore WordPress.Security.NonceVerification

        self::enqueue($user_id);
    }

    /**
     * Enqueue the section's script and styles for a user. Also used on the
     * front end (Authlify Pro's account security page and shortcode).
     *
     * @param int $user_id User whose section is shown.
     * @since 3.0.0
     */
    public static function enqueue($user_id)
    {
        $user_id = (int) $user_id;
        $self = get_current_user_id() === $user_id;

        wp_enqueue_style('authlify-twofactor-profile', AUTHLIFY_URL . 'assets/twofactor/profile.css', array(), AUTHLIFY_VERSION);

        $deps = array();
        if ($self) {
            wp_register_script('authlify-qrcode', AUTHLIFY_URL . 'lib/qrcode-generator/qrcode.js', array(), '2.0.4', true);
            $deps[] = 'authlify-qrcode';
            if (Passkeys::supported()) {
                wp_register_script('authlify-passkey', AUTHLIFY_URL . 'assets/twofactor/passkey.js', array(), AUTHLIFY_VERSION, true);
                $deps[] = 'authlify-passkey';
            }
        }

        wp_enqueue_script('authlify-twofactor-profile', AUTHLIFY_URL . 'assets/twofactor/profile.js', $deps, AUTHLIFY_VERSION, true);
        wp_localize_script('authlify-twofactor-profile', 'authlifyTwoFactor', array(
            'root' => esc_url_raw(rest_url(Rest::NS . '/twofactor/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'userId' => $user_id,
            'self' => $self,
            'status' => '' === TwoFactor::other_provider() && $user_id ? Rest::user_status($user_id) : null,
            'passkeysSupported' => Passkeys::supported(),
            'recovered' => $self && !empty($_GET['authlify_recovered']), // phpcs:ignore WordPress.Security.NonceVerification -- display only.
            'front' => !is_admin(),
            'i18n' => self::strings(),
        ));
    }

    /**
     * Strings for the script.
     *
     * @return array
     */
    private static function strings()
    {
        return array(
            'on' => __('On', 'modify-login'),
            'off' => __('Off', 'modify-login'),
            'summaryOn' => __('Two-factor login is on', 'modify-login'),
            'summaryOff' => __('Two-factor login is off', 'modify-login'),
            'summaryOnText' => __('After your password, you confirm it is you with one of the methods below.', 'modify-login'),
            'summaryOffText' => __('Add an authenticator app or a passkey so a stolen password is not enough to sign in.', 'modify-login'),
            /* translators: %s: method names */
            'unavailable' => __('%s cannot be used on this site right now. Two-factor login stays on: you sign in with a backup code or an emailed link. Add an authenticator app or a passkey to sign in normally again.', 'modify-login'),
            /* translators: %s: method names */
            'unavailableOther' => __('%s cannot be used on this site right now, so this person signs in with a backup code or an emailed link.', 'modify-login'),
            'remove' => __('Remove', 'modify-login'),
            'copy' => __('Copy', 'modify-login'),
            'copied' => __('Copied', 'modify-login'),
            'stepScan' => __('Scan the QR code', 'modify-login'),
            'stepCode' => __('Enter the 6-digit code', 'modify-login'),
            /* translators: %d: number of codes */
            'backupCount' => __('%d left', 'modify-login'),
            /* translators: %d: number of passkeys */
            'passkeyCount' => __('%d passkeys', 'modify-login'),
            'passkeyCountOne' => __('1 passkey', 'modify-login'),
            'statusOn' => __('Two-factor login is on for your account.', 'modify-login'),
            'statusOff' => __('Two-factor login is off. Add an authenticator app or a passkey to turn it on.', 'modify-login'),
            'userOn' => __('This user signs in with two-factor login.', 'modify-login'),
            'userOff' => __('This user has not set up two-factor login.', 'modify-login'),
            'totpTitle' => __('Authenticator app', 'modify-login'),
            'totpHelp' => __('A 6-digit code from Google Authenticator, Microsoft Authenticator, 1Password, Authy or similar.', 'modify-login'),
            'totpOn' => __('Your authenticator app is set up.', 'modify-login'),
            'totpSetup' => __('Set up an authenticator app', 'modify-login'),
            'totpScan' => __('Open your authenticator app, add an account, and scan this code. Can\'t scan? Type the key instead.', 'modify-login'),
            'totpKey' => __('Key', 'modify-login'),
            'totpCode' => __('Code from the app', 'modify-login'),
            'totpInvalid' => __('Enter the 6-digit code from the app.', 'modify-login'),
            'totpCodeHint' => __('The app shows a new code for this site every 30 seconds.', 'modify-login'),
            'totpVerify' => __('Verify and turn on', 'modify-login'),
            'totpRemove' => __('Remove authenticator app', 'modify-login'),
            'totpRemoveConfirm' => __('Remove the authenticator app from your account?', 'modify-login'),
            'totpEnabled' => __('Authenticator app added. Two-factor login is on.', 'modify-login'),
            'cancel' => __('Cancel', 'modify-login'),
            'backupTitle' => __('Backup codes', 'modify-login'),
            'backupHelp' => __('One-time codes for when your phone or passkey is not at hand. Keep them somewhere safe.', 'modify-login'),
            /* translators: %d: number of codes */
            'backupLeft' => __('%d unused codes left.', 'modify-login'),
            'backupLeftOne' => __('1 unused code left.', 'modify-login'),
            'backupNone' => __('You have no backup codes.', 'modify-login'),
            'backupLow' => __('You are running out of backup codes. Make a new set.', 'modify-login'),
            'backupGenerate' => __('Create backup codes', 'modify-login'),
            'backupRegenerate' => __('Create new codes', 'modify-login'),
            'backupRegenerateConfirm' => __('Create a new set? Your old backup codes will stop working.', 'modify-login'),
            'backupShowOnce' => __('Save these codes now. They will not be shown again, and each works once.', 'modify-login'),
            'backupCopy' => __('Copy codes', 'modify-login'),
            'backupDownload' => __('Download', 'modify-login'),
            'backupCopied' => __('Codes copied.', 'modify-login'),
            'backupDone' => __('I saved them', 'modify-login'),
            'passkeyTitle' => __('Passkeys', 'modify-login'),
            'passkeyHelp' => __('Use your fingerprint, face, screen lock or a security key. Works as the second step, or instead of your password.', 'modify-login'),
            'passkeyNone' => __('You have not added a passkey yet.', 'modify-login'),
            'passkeyAdd' => __('Add a passkey', 'modify-login'),
            'passkeyName' => __('Passkey name', 'modify-login'),
            'passkeyNameHint' => __('e.g. MacBook Touch ID', 'modify-login'),
            'passkeyCreate' => __('Add a passkey', 'modify-login'),
            'passkeyAdded' => __('Passkey added.', 'modify-login'),
            'passkeyAddedCodes' => __('Passkey added. Two-factor login is on. Save your backup codes below.', 'modify-login'),
            'passkeyRename' => __('Rename', 'modify-login'),
            'passkeyRenamed' => __('Passkey renamed.', 'modify-login'),
            'lastRequired' => __('Two-factor login is required for your account. Add another method before removing this one.', 'modify-login'),
            'noMethods' => __('No two-factor methods are offered on this site yet.', 'modify-login'),
            'confirmFirst' => __('Confirm it is you', 'modify-login'),
            'passkeyRemoved' => __('Passkey removed.', 'modify-login'),
            'passkeyRemove' => __('Remove', 'modify-login'),
            /* translators: %s: passkey name */
            'passkeyRemoveConfirm' => __('Remove the passkey "%s"?', 'modify-login'),
            /* translators: %s: date */
            'passkeyCreated' => __('Added %s', 'modify-login'),
            /* translators: %s: date */
            'passkeyUsed' => __('last used %s', 'modify-login'),
            'passkeyNeverUsed' => __('never used', 'modify-login'),
            'passkeyCancelled' => __('The passkey prompt was closed. Nothing was added.', 'modify-login'),
            'passkeyInsecure' => __('Passkeys need a secure (https) connection. Open this page over https to add one.', 'modify-login'),
            'passkeyBrowser' => __('This browser does not support passkeys.', 'modify-login'),
            'passkeyPhp' => __('Passkeys need PHP 8.0 or newer on the server. Ask your host to upgrade PHP to use them.', 'modify-login'),
            'resetTitle' => __('Reset two-factor login', 'modify-login'),
            'resetHelp' => __('Removes this user\'s authenticator app, backup codes and passkeys, so they can sign in with just their password and set it up again.', 'modify-login'),
            'resetButton' => __('Reset two-factor login', 'modify-login'),
            'resetConfirm' => __('Reset two-factor login for this user? They will be able to sign in with only their password.', 'modify-login'),
            'resetDone' => __('Two-factor login was reset.', 'modify-login'),
            'turnOff' => __('Turn off two-factor login', 'modify-login'),
            'turnOffConfirm' => __('Remove every two-factor method from your account?', 'modify-login'),
            'methodOff' => __('This method is not offered on this site.', 'modify-login'),
            'error' => __('Something went wrong. Please try again.', 'modify-login'),
            'working' => __('Working…', 'modify-login'),
            'recovered' => __('You signed in with a recovery link. Set up a new method now, then remove the one you lost.', 'modify-login'),
        );
    }

    /**
     * The section.
     *
     * @param \WP_User $user User being edited.
     */
    public static function render($user)
    {
        self::section($user);
    }

    /**
     * The section's markup (the script fills it in).
     *
     * @param \WP_User $user  User.
     * @param string   $tag   Heading tag ('' for no heading).
     * @param string   $class Extra wrapper class.
     * @since 3.0.0
     */
    public static function section($user, $tag = 'h2', $class = '')
    {
        if (TwoFactor::disabled()) {
            return;
        }

        $self = get_current_user_id() === (int) $user->ID;
        if (!$self && !current_user_can('edit_user', $user->ID)) {
            return;
        }

        $tag = in_array($tag, array('h2', 'h3', ''), true) ? $tag : 'h2';
        $other = TwoFactor::other_provider();
        ?>
        <div class="authlify-2fa-profile<?php echo '' !== $class ? ' ' . esc_attr($class) : ''; ?>" id="authlify-two-factor">
            <?php if ('' !== $tag) : ?>
                <<?php echo esc_html($tag); ?>><?php esc_html_e('Two-factor login', 'modify-login'); ?></<?php echo esc_html($tag); ?>>
            <?php endif; ?>
            <?php if ('' !== $other) : ?>
                <p class="description"><?php echo esc_html(sprintf(
                    /* translators: %s: plugin name */
                    __('Two-factor login on this site is handled by %s.', 'modify-login'),
                    $other
                )); ?></p>
            <?php else : ?>
                <div class="authlify-2fa-profile__live" id="authlify-2fa-live" role="status" aria-live="polite"></div>
                <div class="authlify-2fa-profile__app" data-self="<?php echo $self ? '1' : '0'; ?>">
                    <p class="description"><?php esc_html_e('Loading…', 'modify-login'); ?></p>
                </div>
                <noscript><p><?php esc_html_e('Two-factor settings need JavaScript.', 'modify-login'); ?></p></noscript>
            <?php endif; ?>
        </div>
        <?php
    }
}
