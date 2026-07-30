<?php
/**
 * Admin rozhraní: přehled odběratelů, nastavení maileru.
 */

// ---- Cron interval 10 minut ----
add_filter( 'cron_schedules', 'auto_newsletter_cron_schedules' );
function auto_newsletter_cron_schedules( $schedules ) {
	$schedules['ten_minutes'] = array(
		'interval' => 600,
		'display'  => 'Každých 10 minut',
	);
	return $schedules;
}

// ---- Admin menu ----
add_action( 'admin_menu', 'auto_newsletter_admin_menu' );
function auto_newsletter_admin_menu() {
	// Schopnost pro zobrazení Mailer sekce (šéfredaktor = editor)
	$mailer_cap = 'edit_pages';

	// Hlavní položka "Mailer"
	add_menu_page(
		__( 'Mailer - Newsletter', 'auto-newsletter' ),
		__( 'Mailer', 'auto-newsletter' ),
		$mailer_cap,
		'auto-newsletter-mailer',
		'auto_newsletter_mailer_page',
		'dashicons-email-alt',
		30
	);
	// První submenu se stejným slugem = přejmenuje auto-generated položku na "Settings"
	add_submenu_page(
		'auto-newsletter-mailer',
		__( 'Newsletter Settings', 'auto-newsletter' ),
		__( 'Settings', 'auto-newsletter' ),
		$mailer_cap,
		'auto-newsletter-mailer',
		'auto_newsletter_mailer_page'
	);
	// Send Management (test, manual batch, queue)
	add_submenu_page(
		'auto-newsletter-mailer',
		__( 'Send Management', 'auto-newsletter' ),
		__( 'Send', 'auto-newsletter' ),
		$mailer_cap,
		'auto-newsletter-send',
		'auto_newsletter_mailer_send_page'
	);
	// Send History
	add_submenu_page(
		'auto-newsletter-mailer',
		__( 'Send History', 'auto-newsletter' ),
		__( 'History', 'auto-newsletter' ),
		$mailer_cap,
		'auto-newsletter-mailer-history',
		'auto_newsletter_mailer_history_page'
	);
	add_submenu_page(
		'auto-newsletter-mailer',
		__( 'Subscribers', 'auto-newsletter' ),
		__( 'List', 'auto-newsletter' ),
		$mailer_cap,
		'auto-newsletter-subscribers',
		'auto_newsletter_subscribers_page'
	);
	// Email Template
	add_submenu_page(
		'auto-newsletter-mailer',
		__( 'Email Template', 'auto-newsletter' ),
		__( 'Template', 'auto-newsletter' ),
		$mailer_cap,
		'auto-newsletter-mailer-template',
		'auto_newsletter_mailer_template_page'
	);
	// Import / Advanced - only for admins
	add_submenu_page(
		'auto-newsletter-mailer',
		__( 'Advanced Settings', 'auto-newsletter' ),
		__( 'Advanced', 'auto-newsletter' ),
		'manage_options',
		'auto-newsletter-import',
		'auto_newsletter_import_page'
	);
}

// ---- Historie odesílání ----
function auto_newsletter_mailer_history_page() {
	global $wpdb;
	$log_table  = $wpdb->prefix . 'auto_newsletter_mail_log';
	$subs_table = $wpdb->prefix . 'auto_newsletter_subscribers';

	$sub_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $subs_table WHERE confirmed=1" );

	$rows = $wpdb->get_results(
		"SELECT post_id, COUNT(*) AS sent_count, MIN(sent_at) AS first_sent, MAX(sent_at) AS last_sent
		 FROM $log_table
		 GROUP BY post_id
		 ORDER BY last_sent DESC
		 LIMIT 100"
	);
	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'Send History', 'auto-newsletter' ); ?></h1>
		<?php if ( empty( $rows ) ) : ?>
			<p><?php echo esc_html__( 'No emails have been sent yet.', 'auto-newsletter' ); ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Post', 'auto-newsletter' ); ?></th>
						<th><?php echo esc_html__( 'Type', 'auto-newsletter' ); ?></th>
						<th><?php echo esc_html__( 'Sent', 'auto-newsletter' ); ?></th>
						<th><?php echo esc_html__( 'First sent', 'auto-newsletter' ); ?></th>
						<th><?php echo esc_html__( 'Last sent', 'auto-newsletter' ); ?></th>
						<th><?php echo esc_html__( 'Status', 'auto-newsletter' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) :
						$post = get_post( $row->post_id );
						if ( ! $post ) continue;
						$mail_sent = get_post_meta( $post->ID, 'auto_newsletter_mail_sent', true );
						$status = ( $mail_sent === '1' )
							? '✅ ' . esc_html__( 'Completed', 'auto-newsletter' )
							: '⏳ ' . sprintf( esc_html__( 'In progress (%d/%d)', 'auto-newsletter' ), (int) $row->sent_count, $sub_count );
						?>
						<tr>
							<td><a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>"><?php echo esc_html( get_the_title( $post ) ); ?></a></td>
							<td><?php echo esc_html( get_post_type_object( get_post_type( $post ) )->labels->singular_name ?? get_post_type( $post ) ); ?></td>
							<td><?php echo (int) $row->sent_count; ?></td>
							<td><?php echo esc_html( mysql2date( 'j. n. Y H:i', $row->first_sent ) ); ?></td>
							<td><?php echo esc_html( mysql2date( 'j. n. Y H:i', $row->last_sent ) ); ?></td>
							<td><?php echo $status; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php echo esc_html__( 'Showing last 100 sent posts.', 'auto-newsletter' ); ?></p>
		<?php endif; ?>
	</div>
	<?php
}

