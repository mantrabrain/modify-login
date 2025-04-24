=== Modify Login - Custom WordPress Login URL & Login Page Designer ===
Contributors: MantraBrain
Donate link: https://mantrabrain.com
Tags: custom login, hide wp-login, login security, login page, login customizer
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Secure WordPress login with custom URL, protect wp-admin, and design beautiful login pages with drag & drop builder. No code required.

== Description ==

**Modify Login** transforms the default WordPress login experience with enhanced security features and a modern, customizable login page design.

✅ Prevent brute force attacks by hiding your login page
✅ Create a beautiful branded login experience in minutes
✅ No coding required - easy drag and drop builder
✅ Redirect unauthorized users to any page

### 🔒 Security Features

* **Custom Login URL**: Replace the standard wp-login.php with your custom endpoint (e.g., yourdomain.com/setup)
* **Login Protection**: Block direct access to wp-login.php and wp-admin for non-logged in users
* **Redirect Protection**: Redirect unauthorized access attempts to a URL of your choice
* **Google reCAPTCHA Integration**: Add CAPTCHA verification to prevent bot attacks
* **Login Attempt Tracking**: Monitor and log all login attempts with IP addresses and location data

### 🎨 Design Features

* **Visual Login Builder**: Modern drag-and-drop interface to customize your login page appearance
* **Background Customization**: Set custom background colors, images, and opacity
* **Logo Control**: Upload your own logo with full control over dimensions and positioning
* **Form Styling**: Customize the login form with custom colors, borders, and padding
* **Button Styling**: Style login buttons with custom colors and hover effects
* **Custom CSS**: Add your own CSS for unlimited customization possibilities

### 🔄 Redirect Options

* **Login Redirect**: Send users to a specific URL after successful login
* **Logout Redirect**: Redirect users to a custom URL after logging out

### 👨‍💻 Developer Friendly

* **Clean Code**: Well-organized, documented code following WordPress best practices
* **Filter Hooks**: Extensive filter hooks for developers to extend functionality
* **Performance Optimized**: Lightweight implementation with minimal impact on site speed

### Perfect For:

* Membership sites
* Client websites
* E-commerce stores
* Educational platforms
* Business websites
* Any WordPress site needing improved security

### How It Works

1. Set a custom login URL endpoint in the plugin settings (default is "setup")
2. Optionally enable redirect protection to block direct access to wp-login.php
3. Customize the login page appearance using the visual builder
4. Add optional reCAPTCHA verification for enhanced security

### Get Help

