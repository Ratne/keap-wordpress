<?php
/**
 * Notifiche email tramite wp_mail.
 *
 * @package KeapConnect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Invia notifiche email agli amministratori per gli eventi del plugin.
 */
class KC_Notifier {

	/**
	 * Chiave transient per il rate limit.
	 */
	const RATE_KEY = 'kc_notify_rate';

	/**
	 * Numero massimo di email per finestra (anti-flood / anti-blacklist).
	 */
	const RATE_MAX = 20;

	/**
	 * @var KC_Settings
	 */
	private $settings;

	/**
	 * Costruttore.
	 *
	 * @param KC_Settings $settings Impostazioni.
	 */
	public function __construct( KC_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Le notifiche sono abilitate?
	 *
	 * @return bool
	 */
	public function enabled() {
		return (bool) $this->settings->get( 'notifications_enabled' );
	}

	/**
	 * Ritorna i destinatari validi (admin + email extra), deduplicati.
	 *
	 * @return array
	 */
	public function recipients() {
		$list = array();

		if ( $this->settings->get( 'notify_include_admin' ) ) {
			$admin = get_option( 'admin_email' );
			if ( $admin && is_email( $admin ) ) {
				$list[] = $admin;
			}
		}

		$extra = trim( (string) $this->settings->get( 'notify_extra_email' ) );
		if ( '' !== $extra && is_email( $extra ) ) {
			$list[] = $extra;
		}

		$list = array_values( array_unique( array_filter( $list, 'is_email' ) ) );
		return $list;
	}

	/**
	 * Stabilisce se notificare in base all'esito e alla configurazione.
	 *
	 * @param bool $success Esito.
	 * @return bool
	 */
	public function should_notify_for( $success ) {
		if ( ! $this->enabled() ) {
			return false;
		}
		$on = $this->settings->get( 'notify_on' );
		if ( 'all' === $on ) {
			return true;
		}
		// 'errors': notifica solo in caso di fallimento.
		return ! $success;
	}

	/**
	 * Notifica l'esito dell'elaborazione di un lead.
	 *
	 * @param bool   $success        Esito.
	 * @param string $email          Email del lead.
	 * @param string $contact_id     ID contatto Keap.
	 * @param string $message        Messaggio di esito.
	 * @param string $correlation_id Correlazione.
	 * @return void
	 */
	public function notify_lead( $success, $email, $contact_id, $message, $correlation_id ) {
		if ( ! $this->should_notify_for( $success ) ) {
			return;
		}

		$subject = $success
			? __( '[Keap Connect] Lead synced', 'keap-connect' )
			: __( '[Keap Connect] Lead sync error', 'keap-connect' );

		$log_url = add_query_arg(
			array(
				'page'           => 'keap-connect',
				'tab'            => 'log',
				'correlation_id' => rawurlencode( $correlation_id ),
			),
			admin_url( 'admin.php' )
		);

		$intro = $success
			? __( 'The following lead was processed:', 'keap-connect' )
			: __( 'The following lead could not be synced to Keap:', 'keap-connect' );

		$lines = array(
			$intro,
			'',
			sprintf( /* translators: %s: email */ __( 'Email: %s', 'keap-connect' ), sanitize_text_field( (string) $email ) ),
			sprintf( /* translators: %s: contact id */ __( 'Contact ID: %s', 'keap-connect' ), '' !== (string) $contact_id ? sanitize_text_field( (string) $contact_id ) : '-' ),
			sprintf( /* translators: %s: status */ __( 'Status: %s', 'keap-connect' ), $success ? __( 'success', 'keap-connect' ) : __( 'error', 'keap-connect' ) ),
			sprintf( /* translators: %s: outcome message */ __( 'Detail: %s', 'keap-connect' ), sanitize_text_field( (string) $message ) ),
			sprintf( /* translators: %s: correlation id */ __( 'Correlation: %s', 'keap-connect' ), sanitize_text_field( (string) $correlation_id ) ),
			'',
			__( 'Open the log to inspect the received body and replay it after fixing:', 'keap-connect' ),
			esc_url_raw( $log_url ),
		);

		$this->send( $subject, implode( "\n", $lines ) );
	}

	/**
	 * Invia una notifica generica (es. errori OAuth/cron).
	 *
	 * @param string $subject Oggetto.
	 * @param string $message Messaggio.
	 * @return void
	 */
	public function notify_generic( $subject, $message ) {
		if ( ! $this->enabled() ) {
			return;
		}
		$this->send( $subject, $message );
	}

	/**
	 * Invia un'email di test ai destinatari configurati.
	 * Ignora il flag "abilitato" e il rate limit (azione manuale dell'admin).
	 *
	 * @return array { sent: bool, recipients: array }
	 */
	public function test() {
		$recipients = $this->recipients();
		if ( empty( $recipients ) ) {
			return array(
				'sent'       => false,
				'recipients' => array(),
			);
		}

		$subject = sanitize_text_field( __( '[Keap Connect] Test email', 'keap-connect' ) );
		$body    = wp_strip_all_tags(
			__( 'This is a test email from Keap Connect. If you received it, notifications are working.', 'keap-connect' )
			. "\n\n" . admin_url( 'admin.php?page=keap-connect&tab=endpoint' )
		);

		$ok = (bool) wp_mail( $recipients, $subject, $body );

		return array(
			'sent'       => $ok,
			'recipients' => $recipients,
		);
	}

	/**
	 * Invia l'email ai destinatari, in sicurezza e con rate limit.
	 *
	 * @param string $subject Oggetto.
	 * @param string $body    Corpo (testo).
	 * @return bool
	 */
	private function send( $subject, $body ) {
		$recipients = $this->recipients();
		if ( empty( $recipients ) ) {
			return false;
		}
		if ( ! $this->within_rate_limit() ) {
			return false;
		}

		// sanitize_text_field rimuove i ritorni a capo: previene header injection nell'oggetto.
		$subject = sanitize_text_field( (string) $subject );
		// Email in testo semplice: niente HTML/tag.
		$body = wp_strip_all_tags( (string) $body );

		return (bool) wp_mail( $recipients, $subject, $body );
	}

	/**
	 * Verifica e aggiorna il rate limit (numero massimo di email per finestra oraria).
	 *
	 * @return bool True se sotto la soglia.
	 */
	private function within_rate_limit() {
		$count = (int) get_transient( self::RATE_KEY );
		if ( $count >= self::RATE_MAX ) {
			return false;
		}
		set_transient( self::RATE_KEY, $count + 1, HOUR_IN_SECONDS );
		return true;
	}
}
