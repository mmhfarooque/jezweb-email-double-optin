=== Jezweb Email Double Opt-in ===
Contributors: jezweb, mahmudfarooque
Tags: email verification, double opt-in, woocommerce, registration, email confirmation
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.5.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Email verification double opt-in system for WordPress and WooCommerce user registration with customizable email templates.

== Description ==

Jezweb Email Double Opt-in adds email verification to your WordPress and WooCommerce registration process. Users must verify their email address before they can log in, ensuring you have valid email addresses for all your users.

= Features =

* **Double Opt-in for WordPress** - Require email verification for standard WordPress registrations
* **WooCommerce Integration** - Verify emails for WooCommerce customer registrations
* **Modern Admin Interface** - Clean, intuitive settings dashboard with toggle switches
* **Customizable Email Templates** - Fully customize the verification email content, subject, and styling
* **SMTP Compatible** - Works with WordPress default mail, SMTP2GO, FluentSMTP, and other SMTP plugins
* **Auto-Updates from GitHub** - Automatic updates when new versions are released on GitHub
* **Statistics Dashboard** - View verification rates and recent user activity
* **Resend Functionality** - Users can request new verification emails
* **Auto-cleanup** - Optionally delete unverified users after a specified period
* **System Status Page** - View PHP, WordPress, and WooCommerce version requirements

= Security Features =

* Cryptographically secure verification tokens (256-bit)
* Rate limiting on verification email resends (max 5 per hour)
* CSRF protection with WordPress nonces
* Input sanitization and output escaping
* Prepared SQL statements to prevent injection
* Capability checks for admin functions
* Token expiration and automatic cleanup

= Requirements =

* PHP 7.4 or higher
* WordPress 5.0 or higher
* WooCommerce 5.0 or higher (optional, for WooCommerce features)

= SMTP Compatibility =

This plugin is fully compatible with:

* WordPress default mail (wp_mail)
* SMTP2GO
* FluentSMTP
* WP Mail SMTP
* Any other SMTP plugin that hooks into wp_mail

Simply configure your preferred SMTP plugin, and verification emails will be sent through that service.

= WooCommerce Features =

When WooCommerce is installed and enabled:

* Require verification for checkout account creation
* Block unverified users from completing checkout
* Show verification status on My Account dashboard
* Handle email changes with re-verification

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/jezweb-email-double-optin` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to Email Opt-in in your admin menu to configure the plugin.

== Frequently Asked Questions ==

= Does this work with WooCommerce? =

Yes! When WooCommerce is installed, additional options appear to require email verification for WooCommerce registrations.

= What SMTP plugins are supported? =

The plugin works with any SMTP plugin that hooks into WordPress's wp_mail function, including SMTP2GO, FluentSMTP, WP Mail SMTP, and others.

= Can I customize the verification email? =

Yes, you can fully customize the email subject, heading, body text, button text, button color, and footer. Placeholders are available for dynamic content like user name and site name.

= What happens to unverified users? =

You can optionally set the plugin to automatically delete unverified users after a specified number of days. Administrators are never deleted.

= How do auto-updates work? =

The plugin checks GitHub for new releases. When a new version is available, you'll see an update notification in your WordPress plugins page, and you can update with one click.

= Is this plugin secure? =

Yes, the plugin implements multiple security measures including cryptographically secure tokens, rate limiting, CSRF protection, input sanitization, and SQL injection prevention.

== Screenshots ==

1. Modern admin dashboard with toggle switches
2. Email template customization
3. User messages configuration
4. Statistics and recent verifications
5. System status and requirements

== Changelog ==

= 1.5.3 =
* Removed GitHub repository link from admin Plugin Information section

= 1.5.2 =
* UX Improvement: Verification link now opens a clean "Email Verified" page
* Users can close the verification tab and continue in their original checkout tab
* Prevents confusing duplicate checkout tabs when verifying email
* Beautiful animated success page with clear instructions
* "Close This Tab" button and "Go to Checkout" fallback link
* Original checkout tab auto-updates via existing polling mechanism

= 1.5.1 =
* Security hardening release
* Added timing-safe token comparison using hash_equals() to prevent timing attacks
* Added rate limiting on checkout email verification (max 5 requests/hour per email)
* Improved SQL escaping in uninstall script
* Production-ready security audit completed

= 1.5.0 =
* NEW: Inline email verification on checkout - verify email BEFORE filling form
* Email verification box appears immediately after entering email address
* "Send Verification Email" button triggers verification from checkout page
* Real-time polling detects when email is verified (auto-updates UI)
* Works with WooCommerce Blocks checkout and classic checkout
* Verification without creating full user account (transient-based)
* Improved user experience - no more surprises at order placement

= 1.4.0 =
* Added WooCommerce Blocks checkout support
* Works with both classic shortcode checkout and new block-based checkout
* Added verification notice banner on blocks checkout page
* Added Store API validation for blocks checkout
* Auto-refresh checkout when email verified (works on both checkout types)
* Resend verification email button on blocks checkout
* Full compatibility with WooCommerce 8.x blocks

= 1.3.0 =
* CRITICAL: Order creation now blocked until email is verified
* New checkout flow - users stay on checkout page until verification complete
* Account created during checkout but order not processed until verified
* Auto-refresh checkout page when email is verified (AJAX polling)
* Prominent verification notice shown at top of checkout page
* Resend verification email button on checkout page
* Improved user experience - no more orders on hold

= 1.2.0 =
* Added WordPress auto-update support (Enable/Disable auto-updates toggle)
* Added "Check for updates" link in plugin row meta
* Improved GitHub updater with better WordPress integration
* Fixed auto-update toggle display for non-WordPress.org plugins

= 1.1.0 =
* Added System Status tab showing PHP, WordPress, and WooCommerce version requirements
* Added rate limiting for verification email resends (security enhancement)
* Enhanced token validation with format checking
* Improved security with proper nonce handling
* Added security features display in admin panel
* Code improvements for WordPress coding standards

= 1.0.0 =
* Initial release
* WordPress registration email verification
* WooCommerce integration
* Customizable email templates
* Modern admin interface
* SMTP compatibility
* GitHub auto-updater
* Statistics dashboard

== Upgrade Notice ==

= 1.5.2 =
UX improvement: Verification link now opens a simple "Email Verified" page instead of another checkout tab, reducing user confusion.

= 1.5.1 =
Security hardening release. Recommended update for all users.

= 1.5.0 =
Major UX improvement: Email verification now happens immediately after entering email, before filling out the rest of the checkout form.

= 1.4.0 =
Adds support for WooCommerce Blocks checkout (the new block-based checkout). Works with both classic and blocks checkout.

= 1.3.0 =
Critical update: Orders are now completely blocked until email verification is complete. Improved checkout flow with auto-refresh when verified.

= 1.1.0 =
Security enhancement release with rate limiting, improved token validation, and system status display.

= 1.0.0 =
Initial release of Jezweb Email Double Opt-in.
