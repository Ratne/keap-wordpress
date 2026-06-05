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
	 * Ritorna le impostazioni con i default applicati.
	 *
	 * @return array
	 */
	public function all() {
		$defaults = $this->defaults();
		$saved    = get_option( self::OPTION_SETTINGS, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, $defaults );
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
			'bearer_token'        => '',
			'external_cron_secret' => '',
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
		update_option( self::OPTION_SETTINGS, $merged );
	}

	/**
	 * Imposta i default e genera i token random se mancanti (in attivazione).
	 *
	 * @return void
	 */
	public function maybe_seed_defaults() {
		$saved = get_option( self::OPTION_SETTINGS, null );
		if ( null === $saved ) {
			$saved = $this->defaults();
		}
		if ( empty( $saved['bearer_token'] ) ) {
			$saved['bearer_token'] = self::generate_token();
		}
		if ( empty( $saved['external_cron_secret'] ) ) {
			$saved['external_cron_secret'] = self::generate_token();
		}
		update_option( self::OPTION_SETTINGS, wp_parse_args( $saved, $this->defaults() ) );
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
	 * Ritorna l'URL dell'endpoint cron esterno (con secret).
	 *
	 * @return string
	 */
	public function external_cron_url() {
		$secret = $this->get( 'external_cron_secret' );
		return add_query_arg( 'secret', rawurlencode( $secret ), rest_url( 'keap-connect/v1/cron' ) );
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
