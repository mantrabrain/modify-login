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
                        <a href="#login-security" data-tab="login-security" class="tab-link group flex items-center px-3 py-2 text-sm font-medium rounded-md <?php echo $active_tab === 'login-security' || $active_tab === 'general' ? 'text-gray-900 bg-gray-100' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50'; ?>" <?php echo $active_tab === 'login-security' || $active_tab === 'general' ? 'aria-current="page"' : ''; ?>>
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
                    <!-- Login Security Settings -->
                    <div id="login-security" class="tab-panel <?php echo $active_tab === 'login-security' || $active_tab === 'general' ? '' : 'hidden'; ?>">
                        <div class="bg-white shadow rounded-lg overflow-hidden">
                            <div class="p-6 space-y-6">
                                <!-- Login Endpoint Explanation Card -->
                                <div class="login-protection-info">
                                    <div class="flex">
                                        <div class="flex-shrink-0">
                                            <svg class="h-6 w-6 text-blue-600" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                                            </svg>
                                        </div>
                                        <div class="ml-3">
                                            <h3>
                                                <?php esc_html_e('How Login Protection Works', 'modify-login'); ?>
                                            </h3>
                                            <ul>
                                                <li>
                                                    <span class="step-number">1</span>
                                                    <span><?php esc_html_e('Set a custom login URL (e.g., "setup") to create an alternative to wp-login.php', 'modify-login'); ?></span>
                                                </li>
                                                <li>
                                                    <span class="step-number">2</span>
                                                    <span><?php esc_html_e('When redirection is enabled, direct access to wp-login.php and wp-admin is blocked for non-logged in users', 'modify-login'); ?></span>
                                                </li>
                                                <li>
                                                    <span class="step-number">3</span>
                                                    <span><?php esc_html_e('Unauthorized access attempts will be redirected to the URL you specify below', 'modify-login'); ?></span>
                                                </li>
                                                <li>
                                                    <span class="step-number">4</span>
                                                    <span><?php esc_html_e('Only your custom login URL will display the WordPress login form', 'modify-login'); ?></span>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <!-- Custom Login URL Section -->
                                    <div class="mb-8">
                                        <h3 class="text-lg font-medium text-gray-900 mb-4 pb-2 border-b border-gray-200">
                                            <?php esc_html_e('Custom Login URL', 'modify-login'); ?>
                                        </h3>
                                        
                                        <div class="form-field">
                                            <label for="login_endpoint" class="block text-sm font-medium text-gray-700">
                                                <?php esc_html_e('Login Endpoint', 'modify-login'); ?>
                                            </label>
                                            <div class="mt-1 flex rounded-md shadow-sm">
                                                <span class="inline-flex items-center px-3 rounded-l-md border border-r-0 border-gray-300 bg-gray-50 text-gray-500 sm:text-sm">
                                                    <?php 
                                                    // Check permalink structure for correct display
                                                    $permalink_structure = get_option('permalink_structure');
                                                    $using_plain_permalinks = empty($permalink_structure);
                                                    
                                                    if ($using_plain_permalinks) {
                                                        echo esc_url(home_url('/')) . '?';
                                                    } else {
                                                        echo esc_url(home_url('/'));
                                                    }
                                                    ?>
                                                </span>
                                                <input type="text" id="login_endpoint" name="login_endpoint" 
                                                    value="<?php echo isset($settings['login_endpoint']) ? esc_attr($settings['login_endpoint']) : ''; ?>" 
                                                    class="flex-1 min-w-0 block w-full px-3 py-2 rounded-none rounded-r-md focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm border-gray-300"
                                                    placeholder="setup">
                                            </div>
                                            <p class="mt-2 text-sm text-gray-500">
                                                <?php 
                                                // Get the current login endpoint or use "setup" as example
                                                $endpoint = isset($settings['login_endpoint']) && !empty($settings['login_endpoint']) 
                                                    ? esc_attr($settings['login_endpoint']) 
                                                    : 'setup';
                                                
                                                $full_login_url = $using_plain_permalinks 
                                                    ? home_url('/') . '?' . $endpoint
                                                    : home_url('/') . $endpoint;
                                                
                                                // Detailed description with current login URL
                                                echo sprintf(
                                                    esc_html__('Create a custom login URL to replace the default wp-login.php page. Your current login URL is: %s', 'modify-login'),
                                                    '<strong><a href="' . esc_url($full_login_url) . '" target="_blank">' . esc_url($full_login_url) . '</a></strong>'
                                                );
                                                ?>
                                            </p>
                                            <div class="mt-3 text-sm text-gray-600">
                                                <ul class="list-disc pl-5 space-y-1">
                                                    <li><?php esc_html_e('Choose a unique, non-obvious name for better security (avoid "login", "admin", etc.)', 'modify-login'); ?></li>
                                                    <li>
                                                        <?php 
                                                        $site_url_base = rtrim(home_url(), '/');
                                                        if ($using_plain_permalinks) {
                                                            echo sprintf(
                                                                esc_html__('With plain permalinks, your login URL will be in the format: %s/?endpoint', 'modify-login'),
                                                                $site_url_base
                                                            );
                                                        } else {
                                                            echo sprintf(
                                                                esc_html__('With pretty permalinks, your login URL will be in the format: %s/endpoint', 'modify-login'),
                                                                $site_url_base
                                                            );
                                                        }
                                                        ?>
                                                    </li>
                                                    <li><?php esc_html_e('Bookmark your new login URL to ensure you can always access it', 'modify-login'); ?></li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Access Protection Section -->
                                    <div class="mb-8">
                                        <h3 class="text-lg font-medium text-gray-900 mb-4 pb-2 border-b border-gray-200">
                                            <?php esc_html_e('Access Protection', 'modify-login'); ?>
                                        </h3>
                                        
                                        <div class="mt-4">
                                            <div class="flex items-start">
                                                <div class="flex items-center h-5">
                                                    <input type="checkbox" id="enable_redirect" name="enable_redirect" 
                                                        value="1" <?php checked(isset($settings['enable_redirect']) ? $settings['enable_redirect'] : 0, true); ?> 
                                                        class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
                                                        onchange="toggleRedirectUrlField()">
                                                </div>
                                                <div class="ml-3 text-sm">
                                                    <label for="enable_redirect" class="font-medium text-gray-700">
                                                        <?php esc_html_e('Enable Redirect Protection', 'modify-login'); ?>
                                                    </label>
                                                    <p class="text-gray-500">
                                                        <?php esc_html_e('Block direct access to wp-login.php and wp-admin for non-logged in users, redirecting them to the URL specified below.', 'modify-login'); ?>
                                                    </p>
                                                </div>
                                            </div>
                                        </div>

                                        <div id="redirect_url_field" class="mt-4" style="<?php echo isset($settings['enable_redirect']) && $settings['enable_redirect'] ? '' : 'display: none;'; ?>">
                                            <label for="redirect_url" class="block text-sm font-medium text-gray-700">
                                                <?php esc_html_e('Redirect URL for Unauthorized Access', 'modify-login'); ?>
                                            </label>
                                            <div class="mt-1">
                                                <input type="url" id="redirect_url" name="redirect_url" 
                                                    value="<?php echo isset($settings['redirect_url']) ? esc_attr($settings['redirect_url']) : ''; ?>" 
                                                    class="shadow-sm focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md"
                                                    placeholder="https://example.com/404">
                                                <p class="mt-2 text-sm text-gray-500">
                                                    <?php esc_html_e('Enter the URL where unauthorized access attempts to wp-login.php and wp-admin will be redirected. Use your 404 page or homepage for best security.', 'modify-login'); ?>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Post-Authentication Redirection Section -->
                                    <div class="mb-8">
                                        <h3 class="text-lg font-medium text-gray-900 mb-4 pb-2 border-b border-gray-200">
                                            <?php esc_html_e('Post-Authentication Redirection', 'modify-login'); ?>
                                        </h3>
                                        
                                        <div class="mt-4">
                                            <label for="login_redirect_url" class="block text-sm font-medium text-gray-700">
                                                <?php esc_html_e('Login Redirect URL', 'modify-login'); ?>
                                            </label>
                                            <div class="mt-1">
                                                <input type="url" name="login_redirect_url" id="login_redirect_url" 
                                                    placeholder="<?php esc_attr_e('e.g., https://example.com/dashboard', 'modify-login'); ?>"
                                                    value="<?php echo isset($settings['login_redirect_url']) ? esc_attr($settings['login_redirect_url']) : ''; ?>" 
                                                    class="shadow-sm focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md">
                                                <p class="mt-2 text-sm text-gray-500">
                                                    <?php esc_html_e('Determines where users are sent after successful login. Perfect for directing users to a specific page like a members area or dashboard. Leave empty to use WordPress default (usually admin dashboard or the page they were trying to access).', 'modify-login'); ?>
                                                </p>
                                            </div>
                                        </div>

                                        <div class="mt-4">
                                            <label for="logout_redirect_url" class="block text-sm font-medium text-gray-700">
                                                <?php esc_html_e('Logout Redirect URL', 'modify-login'); ?>
                                            </label>
                                            <div class="mt-1">
                                                <input type="url" name="logout_redirect_url" id="logout_redirect_url" 
                                                    placeholder="<?php esc_attr_e('e.g., https://example.com', 'modify-login'); ?>"
                                                    value="<?php echo isset($settings['logout_redirect_url']) ? esc_attr($settings['logout_redirect_url']) : ''; ?>" 
                                                    class="shadow-sm focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md">
                                                <p class="mt-2 text-sm text-gray-500">
                                                    <?php esc_html_e('Controls where users are redirected when they log out. Useful for sending users to your homepage or a custom "logged out" page instead of the default WordPress login screen with logout message.', 'modify-login'); ?>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Login Tracking Settings -->
                                    <div class="mb-8">
                                        <h3 class="text-lg font-medium text-gray-900 mb-4 pb-2 border-b border-gray-200">
                                            <?php esc_html_e('Login Tracking', 'modify-login'); ?>
                                        </h3>
                                        
                                        <div class="mt-4">
                                            <div class="flex items-start">
                                                <div class="flex items-center h-5">
                                                    <input type="checkbox" id="enable_tracking" name="enable_tracking" 
                                                        value="1" <?php checked(isset($settings['enable_tracking']) ? $settings['enable_tracking'] : 0, true); ?> 
                                                        class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded">
                                                </div>
                                                <div class="ml-3 text-sm">
                                                    <label for="enable_tracking" class="font-medium text-gray-700">
                                                        <?php esc_html_e('Enable Login Tracking', 'modify-login'); ?>
                                                    </label>
                                                    <p class="text-gray-500">
                                                        <?php esc_html_e('Log all login attempts to your site, including successful logins and failures. This helps you monitor unauthorized access attempts.', 'modify-login'); ?>
                                                    </p>
                                                    <p class="text-gray-500 mt-1">
                                                        <?php esc_html_e('Each log entry will include username, IP address, location data, and time of the attempt.', 'modify-login'); ?>
                                                    </p>
                                                    <p class="text-gray-500 mt-1">
                                                        <?php echo sprintf(
                                                            esc_html__('View login attempts in the %s section.', 'modify-login'),
                                                            '<a href="' . admin_url('admin.php?page=modify-login-logs') . '" class="text-blue-600 hover:text-blue-800">Login Logs</a>'
                                                        ); ?>
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-6 pt-4 border-t border-gray-200">
                                        <div class="important-note-warning">
                                            <div class="flex">
                                                <div class="flex-shrink-0">
                                                    <svg class="h-6 w-6 text-yellow-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                                                    </svg>
                                                </div>
                                                <div class="ml-3">
                                                    <h3>
                                                        <?php esc_html_e('Important Note', 'modify-login'); ?>
                                                    </h3>
                                                    <div>
                                                        <p>
                                                            <?php esc_html_e('After changing these settings, please verify that you can access your new login URL before logging out.', 'modify-login'); ?>
                                                        </p>
                                                        <p>
                                                            <?php esc_html_e('If you get locked out, access your WordPress database and delete the "modify_login_settings" option from the wp_options table.', 'modify-login'); ?>
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
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
                                <!-- Information about reCAPTCHA -->
                                <div class="login-protection-info mb-6">
                                    <div class="flex">
                                        <div class="flex-shrink-0">
                                            <svg class="h-6 w-6 text-blue-600" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                                            </svg>
                                        </div>
                                        <div class="ml-3">
                                            <h3><?php esc_html_e('How to Get reCAPTCHA Keys', 'modify-login'); ?></h3>
                                            <div class="mt-2 text-sm">
                                                <p><?php esc_html_e('To use Google reCAPTCHA, you need to obtain Site and Secret keys:', 'modify-login'); ?></p>
                                                <ol class="list-decimal pl-5 mt-2 space-y-1">
                                                    <li><?php esc_html_e('Go to the Google reCAPTCHA admin console: https://www.google.com/recaptcha/admin', 'modify-login'); ?></li>
                                                    <li><?php esc_html_e('Sign in with your Google account', 'modify-login'); ?></li>
                                                    <li><?php esc_html_e('Register a new site by clicking the "+" button', 'modify-login'); ?></li>
                                                    <li><?php esc_html_e('Enter a label (e.g., "My WordPress Site")', 'modify-login'); ?></li>
                                                    <li><?php esc_html_e('Select reCAPTCHA v2 "I\'m not a robot" Checkbox', 'modify-login'); ?></li>
                                                    <li><?php esc_html_e('Add your domain(s) in the "Domains" field', 'modify-login'); ?></li>
                                                    <li><?php esc_html_e('Accept the Terms of Service and click "Submit"', 'modify-login'); ?></li>
                                                    <li><?php esc_html_e('Copy the provided "Site Key" and "Secret Key" below', 'modify-login'); ?></li>
                                                </ol>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- reCAPTCHA Settings -->
                                <div class="mb-8">
                                    <h3 class="text-lg font-medium text-gray-900 mb-4 pb-2 border-b border-gray-200">
                                        <?php esc_html_e('reCAPTCHA Protection', 'modify-login'); ?>
                                    </h3>
                                    
                                    <div class="mt-4">
                                        <div class="flex items-start">
                                            <div class="flex items-center h-5">
                                                <input type="checkbox" id="enable_recaptcha" name="enable_recaptcha" 
                                                    value="1" <?php checked(isset($settings['enable_recaptcha']) && $settings['enable_recaptcha'] === 'yes', true); ?> 
                                                    class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
                                                    onchange="toggleRecaptchaFields()">
                                            </div>
                                            <div class="ml-3 text-sm">
                                                <label for="enable_recaptcha" class="font-medium text-gray-700">
                                                    <?php esc_html_e('Enable reCAPTCHA', 'modify-login'); ?>
                                                </label>
                                                <p class="text-gray-500">
                                                    <?php esc_html_e('Add Google reCAPTCHA to your login form to prevent automated login attempts.', 'modify-login'); ?>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div id="recaptcha_fields" class="mt-4 ml-6 pl-4 border-l-2 border-gray-200 <?php echo isset($settings['enable_recaptcha']) && $settings['enable_recaptcha'] === 'yes' ? '' : 'hidden'; ?>">
                                        <div class="mb-4">
                                            <label for="recaptcha_site_key" class="block text-sm font-medium text-gray-700 mb-1">
                                                <?php esc_html_e('Site Key', 'modify-login'); ?>
                                            </label>
                                            <input type="text" id="recaptcha_site_key" name="recaptcha_site_key" 
                                                value="<?php echo esc_attr(isset($settings['recaptcha_site_key']) ? $settings['recaptcha_site_key'] : ''); ?>" 
                                                class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                                            <p class="mt-1 text-sm text-gray-500">
                                                <?php esc_html_e('Enter your reCAPTCHA site key here.', 'modify-login'); ?>
                                            </p>
                                        </div>
                                        
                                        <div class="mb-4">
                                            <label for="recaptcha_secret_key" class="block text-sm font-medium text-gray-700 mb-1">
                                                <?php esc_html_e('Secret Key', 'modify-login'); ?>
                                            </label>
                                            <input type="password" id="recaptcha_secret_key" name="recaptcha_secret_key" 
                                                value="<?php echo esc_attr(isset($settings['recaptcha_secret_key']) ? $settings['recaptcha_secret_key'] : ''); ?>" 
                                                class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                                            <p class="mt-1 text-sm text-gray-500">
                                                <?php esc_html_e('Enter your reCAPTCHA secret key here.', 'modify-login'); ?>
                                            </p>
                                        </div>
                                        
                                        <p class="text-sm text-gray-500 mt-2">
                                            <?php 
                                            // Translators: %s is the URL to Google reCAPTCHA admin
                                            echo sprintf(
                                                __('You can get your keys from %s', 'modify-login'),
                                                '<a href="https://www.google.com/recaptcha/admin" target="_blank" class="text-blue-600 hover:text-blue-800">Google reCAPTCHA Admin</a>'
                                            ); 
                                            ?>
                                        </p>
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

