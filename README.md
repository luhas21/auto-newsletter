# Auto Newsletter

Automatically sends email notifications to subscribers when you publish new posts. Simple, lightweight, and perfect for blogs and content sites. Features batch sending to prevent server overload.

## Description

Auto Newsletter is a lightweight plugin that automatically sends email notifications to your subscribers whenever you publish a new post. No complex setup, no bloated features – just install, set up your subscription form, and let the plugin do the work.

### Key Features

* **Automatic Post Notifications:** Sends emails to subscribers when new posts are published.
* **Batch Sending:** Prevents server overload by sending emails in batches (default 50 emails per 10 minutes).
* **Double Opt-in:** Ensures subscribers confirm their email address.
* **Simple Admin:** Clean interface for managing subscribers and viewing send logs.
* **Import/Export:** Easily import subscribers from CSV files.
* **SPF/DKIM Friendly:** Uses WordPress `wp_mail()` function, compatible with most hosting setups.

## Installation

1. Upload the plugin files to the `/wp-content/plugins/auto-newsletter` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to "Newsletter" in the admin menu to configure settings and get the subscription shortcode.

## Frequently Asked Questions

### Does it send emails immediately?

No, it uses a cron job to send emails in batches (default 50 every 10 minutes) to prevent server overload.

### Can I customize the email template?

Yes, you can edit the subject and body of the notification email in the settings. Use `{title}`, `{excerpt}`, and `{unsub_link}` placeholders.

### Does it work with Gutenberg?

Yes, the subscription form can be added via shortcode `[auto_newsletter_form]` or via a PHP function.

## Developers

### Custom Update Server (for developers)

If you want to use your own update server during development, follow these steps:

1. **Copy `.env.example` to `.env`** (this file is gitignored, so your secrets are safe).
2. **Configure `wp-config.php`** by adding these lines:
   ```php
   define( 'AUTO_NEWSLETTER_DEV_UPDATER', true );
   define( 'AUTO_NEWSLETTER_UPDATE_URL', 'https://your-update-server.com/path/to/update.json' );
   ```
3. **Your update server** must provide a JSON file in this format (see `update.json.example` in this repo).

### File Structure

* `auto-newsletter.php` – Main plugin file.
* `admin.php` – Admin interface.
* `mailer.php` – Core sending logic.
* `updater.php` – Custom updater class (only loads if `AUTO_NEWSLETTER_DEV_UPDATER` is defined).
* `.env` – Your local configuration (ignored by Git).

## Changelog

### 1.0.0
* Initial release.
* Automatic post notifications.
* Batch sending (50/10min).
* Double opt-in.
* CSV import.