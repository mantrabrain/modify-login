=== Hide WordPress Login, Modify WordPress Login, WordPress Login Page Modify - Modify Login ===
Contributors: MantraBrain
Donate link: https://mantrabrain.com
Tags: wp-login.php, hide, hide login, security, safe login
Requires at least: 5.6
Tested up to: 6.4
Stable tag: 1.1
License: GPLv2

Modify WordPress admin login technique. This plugin will modify your login url so that your website will be more secure.
  

== Description ==

This plugin helps you to modify your login url so that your website will be more secure and safe.

It doesn't modify your core WordPress file to modify login url.

After installing this plugin you cann't login your website with /wp-admin or /wp-login.php, you need to add endpoint at the end of your login url as follow:

Default Login URL :  `http://yourdomain.com?setup`

You can easily modify this endpoint from

Settings > Modify Login  and change the login endpoint as per your requirement.


= Features =

* Block default wp-login.php 
* Easy to change login endpoint url
* High security
* Light weight plugin
* Easy to setup and modify endpoint


= Modify Login Video Tutorial: =

[youtube https://www.youtube.com/watch?v=KlWkYHYH8Y0]



== Screenshots ==
1. Backend setting page

== Installation ==

1. Download and extract plugin files to a wp-content/plugin directory.
2. Activate the plugin through the WordPress admin interface.
3. Done 

== Frequently Asked Questions ==

= What is default login endpoint? =

Your default endpoint is `setup`
Demo : `http://yourdomain.com?setup`

= I can't access my admin dashboard? =
This issue might arise due to plugins altering your .htaccess files, introducing new rules, or from an outdated WordPress MU configuration that hasn't been updated since Multisite was incorporated.
Start by examining your .htaccess file and comparing it to a standard one to identify any discrepancies causing the problem.



= How to add endpoint of login url ? =

Go to `Settings > Modify Login` and update the login endpoint

== Changelog ==

= 1.1 - 17/12/2023 =
- Added - Redirect URL
- Fixed - WordPress 6.4 compatibility check


= 1.0.5 - 27/05/2022 =
- Version compatibility tested

= 1.0.4 - 24/07/2021 =
- Version compatibility tested

= 1.0.3 - 03/04/2021 =
- Version compatibility tested

= 1.0.2 - 01/09/2020 =
- Version compatibility tested

= 1.0.1 - 29/08/2019 =
- Initial Version released