<script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize tab switching
        const tabLinks = document.querySelectorAll('.tab-link');
        const tabPanels = document.querySelectorAll('.tab-panel');
        const activeTabInput = document.getElementById('active_tab');

        tabLinks.forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const tabId = this.getAttribute('data-tab');
                
                // Update active tab styles
                tabLinks.forEach(l => l.classList.remove('text-gray-900', 'bg-gray-100'));
                tabLinks.forEach(l => l.classList.add('text-gray-600', 'hover:text-gray-900', 'hover:bg-gray-50'));
                this.classList.remove('text-gray-600', 'hover:text-gray-900', 'hover:bg-gray-50');
                this.classList.add('text-gray-900', 'bg-gray-100');
                
                // Show active tab panel
                tabPanels.forEach(panel => panel.classList.add('hidden'));
                document.getElementById(tabId).classList.remove('hidden');
                
                // Update hidden input for active tab
                activeTabInput.value = tabId;
            });
        });
        
        // Initialize conditional field visibility
        toggleRedirectUrlField();
    });
    
    function toggleRedirectUrlField() {
        const redirectFieldContainer = document.getElementById('redirect_url_field');
        const enableRedirectCheckbox = document.getElementById('enable_redirect');
        
        if (enableRedirectCheckbox.checked) {
            redirectFieldContainer.style.display = 'block';
        } else {
            redirectFieldContainer.style.display = 'none';
        }
    }

    // Toggle visibility of reCAPTCHA fields based on checkbox
    function toggleRecaptchaFields() {
        const enableRecaptcha = document.getElementById('enable_recaptcha');
        const recaptchaFields = document.getElementById('recaptcha_fields');
        
        if (enableRecaptcha && recaptchaFields) {
            if (enableRecaptcha.checked) {
                recaptchaFields.classList.remove('hidden');
            } else {
                recaptchaFields.classList.add('hidden');
            }
        }
    }
</script> 