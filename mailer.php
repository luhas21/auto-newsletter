<?php
// Mailer modul: rozesílání nových příspěvků odběratelům.
// Odesílání přes wp_mail (tvůj WP Mail SMTP + mxrouting.net). Dávkováno cronem.

/**
 * Helper: načte option, ale pokud je prázdná (uloženo '' nebo nikdy neuloženo),
 * vrátí $default. Tím pádem default nikdy není prázdný — ani v admin formuláři,
 * ani v runtime.
 */
function auto_newsletter_mailer_get_option( $key, $default ) {
	$val = get_option( $key );
	return ( $val === false || $val === '' ) ? $default : $val;
}

/**
 * Shortcode [auto_newsletter_form] — formulář pro přihlášení k odběru.
 * Zpracování probíhá v init hooku (viz dole), aby se dalo přesměrovat.
 */
add_shortcode( 'auto_newsletter_form', 'auto_newsletter_subscribe_form' );
function auto_newsletter_subscribe_form( $atts = array() ) {
	$atts = shortcode_atts( array( 'gdpr_url' => '/gdpr', 'redirect' => '' ), $atts );
	if ( isset( $_GET['auto_newsletter_subscribed'] ) || isset( $_GET['auto_newsletter_error'] ) ) {
		return ''; // hláška se zobrazí přes wp_footer jako overlay
	}

	// Cloudflare Turnstile site key
	$site_key = get_option( 'auto_newsletter_turnstile_site_key', '' );
	$turnstile_html = '';
	if ( $site_key ) {
		wp_enqueue_script( 'cloudflare-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, true );
		$turnstile_html = '<p style="margin:0 0 8px 0">
			<div class="cf-turnstile" style="margin-left:-8px" data-sitekey="' . esc_attr( $site_key ) . '" data-theme="light"></div>
		</p>';
	}

	return '<form method="post" class="obec-subscribe-form">
		' . wp_nonce_field( 'auto_newsletter_subscribe', 'auto_newsletter_subscribe_nonce', true, false ) . '
		<input type="hidden" name="auto_newsletter_gdpr_url" value="' . esc_attr( $atts['gdpr_url'] ) . '">
		<input type="hidden" name="auto_newsletter_redirect" value="' . esc_attr( $atts['redirect'] ) . '">
		<p style="margin:0 0 8px">
			<input type="email" name="auto_newsletter_email" placeholder="vas@email.cz" required
				style="box-sizing:border-box;width:100%;padding:8px 10px;border:1px solid #bbb;border-radius:0;">
		</p>
		<p style="margin:0 0 10px;font-size:13px;line-height:1.4">
			<label>
				<input type="checkbox" name="auto_newsletter_consent" value="1" required>
				Souhlasím se zasíláním novinek na uvedený e-mail. <a href="' . esc_url( $atts['gdpr_url'] ) . '" style="color:#108615">Více o ochraně osobních údajů</a>.
			</label>
		</p>
		' . $turnstile_html . '
		<p style="margin:0">
			<input type="submit" name="auto_newsletter_subscribe" value="Odebírat novinky"
				style="background:#108615;color:#fff;border:none;padding:8px 20px;cursor:pointer;width:100%;font-size:15px;">
		</p>
	</form>';
}

/** Zpracování formuláře – brzy (init) kvůli redirectu. */
add_action( 'init', 'auto_newsletter_process_subscribe' );
function auto_newsletter_process_subscribe() {
	if ( ! isset( $_POST['auto_newsletter_subscribe'] ) || ! isset( $_POST['auto_newsletter_email'] ) ) return;

	// --- URL pro přesměrování zpět na stránku s formulářem
	$base_url = home_url( $_SERVER['REQUEST_URI'] );

	// --- Nonce (CSRF) – při neplatném jen přesměruj, nedie
	if ( ! isset( $_POST['auto_newsletter_subscribe_nonce'] ) || ! wp_verify_nonce( $_POST['auto_newsletter_subscribe_nonce'], 'auto_newsletter_subscribe' ) ) {
		$url = add_query_arg( 'auto_newsletter_error', 'expired', $base_url );
		wp_safe_redirect( $url );
		exit;
	}

	// --- Rate limiting (max 5 pokusů / 60 s na IP)
	$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
	$rate_key = 'auto_newsletter_rate_' . md5( $ip );
	$attempts = (int) get_transient( $rate_key );
	if ( $attempts >= 5 ) {
		$url = add_query_arg( 'auto_newsletter_error', 'rate_limit', $base_url );
		wp_safe_redirect( $url );
		exit;
	}
	set_transient( $rate_key, $attempts + 1, 60 );

	// Ověření Cloudflare Turnstile
	$secret_key = get_option( 'auto_newsletter_turnstile_secret_key', '' );
	if ( $secret_key ) {
		if ( isset( $_POST['cf-turnstile-response'] ) ) {
			$token = $_POST['cf-turnstile-response'];
			$response = wp_remote_post( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', array(
				'body' => array(
					'secret'   => $secret_key,
					'response' => $token,
					'remoteip' => $ip,
				),
			) );
			if ( is_wp_error( $response ) ) {
				$url = add_query_arg( 'auto_newsletter_error', 'captcha', $base_url );
				wp_safe_redirect( $url );
				exit;
			}
			$result = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( empty( $result['success'] ) ) {
				$url = add_query_arg( 'auto_newsletter_error', 'captcha', $base_url );
				wp_safe_redirect( $url );
				exit;
			}
		} else {
			// Secret key je nastaven, ale token nepřišel (bot)
			$url = add_query_arg( 'auto_newsletter_error', 'captcha', $base_url );
			wp_safe_redirect( $url );
			exit;
		}
	}

	$email = sanitize_email( $_POST['auto_newsletter_email'] );
	$redirect = ! empty( $_POST['auto_newsletter_redirect'] ) ? esc_url_raw( $_POST['auto_newsletter_redirect'] ) : '';
	if ( is_email( $email ) && ! empty( $_POST['auto_newsletter_consent'] ) ) {
		$result = auto_newsletter_add_subscriber( $email );
		if ( $result === 'exists_confirmed' ) {
			$url = add_query_arg( 'auto_newsletter_error', 'exists_confirmed', $base_url );
			wp_safe_redirect( $url );
			exit;
		}
		if ( $result === 'exists_unconfirmed' ) {
			$url = add_query_arg( array( 'auto_newsletter_error' => 'exists_unconfirmed', 'auto_newsletter_email' => urlencode( $email ) ), $base_url );
			wp_safe_redirect( $url );
			exit;
		}
		$url = $redirect ? $redirect : add_query_arg( 'auto_newsletter_subscribed', '1', $base_url );
		wp_safe_redirect( $url );
		exit;
	}
	// Chyba: přesměruj zpět s parametrem chyby
	$url = add_query_arg( 'auto_newsletter_error', 'invalid', $base_url );
	wp_safe_redirect( $url );
	exit;
}

