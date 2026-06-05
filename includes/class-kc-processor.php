<?php
/**
 * Orchestrazione del flusso lead -> Keap.
 *
 * @package KeapConnect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordina ricerca/creazione/aggiornamento contatto e applicazione tag.
 */
class KC_Processor {

	/**
	 * @var KC_Settings
	 */
	private $settings;

	/**
	 * @var KC_Keap_Client
	 */
	private $client;

	/**
	 * @var KC_Mapper
	 */
	private $mapper;

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
	 * @param KC_Settings    $settings Impostazioni.
	 * @param KC_Keap_Client $client   Client Keap.
	 * @param KC_Mapper      $mapper   Mapper.
	 * @param KC_Logger      $logger   Logger.
	 * @param KC_Notifier    $notifier Notifier.
	 */
	public function __construct( KC_Settings $settings, KC_Keap_Client $client, KC_Mapper $mapper, KC_Logger $logger, KC_Notifier $notifier ) {
		$this->settings = $settings;
		$this->client   = $client;
		$this->mapper   = $mapper;
		$this->logger   = $logger;
		$this->notifier = $notifier;
	}

	/**
	 * Processa un lead in ingresso.
	 *
	 * @param array  $body           Body ricevuto.
	 * @param string $correlation_id ID correlazione.
	 * @return array Esito: success, contact_id, message, steps.
	 */
	public function process( array $body, $correlation_id ) {
		$email = $this->mapper->extract_email( $body );

		if ( '' === $email || ! is_email( $email ) ) {
			$message = __( 'Email missing or invalid in the body.', 'keap-connect' );
			$this->logger->log(
				array(
					'correlation_id' => $correlation_id,
					'direction'      => KC_Logger::DIR_IN,
					'status'         => KC_Logger::STATUS_ERROR,
					'email'          => $email,
					'message'        => $message,
				)
			);
			$this->notifier->notify_lead( false, $email, '', $message, $correlation_id );
			return array(
				'success'    => false,
				'contact_id' => '',
				'message'    => $message,
				'code'       => 422,
			);
		}

		$payload = $this->mapper->build_payload( $body );

		// 1. Ricerca per email.
		$search = $this->client->find_contact_by_email( $email, $correlation_id );
		if ( ! $search['success'] ) {
			return $this->fail( $correlation_id, $email, __( 'Error searching for the contact.', 'keap-connect' ), $search );
		}

		$contact_id = $search['contact_id'];
		$created    = false;

		// 2. Crea o aggiorna.
		if ( '' === $contact_id ) {
			if ( ! $this->settings->get( 'create_if_missing' ) ) {
				$this->logger->log(
					array(
						'correlation_id' => $correlation_id,
						'direction'      => KC_Logger::DIR_IN,
						'email'          => $email,
						'status'         => KC_Logger::STATUS_SKIPPED,
						'message'        => __( 'Contact not found, creation disabled.', 'keap-connect' ),
					)
				);
				$skip_message = __( 'Contact not found, no action taken.', 'keap-connect' );
				$this->notifier->notify_lead( true, $email, '', $skip_message, $correlation_id );
				return array(
					'success'    => true,
					'contact_id' => '',
					'message'    => $skip_message,
					'code'       => 200,
				);
			}

			// Assicura che l'email sia nel payload anche se non mappata esplicitamente.
			if ( empty( $payload['email_addresses'] ) ) {
				$payload['email_addresses'] = array(
					array(
						'field' => 'EMAIL1',
						'email' => $email,
					),
				);
			}

			$create = $this->client->create_contact( $payload, $correlation_id );
			if ( ! $create['success'] ) {
				return $this->fail( $correlation_id, $email, __( 'Error creating the contact.', 'keap-connect' ), $create );
			}
			$contact_id = $create['contact_id'];
			$created    = true;
		} elseif ( ! empty( $payload ) ) {
			$update = $this->client->update_contact( $contact_id, $payload, $correlation_id );
			if ( ! $update['success'] ) {
				return $this->fail( $correlation_id, $email, __( 'Error updating the contact.', 'keap-connect' ), $update );
			}
		}

		// 3. Applica i tag.
		$tag_ids   = $this->mapper->extract_tag_ids( $body );
		$tag_errors = array();
		foreach ( $tag_ids as $tag_id ) {
			$tag_result = $this->client->apply_tag( $tag_id, $contact_id, $correlation_id );
			if ( ! $tag_result['success'] ) {
				$tag_errors[] = $tag_id;
			}
		}

		$status  = empty( $tag_errors ) ? KC_Logger::STATUS_SUCCESS : KC_Logger::STATUS_ERROR;
		$message = sprintf(
			/* translators: 1: created/updated, 2: tag count */
			__( 'Contact %1$s. Tags applied: %2$d.', 'keap-connect' ),
			$created ? __( 'created', 'keap-connect' ) : __( 'updated', 'keap-connect' ),
			count( $tag_ids ) - count( $tag_errors )
		);
		if ( ! empty( $tag_errors ) ) {
			/* translators: %s: comma-separated tag IDs */
			$message .= ' ' . sprintf( __( 'Failed tags: %s', 'keap-connect' ), implode( ', ', $tag_errors ) );
		}

		$this->logger->log(
			array(
				'correlation_id' => $correlation_id,
				'direction'      => KC_Logger::DIR_IN,
				'email'          => $email,
				'contact_id'     => $contact_id,
				'status'         => $status,
				'message'        => $message,
			)
		);

		$this->notifier->notify_lead( empty( $tag_errors ), $email, $contact_id, $message, $correlation_id );

		return array(
			'success'    => empty( $tag_errors ),
			'contact_id' => $contact_id,
			'message'    => $message,
			'code'       => empty( $tag_errors ) ? 200 : 207,
		);
	}

	/**
	 * Helper per gli errori: logga e ritorna l'esito.
	 *
	 * @param string $correlation_id Correlazione.
	 * @param string $email          Email.
	 * @param string $message        Messaggio.
	 * @param array  $result         Risultato chiamata Keap.
	 * @return array
	 */
	private function fail( $correlation_id, $email, $message, $result ) {
		$detail = isset( $result['error'] ) ? $result['error'] : '';
		$this->logger->log(
			array(
				'correlation_id' => $correlation_id,
				'direction'      => KC_Logger::DIR_IN,
				'email'          => $email,
				'status'         => KC_Logger::STATUS_ERROR,
				'message'        => $message . ' ' . $detail,
			)
		);
		$this->notifier->notify_lead( false, $email, '', $message . ' ' . $detail, $correlation_id );
		return array(
			'success'    => false,
			'contact_id' => '',
			'message'    => $message,
			'code'       => isset( $result['code'] ) && $result['code'] >= 400 ? $result['code'] : 502,
		);
	}
}
