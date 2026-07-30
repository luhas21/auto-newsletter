=== Auto Newsletter ===
Contributors: petrsahula
Tags: newsletter, email, subscribers, post notification, automatic, batch sending
Requires at least: 5.0
Tested up to: 6.5
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically sends email notifications to subscribers when you publish new posts. Simple, lightweight, and perfect for blogs.

== Description ==

Auto Newsletter is a lightweight plugin that automatically sends email notifications to your subscribers whenever you publish a new post. No complex setup, no bloated features – just install, set up your subscription form, and let the plugin do the work.

= Key Features =

* **Automatic Post Notifications:** Sends emails to subscribers when new posts are published.
* **Batch Sending:** Prevents server overload by sending emails in batches (default 50 emails per 10 minutes).
* **Double Opt-in:** Ensures subscribers confirm their email address.
* **Simple Admin:** Clean interface for managing subscribers and viewing send logs.
* **Import/Export:** Easily import subscribers from CSV files.
* **SPF/DKIM Friendly:** Uses WordPress `wp_mail()` function, compatible with most hosting setups.

= Why Auto Newsletter? =

Most newsletter plugins are either too simple (no automation) or too complex (CRM, drag-drop builders). Auto Newsletter sits in the middle – it does one thing perfectly: **notifies your readers when you write something new.**

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/auto-newsletter` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to "Newsletter" in the admin menu to configure settings and get the subscription shortcode.

== Frequently Asked Questions ==

= Does it send emails immediately? =

No, it uses a cron job to send emails in batches (default 50 every 10 minutes) to prevent server overload.

= Can I customize the email template? =

Yes, you can edit the subject and body of the notification email in the settings. Use `{title}`, `{excerpt}`, and `{unsub_link}` placeholders.

= Does it work with Gutenberg? =

Yes, the subscription form can be added via shortcode `[auto_newsletter_form]` or via a PHP function.

== Screenshots ==

1. Subscribers management screen.
2. Email template settings.
3. Send log history.

== Changelog ==

= 1.0.0 =
* Initial release.
* Automatic post notifications.
* Batch sending (50/10min).
* Double opt-in.
* CSV import.

== Upgrade Notice ==

= 1.0.0 =
Initial release.