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

		// UTM Source attribution (opzionale): estrae gli UTM presenti nel body.
		$source_cfg = $this->settings->get_source();
		$utm        = ( ! empty( $source_cfg['enabled'] ) ) ? $this->extract_utm( $body, $source_cfg['rows'] ) : array();

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

			// Nuovo contatto: first + last (mirror) per gli UTM presenti.
			if ( ! empty( $utm ) ) {
				$this->merge_custom_fields( $payload, $this->build_utm_custom_fields( 'mirror', $utm, $source_cfg['rows'] ) );
			}

			$create = $this->client->create_contact( $payload, $correlation_id );
			if ( ! $create['success'] ) {
				return $this->fail( $correlation_id, $email, __( 'Error creating the contact.', 'keap-connect' ), $create );
			}
			$contact_id = $create['contact_id'];
			$created    = true;
		} else {
			// Contatto esistente: decide se valorizzare anche i first (first-touch).
			if ( ! empty( $utm ) ) {
				$mode    = $this->utm_mode_for_existing( $contact_id, $source_cfg['rows'], $correlation_id );
				$utm_cfs = $this->build_utm_custom_fields( $mode, $utm, $source_cfg['rows'] );
				$this->merge_custom_fields( $payload, $utm_cfs );
			}

			if ( ! empty( $payload ) ) {
				$update = $this->client->update_contact( $contact_id, $payload, $correlation_id );
				if ( ! $update['success'] ) {
					return $this->fail( $correlation_id, $email, __( 'Error updating the contact.', 'keap-connect' ), $update );
				}
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
	 * Estrae gli UTM presenti nel body in base ai nomi parametro configurati.
	 *
	 * @param array $body Body in ingresso.
	 * @param array $rows Righe di configurazione source.
	 * @return array Mappa key => value (solo UTM presenti e non vuoti).
	 */
	private function extract_utm( array $body, array $rows ) {
		$utm = array();
		foreach ( $rows as $row ) {
			$key   = isset( $row['key'] ) ? $row['key'] : '';
			$param = isset( $row['param'] ) && '' !== $row['param'] ? $row['param'] : ( '' !== $key ? 'utm_' . $key : '' );
			if ( '' === $key || '' === $param ) {
				continue;
			}

			$value = $this->body_value_ci( $body, $param );
			if ( is_array( $value ) ) {
				$value = reset( $value );
			}
			if ( null === $value ) {
				continue;
			}
			$value = trim( (string) $value );
			if ( '' === $value ) {
				continue;
			}
			$utm[ $key ] = $value;
		}
		return $utm;
	}

	/**
	 * Determina la modalita' di scrittura UTM per un contatto esistente.
	 *
	 * Se almeno un first_* e' gia' valorizzato -> 'last_only', altrimenti 'mirror'.
	 *
	 * @param string $contact_id     ID contatto.
	 * @param array  $rows           Righe di configurazione source.
	 * @param string $correlation_id Correlazione.
	 * @return string 'mirror' | 'last_only'
	 */
	private function utm_mode_for_existing( $contact_id, array $rows, $correlation_id ) {
		$result = $this->client->get_contact_custom_fields( $contact_id, $correlation_id );
		if ( empty( $result['success'] ) ) {
			// In caso di errore lettura, comportamento conservativo: aggiorna solo last.
			return 'last_only';
		}

		$existing = isset( $result['fields'] ) ? $result['fields'] : array();
		foreach ( $rows as $row ) {
			$first_field = isset( $row['first_field'] ) ? (string) $row['first_field'] : '';
			if ( '' === $first_field ) {
				continue;
			}
			if ( array_key_exists( $first_field, $existing ) ) {
				$val = $existing[ $first_field ];
				if ( null !== $val && '' !== trim( (string) $val ) ) {
					return 'last_only';
				}
			}
		}
		return 'mirror';
	}

	/**
	 * Costruisce le custom_fields UTM da aggiungere al payload.
	 *
	 * @param string $mode 'mirror' (first+last) o 'last_only'.
	 * @param array  $utm  Mappa key => value degli UTM presenti.
	 * @param array  $rows Righe di configurazione source.
	 * @return array Lista di { id, content }.
	 */
	private function build_utm_custom_fields( $mode, array $utm, array $rows ) {
		$fields = array();
		foreach ( $rows as $row ) {
			$key = isset( $row['key'] ) ? $row['key'] : '';
			if ( '' === $key || ! isset( $utm[ $key ] ) ) {
				continue;
			}
			$value       = $utm[ $key ];
			$first_field = isset( $row['first_field'] ) ? (string) $row['first_field'] : '';
			$last_field  = isset( $row['last_field'] ) ? (string) $row['last_field'] : '';

			if ( 'mirror' === $mode && '' !== $first_field ) {
				$fields[] = array(
					'id'      => is_numeric( $first_field ) ? (int) $first_field : $first_field,
					'content' => $value,
				);
			}
			if ( '' !== $last_field ) {
				$fields[] = array(
					'id'      => is_numeric( $last_field ) ? (int) $last_field : $last_field,
					'content' => $value,
				);
			}
		}
		return $fields;
	}

	/**
	 * Unisce delle custom_fields nel payload (append senza duplicare la chiave).
	 *
	 * @param array $payload Riferimento al payload.
	 * @param array $fields  Custom fields da aggiungere.
	 * @return void
	 */
	private function merge_custom_fields( array &$payload, array $fields ) {
		if ( empty( $fields ) ) {
			return;
		}
		if ( ! isset( $payload['custom_fields'] ) || ! is_array( $payload['custom_fields'] ) ) {
			$payload['custom_fields'] = array();
		}
		$payload['custom_fields'] = array_merge( $payload['custom_fields'], $fields );
	}

	/**
	 * Recupera un valore dal body in modo case-insensitive.
	 *
	 * @param array  $body Body.
	 * @param string $key  Chiave.
	 * @return mixed|null
	 */
	private function body_value_ci( array $body, $key ) {
		if ( array_key_exists( $key, $body ) ) {
			return $body[ $key ];
		}
		foreach ( $body as $k => $v ) {
			if ( is_string( $k ) && 0 === strcasecmp( $k, $key ) ) {
				return $v;
			}
		}
		return null;
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
