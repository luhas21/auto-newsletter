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
		__( 'Send Management', 'auto-newsletter' ),
		$mailer_cap,
		'auto-newsletter-send',
		'auto_newsletter_mailer_send_page'
	);
	// Send History
	add_submenu_page(
		'auto-newsletter-mailer',
		__( 'Send History', 'auto-newsletter' ),
		__( 'Send History', 'auto-newsletter' ),
		$mailer_cap,
		'auto-newsletter-mailer-history',
		'auto_newsletter_mailer_history_page'
	);
	add_submenu_page(
		'auto-newsletter-mailer',
		__( 'Subscribers', 'auto-newsletter' ),
		__( 'Subscribers', 'auto-newsletter' ),
		$mailer_cap,
		'auto-newsletter-subscribers',
		'auto_newsletter_subscribers_page'
	);
	// Email Template
	add_submenu_page(
		'auto-newsletter-mailer',
		__( 'Email Template', 'auto-newsletter' ),
		__( 'Email Template', 'auto-newsletter' ),
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
		<h1>Historie odesílání</h1>
		<?php if ( empty( $rows ) ) : ?>
			<p>Zatím nebyl odeslán žádný email.</p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th>Příspěvek</th>
						<th>Typ</th>
						<th>Odesláno</th>
						<th>První odeslání</th>
						<th>Poslední odeslání</th>
						<th>Stav</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) :
						$post = get_post( $row->post_id );
						if ( ! $post ) continue;
						$mail_sent = get_post_meta( $post->ID, 'auto_newsletter_mail_sent', true );
						$status = ( $mail_sent === '1' )
							? '✅ Dokončeno'
							: '⏳ Probíhá (' . (int) $row->sent_count . '/' . $sub_count . ')';
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
			<p class="description">Zobrazeno posledních 100 rozeslaných příspěvků.</p>
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
// Vlastní option group, aby se při submiti Nastavení nepřepisovaly a naopak.
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
		<h1>Subscribers</h1>

		<!-- Statistiky -->
		<div style="display:flex;gap:20px;margin:20px 0;flex-wrap:wrap">
			<div class="card" style="min-width:150px;padding:15px 20px">
				<div style="font-size:24px;font-weight:bold;color:#1d2327"><?php echo $total; ?></div>
				<div class="description" style="font-size:13px">Celkem</div>
			</div>
			<div class="card" style="min-width:150px;padding:15px 20px">
				<div style="font-size:24px;font-weight:bold;color:#00a32a"><?php echo $confirmed_count; ?></div>
				<div class="description" style="font-size:13px">Potvrzení</div>
			</div>
			<div class="card" style="min-width:150px;padding:15px 20px">
				<div style="font-size:24px;font-weight:bold;color:#d63638"><?php echo $unconfirmed_count; ?></div>
				<div class="description" style="font-size:13px">Nepotvrzení</div>
			</div>
		</div>

		<!-- Filtr (WP subsubsub styl) -->
		<ul class="subsubsub" style="margin:15px 0 10px">
			<li><a href="<?php echo esc_url( menu_page_url( 'auto-newsletter-subscribers', false ) ); ?>" <?php if ( ! $filter ) echo 'class="current"'; ?>>Všichni <span class="count">(<?php echo $total; ?>)</span></a> |</li>
			<li><a href="<?php echo esc_url( add_query_arg( 'auto_newsletter_filter', 'confirmed', menu_page_url( 'auto-newsletter-subscribers', false ) ) ); ?>" <?php if ( $filter === 'confirmed' ) echo 'class="current"'; ?>>Potvrzení <span class="count">(<?php echo $confirmed_count; ?>)</span></a> |</li>
			<li><a href="<?php echo esc_url( add_query_arg( 'auto_newsletter_filter', 'unconfirmed', menu_page_url( 'auto-newsletter-subscribers', false ) ) ); ?>" <?php if ( $filter === 'unconfirmed' ) echo 'class="current"'; ?>>Nepotvrzení <span class="count">(<?php echo $unconfirmed_count; ?>)</span></a></li>
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
						<option value="">Hromadná akce</option>
						<option value="confirm">Potvrdit vybrané</option>
						<option value="delete">Smazat vybrané</option>
					</select>
					<button type="submit" class="button">Použít</button>
				</div>
				<br class="clear">
			</div>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<td style="width:30px"><input type="checkbox" id="auto-newsletter-select-all"></td>
						<th>Email</th>
						<th>Stav</th>
						<th>Registrován</th>
						<th>Akce</th>
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
									<span style="color:green">Potvrzený</span>
								<?php else : ?>
									<span style="color:#d63638">Nepotvrzený</span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $s->created_at ? date_i18n( 'j. n. Y H:i', strtotime( $s->created_at ) ) : '—' ); ?></td>
							<td><a href="<?php echo esc_url( $delete_url ); ?>" style="color:#d63638" onclick="return confirm('Smazat tohoto odběratele?')">Smazat</a></td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr><td colspan="5">Žádní odběratelé.</td></tr>
				<?php endif; ?>
				</tbody>
			</table>
			<p style="margin-top:10px">
				<select name="auto_newsletter_sub_action">
					<option value="">Hromadná akce</option>
					<option value="confirm">Potvrdit vybrané</option>
					<option value="delete">Smazat vybrané</option>
				</select>
				<button type="submit" class="button">Použít</button>
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
 * Stránka Odesílání – testovací email, ruční dávka, fronta.
 */