// ---- Možnost zapnutí/vypnutí maileru ----
add_action( 'admin_init', 'auto_newsletter_mailer_settings' );
function auto_newsletter_mailer_settings() {
	register_setting( 'auto_newsletter_mailer', 'auto_newsletter_mailer_enabled' );
	register_setting( 'auto_newsletter_mailer', 'auto_newsletter_notify_on_register' );
	register_setting( 'auto_newsletter_mailer', 'auto_newsletter_notify_on_confirm' );
	register_setting( 'auto_newsletter_mailer', 'auto_newsletter_notify_emails', 'auto_newsletter_sanitize_notify_emails' );
}

/**
 * Sanitizace a validace pole pro e-maily správce.
 * Nevalidní e-maily zahodí a zobrazí chybovou hlášku.
 */
function auto_newsletter_sanitize_notify_emails( $input ) {
	if ( empty( $input ) ) {
		return '';
	}
	$raw = array_map( 'trim', explode( ',', $input ) );
	$valid = array();
	$invalid = array();
	foreach ( $raw as $email ) {
		if ( empty( $email ) ) {
			continue;
		}
		if ( is_email( $email ) ) {
			$valid[] = $email;
		} else {
			$invalid[] = $email;
		}
	}
	if ( ! empty( $invalid ) ) {
		add_settings_error(
			'auto_newsletter_mailer',
			'invalid_email',
			sprintf(
				'Následující e-maily nejsou platné a byly ignorovány: %s',
				implode( ', ', $invalid )
			),
			'error'
		);
		return $input; // ← vrátíme původní hodnotu, ať pole neztratí zadaný text
	}
	return implode( ', ', $valid );
}