/**
 * Odeslání upozornění správci webu o novém odběrateli (registrace nebo potvrzení).
 */
function auto_newsletter_send_admin_notification( $email, $type ) {
	$to = get_option( 'auto_newsletter_notify_emails', '' );
	if ( empty( $to ) ) {
		$to = get_option( 'admin_email' );
	}
	$emails = array_map( 'trim', explode( ',', $to ) );
	$emails = array_filter( $emails, 'is_email' );
	if ( empty( $emails ) ) {
		return;
	}

	if ( $type === 'register' ) {
		$subject = '🔔 Nová registrace k odběru (nepotvrzená)';
		$body = "Dobrý den,\n\n"
			. "Na webu se zaregistroval nový odběratel:\n\n"
			. "E-mail: $email\n"
			. "Datum a čas: " . wp_date( 'j. n. Y H:i' ) . "\n"
			. "Stav: NEPOTVRZENÝ (čeká na double opt-in)\n\n"
			. "Odběratel ještě nepotvrdil svůj e-mail – registraci bude možné dokončit kliknutím na potvrzovací odkaz.";
	} else {
		$subject = '✅ Nový potvrzený odběratel';
		$body = "Dobrý den,\n\n"
			. "Odběratel potvrdil svůj e-mail:\n\n"
			. "E-mail: $email\n"
			. "Datum a čas potvrzení: " . wp_date( 'j. n. Y H:i' ) . "\n"
			. "Stav: POTVRZENÝ (bude dostávat notifikace o nových příspěvcích)";
	}

	wp_mail( $emails, $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) );
}

