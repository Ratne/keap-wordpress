<?php
/**
 * Client HTTP per Keap REST v2.
 *
 * @package KeapConnect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Effettua le chiamate verso Keap con fallback dei token e logging.
 */
class KC_Keap_Client {

	/**
	 * @var KC_Auth
	 */
	private $auth;

	/**
	 * @var KC_Logger
	 */
	private $logger;

	/**
	 * Costruttore.
	 *
	 * @param KC_Auth   $auth   Auth.
	 * @param KC_Logger $logger Logger.
	 */
	public function __construct( KC_Auth $auth, KC_Logger $logger ) {
		$this->auth   = $auth;
		$this->logger = $logger;
	}

	/**
	 * Esegue una richiesta verso Keap provando i token in ordine (fallback).
	 *
	 * @param string $method         Metodo HTTP.
	 * @param string $path           Path relativo (es. /contacts).
	 * @param array  $args           Opzioni: body (array), query (array).
	 * @param string $correlation_id ID correlazione per i log.
	 * @return array Risultato: success, code, data, error, token_type.
	 */
	public function request( $method, $path, array $args = array(), $correlation_id = '' ) {
		$tokens = $this->auth->get_tokens_to_try();

		if ( empty( $tokens ) ) {
			$this->logger->log(
				array(
					'correlation_id' => $correlation_id,
					'direction'      => KC_Logger::DIR_OUT,
					'method'         => $method,
					'endpoint'       => $path,
					'status'         => KC_Logger::STATUS_ERROR,
					'message'        => __( 'No Keap token configured.', 'keap-connect' ),
				)
			);
			return array(
				'success'    => false,
				'code'       => 0,
				'data'       => null,
				'error'      => __( 'No Keap token configured.', 'keap-connect' ),
				'token_type' => '',
			);
		}

		$url = KC_Settings::KEAP_API_BASE . $path;
		if ( ! empty( $args['query'] ) && is_array( $args['query'] ) ) {
			$url = add_query_arg( array_map( 'rawurlencode', $args['query'] ), $url );
		}

		$last = null;
		$total = count( $tokens );

		foreach ( $tokens as $index => $token ) {
			$request_args = array(
				'method'  => $method,
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token['token'],
					'Accept'        => 'application/json',
				),
			);

			if ( isset( $args['body'] ) && null !== $args['body'] ) {
				$request_args['headers']['Content-Type'] = 'application/json';
				$request_args['body'] = wp_json_encode( $args['body'] );
			}

			$response = wp_remote_request( $url, $request_args );

			$result = $this->normalize_response( $response, $token['type'] );

			$this->logger->log(
				array(
					'correlation_id' => $correlation_id,
					'direction'      => KC_Logger::DIR_OUT,
					'method'         => $method,
					'endpoint'       => $url,
					'request_body'   => isset( $args['body'] ) ? $args['body'] : '',
					'response_code'  => $result['code'],
					'response_body'  => is_wp_error( $response ) ? $result['error'] : wp_remote_retrieve_body( $response ),
					'status'         => $result['success'] ? KC_Logger::STATUS_SUCCESS : KC_Logger::STATUS_ERROR,
					'message'        => sprintf( 'Token: %s (%d/%d)', $token['type'], $index + 1, $total ),
				)
			);

			$last = $result;

			// Successo: ritorna subito.
			if ( $result['success'] ) {
				return $result;
			}

			// Errore di auth e ci sono altri token: prova il prossimo.
			$is_auth_error = in_array( $result['code'], array( 401, 403 ), true );
			if ( $is_auth_error && $index < ( $total - 1 ) ) {
				continue;
			}

			// Errore non di auth: non ha senso ritentare con altro token.
			if ( ! $is_auth_error ) {
				return $result;
			}
		}

