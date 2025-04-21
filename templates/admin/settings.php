<?php
/**
 * Admin settings template
 *
 * @package ModifyLogin
 * @since 2.0.0
 */

defined('ABSPATH') || exit;

// Get settings
$settings = $this->get_settings();

$options = get_option('modify_login_settings', array());
$login_endpoint = isset($options['login_endpoint']) ? $options['login_endpoint'] : '';
$redirect_url = isset($options['redirect_url']) ? $options['redirect_url'] : '';
$enable_redirect = isset($options['enable_redirect']) ? $options['enable_redirect'] : false;
?>

<div class="wrap modify-login-settings">
    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
        <!-- Header -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900">
                <?php esc_html_e('Modify Login Settings', 'modify-login'); ?>
            </h1>
            <p class="mt-2 text-sm text-gray-700">
                <?php esc_html_e('Secure your website by altering the login URL and restricting entry to the wp-login.php page and wp-admin directory for non-authenticated users!', 'modify-login'); ?>
            </p>
        </div>

        <?php settings_errors('modify_login_messages'); ?>

        <!-- Settings Form -->
        <form method="post" action="options.php" class="space-y-8">
            <?php settings_fields('modify_login_settings'); ?>
            <?php do_settings_sections('modify_login_settings'); ?>

            <div class="flex flex-col lg:flex-row gap-8">
                <!-- Left Sidebar - Navigation Tabs -->
                <div class="w-full lg:w-64 flex-shrink-0">
                    <nav class="space-y-1" aria-label="Settings">
                        <a href="#general" class="tab-link group flex items-center px-3 py-2 text-sm font-medium rounded-md text-gray-900 bg-gray-100" aria-current="page">
                            <svg class="mr-3 h-5 w-5 text-gray-500 group-hover:text-gray-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd" />
                            </svg>
                            <?php esc_html_e('General', 'modify-login'); ?>
                        </a>
                        <a href="#login-security" class="tab-link group flex items-center px-3 py-2 text-sm font-medium rounded-md text-gray-600 hover:text-gray-900 hover:bg-gray-50">
                            <svg class="mr-3 h-5 w-5 text-gray-400 group-hover:text-gray-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                <path d="M10 2a5 5 0 00-5 5v2a2 2 0 00-2 2v5a2 2 0 002 2h10a2 2 0 002-2v-5a2 2 0 00-2-2H7V7a3 3 0 015.905-1.683 1 1 0 001.19-1.677A5.002 5.002 0 0010 2z" />
                            </svg>
                            <?php esc_html_e('Login Security', 'modify-login'); ?>
                        </a>
                        <a href="#captcha" class="tab-link group flex items-center px-3 py-2 text-sm font-medium rounded-md text-gray-600 hover:text-gray-900 hover:bg-gray-50">
                            <svg class="mr-3 h-5 w-5 text-gray-400 group-hover:text-gray-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-6-3a2 2 0 11-4 0 2 2 0 014 0zm-2 4a5 5 0 00-4.546 2.916A5.986 5.986 0 0010 16a5.986 5.986 0 004.546-2.084A5 5 0 0010 11z" clip-rule="evenodd" />
                            </svg>
                            <?php esc_html_e('Captcha', 'modify-login'); ?>
                        </a>
                        <a href="#design" class="tab-link group flex items-center px-3 py-2 text-sm font-medium rounded-md text-gray-600 hover:text-gray-900 hover:bg-gray-50">
                            <svg class="mr-3 h-5 w-5 text-gray-400 group-hover:text-gray-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4zm2 6a1 1 0 011-1h6a1 1 0 110 2H7a1 1 0 01-1-1zm1 3a1 1 0 100 2h6a1 1 0 100-2H7z" clip-rule="evenodd" />
                            </svg>
                            <?php esc_html_e('Design', 'modify-login'); ?>
                        </a>
                    </nav>
                </div>

                <!-- Right Content - Tab Panels -->
                <div class="flex-1">
                    <!-- General Settings -->
                    <div id="general" class="tab-panel">
                        <div class="bg-white shadow rounded-lg overflow-hidden">
                            <div class="p-6 space-y-6">
                                <div class="grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                                    <div class="sm:col-span-4">
                                        <label for="login_redirect_url" class="block text-sm font-medium text-gray-700">
                                            <?php esc_html_e('Login Redirect URL', 'modify-login'); ?>
                                        </label>
                                        <div class="mt-1">
                                            <input type="url" name="login_redirect_url" id="login_redirect_url" 
                                                placeholder="<?php esc_attr_e('e.g., https://example.com/dashboard', 'modify-login'); ?>"
                                                value="<?php echo esc_attr($settings['login_redirect_url']); ?>" 
                                                class="shadow-sm focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md">
                                            <p class="mt-2 text-sm text-gray-500">
                                                <?php esc_html_e('Leave empty to use the default WordPress redirect.', 'modify-login'); ?>
                                            </p>
                                        </div>
                                    </div>

                                    <div class="sm:col-span-4">
                                        <label for="logout_redirect_url" class="block text-sm font-medium text-gray-700">
                                            <?php esc_html_e('Logout Redirect URL', 'modify-login'); ?>
                                        </label>
                                        <div class="mt-1">
                                            <input type="url" name="logout_redirect_url" id="logout_redirect_url" 
                                                placeholder="<?php esc_attr_e('e.g., https://example.com', 'modify-login'); ?>"
                                                value="<?php echo esc_attr($settings['logout_redirect_url']); ?>" 
                                                class="shadow-sm focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md">
                                            <p class="mt-2 text-sm text-gray-500">
                                                <?php esc_html_e('Leave empty to use the default WordPress redirect.', 'modify-login'); ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Login Security Settings -->
                    <div id="login-security" class="tab-panel hidden">
                        <div class="bg-white shadow rounded-lg overflow-hidden">
                            <div class="p-6 space-y-6">
                                <div class="form-group">
                                    <div class="form-field">
                                        <label for="login_endpoint"><?php esc_html_e('Login Endpoint', 'modify-login'); ?></label>
                                        <div class="input-group">
                                            <span class="input-group-text"><?php echo esc_url(home_url('/')); ?></span>
                                            <input type="text" id="login_endpoint" name="modify_login_settings[login_endpoint]" value="<?php echo esc_attr($login_endpoint); ?>" class="form-control" placeholder="setup">
                                        </div>
                                        <p class="help-text"><?php esc_html_e('Enter the custom endpoint for your login page. Example: setup', 'modify-login'); ?></p>
                                    </div>

                                    <div class="form-field">
                                        <label for="enable_redirect"><?php esc_html_e('Enable Redirect', 'modify-login'); ?></label>
                                        <div class="checkbox-group">
                                            <input type="checkbox" id="enable_redirect" name="modify_login_settings[enable_redirect]" value="1" <?php checked($enable_redirect, true); ?>>
                                            <label for="enable_redirect" class="checkbox-label"><?php esc_html_e('Redirect unauthorized access attempts', 'modify-login'); ?></label>
                                        </div>
                                    </div>

                                    <div class="form-field">
                                        <label for="redirect_url"><?php esc_html_e('Redirect URL', 'modify-login'); ?></label>
                                        <input type="text" id="redirect_url" name="modify_login_settings[redirect_url]" value="<?php echo esc_attr($redirect_url); ?>" class="form-control" placeholder="http://example.com/404">
                                        <p class="help-text"><?php esc_html_e('Enter the URL where unauthorized access attempts should be redirected to.', 'modify-login'); ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Captcha Settings -->
                    <div id="captcha" class="tab-panel hidden">
                        <div class="bg-white shadow rounded-lg overflow-hidden">
                            <div class="p-6 space-y-6">
                                <!-- reCAPTCHA Settings -->
                                <div class="sm:col-span-6">
                                    <div class="flex items-start">
                                        <div class="flex items-center h-5">
                                            <input type="checkbox" name="enable_recaptcha" id="enable_recaptcha" 
                                                value="yes" <?php checked($settings['enable_recaptcha'], 'yes'); ?> 
                                                class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded">
                                        </div>
                                        <div class="ml-3 text-sm">
                                            <label for="enable_recaptcha" class="font-medium text-gray-700">
                                                <?php esc_html_e('Enable reCAPTCHA', 'modify-login'); ?>
                                            </label>
                                            <p class="text-gray-500">
                                                <?php esc_html_e('Add Google reCAPTCHA to the login form.', 'modify-login'); ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                                    <div class="sm:col-span-4">
                                        <label for="recaptcha_site_key" class="block text-sm font-medium text-gray-700">
                                            <?php esc_html_e('reCAPTCHA Site Key', 'modify-login'); ?>
                                        </label>
                                        <div class="mt-1">
                                            <input type="text" name="recaptcha_site_key" id="recaptcha_site_key" 
                                                value="<?php echo esc_attr($settings['recaptcha_site_key']); ?>" 
                                                class="shadow-sm focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md">
                                        </div>
                                    </div>

                                    <div class="sm:col-span-4">
                                        <label for="recaptcha_secret_key" class="block text-sm font-medium text-gray-700">
                                            <?php esc_html_e('reCAPTCHA Secret Key', 'modify-login'); ?>
                                        </label>
                                        <div class="mt-1">
                                            <input type="text" name="recaptcha_secret_key" id="recaptcha_secret_key" 
                                                value="<?php echo esc_attr($settings['recaptcha_secret_key']); ?>" 
                                                class="shadow-sm focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Design Settings -->
                    <div id="design" class="tab-panel hidden">
                        <div class="bg-white shadow rounded-lg overflow-hidden">
                            <div class="p-6 space-y-6">
                                <div class="grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                                    <div class="sm:col-span-4">
                                        <label for="primary_color" class="block text-sm font-medium text-gray-700">
                                            <?php esc_html_e('Primary Color', 'modify-login'); ?>
                                        </label>
                                        <div class="mt-1">
                                            <input type="color" name="primary_color" id="primary_color" 
                                                value="<?php echo esc_attr($settings['primary_color']); ?>" 
                                                class="h-10 w-20 p-1 rounded-md border border-gray-300 shadow-sm">
                                        </div>
                                    </div>

                                    <div class="sm:col-span-4">
                                        <label for="background_color" class="block text-sm font-medium text-gray-700">
                                            <?php esc_html_e('Background Color', 'modify-login'); ?>
                                        </label>
                                        <div class="mt-1">
                                            <input type="color" name="background_color" id="background_color" 
                                                value="<?php echo esc_attr($settings['background_color']); ?>" 
                                                class="h-10 w-20 p-1 rounded-md border border-gray-300 shadow-sm">
                                        </div>
                                    </div>

                                    <div class="sm:col-span-4">
                                        <label for="text_color" class="block text-sm font-medium text-gray-700">
                                            <?php esc_html_e('Text Color', 'modify-login'); ?>
                                        </label>
                                        <div class="mt-1">
                                            <input type="color" name="text_color" id="text_color" 
                                                value="<?php echo esc_attr($settings['text_color']); ?>" 
                                                class="h-10 w-20 p-1 rounded-md border border-gray-300 shadow-sm">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Submit Button -->
            <div class="pt-5">
                <div class="flex justify-end">
                    <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                        <?php esc_html_e('Save Settings', 'modify-login'); ?>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Tab switching functionality
    const tabLinks = document.querySelectorAll('.tab-link');
    const tabPanels = document.querySelectorAll('.tab-panel');

    tabLinks.forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            
            // Remove active state from all tabs
            tabLinks.forEach(tab => {
                tab.classList.remove('bg-gray-100', 'text-gray-900');
                tab.classList.add('text-gray-600', 'hover:text-gray-900', 'hover:bg-gray-50');
            });
            
            // Add active state to clicked tab
            link.classList.remove('text-gray-600', 'hover:text-gray-900', 'hover:bg-gray-50');
            link.classList.add('bg-gray-100', 'text-gray-900');
            
            // Hide all panels
            tabPanels.forEach(panel => {
                panel.classList.add('hidden');
            });
            
            // Show selected panel
            const targetId = link.getAttribute('href').substring(1);
            document.getElementById(targetId).classList.remove('hidden');
        });
    });
});
</script> 