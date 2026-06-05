<?php
/**
 * Autenticazione Keap: OAuth + PAT/SAK con fallback.
 *
 * @package KeapConnect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gestisce i token verso Keap.
 */
class KC_Auth {

	/**
	 * @var KC_Settings
	 */
	private $settings;

	/**
	 * @var KC_Logger
	 */
	private $logger;

	/**
	 * Margine (secondi) entro cui considerare il token in scadenza.
	 */
	const EXPIRY_MARGIN = 300;

	/**
	 * Costruttore.
	 *
	 * @param KC_Settings $settings Impostazioni.
	 * @param KC_Logger   $logger   Logger.
	 */
	public function __construct( KC_Settings $settings, KC_Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Ritorna l'elenco ordinato dei token da provare in base al metodo scelto.
	 *
	 * @return array Array di array {type, token}.
	 */
	public function get_tokens_to_try() {
		$method = $this->settings->get( 'auth_method' );
		$tokens = array();

		$add_oauth = function () use ( &$tokens ) {
			$token = $this->get_oauth_access_token();
			if ( $token ) {
				$tokens[] = array(
					'type'  => KC_Settings::AUTH_OAUTH,
					'token' => $token,
				);
			}
		};

		$add_pat = function () use ( &$tokens ) {
			$pat = $this->settings->get( 'pat_token' );
			if ( $pat ) {
				$tokens[] = array(
					'type'  => KC_Settings::AUTH_PAT,
					'token' => $pat,
				);
			}
		};

		if ( KC_Settings::AUTH_OAUTH === $method ) {
			$add_oauth();
		} elseif ( KC_Settings::AUTH_PAT === $method ) {
			$add_pat();
		} else {
			// Entrambi: OAuth primario, PAT/SAK come fallback.
			$add_oauth();
			$add_pat();
		}

		return $tokens;
	}

	/**
	 * Ritorna true se OAuth e' parte del metodo selezionato.
	 *
	 * @return bool
	 */
	public function uses_oauth() {
		return in_array( $this->settings->get( 'auth_method' ), array( KC_Settings::AUTH_OAUTH, KC_Settings::AUTH_BOTH ), true );
	}

	/**
	 * Ritorna l'access token OAuth valido, rinnovandolo se necessario.
	 *
	 * @return string Token o stringa vuota.
	 */
	public function get_oauth_access_token() {
		$access  = $this->settings->get( 'oauth_access_token' );
		$refresh = $this->settings->get( 'oauth_refresh_token' );

		if ( empty( $access ) && empty( $refresh ) ) {
			return '';
		}

		if ( $this->is_oauth_expiring() && ! empty( $refresh ) ) {
			$this->refresh_oauth();
			$access = $this->settings->get( 'oauth_access_token' );
		}

		return (string) $access;
	}

	/**
	 * Verifica se il token OAuth e' scaduto o in scadenza.
	 *
	 * @return bool
	 */
	public function is_oauth_expiring() {
		$expires_at = (int) $this->settings->get( 'oauth_expires_at' );
		if ( $expires_at <= 0 ) {
			return true;
		}
		return ( time() + self::EXPIRY_MARGIN ) >= $expires_at;
	}

	/**
	 * Costruisce l'URL di autorizzazione OAuth.
	 *
	 * @param string $redirect_uri URI di callback.
	 * @param string $state        Stato anti-CSRF.
	 * @return string
	 */
	public function get_authorization_url( $redirect_uri, $state ) {
		$args = array(
			'client_id'     => $this->settings->get( 'oauth_client_id' ),
			'redirect_uri'  => $redirect_uri,
			'response_type' => 'code',
			'scope'         => 'full',
			'state'         => $state,
		);
		return add_query_arg( array_map( 'rawurlencode', $args ), KC_Settings::KEAP_AUTHORIZE_URL );
	}

	/**
	 * Scambia un authorization code con access/refresh token.
	 *
	 * @param string $code         Authorization code.
	 * @param string $redirect_uri URI di callback usato in fase di authorize.
	 * @return true|WP_Error
	 */
	public function exchange_code( $code, $redirect_uri ) {
		// Come nel progetto Express di riferimento: credenziali nel body, senza header Basic.
		$response = $this->token_request(
			array(
				'grant_type'   => 'authorization_code',
				'code'         => $code,
				'redirect_uri' => $redirect_uri,
			),
			false
		);
		return $this->handle_token_response( $response, 'authorization_code' );
	}

	/**
	 * Rinnova l'access token tramite refresh token.
	 *
	 * @return true|WP_Error
	 */
	public function refresh_oauth() {
		$refresh = $this->settings->get( 'oauth_refresh_token' );
		if ( empty( $refresh ) ) {
			return new WP_Error( 'kc_no_refresh', __( 'Refresh token missing.', 'keap-connect' ) );
		}
		// Come nel progetto Express di riferimento: header Basic + body con grant_type/refresh_token.
		$response = $this->token_request(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh,
			),
			true
		);
		return $this->handle_token_response( $response, 'refresh_token' );
	}