		return $last;
	}

	/**
	 * Normalizza la risposta HTTP in un array standard.
	 *
	 * @param array|WP_Error $response   Risposta.
	 * @param string         $token_type Tipo token usato.
	 * @return array
	 */
	private function normalize_response( $response, $token_type ) {
		if ( is_wp_error( $response ) ) {
			return array(
				'success'    => false,
				'code'       => 0,
				'data'       => null,
				'error'      => $response->get_error_message(),
				'token_type' => $token_type,
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		$success = ( $code >= 200 && $code < 300 );
		$error   = '';
		if ( ! $success ) {
			if ( is_array( $data ) && isset( $data['message'] ) ) {
				$error = $data['message'];
			} elseif ( is_array( $data ) && isset( $data['error_description'] ) ) {
				$error = $data['error_description'];
			} else {
				$error = $raw;
			}
		}

		return array(
			'success'    => $success,
			'code'       => $code,
			'data'       => $data,
			'error'      => $error,
			'token_type' => $token_type,
		);
	}

	/**
	 * Cerca un contatto per email e ritorna il primo id trovato.
	 *
	 * @param string $email          Email.
	 * @param string $correlation_id Correlazione.
	 * @return array Risultato con eventuale 'contact_id'.
	 */
	public function find_contact_by_email( $email, $correlation_id = '' ) {
		$result = $this->request(
			'GET',
			'/contacts',
			array(
				'query' => array(
					'filter'   => 'email==' . $email,
					'page_size' => '1',
				),
			),
			$correlation_id
		);

		$result['contact_id'] = '';
		if ( $result['success'] && is_array( $result['data'] ) ) {
			$contacts = isset( $result['data']['contacts'] ) ? $result['data']['contacts'] : array();
			if ( ! empty( $contacts ) && isset( $contacts[0]['id'] ) ) {
				$result['contact_id'] = (string) $contacts[0]['id'];
			}
		}

		return $result;
	}

	/**
	 * Crea un nuovo contatto.
	 *
	 * @param array  $payload        Payload contatto.
	 * @param string $correlation_id Correlazione.
	 * @return array
	 */
	public function create_contact( array $payload, $correlation_id = '' ) {
		$result = $this->request( 'POST', '/contacts', array( 'body' => $payload ), $correlation_id );
		$result['contact_id'] = '';
		if ( $result['success'] && is_array( $result['data'] ) && isset( $result['data']['id'] ) ) {
			$result['contact_id'] = (string) $result['data']['id'];
		}
		return $result;
	}

	/**
	 * Aggiorna un contatto esistente.
	 *
	 * @param string $contact_id     ID contatto.
	 * @param array  $payload        Payload.
	 * @param string $correlation_id Correlazione.
	 * @return array
	 */
	public function update_contact( $contact_id, array $payload, $correlation_id = '' ) {
		return $this->request( 'PATCH', '/contacts/' . rawurlencode( $contact_id ), array( 'body' => $payload ), $correlation_id );
	}

	/**
	 * Applica un tag a un contatto.
	 *
	 * @param string|int $tag_id         ID tag.
	 * @param string|int $contact_id     ID contatto.
	 * @param string     $correlation_id Correlazione.
	 * @return array
	 */
	public function apply_tag( $tag_id, $contact_id, $correlation_id = '' ) {
		$path = '/tags/' . rawurlencode( (string) $tag_id ) . '/contacts:applyTags';
		return $this->request(
			'POST',
			$path,
			array(
				'body' => array(
					'contact_ids' => array( (int) $contact_id ),
				),
			),
			$correlation_id
		);
	}

	/**
	 * Recupera il modello del contatto (inclusi i custom field).
	 *
	 * @param string $correlation_id Correlazione.
	 * @return array
	 */
	public function get_contact_model( $correlation_id = '' ) {
		return $this->request( 'GET', '/contacts/model', array(), $correlation_id );
	}

	/**
	 * Testa un singolo token specifico (senza fallback) con una GET al modello.
	 *
	 * @param string $token          Token Bearer da usare.
	 * @param string $token_type     Tipo (oauth|pat) per i log.
	 * @param string $correlation_id Correlazione.
	 * @return array Risultato normalizzato.
	 */
	public function test_with_token( $token, $token_type, $correlation_id = '' ) {
		$url = KC_Settings::KEAP_API_BASE . '/contacts/model';

		$response = wp_remote_request(
			$url,
			array(
				'method'  => 'GET',
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
			)
		);

		$result = $this->normalize_response( $response, $token_type );

		$this->logger->log(
			array(
				'correlation_id' => $correlation_id,
				'direction'      => KC_Logger::DIR_OUT,
				'method'         => 'GET',
				'endpoint'       => $url . ' (test ' . $token_type . ')',
				'response_code'  => $result['code'],
				'response_body'  => is_wp_error( $response ) ? $result['error'] : wp_remote_retrieve_body( $response ),
				'status'         => $result['success'] ? KC_Logger::STATUS_SUCCESS : KC_Logger::STATUS_ERROR,
				'message'        => 'Test ' . $token_type,
			)
		);

		return $result;
	}
}