function auto_newsletter_mailer_send_page() {
	global $wpdb;
	?>
	<div class="wrap">
		<h1>Správa odesílání</h1>

		<h2>Testovací odeslání</h2>
		<p>Odešle zkušební email na zadanou adresu s ukázkovým obsahem.</p>
		<form method="post">
			<?php wp_nonce_field( 'auto_newsletter_test_mail' ); ?>
			<input type="email" name="auto_newsletter_test_email" placeholder="vas@email.cz" required style="width:300px">
			<button type="submit" name="auto_newsletter_send_test" class="button">Poslat test</button>
		</form>
		<?php
		if ( isset( $_POST['auto_newsletter_send_test'] ) && check_admin_referer( 'auto_newsletter_test_mail' ) ) {
			$email = sanitize_email( $_POST['auto_newsletter_test_email'] );
			if ( is_email( $email ) ) {
				$sent = wp_mail( $email, 'Testovací email – Auto Newsletter', '<h2>Toto je test</h2><p>Pokud vidíte tento email, odesílání funguje.</p>', array( 'Content-Type: text/html; charset=UTF-8' ) );
				if ( $sent ) {
					echo '<div class="notice notice-success"><p>Testovací email odeslán na ' . esc_html( $email ) . '</p></div>';
				} else {
					echo '<div class="notice notice-error"><p>Odeslání selhalo. Zkontroluj WP Mail SMTP nastavení.</p></div>';
				}
			} else {
				echo '<div class="notice notice-error"><p>Zadaná e-mailová adresa není platná: ' . esc_html( $_POST['auto_newsletter_test_email'] ) . '</p></div>';
			}
		}
		if ( isset( $_POST['auto_newsletter_manual_send'] ) && check_admin_referer( 'auto_newsletter_manual_send' ) ) {
			auto_newsletter_send_batch();
			echo '<div class="notice notice-success"><p>Ruční odeslání dokončeno. Zkontroluj e-mail.</p></div>';
		}
		// Smazání fronty
		if ( isset( $_POST['auto_newsletter_clear_queue'] ) && check_admin_referer( 'auto_newsletter_clear_queue' ) ) {
			$cleared = auto_newsletter_mark_queue_as_done();
			echo '<div class="notice notice-success"><p>Fronta smazána. Počet záznamů: ' . $cleared . '</p></div>';
		}
		// Zrušení odeslání jednoho příspěvku z fronty
		if ( isset( $_POST['auto_newsletter_clear_single'] ) ) {
			$cancel_id = (int) $_POST['auto_newsletter_clear_single'];
			check_admin_referer( 'auto_newsletter_clear_single_' . $cancel_id );
			update_post_meta( $cancel_id, 'auto_newsletter_mail_sent', 1 );
			echo '<div class="notice notice-success"><p>Zbylé odeslání příspěvku „' . esc_html( get_the_title( $cancel_id ) ) . '“ bylo zrušeno.</p></div>';
		}
		?>
		<hr>
		<h2>Ruční odeslání dávky</h2>
		<p>Spustí rozeslání nevyřízených notifikací hned teď (mimo cron).</p>
		<form method="post">
			<?php wp_nonce_field( 'auto_newsletter_manual_send' ); ?>
			<button type="submit" name="auto_newsletter_manual_send" class="button button-primary">Odeslat nevyřízené emaily</button>
		</form>
		<hr>
		<h2>Fronta odesílání</h2>
		<?php
		$queue_pending = get_posts( array(
			'post_type'      => array( 'clanek', 'dokument', 'akce', 'oznameni', 'dokument_ke_stazeni' ),
			'posts_per_page' => 20,
			'meta_query'     => array( array( 'key' => 'auto_newsletter_mail_sent', 'value' => '0' ) ),
			'orderby'        => 'date',
			'order'          => 'ASC',
		) );
		if ( $queue_pending ) :
			$sub_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}auto_newsletter_subscribers WHERE confirmed=1" );
			$log_table = $wpdb->prefix . 'auto_newsletter_mail_log';
			?>
			<p>Čekající příspěvky (<?php echo count( $queue_pending ); ?>):</p>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th>Příspěvek</th>
						<th>Typ</th>
						<th>Datum</th>
						<th>Odesláno / celkem</th>
						<th>Akce</th>
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
								<form method="post" style="margin:0" onsubmit="return confirm('Zrušit zbylé rozesílání tohoto příspěvku? Odběratelé, kteří email ještě nedostali, ho už nedostanou.')">
									<?php wp_nonce_field( 'auto_newsletter_clear_single_' . $qp->ID ); ?>
									<input type="hidden" name="auto_newsletter_clear_single" value="<?php echo (int) $qp->ID; ?>">
									<button type="submit" class="button button-small" style="color:#d63638;border-color:#d63638">Zrušit</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<form method="post" style="margin-top:10px" onsubmit="return confirm('Opravdu smazat frontu? Odběratelé nedostanou emaily o těchto příspěvcích.')">
				<?php wp_nonce_field( 'auto_newsletter_clear_queue' ); ?>
				<input type="hidden" name="auto_newsletter_clear_queue" value="1">
				<button type="submit" class="button" style="color:#d63638;border-color:#d63638">Smazat frontu</button>
			</form>
		<?php
		else :
			?>
			<p>Žádné čekající příspěvky. Fronta je prázdná.</p>
		<?php endif; ?>
	</div>
