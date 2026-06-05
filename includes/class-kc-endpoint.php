<?php
/**
 * Endpoint REST di intake.
 *
 * @package KeapConnect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registra e gestisce la rotta REST che riceve i lead.
 */
class KC_Endpoint {

	const REST_NAMESPACE = 'keap-connect/v1';

	/**
	 * @var KC_Settings
	 */
	private $settings;

	/**
	 * @var KC_Processor
	 */
	private $processor;

	/**
	 * @var KC_Logger
	 */
	private $logger;

	/**
	 * Costruttore.
	 *
	 * @param KC_Settings  $settings  Impostazioni.
	 * @param KC_Processor $processor Processor.
	 * @param KC_Logger    $logger    Logger.
	 */
	public function __construct( KC_Settings $settings, KC_Processor $processor, KC_Logger $logger ) {
		$this->settings  = $settings;
		$this->processor = $processor;
		$this->logger    = $logger;
	}

	/**
	 * Registra le rotte REST.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/intake',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_intake' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Verifica il Bearer token se richiesto.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return bool|WP_Error
	 */
	public function check_permission( WP_REST_Request $request ) {
		if ( ! $this->settings->get( 'require_bearer' ) ) {
			return true;
		}

		$expected_hash = (string) $this->settings->get( 'bearer_token_hash' );
		$provided      = $this->extract_bearer( $request );

		if ( '' !== $expected_hash && '' !== $provided && KC_Crypto::verify_token( $provided, $expected_hash ) ) {
			return true;
		}

		// Throttle: registra al massimo un tentativo non autorizzato ogni 60s
		// per evitare che richieste non autenticate gonfino la tabella di log (DoS).
		if ( false === get_transient( 'kc_auth_fail_logged' ) ) {
			set_transient( 'kc_auth_fail_logged', 1, MINUTE_IN_SECONDS );
			$this->logger->log(
				array(
					'correlation_id' => KC_Logger::new_correlation_id(),
					'direction'      => KC_Logger::DIR_IN,
					'method'         => 'POST',
					'endpoint'       => '/intake',
					'status'         => KC_Logger::STATUS_ERROR,
					'response_code'  => 401,
					'message'        => __( 'Bearer token missing or invalid.', 'keap-connect' ),
				)
			);
		}

		return new WP_Error(
			'kc_unauthorized',
			__( 'Bearer token missing or invalid.', 'keap-connect' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Estrae il Bearer token dalla richiesta.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return string
	 */
	private function extract_bearer( WP_REST_Request $request ) {
		$header = $request->get_header( 'authorization' );
		if ( empty( $header ) && isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
		}
		if ( ! empty( $header ) && preg_match( '/Bearer\s+(.+)/i', $header, $matches ) ) {
			return trim( $matches[1] );
		}
		return '';
	}

	/**
	 * Gestisce la richiesta di intake.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response
	 */
	public function handle_intake( WP_REST_Request $request ) {
		$correlation_id = KC_Logger::new_correlation_id();

		$body = $request->get_json_params();
		if ( empty( $body ) || ! is_array( $body ) ) {
			$body = $request->get_params();
		}
		$body = is_array( $body ) ? $body : array();

		// Log del body in ingresso.
		$this->logger->log(
			array(
				'correlation_id' => $correlation_id,
				'direction'      => KC_Logger::DIR_IN,
				'method'         => 'POST',
				'endpoint'       => '/intake',
				'request_body'   => $body,
				'status'         => KC_Logger::STATUS_RECEIVED,
				'message'        => __( 'Request received.', 'keap-connect' ),
			)
		);

		if ( empty( $body ) ) {
			return new WP_REST_Response(
				array(
					'success'        => false,
					'message'        => __( 'Empty body or not JSON format.', 'keap-connect' ),
					'correlation_id' => $correlation_id,
				),
				400
			);
		}

		$result = $this->processor->process( $body, $correlation_id );

		return new WP_REST_Response(
			array(
				'success'        => $result['success'],
				'contact_id'     => $result['contact_id'],
				'message'        => $result['message'],
				'correlation_id' => $correlation_id,
			),
			isset( $result['code'] ) ? $result['code'] : ( $result['success'] ? 200 : 502 )
		);
	}
}