function auto_newsletter_add_subscriber( $email ) {
	global $wpdb;
	$table = $wpdb->prefix . 'auto_newsletter_subscribers';
	$exists = $wpdb->get_row( $wpdb->prepare( "SELECT id, confirmed FROM $table WHERE email=%s", $email ) );
	if ( $exists ) {
		return $exists->confirmed ? 'exists_confirmed' : 'exists_unconfirmed';
	}

	$hash = wp_hash( $email . time() );
	$wpdb->insert( $table, array(
		'email'    => $email,
		'hash'     => $hash,
		'confirmed' => 0,
	) );

	// potvrzovací mail (šablona z administrace)
	$default_subject = 'Potvrďte odběr novinek';
	$default_body = "Dobrý den,\\n\\n"
		. "právě jste se přihlásili k odběru novinek z našeho webu.\\n\\n"
		. "Pro potvrzení klikněte na tento odkaz:\\n{link}\\n\\n"
		. "Pokud jste to nebyli Vy, tento e-mail ignorujte.\\n\\n"
		. "Děkujeme";
	$subject = auto_newsletter_mailer_get_option( 'auto_newsletter_confirm_subject', $default_subject );
	$body = auto_newsletter_mailer_get_option( 'auto_newsletter_confirm_body', $default_body );
	$link = add_query_arg( array( 'auto_newsletter_confirm' => $hash ), home_url() );
	$body = str_replace( '{link}', $link, $body );
	$body = str_replace( '{email}', $email, $body );
	// Vyrobit HTML – body je plain text, URL v něm jsou {link} nahrazené za URL
	$body_html = '<p>' . str_replace( "\n\n", '</p><p>', nl2br( esc_html( $body ) ) ) . '</p>';
	// Udělat z URL v textu klikací odkazy
	$body_html = make_clickable( $body_html );
	wp_mail( $email, $subject, $body_html, array( 'Content-Type: text/html; charset=UTF-8' ) );

	// Notifikace adminovi o nové registraci
	if ( get_option( 'auto_newsletter_notify_on_register', '' ) === '1' ) {
		auto_newsletter_send_admin_notification( $email, 'register' );
	}
	return 'added';
}

/**
 * Znovuodeslání potvrzovacího emailu (z tlačítka v hlášce).
 */