<?php
}

// ---- Respektovat vypnutí maileru v cronu ----
add_filter( 'auto_newsletter_mailer_should_send', function() {
	return get_option( 'auto_newsletter_mailer_enabled', '1' ) === '1';
} );

// ---- Šablona potvrzovacího emailu ----
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
	$default_subject = 'Potvrďte odběr novinek z Webu';
	$default_body = "Dobrý den,\n\n"
		. "právě jste se přihlásili k odběru novinek z webu Web.\n\n"
		. "Pro potvrzení klikněte na tento odkaz:\n{link}\n\n"
		. "Pokud jste to nebyli Vy, tento e-mail ignorujte.\n\n"
		. "Děkujeme, tým Web";
	$subject = auto_newsletter_mailer_get_option( 'auto_newsletter_confirm_subject', $default_subject );
	$body = auto_newsletter_mailer_get_option( 'auto_newsletter_confirm_body', $default_body );
	$confirm_msg = auto_newsletter_mailer_get_option( 'auto_newsletter_confirm_page_msg', 'Děkujeme, Váš e-mail byl potvrzen. Budeme Vás informovat o novinkách.' );
	$unsub_msg = auto_newsletter_mailer_get_option( 'auto_newsletter_unsub_page_msg', 'Byli jste odhlášeni z odběru novinek.' );
	?>
	<div class="wrap">
		<h1>Šablona potvrzovacího emailu</h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'auto_newsletter_mailer_template' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row">Předmět</th>
					<td>
						<input type="text" name="auto_newsletter_confirm_subject"
							value="<?php echo esc_attr( $subject ); ?>" class="regular-text"
							placeholder="Např. Potvrďte odběr novinek z Webu">
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

Děkujeme, tým Web"
						><?php echo esc_textarea( $body ); ?></textarea>
						<p class="description">
							Použijte <code>{link}</code> pro potvrzovací odkaz, <code>{email}</code> pro email odběratele.
							Každý řádek = nový odstavec.
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Hláška po potvrzení</th>
					<td>
						<textarea name="auto_newsletter_confirm_page_msg" rows="3" class="large-text"
							placeholder="Děkujeme, Váš e-mail byl potvrzen. Budeme Vás informovat o novinkách."
						><?php echo esc_textarea( $confirm_msg ); ?></textarea>
					</td>
				</tr>
				<tr>
					<tr>
						<th scope="row">Hláška po odhlášení</th>
						<td>
							<textarea name="auto_newsletter_unsub_page_msg" rows="3" class="large-text"
								placeholder="Byli jste odhlášeni z odběru novinek."><?php echo esc_textarea( $unsub_msg ); ?></textarea>
						</td>
					</tr>
					</table>

					<h2 style="margin-top:30px">Šablona notifikačního emailu</h2>
					<p>Tento email se odesílá odběratelům při zveřejnění nového příspěvku.</p>
					<table class="form-table">
					<?php
					$notify_subject = auto_newsletter_mailer_get_option(
						'auto_newsletter_notify_subject',
						'🔔 Novinka na webu Web: {title}'
					);
					$notify_body = auto_newsletter_mailer_get_option(
						'auto_newsletter_notify_body',
						"Dobrý den,\n\n"
						. "na webu Web byl právě zveřejněn nový příspěvek:\n\n"
						. "{title}\n\n{excerpt}\n\n"
						. "--\nTento e-mail jste dostali, protože jste přihlášeni k odběru novinek.\n"
						. "Pokud si nepřejete dostávat další e-maily, {unsub_link}."
					);
					?>
					<tr>
						<th scope="row">Předmět</th>
						<td>
							<input type="text" name="auto_newsletter_notify_subject"
								value="<?php echo esc_attr( $notify_subject ); ?>" class="regular-text"
								placeholder="🔔 Novinka na webu Web: {title}">
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
					Pokud si nepřejete dostávat další e-maily, {unsub_link}."><?php echo esc_textarea( $notify_body ); ?></textarea>
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
					echo '<div class="notice notice-success"><p>Import dokončen: <strong>' . $imported . '</strong> importováno, <strong>' . $skipped . '</strong> přeskočeno (duplicity/neplatné).</p></div>';
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
		<h1>Pokročilé nastavení</h1>

		<!-- Turnstile nastavení (jen pro administrátory) -->
		<h2>Cloudflare Turnstile</h2>
		<p>Nastavení pro ochranu přihlašovacího formuláře odběratelů.</p>
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
