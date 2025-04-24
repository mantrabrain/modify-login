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

// Check for success and error messages
$success_message = isset($success_message) ? $success_message : '';
$error_message = isset($error_message) ? $error_message : '';

// Set active tab - use the value from PHP or default to general
$active_tab = isset($active_tab) ? $active_tab : 'general';
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

        <?php if (!empty($success_message)): ?>
        <div id="success-message" class="bg-green-50 border-l-4 border-green-500 p-4 mb-6 animate-fade">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-green-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                    </svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-green-800">
                        <?php echo esc_html($success_message); ?>
                    </p>
                </div>
                <div class="ml-auto pl-3">
                    <div class="-mx-1.5 -my-1.5">
                        <button type="button" class="inline-flex bg-green-50 rounded-md p-1.5 text-green-500 hover:bg-green-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500" onclick="document.getElementById('success-message').style.display='none';">
                            <span class="sr-only">Dismiss</span>
                            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
        <div class="bg-red-50 border-l-4 border-red-400 p-4 mb-6">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-red-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                    </svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm text-red-700">
                        <?php echo esc_html($error_message); ?>
                    </p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Settings Form -->
        <form method="post" action="" class="space-y-8">
            <?php wp_nonce_field('modify_login_save_settings', 'modify_login_settings_nonce'); ?>
            <!-- Hidden field to store active tab -->
            <input type="hidden" name="active_tab" id="active_tab" value="<?php echo esc_attr($active_tab); ?>">
            
            <div class="flex flex-col lg:flex-row gap-8">
                <!-- Left Sidebar - Navigation Tabs -->
                <div class="w-full lg:w-64 flex-shrink-0">
                    <nav class="space-y-1" aria-label="Settings">
                        <a href="#general" data-tab="general" class="tab-link group flex items-center px-3 py-2 text-sm font-medium rounded-md <?php echo $active_tab === 'general' ? 'text-gray-900 bg-gray-100' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50'; ?>" <?php echo $active_tab === 'general' ? 'aria-current="page"' : ''; ?>>
                            <svg class="mr-3 h-5 w-5 text-gray-500 group-hover:text-gray-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd" />
                            </svg>
                            <?php esc_html_e('General', 'modify-login'); ?>
                        </a>
                        <a href="#login-security" data-tab="login-security" class="tab-link group flex items-center px-3 py-2 text-sm font-medium rounded-md <?php echo $active_tab === 'login-security' ? 'text-gray-900 bg-gray-100' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50'; ?>" <?php echo $active_tab === 'login-security' ? 'aria-current="page"' : ''; ?>>
                            <svg class="mr-3 h-5 w-5 text-gray-400 group-hover:text-gray-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                <path d="M10 2a5 5 0 00-5 5v2a2 2 0 00-2 2v5a2 2 0 002 2h10a2 2 0 002-2v-5a2 2 0 00-2-2H7V7a3 3 0 015.905-1.683 1 1 0 001.19-1.677A5.002 5.002 0 0010 2z" />
                            </svg>
                            <?php esc_html_e('Login Security', 'modify-login'); ?>
                        </a>
                        <a href="#captcha" data-tab="captcha" class="tab-link group flex items-center px-3 py-2 text-sm font-medium rounded-md <?php echo $active_tab === 'captcha' ? 'text-gray-900 bg-gray-100' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50'; ?>" <?php echo $active_tab === 'captcha' ? 'aria-current="page"' : ''; ?>>
                            <svg class="mr-3 h-5 w-5 text-gray-400 group-hover:text-gray-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-6-3a2 2 0 11-4 0 2 2 0 014 0zm-2 4a5 5 0 00-4.546 2.916A5.986 5.986 0 0010 16a5.986 5.986 0 004.546-2.084A5 5 0 0010 11z" clip-rule="evenodd" />
                            </svg>
                            <?php esc_html_e('Captcha', 'modify-login'); ?>
                        </a>
                    </nav>
                </div>

                <!-- Right Content - Tab Panels -->
                <div class="flex-1">
                    <!-- General Settings -->
                    <div id="general" class="tab-panel <?php echo $active_tab === 'general' ? '' : 'hidden'; ?>">
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
                    <div id="login-security" class="tab-panel <?php echo $active_tab === 'login-security' ? '' : 'hidden'; ?>">
                        <div class="bg-white shadow rounded-lg overflow-hidden">
                            <div class="p-6 space-y-6">
                                <div class="form-group">
                                    <div class="form-field">
                                        <label for="login_endpoint" class="block text-sm font-medium text-gray-700">
                                            <?php esc_html_e('Login Endpoint', 'modify-login'); ?>
                                        </label>
                                        <div class="mt-1 flex rounded-md shadow-sm">
                                            <span class="inline-flex items-center px-3 rounded-l-md border border-r-0 border-gray-300 bg-gray-50 text-gray-500 sm:text-sm">
                                                <?php echo esc_url(home_url('/')); ?>
                                            </span>
                                            <input type="text" id="login_endpoint" name="login_endpoint" 
                                                value="<?php echo esc_attr($settings['login_endpoint']); ?>" 
                                                class="flex-1 min-w-0 block w-full px-3 py-2 rounded-none rounded-r-md focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm border-gray-300"
                                                placeholder="setup">
                                        </div>
                                        <p class="mt-2 text-sm text-gray-500">
                                            <?php esc_html_e('Enter the custom endpoint for your login page. Example: setup', 'modify-login'); ?>
                                        </p>
                                    </div>

                                    <div class="mt-4">
                                        <div class="flex items-start">
                                            <div class="flex items-center h-5">
                                                <input type="checkbox" id="enable_redirect" name="enable_redirect" 
                                                    value="1" <?php checked($settings['enable_redirect'], true); ?> 
                                                    class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded">
                                            </div>
                                            <div class="ml-3 text-sm">
                                                <label for="enable_redirect" class="font-medium text-gray-700">
                                                    <?php esc_html_e('Enable Redirect', 'modify-login'); ?>
                                                </label>
                                                <p class="text-gray-500">
                                                    <?php esc_html_e('Redirect unauthorized access attempts', 'modify-login'); ?>
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mt-4">
                                        <label for="redirect_url" class="block text-sm font-medium text-gray-700">
                                            <?php esc_html_e('Redirect URL', 'modify-login'); ?>
                                        </label>
                                        <div class="mt-1">
                                            <input type="url" id="redirect_url" name="redirect_url" 
                                                value="<?php echo esc_attr($settings['redirect_url']); ?>" 
                                                class="shadow-sm focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md"
                                                placeholder="https://example.com/404">
                                            <p class="mt-2 text-sm text-gray-500">
                                                <?php esc_html_e('Enter the URL where unauthorized access attempts should be redirected to.', 'modify-login'); ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Captcha Settings -->
                    <div id="captcha" class="tab-panel <?php echo $active_tab === 'captcha' ? '' : 'hidden'; ?>">
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
                </div>
            </div>

            <!-- Submit Button -->
            <div class="pt-5">
                <div class="flex justify-end">
                    <button type="submit" name="modify_login_save_settings_submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                        <?php esc_html_e('Save Settings', 'modify-login'); ?>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div> 