add_action( 'init', 'auto_newsletter_resend_confirm' );
function auto_newsletter_resend_confirm() {
	if ( ! isset( $_POST['auto_newsletter_resend'] ) || ! isset( $_POST['auto_newsletter_email'] ) ) return;

	// URL pro přesměrování zpět na stránku s formulářem
	$base_url = home_url( $_SERVER['REQUEST_URI'] );

	if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'auto_newsletter_resend' ) ) {
		$url = add_query_arg( 'auto_newsletter_error', 'expired', $base_url );
		wp_safe_redirect( $url );
		exit;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'auto_newsletter_subscribers';
	$email = sanitize_email( $_POST['auto_newsletter_email'] );
	$sub = $wpdb->get_row( $wpdb->prepare( "SELECT hash, confirmed FROM $table WHERE email=%s", $email ) );
	if ( ! $sub || $sub->confirmed ) {
		return;
	}

	// Odeslat potvrzovací mail znovu
	$default_subject = 'Potvrďte odběr novinek z našeho webu';
	$default_body = "Dobrý den,\n\n"
		. "právě jste se přihlásili k odběru novinek z webu našeho webu.\n\n"
		. "Pro potvrzení klikněte na tento odkaz:\n{link}\n\n"
		. "Pokud jste to nebyli Vy, tento e-mail ignorujte.\n\n"
		. "Děkujeme, tým našeho webu";
	$subject = auto_newsletter_mailer_get_option( 'auto_newsletter_confirm_subject', $default_subject );
	$body = auto_newsletter_mailer_get_option( 'auto_newsletter_confirm_body', $default_body );
	$link = add_query_arg( array( 'auto_newsletter_confirm' => $sub->hash ), home_url() );
	$body = str_replace( '{link}', $link, $body );
	$body = str_replace( '{email}', $email, $body );
	$body_html = '<p>' . str_replace( "\n\n", '</p><p>', nl2br( esc_html( $body ) ) ) . '</p>';
	$body_html = make_clickable( $body_html );
	wp_mail( $email, $subject, $body_html, array( 'Content-Type: text/html; charset=UTF-8' ) );

	$url = add_query_arg( 'auto_newsletter_ok', 'resent', $base_url );
	wp_safe_redirect( $url );
	exit;
}

/** Zpracování potvrzení a odhlášení – redirect s hláškou. */
add_action( 'init', 'auto_newsletter_handle_links' );
function auto_newsletter_handle_links() {
	global $wpdb;
	$table = $wpdb->prefix . 'auto_newsletter_subscribers';

	// Ověřit, že tabulka existuje (prvotní instalace / čerstvé DB)
	if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) !== $table ) {
		return;
	}

	if ( isset( $_GET['auto_newsletter_confirm'] ) ) {
		$hash = sanitize_text_field( $_GET['auto_newsletter_confirm'] );
		$wpdb->update( $table, array( 'confirmed' => 1 ), array( 'hash' => $hash ) );

		// Notifikace adminovi o potvrzeném odběrateli
		if ( get_option( 'auto_newsletter_notify_on_confirm', '1' ) === '1' ) {
			$sub = $wpdb->get_row( $wpdb->prepare( "SELECT email FROM $table WHERE hash=%s", $hash ) );
			if ( $sub ) {
				auto_newsletter_send_admin_notification( $sub->email, 'confirm' );
			}
		}

		wp_safe_redirect( home_url( '?auto_newsletter_ok=confirm' ) );
		exit;
	}
	if ( isset( $_GET['auto_newsletter_unsub'] ) ) {
		$hash = sanitize_text_field( $_GET['auto_newsletter_unsub'] );
		$wpdb->delete( $table, array( 'hash' => $hash ) );
		wp_safe_redirect( home_url( '?auto_newsletter_ok=unsub' ) );
		exit;
	}
}