// Turnstile klíče – registrovány zvlášť, zobrazeny jen na Import stránce (admin)
// Vlastní option group, aby se při submiti Settings nepřepisovaly a naopak.
add_action( 'admin_init', 'auto_newsletter_turnstile_settings' );
function auto_newsletter_turnstile_settings() {
	register_setting( 'auto_newsletter_import', 'auto_newsletter_turnstile_site_key' );
	register_setting( 'auto_newsletter_import', 'auto_newsletter_turnstile_secret_key' );
}
function auto_newsletter_turnstile_site_key_field() {
	$val = get_option( 'auto_newsletter_turnstile_site_key', '' );
	?>
	<input type="text" name="auto_newsletter_turnstile_site_key"
		value="<?php echo esc_attr( $val ); ?>" class="regular-text"
		placeholder="0x4AAAAAAA...">
	<p class="description">Site Key z Cloudflare Turnstile (https://dash.cloudflare.com → Turnstile).</p>
	<?php
}

function auto_newsletter_turnstile_secret_key_field() {
	$val = get_option( 'auto_newsletter_turnstile_secret_key', '' );
	?>
	<input type="text" name="auto_newsletter_turnstile_secret_key"
		value="<?php echo esc_attr( $val ); ?>" class="regular-text"
		placeholder="0x4AAAAAAA...">
	<p class="description">Secret Key z Cloudflare Turnstile.</p>
	<?php
}

// ---- Stránka s přehledem odběratelů ----
function auto_newsletter_subscribers_page() {
	global $wpdb;
	$table = $wpdb->prefix . 'auto_newsletter_subscribers';

	// Jednotlivé smazání odběratele (GET)
	if ( isset( $_GET['auto_newsletter_delete_sub'] ) && isset( $_GET['_wpnonce'] ) ) {
		$id = intval( $_GET['auto_newsletter_delete_sub'] );
		if ( wp_verify_nonce( $_GET['_wpnonce'], 'auto_newsletter_delete_sub_' . $id ) ) {
			$wpdb->delete( $table, array( 'id' => $id ) );
			echo '<div class="notice notice-success"><p>Odběratel smazán.</p></div>';
		}
	}

	// Hromadné akce
	$filter = isset( $_POST['auto_newsletter_filter'] ) ? $_POST['auto_newsletter_filter'] : ( isset( $_GET['auto_newsletter_filter'] ) ? $_GET['auto_newsletter_filter'] : '' );
	
	if ( isset( $_POST['auto_newsletter_sub_action'] ) && check_admin_referer( 'auto_newsletter_subscribers' ) ) {
		if ( $_POST['auto_newsletter_sub_action'] === 'delete' && ! empty( $_POST['sub_ids'] ) ) {
			$ids = array_map( 'intval', (array) $_POST['sub_ids'] );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE id IN ($placeholders)", $ids ) );
			echo '<div class="notice notice-success"><p>Smazáno ' . count( $ids ) . ' odběratelů.</p></div>';
		}
		if ( $_POST['auto_newsletter_sub_action'] === 'confirm' && ! empty( $_POST['sub_ids'] ) ) {
			$ids = array_map( 'intval', (array) $_POST['sub_ids'] );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$wpdb->query( $wpdb->prepare( "UPDATE $table SET confirmed=1 WHERE id IN ($placeholders)", $ids ) );
			echo '<div class="notice notice-success"><p>Potvrzeno ' . count( $ids ) . ' odběratelů.</p></div>';
		}
	}
	// Statistiky
	$total = $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
	$confirmed_count = $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE confirmed=1" );
	$unconfirmed_count = $total - $confirmed_count;

	// Filtr podle stavu
	$filter = isset( $_GET['auto_newsletter_filter'] ) ? $_GET['auto_newsletter_filter'] : '';
	$where = '';
	if ( $filter === 'confirmed' ) {
		$where = 'WHERE confirmed=1';
	} elseif ( $filter === 'unconfirmed' ) {
		$where = 'WHERE confirmed=0';
	}

	$subs = $wpdb->get_results( "SELECT * FROM $table $where ORDER BY created_at DESC" );
	?>

	<div class="wrap">
		<h1><?php echo esc_html__( 'Subscribers', 'auto-newsletter' ); ?></h1>

		<!-- Stats -->
		<div style="display:flex;gap:20px;margin:20px 0;flex-wrap:wrap">
			<div class="card" style="min-width:150px;padding:15px 20px">
				<div style="font-size:24px;font-weight:bold;color:#1d2327"><?php echo $total; ?></div>
				<div class="description" style="font-size:13px"><?php echo esc_html__( 'Total', 'auto-newsletter' ); ?></div>
			</div>
			<div class="card" style="min-width:150px;padding:15px 20px">
				<div style="font-size:24px;font-weight:bold;color:#00a32a"><?php echo $confirmed_count; ?></div>
				<div class="description" style="font-size:13px"><?php echo esc_html__( 'Confirmed', 'auto-newsletter' ); ?></div>
			</div>
			<div class="card" style="min-width:150px;padding:15px 20px">
				<div style="font-size:24px;font-weight:bold;color:#d63638"><?php echo $unconfirmed_count; ?></div>
				<div class="description" style="font-size:13px"><?php echo esc_html__( 'Unconfirmed', 'auto-newsletter' ); ?></div>
			</div>
		</div>

		<!-- Filter (WP subsubsub style) -->
		<ul class="subsubsub" style="margin:15px 0 10px">
			<li><a href="<?php echo esc_url( menu_page_url( 'auto-newsletter-subscribers', false ) ); ?>" <?php if ( ! $filter ) echo 'class="current"'; ?>><?php echo esc_html__( 'All', 'auto-newsletter' ); ?> <span class="count">(<?php echo $total; ?>)</span></a> |</li>
			<li><a href="<?php echo esc_url( add_query_arg( 'auto_newsletter_filter', 'confirmed', menu_page_url( 'auto-newsletter-subscribers', false ) ) ); ?>" <?php if ( $filter === 'confirmed' ) echo 'class="current"'; ?>><?php echo esc_html__( 'Confirmed', 'auto-newsletter' ); ?> <span class="count">(<?php echo $confirmed_count; ?>)</span></a> |</li>
			<li><a href="<?php echo esc_url( add_query_arg( 'auto_newsletter_filter', 'unconfirmed', menu_page_url( 'auto-newsletter-subscribers', false ) ) ); ?>" <?php if ( $filter === 'unconfirmed' ) echo 'class="current"'; ?>><?php echo esc_html__( 'Unconfirmed', 'auto-newsletter' ); ?> <span class="count">(<?php echo $unconfirmed_count; ?>)</span></a></li>
		</ul>
		<div class="clear"></div>

		<form method="post">
			<?php wp_nonce_field( 'auto_newsletter_subscribers' ); ?>
			<?php if ( $filter ) : ?>
				<input type="hidden" name="auto_newsletter_filter" value="<?php echo esc_attr( $filter ); ?>">
			<?php endif; ?>
			<div class="tablenav top">
				<div class="alignleft actions">
					<select name="auto_newsletter_sub_action">
						<option value=""><?php echo esc_html__( 'Bulk Actions', 'auto-newsletter' ); ?></option>
						<option value="confirm"><?php echo esc_html__( 'Confirm selected', 'auto-newsletter' ); ?></option>
						<option value="delete"><?php echo esc_html__( 'Delete selected', 'auto-newsletter' ); ?></option>
					</select>
					<button type="submit" class="button"><?php echo esc_html__( 'Apply', 'auto-newsletter' ); ?></button>
				</div>
				<br class="clear">
			</div>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<td style="width:30px"><input type="checkbox" id="auto-newsletter-select-all"></td>
						<th><?php echo esc_html__( 'Email', 'auto-newsletter' ); ?></th>
						<th><?php echo esc_html__( 'Status', 'auto-newsletter' ); ?></th>
						<th><?php echo esc_html__( 'Registered', 'auto-newsletter' ); ?></th>
						<th><?php echo esc_html__( 'Actions', 'auto-newsletter' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( $subs ) : ?>
					<?php foreach ( $subs as $s ) : ?>
						<?php $delete_url = wp_nonce_url(
							add_query_arg( 'auto_newsletter_delete_sub', $s->id, menu_page_url( 'auto-newsletter-subscribers', false ) ),
							'auto_newsletter_delete_sub_' . $s->id
						); ?>
						<tr>
							<td><input type="checkbox" name="sub_ids[]" value="<?php echo esc_attr( $s->id ); ?>"></td>
							<td><?php echo esc_html( $s->email ); ?></td>
							<td>
								<?php if ( $s->confirmed ) : ?>
									<span style="color:green"><?php echo esc_html__( 'Confirmed', 'auto-newsletter' ); ?></span>
								<?php else : ?>
									<span style="color:#d63638"><?php echo esc_html__( 'Unconfirmed', 'auto-newsletter' ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $s->created_at ? date_i18n( 'j. n. Y H:i', strtotime( $s->created_at ) ) : '—' ); ?></td>
							<td><a href="<?php echo esc_url( $delete_url ); ?>" style="color:#d63638" onclick="return confirm('<?php echo esc_js( __( 'Delete this subscriber?', 'auto-newsletter' ) ); ?>')"><?php echo esc_html__( 'Delete', 'auto-newsletter' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr><td colspan="5"><?php echo esc_html__( 'No subscribers found.', 'auto-newsletter' ); ?></td></tr>
				<?php endif; ?>
				</tbody>
			</table>
			<p style="margin-top:10px">
				<select name="auto_newsletter_sub_action">
										<option value=""><?php echo esc_html__( 'Bulk Actions', 'auto-newsletter' ); ?></option>
										<option value="confirm"><?php echo esc_html__( 'Confirm selected', 'auto-newsletter' ); ?></option>
										<option value="delete"><?php echo esc_html__( 'Delete selected', 'auto-newsletter' ); ?></option>
									</select>
									<button type="submit" class="button"><?php echo esc_html__( 'Apply', 'auto-newsletter' ); ?></button>
			</p>
		</form>
		<script>
		document.getElementById('auto-newsletter-select-all')?.addEventListener('change', function() {
			document.querySelectorAll('input[name="sub_ids[]"]').forEach(cb => cb.checked = this.checked);
		});
		</script>
	</div>
	<?php
}

// ---- Stránka nastavení maileru ----
function auto_newsletter_mailer_page() {
	global $wpdb;
	$master_enabled = get_option( 'auto_newsletter_mailer_enabled', '1' );
	$notify_register = get_option( 'auto_newsletter_notify_on_register', '' );
	$notify_confirm = get_option( 'auto_newsletter_notify_on_confirm', '1' );
	$notify_emails = get_option( 'auto_newsletter_notify_emails', '' );
	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'Newsletter Settings', 'auto-newsletter' ); ?></h1>
		<?php settings_errors( 'auto_newsletter_mailer' ); ?>

		<form method="post" action="options.php">
			<?php settings_fields( 'auto_newsletter_mailer' ); ?>

			<!-- ⚡ MAIN SWITCH -->
			<div style="background:#f0f6fc;border:1px solid #c5d9ed;border-left:4px solid #108615;padding:18px 22px;margin:20px 0;border-radius:4px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
				<div style="font-size:22px;line-height:1">⚡</div>
				<div style="flex:1;min-width:200px">
					<label style="font-weight:600;font-size:14px;cursor:pointer">
						<input type="checkbox" name="auto_newsletter_mailer_enabled" value="1" <?php checked( $master_enabled, '1' ); ?>>
						<?php echo esc_html__( 'Enable sending notification emails to subscribers', 'auto-newsletter' ); ?>
					</label>
					<p style="margin:4px 0 0;color:#646970;font-size:13px">
						<?php echo esc_html__( 'Main switch for the entire system. If disabled, cron will not send anything and new posts will not be queued. Useful for debugging or temporary pause.', 'auto-newsletter' ); ?>
					</p>
				</div>
			</div>

			<!-- 📬 NEW SUBSCRIBER NOTIFICATIONS -->
			<h2 style="margin-top:28px"><?php echo esc_html__( 'New Subscriber Notifications', 'auto-newsletter' ); ?></h2>
			<p style="color:#646970;font-size:13px;margin-bottom:12px">
				<?php echo esc_html__( 'Sends notification to specified emails after registration or confirmation of a new subscriber.', 'auto-newsletter' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php echo esc_html__( 'Registration (unconfirmed)', 'auto-newsletter' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="auto_newsletter_notify_on_register" value="1" <?php checked( $notify_register, '1' ); ?>>
							<?php echo esc_html__( 'Send notification on new subscriber registration', 'auto-newsletter' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Subscriber Confirmation', 'auto-newsletter' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="auto_newsletter_notify_on_confirm" value="1" <?php checked( $notify_confirm, '1' ); ?>>
							<?php echo esc_html__( 'Send notification on subscriber confirmation', 'auto-newsletter' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Notify emails', 'auto-newsletter' ); ?></th>
					<td>
						<input type="text" name="auto_newsletter_notify_emails"
							value="<?php echo esc_attr( $notify_emails ); ?>" class="regular-text" style="width:100%;max-width:400px"
							placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
						<p class="description"><?php echo esc_html__( 'One or more email addresses separated by commas (e.g. info@web.cz, contact@web.cz). If empty, administrator email will be used. Invalid emails will be discarded on save.', 'auto-newsletter' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( esc_html__( 'Save Changes', 'auto-newsletter' ) ); ?>
		</form>
	</div>
<?php
}

/**
 * Send Management - test email, manual batch, queue.
 */
function auto_newsletter_mailer_send_page() {
	global $wpdb;
	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'Send Management', 'auto-newsletter' ); ?></h1>

		<h2><?php echo esc_html__( 'Test Email', 'auto-newsletter' ); ?></h2>
		<p><?php echo esc_html__( 'Sends a test email to the specified address with sample content.', 'auto-newsletter' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( 'auto_newsletter_test_mail' ); ?>
			<input type="email" name="auto_newsletter_test_email" placeholder="your@email.com" required style="width:300px">
			<button type="submit" name="auto_newsletter_send_test" class="button"><?php echo esc_html__( 'Send Test', 'auto-newsletter' ); ?></button>
		</form>
		<?php
		if ( isset( $_POST['auto_newsletter_send_test'] ) && check_admin_referer( 'auto_newsletter_test_mail' ) ) {
			$email = sanitize_email( $_POST['auto_newsletter_test_email'] );
			if ( is_email( $email ) ) {
				$sent = wp_mail( $email, 'Test email - Auto Newsletter', '<h2>This is a test</h2><p>If you see this email, sending works.</p>', array( 'Content-Type: text/html; charset=UTF-8' ) );
				if ( $sent ) {
					echo '<div class="notice notice-success"><p>' . esc_html__( 'Test email sent to', 'auto-newsletter' ) . ' ' . esc_html( $email ) . '</p></div>';
				} else {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Sending failed. Check WP Mail SMTP settings.', 'auto-newsletter' ) . '</p></div>';
				}
			} else {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Invalid email address:', 'auto-newsletter' ) . ' ' . esc_html( $_POST['auto_newsletter_test_email'] ) . '</p></div>';
			}
		}
		if ( isset( $_POST['auto_newsletter_manual_send'] ) && check_admin_referer( 'auto_newsletter_manual_send' ) ) {
			auto_newsletter_send_batch();
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Manual send completed. Check your email.', 'auto-newsletter' ) . '</p></div>';
		}
		// Clear queue
		if ( isset( $_POST['auto_newsletter_clear_queue'] ) && check_admin_referer( 'auto_newsletter_clear_queue' ) ) {
			$cleared = auto_newsletter_mark_queue_as_done();
			echo '<div class="notice notice-success"><p>' . sprintf( esc_html__( 'Queue cleared. Records: %d', 'auto-newsletter' ), $cleared ) . '</p></div>';
		}
		// Cancel single post from queue
		if ( isset( $_POST['auto_newsletter_clear_single'] ) ) {
			$cancel_id = (int) $_POST['auto_newsletter_clear_single'];
			check_admin_referer( 'auto_newsletter_clear_single_' . $cancel_id );
			update_post_meta( $cancel_id, 'auto_newsletter_mail_sent', 1 );
			echo '<div class="notice notice-success"><p>Zbylé odeslání příspěvku „' . esc_html( get_the_title( $cancel_id ) ) . '“ bylo zrušeno.</p></div>';
		}
		?>
		<hr>
		<h2><?php echo esc_html__( 'Manual Batch Send', 'auto-newsletter' ); ?></h2>
		<p><?php echo esc_html__( 'Sends pending notifications right now (outside of cron).', 'auto-newsletter' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( 'auto_newsletter_manual_send' ); ?>
			<button type="submit" name="auto_newsletter_manual_send" class="button button-primary"><?php echo esc_html__( 'Send Pending Emails', 'auto-newsletter' ); ?></button>
		</form>
		<hr>
		<h2><?php echo esc_html__( 'Send Queue', 'auto-newsletter' ); ?></h2>
		<?php
		$queue_pending = get_posts( array(
			'post_type'      => array( 'post', 'page' ),
			'posts_per_page' => 20,
			'meta_query'     => array( array( 'key' => 'auto_newsletter_mail_sent', 'value' => '0' ) ),
			'orderby'        => 'date',
			'order'          => 'ASC',
		) );
		if ( $queue_pending ) :
			$sub_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}auto_newsletter_subscribers WHERE confirmed=1" );
			$log_table = $wpdb->prefix . 'auto_newsletter_mail_log';
			?>
			<p><?php echo sprintf( esc_html__( 'Pending posts (%d):', 'auto-newsletter' ), count( $queue_pending ) ); ?></p>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Post', 'auto-newsletter' ); ?></th>
						<th><?php echo esc_html__( 'Type', 'auto-newsletter' ); ?></th>
						<th><?php echo esc_html__( 'Date', 'auto-newsletter' ); ?></th>
						<th><?php echo esc_html__( 'Sent / Total', 'auto-newsletter' ); ?></th>
						<th><?php echo esc_html__( 'Actions', 'auto-newsletter' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $queue_pending as $qp ) :
						$already_sent = (int) $wpdb->get_var( $wpdb->prepare(
							"SELECT COUNT(*) FROM $log_table WHERE post_id = %d",
							$qp->ID
						) );
						?>
						<tr>
							<td><a href="<?php echo get_edit_post_link( $qp->ID ); ?>"><?php echo esc_html( get_the_title( $qp ) ); ?></a></td>
							<td><?php echo esc_html( get_post_type_object( get_post_type( $qp ) )->labels->singular_name ?? get_post_type( $qp ) ); ?></td>
							<td><?php echo get_the_date( 'j. n. Y H:i', $qp->ID ); ?></td>
							<td><?php echo $already_sent; ?> / <?php echo $sub_count; ?></td>
							<td>
								<form method="post" style="margin:0" onsubmit="return confirm('<?php echo esc_js( __( 'Cancel remaining sends for this post? Subscribers who haven\'t received the email yet will not get it.', 'auto-newsletter' ) ); ?>')">
									<?php wp_nonce_field( 'auto_newsletter_clear_single_' . $qp->ID ); ?>
									<input type="hidden" name="auto_newsletter_clear_single" value="<?php echo (int) $qp->ID; ?>">
									<button type="submit" class="button button-small" style="color:#d63638;border-color:#d63638"><?php echo esc_html__( 'Cancel', 'auto-newsletter' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<form method="post" style="margin-top:10px" onsubmit="return confirm('<?php echo esc_js( __( 'Really clear the queue? Subscribers will not receive emails about these posts.', 'auto-newsletter' ) ); ?>')">
				<?php wp_nonce_field( 'auto_newsletter_clear_queue' ); ?>
				<input type="hidden" name="auto_newsletter_clear_queue" value="1">
				<button type="submit" class="button" style="color:#d63638;border-color:#d63638"><?php echo esc_html__( 'Clear Queue', 'auto-newsletter' ); ?></button>
			</form>
		<?php
		else :
			?>
			<p><?php echo esc_html__( 'No pending posts. Queue is empty.', 'auto-newsletter' ); ?></p>
		<?php endif; ?>
	</div>
<?php
}

// ---- Respektovat vypnutí maileru v cronu ----
add_filter( 'auto_newsletter_mailer_should_send', function() {
	return get_option( 'auto_newsletter_mailer_enabled', '1' ) === '1';
} );

// ---- Email Template ----
add_action( 'admin_init', 'auto_newsletter_confirm_template_settings' );
function auto_newsletter_confirm_template_settings() {
	register_setting( 'auto_newsletter_mailer_template', 'auto_newsletter_confirm_subject' );
	register_setting( 'auto_newsletter_mailer_template', 'auto_newsletter_confirm_body' );
	register_setting( 'auto_newsletter_mailer_template', 'auto_newsletter_confirm_page_msg' );
	register_setting( 'auto_newsletter_mailer_template', 'auto_newsletter_unsub_page_msg' );
	register_setting( 'auto_newsletter_mailer_template', 'auto_newsletter_notify_subject' );
	register_setting( 'auto_newsletter_mailer_template', 'auto_newsletter_notify_body' );
}
function auto_newsletter_mailer_template_page() {
	$default_subject = 'Confirm your subscription';
	$default_body = "Hello,\\n\\n"
			. "you have just subscribed to news from our website.\\n\\n"
			. "Click this link to confirm:\\n{link}\\n\\n"
			. "If this wasn't you, ignore this email.\\n\\n"
			. "Thank you, team";
	$subject = auto_newsletter_mailer_get_option( 'auto_newsletter_confirm_subject', $default_subject );
	$body = auto_newsletter_mailer_get_option( 'auto_newsletter_confirm_body', $default_body );
	$confirm_msg = auto_newsletter_mailer_get_option( 'auto_newsletter_confirm_page_msg', 'Thank you, your email has been confirmed. We will keep you updated on new posts.' );
	$unsub_msg = auto_newsletter_mailer_get_option( 'auto_newsletter_unsub_page_msg', 'You have been unsubscribed from the newsletter.' );
	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'Email Template', 'auto-newsletter' ); ?></h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'auto_newsletter_mailer_template' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row">Předmět</th>
					<td>
						<input type="text" name="auto_newsletter_confirm_subject"
							value="<?php echo esc_attr( $subject ); ?>" class="regular-text"
							placeholder="Např. Confirm your subscription">
					</td>
				</tr>
				<tr>
					<th scope="row">Tělo emailu</th>
					<td>
						<textarea name="auto_newsletter_confirm_body" rows="12" class="large-text"
							placeholder="Dobrý den,

právě jste se přihlásili k odběru novinek z webu Web.

Pro potvrzení klikněte na tento odkaz:
{link}

Pokud jste to nebyli Vy, tento e-mail ignorujte.

"Thank you, the Web Team"
						><?php echo esc_textarea( $body ); ?></textarea>
						<p class="description">
							Use <code>{link}</code> for the confirmation link, <code>{email}</code> for the subscriber email.
							Každý řádek = nový odstavec.
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Confirmation message</th>
					<td>
						<textarea name="auto_newsletter_confirm_page_msg" rows="3" class="large-text"
							placeholder="Thank you, your email has been confirmed. We will keep you updated."
						><?php echo esc_textarea( $confirm_msg ); ?></textarea>
					</td>
				</tr>
				<tr>
					<tr>
						<th scope="row">Unsubscription message</th>
						<td>
							<textarea name="auto_newsletter_unsub_page_msg" rows="3" class="large-text"
								placeholder="Byli jste odhlášeni z odběru novinek."><?php echo esc_textarea( $unsub_msg ); ?></textarea>
						</td>
					</tr>
					</table>

					<h2 style="margin-top:30px"><?php echo esc_html__( 'Notification Email Template', 'auto-newsletter' ); ?></h2>
					<p><?php echo esc_html__( 'This email is sent to subscribers when a new post is published.', 'auto-newsletter' ); ?></p>
					<table class="form-table">
					<?php
					$notify_subject = auto_newsletter_mailer_get_option(
						'auto_newsletter_notify_subject',
						'🔔 New post: {title}'
					);
					$notify_body = auto_newsletter_mailer_get_option(
						'auto_newsletter_notify_body',
						"Dobrý den,\n\n"
						. "na webu Web byl právě zveřejněn nový příspěvek:\n\n"
						. "{title}\n\n{excerpt}\n\n"
						. "--\nTento e-mail jste dostali, protože jste přihlášeni k odběru novinek.\n"
						. "If you do not wish to receive further emails, {unsub_link}."
					);
					?>
					<tr>
						<th scope="row">Předmět</th>
						<td>
							<input type="text" name="auto_newsletter_notify_subject"
								value="<?php echo esc_attr( $notify_subject ); ?>" class="regular-text"
								placeholder="🔔 New post: {title}">
						</td>
					</tr>
					<tr>
						<th scope="row">Tělo emailu</th>
						<td>
							<textarea name="auto_newsletter_notify_body" rows="12" class="large-text"
								placeholder="Dobrý den,

					na webu Web byl právě zveřejněn nový příspěvek:

					{title}

					{excerpt}

					--
					Tento e-mail jste dostali, protože jste přihlášeni k odběru novinek.
					If you do not wish to receive further emails, {unsub_link}."><?php echo esc_textarea( $notify_body ); ?></textarea>
							<p class="description">
								Dostupné zástupné kódy: <code>{title}</code> (název příspěvku),
								<code>{excerpt}</code> (úryvek), <code>{url}</code> (odkaz na příspěvek),
								<code>{unsub_link}</code> (odkaz na odhlášení). Každý řádek = nový odstavec.
							</p>
						</td>
					</tr>
					</table>
										</p>
									</td>
								</tr>
							</table>
										</p>
									</td>
								</tr>
							</table>
							</table>
			<?php submit_button( 'Uložit vše' ); ?>
		</form>
	</div>
	<?php
}

// ---- Stránka pro import CSV ----
function auto_newsletter_import_page() {
	global $wpdb;
	$table = $wpdb->prefix . 'auto_newsletter_subscribers';

	// Zpracování nahraného CSV
	if ( isset( $_POST['auto_newsletter_import_csv'] ) && check_admin_referer( 'auto_newsletter_import_csv' ) ) {
		if ( ! empty( $_FILES['auto_newsletter_csv_file']['tmp_name'] ) ) {
			$file = $_FILES['auto_newsletter_csv_file']['tmp_name'];
			$handle = fopen( $file, 'r' );
			if ( $handle ) {
				$row = 0;
				$imported = 0;
				$skipped = 0;
				$errors = array();

				// Přeskočit hlavičku
				$header = fgetcsv( $handle, 1000, ',', '"' );
				// Normalizovat názvy sloupců (odstranit BOM, mezery, uvozovky)
				$header = array_map( function( $col ) {
					// Odstranit UTF-8 BOM (U+FEFF)
					$col = str_replace( "\xEF\xBB\xBF", '', $col );
					// Odstranit uvozovky na začátku a konci
					$col = trim( $col, "\" 	\n\r\0\x0B" );
					$col = trim( $col );
					return $col;
				}, $header );
				// Najít index sloupců
				$email_idx = array_search( 'E-mail', $header );
				$global_idx = array_search( 'Globální stav', $header );
				$list_idx = array_search( 'Stav seznamu', $header );
				$time_idx = array_search( 'Čas předplatného', $header );

				if ( $email_idx === false ) {
					echo '<div class="notice notice-error"><p>CSV neobsahuje sloupec "E-mail".</p></div>';
				} else {
					while ( ( $data = fgetcsv( $handle, 1000, ',', '"' ) ) !== false ) {
						$row++;
						$email = sanitize_email( $data[ $email_idx ] );
						if ( ! is_email( $email ) ) {
							$errors[] = "Řádek $row: neplatný e-mail";
							$skipped++;
							continue;
						}
						// Kontrola duplicity
						$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE email=%s", $email ) );
						if ( $exists ) {
							$skipped++;
							continue;
						}
						// Určit confirmed podle Globální stav
						$confirmed = 0;
						$skip_unsubscribed = isset( $_POST['auto_newsletter_import_confirm'] ) && $_POST['auto_newsletter_import_confirm'] === '1';
				
						if ( $global_idx !== false && isset( $data[ $global_idx ] ) ) {
							if ( $data[ $global_idx ] === 'subscribed' ) {
								$confirmed = 1;
							} elseif ( $skip_unsubscribed ) {
								// Pokud je zaškrtnuto "pouze subscribed" a tento není subscribed, přeskočit
								$skipped++;
								continue;
							}
						} elseif ( $skip_unsubscribed ) {
							// Sloupec Globální stav neexistuje nebo je prázdný, a chceme jen subscribed → přeskočit
							$skipped++;
							continue;
						}
						// Datum registrace z CSV
						$created_at = null;
						if ( $time_idx !== false && isset( $data[ $time_idx ] ) && ! empty( $data[ $time_idx ] ) ) {
							// Formát: 2024-06-14 06:41:04
							$timestamp = strtotime( $data[ $time_idx ] );
							if ( $timestamp ) {
								$created_at = date( 'Y-m-d H:i:s', $timestamp );
							}
						}
						// Vygenerovat hash
						$hash = wp_hash( $email . time() . $row );
						$insert_data = array(
							'email' => $email,
							'hash' => $hash,
							'confirmed' => $confirmed,
						);
						if ( $created_at ) {
							$insert_data['created_at'] = $created_at;
						}
						$inserted = $wpdb->insert( $table, $insert_data );
						if ( $inserted ) {
							$imported++;
						} else {
							$errors[] = "Řádek $row: chyba při zápisu $email";
							$skipped++;
						}
					}
					fclose( $handle );
					echo '<div class="notice notice-success"><p>Import completed: <strong>' . $imported . '</strong> imported, <strong>' . $skipped . '</strong> skipped (duplicity/neplatné).</p></div>';
					if ( ! empty( $errors ) ) {
						echo '<div class="notice notice-warning"><p>Chyby:<br>' . implode( '<br>', $errors ) . '</p></div>';
					}
				}
			} else {
				echo '<div class="notice notice-error"><p>Nepodařilo se otevřít soubor.</p></div>';
			}
		} else {
			echo '<div class="notice notice-error"><p>Vyberte CSV soubor.</p></div>';
		}
	}

	// Formulář pro upload
	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'Advanced Settings', 'auto-newsletter' ); ?></h1>

		<!-- Turnstile settings (admins only) -->
		<h2>Cloudflare Turnstile</h2>
		<p><?php echo esc_html__( 'Protect the subscription form with Cloudflare Turnstile.', 'auto-newsletter' ); ?></p>
		<form method="post" action="options.php">
			<?php settings_fields( 'auto_newsletter_import' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="auto_newsletter_turnstile_site_key">Turnstile Site Key</label></th>
					<td>
						<input type="text" id="auto_newsletter_turnstile_site_key"
							name="auto_newsletter_turnstile_site_key"
							value="<?php echo esc_attr( get_option( 'auto_newsletter_turnstile_site_key', '' ) ); ?>"
							class="regular-text" placeholder="0x4AAAAAAA...">
						<p class="description">Site Key z Cloudflare Turnstile (<a href="https://dash.cloudflare.com" target="_blank">dash.cloudflare.com</a> → Turnstile). Prázdné = vypnuto.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="auto_newsletter_turnstile_secret_key">Turnstile Secret Key</label></th>
					<td>
						<input type="text" id="auto_newsletter_turnstile_secret_key"
							name="auto_newsletter_turnstile_secret_key"
							value="<?php echo esc_attr( get_option( 'auto_newsletter_turnstile_secret_key', '' ) ); ?>"
							class="regular-text" placeholder="0x4AAAAAAA...">
						<p class="description">Secret Key z Cloudflare Turnstile.</p>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Uložit Turnstile klíče' ); ?>
		</form>

		<hr>

		<h2>Import odběratelů z CSV</h2>
		<p>Nahrajte CSV soubor s emaily. Očekávané sloupce: <code>E-mail</code>, <code>Stav</code>.</p>
		<p>Duplicity (e-maily, které už v databázi jsou) budou přeskočeny.</p>
		<form method="post" enctype="multipart/form-data">
			<?php wp_nonce_field( 'auto_newsletter_import_csv' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row">CSV soubor</th>
					<td>
						<input type="file" name="auto_newsletter_csv_file" accept=".csv" required>
						<p class="description">Maximální velikost: <?php echo ini_get( 'upload_max_filesize' ); ?>B</p>
					</td>
				</tr>
			</table>
			<p>
				<label>
					<input type="checkbox" name="auto_newsletter_import_confirm" value="1" checked>
					Importovat pouze se stavem <code>subscribed</code> (Globální stav), ostatní přeskočit
				</label>
			</p>
			<?php submit_button( 'Importovat', 'primary', 'auto_newsletter_import_csv' ); ?>
		</form>
	</div>
	<?php
}
