<?php
/**
 * Mappatura body in ingresso -> payload Keap.
 *
 * @package KeapConnect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trasforma il body ricevuto in un payload per la REST v2 di Keap.
 */
class KC_Mapper {

	/**
	 * @var KC_Settings
	 */
	private $settings;

	/**
	 * Campi standard supportati e relativa etichetta.
	 *
	 * @return array
	 */
	public static function standard_fields() {
		return array(
			'given_name'  => __( 'First name (given_name)', 'keap-connect' ),
			'family_name' => __( 'Last name (family_name)', 'keap-connect' ),
			'email'       => __( 'Email', 'keap-connect' ),
			'phone'       => __( 'Phone', 'keap-connect' ),
			'company'     => __( 'Company (company)', 'keap-connect' ),
			'job_title'   => __( 'Job title (job_title)', 'keap-connect' ),
		);
	}

	/**
	 * Componenti indirizzo supportati.
	 *
	 * @return array
	 */
	public static function address_components() {
		return array(
			'line1'        => __( 'Address (line1)', 'keap-connect' ),
			'line2'        => __( 'Address 2 (line2)', 'keap-connect' ),
			'locality'     => __( 'City (locality)', 'keap-connect' ),
			'region'       => __( 'State/Region (region)', 'keap-connect' ),
			'postal_code'  => __( 'Postal code (postal_code)', 'keap-connect' ),
			'country_code' => __( 'Country (country_code)', 'keap-connect' ),
		);
	}

	/**
	 * Tipi di indirizzo Keap.
	 *
	 * @return array
	 */
	public static function address_types() {
		return array(
			'BILLING'  => __( 'Billing (BILLING)', 'keap-connect' ),
			'SHIPPING' => __( 'Shipping (SHIPPING)', 'keap-connect' ),
			'OTHER'    => __( 'Other (OTHER)', 'keap-connect' ),
		);
	}

	/**
	 * Costruttore.
	 *
	 * @param KC_Settings $settings Impostazioni.
	 */
	public function __construct( KC_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Estrae un valore dal body in modo case-insensitive sulla chiave.
	 *
	 * @param array  $body Body.
	 * @param string $key  Chiave sorgente.
	 * @return mixed|null
	 */
	private function get_value( array $body, $key ) {
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
	 * Costruisce il payload del contatto a partire dal body.
	 *
	 * @param array $body Body in ingresso.
	 * @return array Payload Keap.
	 */
	public function build_payload( array $body ) {
		$payload   = array();
		$addresses = array();

		foreach ( $this->settings->get_field_map() as $row ) {
			$source      = isset( $row['source'] ) ? $row['source'] : '';
			$target_type = isset( $row['target_type'] ) ? $row['target_type'] : '';
			$target      = isset( $row['target'] ) ? $row['target'] : '';

			if ( '' === $source || '' === $target_type || '' === $target ) {
				continue;
			}

			$value = $this->get_value( $body, $source );
			if ( null === $value || '' === $value ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', $value ) );
			} else {
				$value = (string) $value;
			}

			switch ( $target_type ) {
				case 'standard':
					$this->apply_standard( $payload, $target, $value );
					break;

				case 'custom':
					if ( ! isset( $payload['custom_fields'] ) ) {
						$payload['custom_fields'] = array();
					}
					$payload['custom_fields'][] = array(
						'id'      => is_numeric( $target ) ? (int) $target : $target,
						'content' => $value,
					);
					break;

				case 'address':
					$type = isset( $row['address_type'] ) && '' !== $row['address_type'] ? $row['address_type'] : 'BILLING';
					if ( ! isset( $addresses[ $type ] ) ) {
						$addresses[ $type ] = array( 'field' => $type );
					}
					$addresses[ $type ][ $target ] = $value;
					break;
			}
		}

		if ( ! empty( $addresses ) ) {
			$payload['addresses'] = array_values( $addresses );
		}

		return $payload;
	}

	/**
	 * Applica un campo standard al payload.
	 *
	 * @param array  $payload Riferimento al payload.
	 * @param string $target  Campo target.
	 * @param string $value   Valore.
	 * @return void
	 */
	private function apply_standard( array &$payload, $target, $value ) {
		switch ( $target ) {
			case 'email':
				if ( ! isset( $payload['email_addresses'] ) ) {
					$payload['email_addresses'] = array();
				}
				$payload['email_addresses'][] = array(
					'field' => 'EMAIL1',
					'email' => $value,
				);
				break;

			case 'phone':
				if ( ! isset( $payload['phone_numbers'] ) ) {
					$payload['phone_numbers'] = array();
				}
				$payload['phone_numbers'][] = array(
					'field'  => 'PHONE1',
					'number' => $value,
				);
				break;

			default:
				$payload[ $target ] = $value;
				break;
		}
	}

	/**
	 * Estrae l'email dal body secondo la mappatura.
	 *
	 * @param array $body Body.
	 * @return string
	 */
	public function extract_email( array $body ) {
		$key   = $this->settings->email_source_key();
		$value = $this->get_value( $body, $key );
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}
		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Estrae gli ID dei tag dal body (campo 'Tag', singolo o lista separata da virgola).
	 *
	 * @param array  $body Body.
	 * @param string $key  Chiave del campo tag.
	 * @return array Lista di ID tag (int).
	 */
	public function extract_tag_ids( array $body, $key = 'Tag' ) {
		$value = $this->get_value( $body, $key );
		if ( null === $value || '' === $value ) {
			return array();
		}

		$raw = array();
		if ( is_array( $value ) ) {
			$raw = $value;
		} else {
			$raw = preg_split( '/[,\s]+/', (string) $value );
		}

		$ids = array();
		foreach ( $raw as $item ) {
			$item = trim( (string) $item );
			if ( '' !== $item && ctype_digit( $item ) ) {
				$ids[] = (int) $item;
			}
		}
		return array_values( array_unique( $ids ) );
	}
}