/** Zobrazení hlášky po potvrzení / odhlášení / přihlášení – overlay (popup) přes celou stránku. */
add_action( 'wp_footer', 'auto_newsletter_show_ok_message' );
function auto_newsletter_show_ok_message() {
	$icon = '✅';
	$msg = '';
	$clean_url = '';
	$show_resend = false;

	if ( isset( $_GET['auto_newsletter_ok'] ) ) {
		if ( $_GET['auto_newsletter_ok'] === 'confirm' ) {
			$msg = auto_newsletter_mailer_get_option( 'auto_newsletter_confirm_page_msg', 'Děkujeme, Váš e-mail byl potvrzen. Budeme Vás informovat o novinkách.' );
		} elseif ( $_GET['auto_newsletter_ok'] === 'unsub' ) {
			$msg = auto_newsletter_mailer_get_option( 'auto_newsletter_unsub_page_msg', 'Byli jste odhlášeni z odběru novinek.' );
			$icon = '👋';
		} elseif ( $_GET['auto_newsletter_ok'] === 'resent' ) {
			$msg = 'Potvrzovací email byl znovu odeslán. Zkontrolujte svou e-mailovou schránku.';
			$icon = '📨';
		}
		$clean_url = remove_query_arg( 'auto_newsletter_ok' );
	} elseif ( isset( $_GET['auto_newsletter_subscribed'] ) ) {
		$msg = 'Děkujeme. Zkontrolujte e-mail a potvrďte odběr.';
		$clean_url = remove_query_arg( 'auto_newsletter_subscribed' );
	} elseif ( isset( $_GET['auto_newsletter_error'] ) ) {
		$icon = '⚠️';
		if ( $_GET['auto_newsletter_error'] === 'captcha' ) {
			$msg = 'Ověření CAPTCHA selhalo. Zkuste to prosím znovu.';
		} elseif ( $_GET['auto_newsletter_error'] === 'exists_confirmed' ) {
			$msg = 'Tento e-mail je již u nás zaregistrován.';
			$icon = 'ℹ️';
		} elseif ( $_GET['auto_newsletter_error'] === 'exists_unconfirmed' ) {
			$show_resend = true;
			$icon = 'ℹ️';
			$msg = 'Tento e-mail je již zaregistrován, ale dosud nebyl potvrzen. Pro dokončení odběru klikněte na odkaz v potvrzovacím emailu, nebo si jej nechte zaslat znovu.';
		} elseif ( $_GET['auto_newsletter_error'] === 'expired' ) {
			$msg = 'Platnost formuláře vypršela. Zkuste to prosím znovu.';
		} elseif ( $_GET['auto_newsletter_error'] === 'rate_limit' ) {
			$msg = 'Příliš mnoho pokusů. Zkuste to prosím za chvíli.';
		} else {
			$msg = 'Vyplňte e-mail a zaškrtněte souhlas.';
		}
		$clean_url = remove_query_arg( array( 'auto_newsletter_error', 'auto_newsletter_email' ) );
	}

	if ( ! $msg ) return;
	?>
	<div id="obec-modal-overlay" style="position:fixed;inset:0;z-index:999999;background:rgba(0,0,0,0.45);display:flex;align-items:center;justify-content:center;">
		<div style="background:#fff;max-width:460px;width:90%;padding:36px 32px 28px;box-shadow:0 8px 32px rgba(0,0,0,0.2);text-align:center;position:relative;border-radius:4px;">
			<button onclick="document.getElementById('obec-modal-overlay').remove()" style="position:absolute;top:8px;right:12px;background:none;border:none;font-size:22px;cursor:pointer;color:#888;line-height:1;">&times;</button>
			<p style="margin:0 0 6px;font-size:36px;"><?php echo $icon; ?></p>
			<p style="margin:0;font-size:16px;line-height:1.5;color:#333;"><?php echo esc_html( $msg ); ?></p>
<?php if ( ! empty( $show_resend ) && ! empty( $_GET['auto_newsletter_email'] ) ) : ?>
			<form method="post" style="margin:16px 0 0">
				<?php wp_nonce_field( 'auto_newsletter_resend' ); ?>
				<input type="hidden" name="auto_newsletter_resend" value="1">
				<input type="hidden" name="auto_newsletter_email" value="<?php echo esc_attr( sanitize_email( $_GET['auto_newsletter_email'] ) ); ?>">
				<button type="submit" style="background:#108615;color:#fff;border:none;padding:8px 24px;cursor:pointer;font-size:14px;border-radius:3px;">Zaslat potvrzovací email znovu</button>
			</form>
<?php endif; ?>
			<p style="margin:20px 0 0;"><button onclick="document.getElementById('obec-modal-overlay').remove()" style="background:#108615;color:#fff;border:none;padding:8px 24px;cursor:pointer;font-size:14px;border-radius:3px;">Zavřít</button></p>
		</div>
	</div>
	<script>
	(function(){
		var o=document.getElementById('obec-modal-overlay');
		if(!o)return;
		// klik na pozadí = zavřít
		o.addEventListener('click',function(e){if(e.target===o)o.remove()});
		// escape = zavřít
		document.addEventListener('keydown',function(e){if(e.key==='Escape')o.remove()});
		// vyčistit URL
		if(window.history.replaceState)window.history.replaceState({},'','<?php echo esc_js( $clean_url ); ?>');
	})();
	</script>
	<?php
}

