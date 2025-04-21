<?php
/**
 * Login page template
 *
 * @package ModifyLogin
 * @since 2.0.0
 */

defined('ABSPATH') || exit;

// Get settings
$settings = get_option('modify_login_settings', array());
$background_color = isset($settings['background_color']) ? $settings['background_color'] : '#ffffff';
$text_color = isset($settings['text_color']) ? $settings['text_color'] : '#1f2937';
$button_color = isset($settings['button_color']) ? $settings['button_color'] : '#4f46e5';
$button_text_color = isset($settings['button_text_color']) ? $settings['button_text_color'] : '#ffffff';
$logo_url = isset($settings['logo_url']) ? $settings['logo_url'] : '';
$background_image = isset($settings['background_image']) ? $settings['background_image'] : '';
?>

<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html__('Login', 'modify-login'); ?> - <?php bloginfo('name'); ?></title>
    <?php wp_head(); ?>
</head>
<body class="min-h-screen flex items-center justify-center" style="background-color: <?php echo esc_attr($background_color); ?>; color: <?php echo esc_attr($text_color); ?>; <?php echo $background_image ? 'background-image: url(' . esc_url($background_image) . '); background-size: cover; background-position: center;' : ''; ?>">
    <div class="w-full max-w-md px-6 py-8 bg-white rounded-lg shadow-lg">
        <!-- Logo -->
        <?php if ($logo_url) : ?>
            <div class="mb-8 text-center">
                <img src="<?php echo esc_url($logo_url); ?>" alt="<?php bloginfo('name'); ?>" class="mx-auto h-12 w-auto">
            </div>
        <?php endif; ?>

        <!-- Login Form -->
        <form name="loginform" id="loginform" action="<?php echo esc_url(site_url('wp-login.php', 'login_post')); ?>" method="post" class="space-y-6">
            <?php if (isset($_GET['login']) && $_GET['login'] === 'failed') : ?>
                <div class="bg-red-50 border-l-4 border-red-400 p-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-red-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                            </svg>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-red-700">
                                <?php esc_html_e('Invalid username or password.', 'modify-login'); ?>
                            </p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div>
                <label for="user_login" class="block text-sm font-medium text-gray-700">
                    <?php esc_html_e('Username or Email', 'modify-login'); ?>
                </label>
                <div class="mt-1">
                    <input type="text" name="log" id="user_login" class="appearance-none block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required>
                </div>
            </div>

            <div>
                <label for="user_pass" class="block text-sm font-medium text-gray-700">
                    <?php esc_html_e('Password', 'modify-login'); ?>
                </label>
                <div class="mt-1">
                    <input type="password" name="pwd" id="user_pass" class="appearance-none block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required>
                </div>
            </div>

            <?php if (isset($settings['enable_recaptcha']) && $settings['enable_recaptcha'] === 'yes') : ?>
                <div class="g-recaptcha" data-sitekey="<?php echo esc_attr($settings['recaptcha_site_key']); ?>"></div>
            <?php endif; ?>

            <div class="flex items-center justify-between">
                <div class="flex items-center">
                    <input type="checkbox" name="rememberme" id="rememberme" class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded">
                    <label for="rememberme" class="ml-2 block text-sm text-gray-900">
                        <?php esc_html_e('Remember me', 'modify-login'); ?>
                    </label>
                </div>

                <div class="text-sm">
                    <a href="<?php echo esc_url(wp_lostpassword_url()); ?>" class="font-medium text-indigo-600 hover:text-indigo-500">
                        <?php esc_html_e('Forgot your password?', 'modify-login'); ?>
                    </a>
                </div>
            </div>

            <div>
                <button type="submit" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500" style="background-color: <?php echo esc_attr($button_color); ?>; color: <?php echo esc_attr($button_text_color); ?>">
                    <?php esc_html_e('Sign in', 'modify-login'); ?>
                </button>
            </div>

            <?php if (isset($settings['enable_social_login']) && $settings['enable_social_login'] === 'yes') : ?>
                <div class="mt-6">
                    <div class="relative">
                        <div class="absolute inset-0 flex items-center">
                            <div class="w-full border-t border-gray-300"></div>
                        </div>
                        <div class="relative flex justify-center text-sm">
                            <span class="px-2 bg-white text-gray-500">
                                <?php esc_html_e('Or continue with', 'modify-login'); ?>
                            </span>
                        </div>
                    </div>

                    <div class="mt-6 grid grid-cols-2 gap-3">
                        <?php if (!empty($settings['google_client_id'])) : ?>
                            <div>
                                <a href="<?php echo esc_url(add_query_arg('provider', 'google', wp_login_url())); ?>" class="w-full inline-flex justify-center py-2 px-4 border border-gray-300 rounded-md shadow-sm bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                    <svg class="w-5 h-5" viewBox="0 0 24 24">
                                        <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                                        <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                                        <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                                        <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                                    </svg>
                                </a>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($settings['facebook_app_id'])) : ?>
                            <div>
                                <a href="<?php echo esc_url(add_query_arg('provider', 'facebook', wp_login_url())); ?>" class="w-full inline-flex justify-center py-2 px-4 border border-gray-300 rounded-md shadow-sm bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                    <svg class="w-5 h-5" fill="#1877F2" viewBox="0 0 24 24">
                                        <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                                    </svg>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </form>
    </div>

    <?php wp_footer(); ?>
</body>
</html> 