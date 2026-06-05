<?php
/**
 * Gestione cron: refresh OAuth e cron esterno.
 *
 * @package KeapConnect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schedula il refresh del token OAuth e gestisce il trigger esterno.
 */
class KC_Cron {

	const HOOK_REFRESH = 'kc_refresh_oauth';
	const SCHEDULE = 'kc_6hours';

	/**
	 * @var KC_Settings
	 */
	private $settings;

	/**
	 * @var KC_Auth
	 */
	private $auth;

	/**
	 * @var KC_Logger
	 */
	private $logger;

	/**
	 * @var KC_Notifier
	 */
	private $notifier;

	/**
	 * Costruttore.
	 *
	 * @param KC_Settings $settings Impostazioni.
	 * @param KC_Auth     $auth     Auth.
	 * @param KC_Logger   $logger   Logger.
	 * @param KC_Notifier $notifier Notifier.
	 */
	public function __construct( KC_Settings $settings, KC_Auth $auth, KC_Logger $logger, KC_Notifier $notifier ) {
		$this->settings = $settings;
		$this->auth     = $auth;
		$this->logger   = $logger;
		$this->notifier = $notifier;
	}

	/**
	 * Registra gli hook (filtri schedule + azione di refresh).
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		add_action( self::HOOK_REFRESH, array( $this, 'run_refresh' ) );

		// Assicura la pianificazione anche se l'attivazione non l'ha creata.
		if ( ! wp_next_scheduled( self::HOOK_REFRESH ) ) {
			self::schedule_events();
		}
	}

	/**
	 * Registra la rotta cron esterno.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			KC_Endpoint::REST_NAMESPACE,
			'/cron',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( $this, 'handle_external_cron' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Aggiunge lo schedule custom da 6 ore.
	 *
	 * @param array $schedules Schedule esistenti.
	 * @return array
	 */
	public static function add_schedule( $schedules ) {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 6 hours (Keap Connect)', 'keap-connect' ),
		);
		return $schedules;
	}

	/**
	 * Pianifica l'evento di refresh.
	 *
	 * @return void
	 */
	public static function schedule_events() {
		if ( ! wp_next_scheduled( self::HOOK_REFRESH ) ) {
			wp_schedule_event( time() + ( 6 * HOUR_IN_SECONDS ), self::SCHEDULE, self::HOOK_REFRESH );
		}
	}

	/**
	 * Rimuove l'evento pianificato.
	 *
	 * @return void
	 */
	public static function clear_events() {
		$timestamp = wp_next_scheduled( self::HOOK_REFRESH );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK_REFRESH );
		}
		wp_clear_scheduled_hook( self::HOOK_REFRESH );
	}

	/**
	 * Esegue il refresh OAuth se previsto dal metodo selezionato.
	 *
	 * @return bool|WP_Error True/refresh ok, false se non applicabile.
	 */
	public function run_refresh() {
		// Pulizia log secondo la retention configurata.
		$this->logger->purge_old( (int) $this->settings->get( 'log_retention_days' ) );

		if ( ! $this->auth->uses_oauth() ) {
			return false;
		}

		// OAuth previsto ma non connesso: avvisa che va riattivato.
		if ( ! $this->auth->is_oauth_connected() ) {
			$this->alert_oauth_problem( __( 'OAuth is not connected. Please reconnect it from the plugin settings.', 'keap-connect' ) );
			return false;
		}

		$result = $this->auth->refresh_oauth();

		if ( true === $result ) {
			// Refresh riuscito: azzera la guardia per futuri alert.
			delete_transient( 'kc_oauth_alert_sent' );
		}

		if ( is_wp_error( $result ) ) {
			$this->alert_oauth_problem(
				sprintf(
					/* translators: %s: error detail */
					__( 'OAuth token refresh failed: %s. Please reconnect it from the plugin settings.', 'keap-connect' ),
					$result->get_error_message()
				)
			);
		}

		return $result;
	}

	/**
	 * Invia (al massimo una volta ogni 6 ore) un'email di alert su problemi OAuth.
	 *
	 * @param string $detail Dettaglio del problema.
	 * @return void
	 */
	private function alert_oauth_problem( $detail ) {
		// Guardia: una sola email per finestra di 6 ore, indipendentemente dai trigger del cron.
		if ( false !== get_transient( 'kc_oauth_alert_sent' ) ) {
			return;
		}
		set_transient( 'kc_oauth_alert_sent', 1, 6 * HOUR_IN_SECONDS );

		$this->notifier->notify_generic(
			__( '[Keap Connect] OAuth needs attention', 'keap-connect' ),
			$detail . "\n\n" . admin_url( 'admin.php?page=keap-connect&tab=connessione' )
		);
	}

	/**
	 * Handler della rotta cron esterno.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response
	 */
	public function handle_external_cron( WP_REST_Request $request ) {
		if ( ! $this->settings->get( 'enable_external_cron' ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => __( 'External cron disabled.', 'keap-connect' ) ), 403 );
		}

		// Il secret puo' arrivare via query string oppure via header X-KC-Cron-Secret.
		$secret = (string) $request->get_param( 'secret' );
		if ( '' === $secret ) {
			$header = $request->get_header( 'x_kc_cron_secret' );
			if ( ! empty( $header ) ) {
				$secret = (string) $header;
			}
		}
		$expected_hash = (string) $this->settings->get( 'external_cron_secret_hash' );

		if ( '' === $expected_hash || '' === $secret || ! KC_Crypto::verify_token( $secret, $expected_hash ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Invalid secret.', 'keap-connect' ) ), 401 );
		}

		$result = $this->run_refresh();

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => $result->get_error_message() ), 502 );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => false === $result ? __( 'No refresh needed (OAuth not in use).', 'keap-connect' ) : __( 'OAuth refresh completed.', 'keap-connect' ),
			),
			200
		);
	}
}