* [Documentation](https://mantrabrain.com/docs-category/modify-login/)
* [Support Forum](https://wordpress.org/support/plugin/modify-login/)
* [Contact Us](https://mantrabrain.com/contact/)

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/modify-login` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Go to Settings > Modify Login to configure the plugin
4. The default login endpoint is set to "setup", but you can change it in the settings
5. Configure additional options as needed
6. Use the Login Builder to customize the appearance of your login page

== Frequently Asked Questions ==

= What is the default login endpoint? =

The plugin comes with "setup" as the default login endpoint. Your login URL will be: yourdomain.com/setup

You can easily change this to any text you prefer in the Settings > Modify Login page.

= How do I access the login page after enabling the custom login URL? =

After activating the plugin, you can access your login page at: yourdomain.com/setup

If you've changed the default endpoint to something else, you'll need to use that instead (e.g., yourdomain.com/your-custom-endpoint).

= Will this plugin work on my multisite WordPress installation? =

Yes, Modify Login is fully compatible with WordPress multisite installations. The login URL customization works across all sites in your network.

= I enabled the custom login URL but now I can't access my admin dashboard. What should I do? =

If you can't access your admin dashboard, try these steps:

1. Append the default endpoint to your site URL (e.g., yourdomain.com/setup)
2. Check your .htaccess file for any conflicting rules
3. Temporarily disable any security plugins that might be interfering
4. If all else fails, rename the plugin folder in /wp-content/plugins/ via FTP to deactivate the plugin

= Does this plugin modify core WordPress files? =

No, Modify Login doesn't modify any core WordPress files. It uses WordPress hooks and filters to change the login URL and customize the login page appearance. This makes it safer and more compatible with WordPress updates.

= Can I customize the redirects after login/logout? =

Yes, you can set custom URLs for both login and logout redirects in the plugin settings. This is useful for directing users to specific pages after they log in or out of your site.

= How does the Login Builder work? =

The Login Builder provides a visual interface where you can customize your login page appearance. You can:
- Change background colors and images
- Upload and position your custom logo
- Style the login form and buttons
- Add custom CSS for advanced styling

The builder shows you a live preview of your changes, making it easy to design the perfect login page.

= Is reCAPTCHA required to use this plugin? =

No, reCAPTCHA integration is optional. You can enable or disable it in the plugin settings. If enabled, you'll need to provide your own reCAPTCHA site key and secret key from Google.

= Will this plugin conflict with other security plugins? =

Modify Login is designed to be compatible with most WordPress security plugins. However, plugins that also modify the login URL might conflict. If you experience issues, try disabling one of the conflicting plugins or adjust their settings to avoid overlap.

= Can I add my own logo to the login page? =

Yes, the Login Builder includes full logo customization. You can upload your own logo and control its size and position. This is perfect for adding your brand identity to the login experience.

= Is the custom login URL compatible with caching plugins? =

Yes, the plugin is designed to work with popular caching plugins. If you experience issues after changing login settings, try clearing your cache.

= What happens to the default wp-login.php page when this plugin is active? =

When redirect protection is enabled, the plugin will redirect anyone trying to access wp-login.php directly to your specified redirect URL (or the homepage if no URL is specified). This adds an extra layer of security by concealing the standard login path.

= Can I track login attempts to my site? =

Yes, version 2.0.0 includes a login attempt tracking feature that logs all login attempts. You can view IP addresses, user agents, and geographic location of login attempts from the plugin's admin area.

= Is there a way to migrate from version 1.x to 2.0.0 safely? =

Yes, your existing settings will be preserved when upgrading from previous versions. However, as with any major update, we recommend backing up your website before upgrading. The new visual login builder will allow you to take advantage of all the new customization features.

= What should I do if my custom login URL stops working? =

If your custom login URL stops working, try these troubleshooting steps:
1. Clear your site cache completely
2. Flush your permalink structure (Settings > Permalinks > Save Changes)
3. Check for plugin conflicts by temporarily disabling other plugins
4. Verify your .htaccess file is correctly configured
5. Reset the login endpoint to the default "setup" in the plugin settings

= What should I do if I forget my custom login endpoint? =

If you forget your custom login endpoint, you have several options to regain access:

1. **Try the default endpoint**: First, try using the default "setup" endpoint (yourdomain.com/setup) as it may still work if you haven't changed it.

2. **Check the database**: Your login endpoint is stored in the WordPress options table. If you have database access, you can find it in the `modify_login_settings` option or `modify_login_login_endpoint` option.

3. **Access via FTP/SFTP**: If you have FTP/SFTP access to your server, you can temporarily rename the plugin folder (from 'modify-login' to something like 'modify-login-disabled') to deactivate the plugin. This will restore the default wp-login.php access.

4. **Use WP-CLI**: If you have WP-CLI access, you can run `wp option get modify_login_settings` to view your stored settings including the endpoint.

5. **Edit wp-config.php**: As a last resort, you can add this line to your wp-config.php file to temporarily disable all plugins:
   ```php
   define('WP_PLUGIN_DIR', '/tmp/disabled-plugins');
   ```
   After logging in with the standard wp-login.php, remember to remove this line immediately.

Always remember to keep a secure record of your custom login endpoint in a password manager or other secure location.

== Screenshots ==

1. Settings page with login security options
2. Login builder interface
3. Customized WordPress login page
4. reCAPTCHA integration on login form
5. Login attempt logs and tracking

== Changelog ==

= 2.0.0 - 2023-12-30 =
* Added: Complete UI redesign with modern interface
* Added: Visual login page builder with live preview
* Added: Background image customization with opacity, position and size controls
* Added: Logo customization options
* Added: Form styling options with color picker
* Added: Button styling customization
* Added: Login/logout custom redirects
* Added: Google reCAPTCHA integration
* Added: Login attempt tracking and logging
* Added: Custom CSS support
* Improved: Better security measures for login protection
* Improved: Code architecture and performance optimization
* Improved: Documentation and user guidance
* Fixed: Various bugs and compatibility issues

= 1.1 - 2023-12-17 =
* Added: Redirect URL for unauthorized access
* Fixed: WordPress 6.4 compatibility check

= 1.0.5 - 2022-05-27 =
* Version compatibility tested

= 1.0.4 - 2021-07-24 =
* Version compatibility tested

= 1.0.3 - 2021-04-03 =
* Version compatibility tested

= 1.0.2 - 2020-09-01 =
* Version compatibility tested

= 1.0.1 - 2019-08-29 =
* Initial Version released

== Upgrade Notice ==

= 2.0.0 =
Major update with completely redesigned interface, visual login page builder, and many new features! Please backup your site before upgrading.

= 1.1 =
Added redirect URL feature for unauthorized access attempts and WordPress 6.4 compatibility.