/**
 * Označí všechny aktuálně čekající příspěvky (auto_newsletter_mail_sent='0') jako odeslané –
 * nic tím reálně neodešle, jen zastaví jejich další zpracování cronem. Používá se
 * výhradně z tlačítka "Smazat frontu" v adminu – čistě ruční akce, žádný hook
 * (např. aktivace pluginu) ji nespouští automaticky, aby ji nemohl bez vědomí
 * administrátora spustit i automatický update (např. WPMU DEV Dashboard) a
 * smazat tak i legitimní, rozpracovanou frontu.
 */
function auto_newsletter_mark_queue_as_done() {
	global $wpdb;
	return (int) $wpdb->query( $wpdb->prepare(
		"UPDATE {$wpdb->postmeta} SET meta_value=%s WHERE meta_key=%s AND meta_value=%s",
		'1', 'auto_newsletter_mail_sent', '0'
	) );
}

/**
 * Při zveřejnění → naplánovat rozeslání (pokud není skip_mail).
 */
add_action( 'transition_post_status', 'auto_newsletter_on_publish', 10, 3 );
function auto_newsletter_on_publish( $new, $old, $post ) {
	if ( $new !== 'publish' || $old === 'publish' ) return;
	if ( ! in_array( $post->post_type, array( 'clanek', 'dokument', 'akce', 'oznameni', 'dokument_ke_stazeni' ) ) ) return;
	if ( get_post_meta( $post->ID, 'auto_newsletter_skip_mail', true ) ) return;
	// Pokud je mailer vypnutý, vůbec neznačkovat – nic se nehromadí
	if ( get_option( 'auto_newsletter_mailer_enabled', '1' ) !== '1' ) return;

	// označit k rozeslání (mailer cron najde přes meta auto_newsletter_mail_sent)
	update_post_meta( $post->ID, 'auto_newsletter_mail_sent', 0 );
}

/**
 * Mailer cron (10 min) — pošle dávku (50) pro nejnovější neposlané příspěvky.
 */
/**
 * Pošle až $limit e-mailů k danému postu těm odběratelům, kteří ho ještě
 * nedostali (podle wp_auto_newsletter_mail_log), a zaloguje odeslání. Pokud po
 * tomto běhu nezbývá nikdo, nastaví auto_newsletter_mail_sent na 1. Vrací počet
 * odeslaných a počet zbývajících (pro zobrazení fronty v adminu).
 */
function auto_newsletter_send_post_batch( $post, $limit ) {
	global $wpdb;
	$subs_table = $wpdb->prefix . 'auto_newsletter_subscribers';
	$log_table  = $wpdb->prefix . 'auto_newsletter_mail_log';

	if ( $limit > 0 ) {
		$subs = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.id, s.email, s.hash FROM $subs_table s
			 WHERE s.confirmed = 1
			 AND NOT EXISTS ( SELECT 1 FROM $log_table l WHERE l.post_id = %d AND l.subscriber_id = s.id )
			 LIMIT %d",
			$post->ID, $limit
		) );
		foreach ( $subs as $sub ) {
			auto_newsletter_send_one( $sub->email, $post, $sub->hash );
			$wpdb->insert( $log_table, array(
				'post_id'       => $post->ID,
				'subscriber_id' => $sub->id,
			) );
		}
	}

	$remaining = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM $subs_table s
		 WHERE s.confirmed = 1
		 AND NOT EXISTS ( SELECT 1 FROM $log_table l WHERE l.post_id = %d AND l.subscriber_id = s.id )",
		$post->ID
	) );
	if ( $remaining === 0 ) {
		update_post_meta( $post->ID, 'auto_newsletter_mail_sent', 1 );
	}
	return array( 'sent' => isset( $subs ) ? count( $subs ) : 0, 'remaining' => $remaining );
}

