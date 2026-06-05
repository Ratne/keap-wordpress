<?php
/**
 * Logger su tabella custom.
 *
 * @package KeapConnect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scrive/legge i log delle richieste in ingresso e verso Keap.
 */
class KC_Logger {

	/**
	 * Nome tabella senza prefisso.
	 */
	const TABLE = 'kc_logs';

	const DIR_IN = 'in';
	const DIR_OUT = 'out';

	const STATUS_RECEIVED = 'received';
	const STATUS_SUCCESS = 'success';
	const STATUS_ERROR = 'error';
	const STATUS_SKIPPED = 'skipped';

	/**
	 * Ritorna il nome completo della tabella.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Crea/aggiorna la tabella di log.
	 *
	 * @return void
	 */
	public static function install_table() {
		global $wpdb;
		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			correlation_id VARCHAR(64) NOT NULL DEFAULT '',
			direction VARCHAR(8) NOT NULL DEFAULT '',
			method VARCHAR(10) NOT NULL DEFAULT '',
			endpoint TEXT NULL,
			request_body LONGTEXT NULL,
			response_code INT(11) NULL,
			response_body LONGTEXT NULL,
			contact_id VARCHAR(64) NULL,
			email VARCHAR(191) NULL,
			status VARCHAR(20) NOT NULL DEFAULT '',
			message TEXT NULL,
			PRIMARY KEY  (id),
			KEY correlation_id (correlation_id),
			KEY created_at (created_at),
			KEY status (status)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'kc_db_version', KC_VERSION );
	}

	/**
	 * Genera un correlation id.
	 *
	 * @return string
	 */
	public static function new_correlation_id() {
		return 'kc_' . gmdate( 'Ymd_His' ) . '_' . wp_generate_password( 8, false, false );
	}

	/**
	 * Inserisce una riga di log.
	 *
	 * @param array $data Dati riga.
	 * @return int ID inserito.
	 */
	public function log( array $data ) {
		global $wpdb;

		$row = wp_parse_args(
			$data,
			array(
				'created_at'     => current_time( 'mysql', true ),
				'correlation_id' => '',
				'direction'      => '',
				'method'         => '',
				'endpoint'       => '',
				'request_body'   => '',
				'response_code'  => null,
				'response_body'  => '',
				'contact_id'     => '',
				'email'          => '',
				'status'         => '',
				'message'        => '',
			)
		);

		$row['request_body']  = self::truncate( self::mask_secrets( self::stringify( $row['request_body'] ) ) );
		$row['response_body'] = self::truncate( self::mask_secrets( self::stringify( $row['response_body'] ) ) );
		$row['endpoint']      = self::truncate( self::mask_secrets( (string) $row['endpoint'] ), 2048 );
		$row['message']       = self::truncate( (string) $row['message'], 2048 );
		$row['email']         = self::truncate( (string) $row['email'], 191 );

		$wpdb->insert(
			self::table_name(),
			array(
				'created_at'     => $row['created_at'],
				'correlation_id' => $row['correlation_id'],
				'direction'      => $row['direction'],
				'method'         => $row['method'],
				'endpoint'       => $row['endpoint'],
				'request_body'   => $row['request_body'],
				'response_code'  => $row['response_code'],
				'response_body'  => $row['response_body'],
				'contact_id'     => (string) $row['contact_id'],
				'email'          => $row['email'],
				'status'         => $row['status'],
				'message'        => $row['message'],
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Tronca una stringa a una lunghezza massima per evitare bloat/DoS del DB.
	 *
	 * @param string $text Testo.
	 * @param int    $max  Lunghezza massima in byte.
	 * @return string
	 */
	private static function truncate( $text, $max = 65535 ) {
		$text = (string) $text;
		if ( strlen( $text ) <= $max ) {
			return $text;
		}
		return substr( $text, 0, $max - 15 ) . '...[truncated]';
	}

	/**
	 * Converte un valore in stringa (JSON per array/oggetti).
	 *
	 * @param mixed $value Valore.
	 * @return string
	 */
	private static function stringify( $value ) {
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( null === $value ) {
			return '';
		}
		return wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Maschera eventuali token/secret nel testo dei log.
	 *
	 * @param string $text Testo.
	 * @return string
	 */
	public static function mask_secrets( $text ) {
		if ( '' === $text || null === $text ) {
			return '';
		}
		$text = preg_replace( '/(Bearer\s+)[A-Za-z0-9._\-]+/i', '$1***', $text );
		$text = preg_replace( '/("?(?:access_token|refresh_token|client_secret|pat_token|secret)"?\s*[:=]\s*"?)([A-Za-z0-9._\-]+)/i', '$1***', $text );
		return $text;
	}

	/**
	 * Recupera i log con filtri e paginazione.
	 *
	 * @param array $args Filtri.
	 * @return array
	 */
	public function query( array $args = array() ) {
		global $wpdb;
		$args = wp_parse_args(
			$args,
			array(
				'per_page'       => 25,
				'page'           => 1,
				'status'         => '',
				'search'         => '',
				'correlation_id' => '',
			)
		);

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( '' !== $args['correlation_id'] ) {
			$where[]  = 'correlation_id = %s';
			$params[] = $args['correlation_id'];
		}
		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(email LIKE %s OR contact_id LIKE %s OR request_body LIKE %s OR response_body LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );
		$table     = self::table_name();

		$per_page = max( 1, (int) $args['per_page'] );
		$page     = max( 1, (int) $args['page'] );
		$offset   = ( $page - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );

		$list_params = array_merge( $params, array( $per_page, $offset ) );
		$list_sql    = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A );

		return array(
			'rows'     => $rows ? $rows : array(),
			'total'    => $total,
			'per_page' => $per_page,
			'page'     => $page,
			'pages'    => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * Recupera tutte le righe di una correlazione.
	 *
	 * @param string $correlation_id ID correlazione.
	 * @return array
	 */
	public function get_by_correlation( $correlation_id ) {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE correlation_id = %s ORDER BY id ASC", $correlation_id ), ARRAY_A );
		return $rows ? $rows : array();
	}

	/**
	 * Recupera il body in ingresso (JSON) salvato per una correlazione.
	 *
	 * @param string $correlation_id ID correlazione.
	 * @return string Body grezzo (stringa) o vuoto.
	 */
	public function get_incoming_body( $correlation_id ) {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$body = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT request_body FROM {$table} WHERE correlation_id = %s AND direction = %s AND request_body <> '' ORDER BY id ASC LIMIT 1",
				$correlation_id,
				self::DIR_IN
			)
		);
		return is_string( $body ) ? $body : '';
	}

	/**
	 * Recupera gli ultimi errori, opzionalmente filtrati su endpoint/messaggio.
	 *
	 * @param int    $limit Numero massimo di righe.
	 * @param string $like  Filtro LIKE su endpoint o messaggio (es. 'token').
	 * @return array
	 */
	public function get_recent_errors( $limit = 5, $like = '' ) {
		global $wpdb;
		$table = self::table_name();
		$limit = max( 1, (int) $limit );

		if ( '' !== $like ) {
			$pattern = '%' . $wpdb->esc_like( $like ) . '%';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s AND ( endpoint LIKE %s OR message LIKE %s ) ORDER BY id DESC LIMIT %d",
				self::STATUS_ERROR,
				$pattern,
				$pattern,
				$limit
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d",
				self::STATUS_ERROR,
				$limit
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return $rows ? $rows : array();
	}

	/**
	 * Svuota completamente i log.
	 *
	 * @return void
	 */
	public function clear_all() {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}

	/**
	 * Elimina i log piu vecchi del numero di giorni indicato.
	 *
	 * @param int $days Giorni di retention.
	 * @return void
	 */
	public function purge_old( $days ) {
		$days = (int) $days;
		if ( $days <= 0 ) {
			return;
		}
		global $wpdb;
		$table  = self::table_name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
	}
}