	/**
	 * Esegue una richiesta al token endpoint di Keap.
	 *
	 * @param array $body      Corpo della richiesta.
	 * @param bool  $use_basic Se true usa header Basic (refresh); se false invia le credenziali nel body (authorization_code).
	 * @return array|WP_Error
	 */
	private function token_request( array $body, $use_basic = true ) {
		$client_id     = $this->settings->get( 'oauth_client_id' );
		$client_secret = $this->settings->get( 'oauth_client_secret' );

		if ( empty( $client_id ) || empty( $client_secret ) ) {
			return new WP_Error( 'kc_missing_oauth_creds', __( 'Client ID or Client Secret missing.', 'keap-connect' ) );
		}

		$headers = array(
			'Content-Type' => 'application/x-www-form-urlencoded',
		);

		if ( $use_basic ) {
			$headers['Authorization'] = 'Basic ' . base64_encode( $client_id . ':' . $client_secret );
		} else {
			$body['client_id']     = $client_id;
			$body['client_secret'] = $client_secret;
		}

		return wp_remote_post(
			KC_Settings::KEAP_TOKEN_URL,
			array(
				'timeout' => 30,
				'headers' => $headers,
				'body'    => $body,
			)
		);
	}

	/**
	 * Gestisce e salva la risposta del token endpoint.
	 *
	 * @param array|WP_Error $response Risposta HTTP.
	 * @param string         $context  Contesto (grant type).
	 * @return true|WP_Error
	 */
	private function handle_token_response( $response, $context ) {
		$correlation = KC_Logger::new_correlation_id();

		if ( is_wp_error( $response ) ) {
			$this->logger->log(
				array(
					'correlation_id' => $correlation,
					'direction'      => KC_Logger::DIR_OUT,
					'method'         => 'POST',
					'endpoint'       => KC_Settings::KEAP_TOKEN_URL . ' (' . $context . ')',
					'status'         => KC_Logger::STATUS_ERROR,
					'message'        => $response->get_error_message(),
				)
			);
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		$this->logger->log(
			array(
				'correlation_id' => $correlation,
				'direction'      => KC_Logger::DIR_OUT,
				'method'         => 'POST',
				'endpoint'       => KC_Settings::KEAP_TOKEN_URL . ' (' . $context . ')',
				'response_code'  => $code,
				'response_body'  => $raw,
				'status'         => ( $code >= 200 && $code < 300 ) ? KC_Logger::STATUS_SUCCESS : KC_Logger::STATUS_ERROR,
				'message'        => 'OAuth ' . $context,
			)
		);

		if ( $code < 200 || $code >= 300 || ! is_array( $data ) || empty( $data['access_token'] ) ) {
			$msg = is_array( $data ) && isset( $data['error_description'] ) ? $data['error_description'] : __( 'Invalid token response.', 'keap-connect' );
			return new WP_Error( 'kc_token_error', $msg, array( 'status' => $code ) );
		}

		$update = array(
			'oauth_access_token' => $data['access_token'],
			'oauth_expires_at'   => time() + (int) ( isset( $data['expires_in'] ) ? $data['expires_in'] : 0 ),
		);
		if ( ! empty( $data['refresh_token'] ) ) {
			$update['oauth_refresh_token'] = $data['refresh_token'];
		}
		if ( isset( $data['scope'] ) ) {
			$update['oauth_scope'] = $data['scope'];
		}
		$this->settings->update( $update );

		return true;
	}

	/**
	 * Disconnette OAuth cancellando i token salvati.
	 *
	 * @return void
	 */
	public function disconnect_oauth() {
		$this->settings->update(
			array(
				'oauth_access_token'  => '',
				'oauth_refresh_token' => '',
				'oauth_expires_at'    => 0,
				'oauth_scope'         => '',
			)
		);
	}

	/**
	 * Ritorna true se OAuth e' connesso (esistono token).
	 *
	 * @return bool
	 */
	public function is_oauth_connected() {
		return ! empty( $this->settings->get( 'oauth_refresh_token' ) ) || ! empty( $this->settings->get( 'oauth_access_token' ) );
	}
}
