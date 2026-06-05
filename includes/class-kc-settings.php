<?php
/**
 * Gestione impostazioni del plugin.
 *
 * @package KeapConnect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lettura/scrittura delle opzioni e helper.
 */
class KC_Settings {

	const OPTION_SETTINGS = 'kc_settings';
	const OPTION_FIELD_MAP = 'kc_field_map';
	const OPTION_KEAP_MODEL = 'kc_keap_model';

	const AUTH_OAUTH = 'oauth';
	const AUTH_PAT = 'pat';
	const AUTH_BOTH = 'both';

	const KEAP_API_BASE = 'https://api.infusionsoft.com/crm/rest/v2';
	const KEAP_TOKEN_URL = 'https://api.infusionsoft.com/token';
	const KEAP_AUTHORIZE_URL = 'https://accounts.infusionsoft.com/app/oauth/authorize';

	/**
	 * Chiavi salvate cifrate (reversibili) nel DB.
	 *
	 * @return array
	 */
	public static function encrypted_keys() {
		return array(
			'oauth_client_id',
			'oauth_client_secret',
			'oauth_access_token',
			'oauth_refresh_token',
			'pat_token',
		);
	}

	/**
	 * Ritorna le impostazioni (con segreti decifrati) e i default applicati.
	 *
	 * @return array
	 */
	public function all() {
		$defaults = $this->defaults();
		$saved    = get_option( self::OPTION_SETTINGS, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$merged = wp_parse_args( $saved, $defaults );

		foreach ( self::encrypted_keys() as $k ) {
			if ( isset( $merged[ $k ] ) && '' !== $merged[ $k ] ) {
				$merged[ $k ] = KC_Crypto::decrypt( $merged[ $k ] );
			}
		}

		return $merged;
	}

	/**
	 * Valori di default delle impostazioni.
	 *
	 * @return array
	 */
	public function defaults() {
		return array(
			'auth_method'         => self::AUTH_OAUTH,
			'oauth_client_id'     => '',
			'oauth_client_secret' => '',
			'oauth_access_token'  => '',
			'oauth_refresh_token' => '',
			'oauth_expires_at'    => 0,
			'oauth_scope'         => '',
			'pat_token'           => '',
			'require_bearer'      => true,
			'bearer_token_hash'   => '',
			'external_cron_secret_hash' => '',
			'enable_external_cron' => false,
			'create_if_missing'   => true,
			'log_retention_days'  => 30,
			'notifications_enabled' => false,
			'notify_include_admin'  => true,
			'notify_extra_email'    => '',
			'notify_on'             => 'errors',
			'last_check_status'   => '',
			'last_check_time'     => 0,
			'last_check_message'  => '',
			'last_check_token'    => '',
		);
	}

	/**
	 * Ritorna un singolo valore di impostazione.
	 *
	 * @param string $key     Chiave.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$all = $this->all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Aggiorna una o piu impostazioni.
	 *
	 * @param array $values Coppie chiave/valore.
	 * @return void
	 */
	public function update( array $values ) {
		$current = $this->all();
		$merged  = array_merge( $current, $values );

		foreach ( self::encrypted_keys() as $k ) {
			if ( isset( $merged[ $k ] ) && '' !== $merged[ $k ] ) {
				$merged[ $k ] = KC_Crypto::encrypt( $merged[ $k ] );
			}
		}

		update_option( self::OPTION_SETTINGS, $merged );
	}

	/**
	 * Imposta i default e genera i token random se mancanti (in attivazione).
	 *
	 * @return void
	 */
	public function maybe_seed_defaults() {
		// Nessun token generato automaticamente: Bearer e secret cron vengono
		// creati dall'utente con il pulsante "Genera" (cosi' da zero non esistono).
		$saved = get_option( self::OPTION_SETTINGS, null );
		if ( null === $saved ) {
			update_option( self::OPTION_SETTINGS, $this->defaults() );
		}
	}

	/**
	 * Migra i segreti gia' salvati: hash dei bearer/cron in chiaro e cifratura
	 * dei segreti Keap in chiaro. Eseguita una sola volta.
	 *
	 * @return void
	 */
	public function maybe_migrate_secrets() {
		if ( get_option( 'kc_secrets_migrated' ) ) {
			return;
		}

		$saved = get_option( self::OPTION_SETTINGS, null );
		if ( is_array( $saved ) ) {
			// Bearer legacy in chiaro -> hash.
			if ( empty( $saved['bearer_token_hash'] ) && ! empty( $saved['bearer_token'] ) ) {
				$saved['bearer_token_hash'] = KC_Crypto::hash_token( $saved['bearer_token'] );
			}
			unset( $saved['bearer_token'] );

			// Cron secret legacy in chiaro -> hash.
			if ( empty( $saved['external_cron_secret_hash'] ) && ! empty( $saved['external_cron_secret'] ) ) {
				$saved['external_cron_secret_hash'] = KC_Crypto::hash_token( $saved['external_cron_secret'] );
			}
			unset( $saved['external_cron_secret'] );

			// Segreti Keap in chiaro -> cifrati.
			foreach ( self::encrypted_keys() as $k ) {
				if ( ! empty( $saved[ $k ] ) && ! KC_Crypto::is_encrypted( $saved[ $k ] ) ) {
					$saved[ $k ] = KC_Crypto::encrypt( $saved[ $k ] );
				}
			}

			update_option( self::OPTION_SETTINGS, $saved );
		}

		update_option( 'kc_secrets_migrated', 1 );
	}

	/**
	 * Ri-cifra i segreti Keap con una nuova chiave e ruota Bearer/cron.
	 * Usata quando si installa una KC_ENCRYPTION_KEY dedicata.
	 *
	 * @param string $new_key Chiave binaria a 32 byte.
	 * @return array { bearer: string, cron: string } valori in chiaro (da mostrare una volta).
	 */
	public function rekey_secrets( $new_key ) {
		$plain = $this->all(); // decifrati con la chiave attuale.
		$raw   = get_option( self::OPTION_SETTINGS, array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		foreach ( self::encrypted_keys() as $k ) {
			$val       = isset( $plain[ $k ] ) ? (string) $plain[ $k ] : '';
			$raw[ $k ] = ( '' !== $val ) ? KC_Crypto::encrypt_with( $val, $new_key ) : '';
		}

		// Bearer/cron sono hashati: non recuperabili, quindi si rigenerano con la nuova chiave.
		$bearer = self::generate_token();
		$cron   = self::generate_token();
		$raw['bearer_token_hash']         = KC_Crypto::hash_token_with( $bearer, $new_key );
		$raw['external_cron_secret_hash'] = KC_Crypto::hash_token_with( $cron, $new_key );

		unset( $raw['bearer_token'], $raw['external_cron_secret'] );

		update_option( self::OPTION_SETTINGS, $raw );

		return array(
			'bearer' => $bearer,
			'cron'   => $cron,
		);
	}

	/**
	 * Indica se e' attiva una chiave di cifratura dedicata (KC_ENCRYPTION_KEY).
	 *
	 * @return bool
	 */
	public function has_dedicated_key() {
		return defined( 'KC_ENCRYPTION_KEY' ) && KC_ENCRYPTION_KEY;
	}

	/**
	 * Genera un token random sicuro.
	 *
	 * @return string
	 */
	public static function generate_token() {
		if ( function_exists( 'wp_generate_password' ) ) {
			return wp_generate_password( 48, false, false );
		}
		return bin2hex( random_bytes( 24 ) );
	}

	/**
	 * Ritorna l'URL dell'endpoint di intake.
	 *
	 * @return string
	 */
	public function intake_url() {
		return rest_url( 'keap-connect/v1/intake' );
	}

	/**
	 * Ritorna l'URL base dell'endpoint cron esterno (senza secret).
	 *
	 * @return string
	 */
	public function external_cron_url() {
		return rest_url( 'keap-connect/v1/cron' );
	}

	/**
	 * Ritorna la mappatura dei campi (array di righe).
	 *
	 * @return array
	 */
	public function get_field_map() {
		$map = get_option( self::OPTION_FIELD_MAP, null );
		if ( null === $map || ! is_array( $map ) ) {
			return $this->default_field_map();
		}
		return $map;
	}

	/**
	 * Salva la mappatura dei campi.
	 *
	 * @param array $map Righe di mappatura.
	 * @return void
	 */
	public function save_field_map( array $map ) {
		update_option( self::OPTION_FIELD_MAP, array_values( $map ) );
	}

	/**
	 * Mappatura di default.
	 *
	 * @return array
	 */
	public function default_field_map() {
		return array(
			array(
				'source'      => 'Nome',
				'target_type' => 'standard',
				'target'      => 'given_name',
				'address_type' => '',
			),
			array(
				'source'      => 'Cognome',
				'target_type' => 'standard',
				'target'      => 'family_name',
				'address_type' => '',
			),
			array(
				'source'      => 'Email',
				'target_type' => 'standard',
				'target'      => 'email',
				'address_type' => '',
			),
			array(
				'source'      => 'Telefono',
				'target_type' => 'standard',
				'target'      => 'phone',
				'address_type' => '',
			),
		);
	}

	/**
	 * Chiave del body usata per estrarre l'email (prima riga mappata su 'email').
	 *
	 * @return string
	 */
	public function email_source_key() {
		foreach ( $this->get_field_map() as $row ) {
			if ( isset( $row['target_type'], $row['target'] ) && 'standard' === $row['target_type'] && 'email' === $row['target'] ) {
				return isset( $row['source'] ) ? $row['source'] : 'Email';
			}
		}
		return 'Email';
	}

	/**
	 * Salva il modello (custom field) recuperato da Keap.
	 *
	 * @param array $model Dati modello.
	 * @return void
	 */
	public function save_keap_model( array $model ) {
		$model['fetched_at'] = time();
		update_option( self::OPTION_KEAP_MODEL, $model );
	}

	/**
	 * Ritorna il modello salvato.
	 *
	 * @return array
	 */
	public function get_keap_model() {
		$model = get_option( self::OPTION_KEAP_MODEL, array() );
		return is_array( $model ) ? $model : array();
	}

	/**
	 * Ritorna i campi custom salvati (array di {id, label, field_type}).
	 *
	 * @return array
	 */
	public function get_custom_fields() {
		$model = $this->get_keap_model();
		return isset( $model['custom_fields'] ) && is_array( $model['custom_fields'] ) ? $model['custom_fields'] : array();
	}
}
