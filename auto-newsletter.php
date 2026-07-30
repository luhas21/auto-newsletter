<?php
/**
 * Plugin Name: Auto Newsletter
 * Description: Automatically sends email notifications to subscribers when you publish new posts. Simple, lightweight, and perfect for blogs and content sites. Features batch sending to prevent server overload.
 * Version: 1.0.0
 * Author: Petr Sahula
 * Text Domain: auto-newsletter
 * License: GPL v2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // no direct access
}

define( 'AUTO_NEWSLETTER_VERSION', '1.0.0' );
define( 'AUTO_NEWSLETTER_PATH', plugin_dir_path( __FILE__ ) );
define( 'AUTO_NEWSLETTER_URL', plugin_dir_url( __FILE__ ) );

/**
 * Plugin i administrace používají emoji v textech (✅, ⏳, 🔔 ...). WP core je
 * jinak nahrazuje obrázky z externí CDN (jsdelivr.net/twemoji) kvůli sjednocení
 * vzhledu napříč prohlížeči – když je CDN nedostupná/blokovaná, zobrazí se
 * rozbitý obrázek místo emoji. Moderní prohlížeče umí emoji vykreslit nativně,
 * takže tohle vypínáme úplně.
 *
 * Frontendové hooky (wp_head, wp_enqueue_scripts, wp_print_styles) registruje
 * jádro už ve wp-includes/default-filters.php, tedy dřív než náš init – tam
 * stačí odstranit na 'init'. Admin verze (admin_print_scripts,
 * admin_enqueue_scripts, admin_print_styles) ale registruje až
 * wp-admin/includes/admin-filters.php, který se načítá až uvnitř
 * wp-admin/admin.php – POZDĚJI než 'init'. Proto se to musí odstraňovat i na
 * 'admin_init' (ten už běží po admin-filters.php), jinak admin verzi jádro
 * znovu zaregistruje až po našem (příliš brzkém) odstranění.
 */
add_action( 'init', 'auto_newsletter_disable_wp_emoji' );
add_action( 'admin_init', 'auto_newsletter_disable_wp_emoji' );
function auto_newsletter_disable_wp_emoji() {
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
	remove_action( 'embed_head', 'print_emoji_detection_script' );
	remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
	remove_action( 'admin_enqueue_scripts', 'wp_enqueue_emoji_styles' );
	remove_action( 'enqueue_embed_scripts', 'wp_enqueue_emoji_styles' );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'admin_print_styles', 'print_emoji_styles' );
	remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
	remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
	remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
}

// Mailer - hlavní funkcionalita
require_once AUTO_NEWSLETTER_PATH . 'mailer.php';

// Admin rozhraní
require_once AUTO_NEWSLETTER_PATH . 'admin.php';

// Aktivace: vytvoření tabulky odběratelů.
register_activation_hook( __FILE__, 'auto_newsletter_activate' );
function auto_newsletter_activate() {
	global $wpdb;
	$table = $wpdb->prefix . 'auto_newsletter_subscribers';
	$charset = $wpdb->get_charset_collate();
	$sql = "CREATE TABLE IF NOT EXISTS $table (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		email VARCHAR(191) NOT NULL,
		hash CHAR(64) NOT NULL,
		confirmed TINYINT(1) NOT NULL DEFAULT 0,
		created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		UNIQUE KEY email (email)
	) $charset;";
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	auto_newsletter_maybe_create_mail_log_table();
	auto_newsletter_schedule_crons();
}

/**
* Zajistí, že crony jsou naplánované.
*/
add_action( 'admin_init', 'auto_newsletter_maybe_create_mail_log_table' );
function auto_newsletter_maybe_create_mail_log_table() {
	global $wpdb;
	$log_table = $wpdb->prefix . 'auto_newsletter_mail_log';
	$charset = $wpdb->get_charset_collate();
	$sql = "CREATE TABLE IF NOT EXISTS $log_table (
		post_id BIGINT UNSIGNED NOT NULL,
		subscriber_id BIGINT UNSIGNED NOT NULL,
		sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (post_id, subscriber_id)
	) $charset;";
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

add_action( 'admin_init', 'auto_newsletter_schedule_crons' );
function auto_newsletter_schedule_crons() {
	if ( ! wp_next_scheduled( 'auto_newsletter_mailer_event' ) ) {
		wp_schedule_event( time() + 600, 'ten_minutes', 'auto_newsletter_mailer_event' );
	}
}

register_deactivation_hook( __FILE__, 'auto_newsletter_deactivate' );
function auto_newsletter_deactivate() {
	wp_clear_scheduled_hook( 'auto_newsletter_mailer_event' );
}