add_action( 'auto_newsletter_mailer_event', 'auto_newsletter_send_batch' );
function auto_newsletter_send_batch() {
	if ( ! apply_filters( 'auto_newsletter_mailer_should_send', true ) ) return;

	$pending = get_posts( array(
		'post_type'      => array( 'clanek', 'dokument', 'akce', 'oznameni', 'dokument_ke_stazeni' ),
		'posts_per_page' => 20,
		'meta_query'     => array( array( 'key' => 'auto_newsletter_mail_sent', 'value' => '0' ) ),
		// ASC – dokonči nejdřív příspěvek, co čeká nejdéle, než začne sdílený rozpočet
		// čerpat novější příspěvek. Jinak by novější mohl "předběhnout" ve frontě starší,
		// ještě nedokončený, a ten by se dorozesílal jen kouskem zbylého rozpočtu.
		'orderby'        => 'date',
		'order'          => 'ASC',
	) );
	if ( empty( $pending ) ) return;

	$budget = 50; // sdíleno napříč všemi čekajícími posty v tomto běhu cronu
	foreach ( $pending as $post ) {
		if ( $budget <= 0 ) break;
		$result = auto_newsletter_send_post_batch( $post, $budget );
		$budget -= $result['sent'];
	}
}

function auto_newsletter_send_one( $email, $post, $hash ) {
	$template_subject = auto_newsletter_mailer_get_option(
		'auto_newsletter_notify_subject',
		'🔔 Novinka na webu našeho webu: {title}'
	);

	$title_plain = wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES );
	$excerpt     = get_the_excerpt( $post );
	$url         = get_permalink( $post );
	$unsub_url   = $hash ? add_query_arg( array( 'auto_newsletter_unsub' => $hash ), home_url() ) : '';

	// Metadata – typ, datum (jen akce), kategorie
	$post_type = get_post_type( $post );
	$taxonomy  = '';
	$type_label = '';
	switch ( $post_type ) {
		case 'clanek':
			$taxonomy = 'kategorie_clanku';
			$type_label = 'Článek';
			break;
		case 'dokument':
			$taxonomy = 'kategorie_dokumentu';
			$type_label = 'Dokument';
			break;
		case 'akce':
			$taxonomy = 'kategorie_akci';
			$type_label = 'Akce';
			break;
		case 'oznameni':
			$taxonomy = 'kategorie_oznameni';
			$type_label = 'Oznámení';
			break;
		case 'dokument_ke_stazeni':
			$taxonomy = 'kategorie_stazeni';
			$type_label = 'Ke stažení';
			break;
	}

	$meta_parts = array();
	// Typ (tučně)
	$meta_parts[] = '<strong>' . esc_html( $type_label ) . '</strong>';

	// Datum – jen u akce
	if ( $post_type === 'akce' ) {
		$datum_od = get_post_meta( $post->ID, 'datum_zacatek', true );
		if ( ! $datum_od ) $datum_od = get_post_meta( $post->ID, 'akce_datum_od', true );
		$cas_od   = get_post_meta( $post->ID, 'cas_zacatek', true );
		$cely_den = get_post_meta( $post->ID, 'akce_cely_den', true ) == '1';

		$dny = array( 'Pondělí', 'Úterý', 'Středa', 'Čtvrtek', 'Pátek', 'Sobota', 'Neděle' );
		if ( $datum_od ) {
			$ts = strtotime( $datum_od );
			$den = $dny[ date( 'N', $ts ) - 1 ];
			$datum_text = $den . ' | ' . date( 'j. n. Y', $ts );
			if ( $cas_od && ! $cely_den ) {
				$datum_text .= ' | ' . $cas_od;
			}
			$meta_parts[] = esc_html( $datum_text );
		}
	}

	// Kategorie
	$terms = $taxonomy ? get_the_terms( $post->ID, $taxonomy ) : null;
	if ( $terms && ! is_wp_error( $terms ) ) {
		$cat_links = array();
		foreach ( $terms as $t ) {
			$cat_links[] = '<a href="' . esc_url( get_term_link( $t ) ) . '" style="color:#108615;text-decoration:none">' . esc_html( $t->name ) . '</a>';
		}
		$meta_parts[] = implode( ', ', $cat_links );
	}

	// Sestavit metadata HTML
	$metadata_html = '';
	if ( ! empty( $meta_parts ) ) {
		$metadata_html = '<div style="font-size:13px;color:#333;margin:4px 0 12px">'
			. implode( ' <span style="color:#ccc;margin:0 4px">|</span> ', $meta_parts )
			. '</div>';
	}

	// 1) Předmět
	$subject = str_replace( '{title}', $title_plain, $template_subject );

	// 2) Sestavit tělo e-mailu přímo (bez placeholderů v DB)
	$body_html = '<div style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px">';

	// Úvod
	$body_html .= '<p style="margin:0 0 16px">Dobrý den,</p>';
	$body_html .= '<p style="margin:0 0 16px">na webu našeho webu byl právě zveřejněn nový příspěvek:</p>';

	// Název (větší, níž)
	$body_html .= '<div style="margin:16px 0 4px"><a href="' . esc_url( $url ) . '" style="color:#108615;font-size:22px;font-weight:bold;text-decoration:none">'
		. esc_html( $title_plain ) . '</a></div>';

	// Metadata (typ, datum, kategorie)
	$body_html .= $metadata_html;

	// Excerpt
	if ( $excerpt ) {
		$body_html .= '<p style="margin:12px 0 16px">' . esc_html( $excerpt ) . '</p>';
	}

	// Patička (14px, obě věty)
	$body_html .= '<div style="font-size:14px;color:#666;margin-top:16px;padding-top:12px;border-top:1px solid #eee">';
	$body_html .= 'Tento e-mail jste dostali, protože jste přihlášeni k odběru novinek.<br>';
	$body_html .= 'Pokud si nepřejete dostávat další e-maily, ';
	if ( $unsub_url ) {
		$body_html .= '<a href="' . esc_url( $unsub_url ) . '" style="color:#108615">odhlásit odběr</a>.';
	} else {
		$body_html .= 'odhlásit odběr.';
	}
	$body_html .= '</div>';

	$body_html .= '</div>'; // konec wrapperu

	wp_mail( $email, $subject, $body_html, array( 'Content-Type: text/html; charset=UTF-8' ) );
}

/**
* Zařadí notifikaci o konkrétním příspěvku do fronty — z tlačítka "Odeslat notifikaci" /
* "Zařadit do fronty" / "Odeslat znovu" v meta boxu při editaci příspěvku.
*
* Neposílá nic hned. Jen nastaví auto_newsletter_mail_sent na 0 (a při $force_resend smaže log
* předchozího odeslání, takže se pošle znovu úplně od začátku). Samotné odeslání pak
* provede až následující cron tik – ve stejném pořadí a se stejným sdíleným rozpočtem
* jako všechny ostatní čekající příspěvky, takže se nemůže "předběhnout" fronta.
*/
function auto_newsletter_send_single_post( $post_id, $force_resend = false ) {
	$post = get_post( $post_id );
	if ( ! $post || $post->post_status !== 'publish' ) return;
	if ( ! in_array( $post->post_type, array( 'clanek', 'dokument', 'akce', 'oznameni', 'dokument_ke_stazeni' ), true ) ) return;

	if ( $force_resend ) {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'auto_newsletter_mail_log', array( 'post_id' => $post_id ) );
	}
	update_post_meta( $post_id, 'auto_newsletter_mail_sent', 0 );
}
