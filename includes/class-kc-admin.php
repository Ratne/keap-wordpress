<?php
/**
 * Interfaccia di amministrazione.
 *
 * @package KeapConnect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gestisce menu, form e rendering delle schede admin.
 */
class KC_Admin {

	const PAGE_SLUG = 'keap-connect';
	const CAPABILITY = 'manage_options';

	/**
	 * @var KC_Settings
	 */
	private $settings;

	/**
	 * @var KC_Auth
	 */
	private $auth;

	/**
	 * @var KC_Keap_Client
	 */
	private $client;

	/**
	 * @var KC_Logger
	 */
	private $logger;

	/**
	 * @var KC_Processor
	 */
	private $processor;

	/**
	 * @var KC_Notifier
	 */
	private $notifier;

	/**
	 * Costruttore.
	 *
	 * @param KC_Settings    $settings  Impostazioni.
	 * @param KC_Auth        $auth      Auth.
	 * @param KC_Keap_Client $client    Client.
	 * @param KC_Logger      $logger    Logger.
	 * @param KC_Processor   $processor Processor.
	 * @param KC_Notifier    $notifier  Notifier.
	 */
	public function __construct( KC_Settings $settings, KC_Auth $auth, KC_Keap_Client $client, KC_Logger $logger, KC_Processor $processor, KC_Notifier $notifier ) {
		$this->settings  = $settings;
		$this->auth      = $auth;
		$this->client    = $client;
		$this->logger    = $logger;
		$this->processor = $processor;
		$this->notifier  = $notifier;
	}

	/**
	 * Registra gli hook admin.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_init', array( $this, 'maybe_handle_oauth_callback' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Aggiunge la voce di menu.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_menu_page(
			__( 'Keap Connect', 'keap-connect' ),
			__( 'Keap Connect', 'keap-connect' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			'dashicons-share-alt',
			58
		);
	}

	/**
	 * Carica gli asset admin solo nella pagina del plugin.
	 *
	 * @param string $hook Hook corrente.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		$css_path = KC_PLUGIN_DIR . 'admin/assets/admin.css';
		$js_path  = KC_PLUGIN_DIR . 'admin/assets/admin.js';
		$css_ver  = file_exists( $css_path ) ? filemtime( $css_path ) : KC_VERSION;
		$js_ver   = file_exists( $js_path ) ? filemtime( $js_path ) : KC_VERSION;

		wp_enqueue_style( 'kc-admin', KC_PLUGIN_URL . 'admin/assets/admin.css', array(), $css_ver );
		wp_enqueue_script( 'kc-admin', KC_PLUGIN_URL . 'admin/assets/admin.js', array( 'jquery' ), $js_ver, true );
		wp_localize_script(
			'kc-admin',
			'kcAdmin',
			array(
				'i18n' => array(
					'show' => __( 'Show', 'keap-connect' ),
					'hide' => __( 'Hide', 'keap-connect' ),
				),
			)
		);
	}

	/**
	 * URI di redirect OAuth (pagina di connessione).
	 *
	 * @return string
	 */
	private function oauth_redirect_uri() {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=connessione' );
	}

	/**
	 * Ritorna la scheda corrente.
	 *
	 * @return string
	 */
	private function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'connessione';
		$valid = array( 'connessione', 'endpoint', 'mappatura', 'log' );
		return in_array( $tab, $valid, true ) ? $tab : 'connessione';
	}

	/**
	 * URL di una scheda.
	 *
	 * @param string $tab Scheda.
	 * @return string
	 */
	private function tab_url( $tab ) {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=' . $tab );
	}

	/**
	 * Gestisce le azioni dei form.
	 *
	 * @return void
	 */
	public function handle_actions() {
		if ( ! isset( $_POST['kc_action'] ) || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$action = sanitize_key( wp_unslash( $_POST['kc_action'] ) );
		check_admin_referer( 'kc_' . $action );

		switch ( $action ) {
			case 'save_connection':
				$this->save_connection();
				break;
			case 'oauth_connect':
				$this->oauth_connect();
				break;
			case 'oauth_disconnect':
				$this->auth->disconnect_oauth();
				$this->redirect_notice( 'connessione', 'oauth_disconnected' );
				break;
			case 'test_connection':
				$this->test_connection();
				break;
			case 'save_endpoint':
				$this->save_endpoint();
				break;
			case 'rotate_bearer':
				$token = KC_Settings::generate_token();
				$this->settings->update( array( 'bearer_token' => $token ) );
				// Mostra il valore una sola volta dopo la rigenerazione.
				set_transient( 'kc_show_bearer_' . get_current_user_id(), $token, 5 * MINUTE_IN_SECONDS );
				$this->redirect_notice( 'endpoint', 'bearer_rotated' );
				break;
			case 'rotate_cron_secret':
				$secret = KC_Settings::generate_token();
				$this->settings->update( array( 'external_cron_secret' => $secret ) );
				set_transient( 'kc_show_cron_' . get_current_user_id(), $secret, 5 * MINUTE_IN_SECONDS );
				$this->redirect_notice( 'endpoint', 'cron_rotated' );
				break;
			case 'save_mapping':
				$this->save_mapping();
				break;
			case 'refresh_fields':
				$this->refresh_fields();
				break;
			case 'clear_logs':
				$this->logger->clear_all();
				$this->redirect_notice( 'log', 'logs_cleared' );
				break;
			case 'replay_lead':
				$this->replay_lead();
				break;
			case 'send_test_email':
				$result = $this->notifier->test();
				if ( empty( $result['recipients'] ) ) {
					$this->redirect_notice( 'endpoint', 'test_email_norcpt' );
				}
				$this->redirect_notice( 'endpoint', $result['sent'] ? 'test_email_sent' : 'test_email_fail' );
				break;
		}
	}

	/**
	 * Re-invia un lead (eventualmente con body modificato) e ri-logga l'esito.
	 *
	 * @return void
	 */
	private function replay_lead() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verificato in handle_actions.
		$raw = isset( $_POST['kc_body'] ) ? wp_unslash( $_POST['kc_body'] ) : '';
		$raw = is_string( $raw ) ? trim( $raw ) : '';

		$body = json_decode( $raw, true );
		if ( ! is_array( $body ) || empty( $body ) ) {
			$this->redirect_notice( 'log', 'replay_invalid' );
		}

		$correlation_id = KC_Logger::new_correlation_id();

		// Logga il body (ri)inviato manualmente.
		$this->logger->log(
			array(
				'correlation_id' => $correlation_id,
				'direction'      => KC_Logger::DIR_IN,
				'method'         => 'REPLAY',
				'endpoint'       => '/intake (replay)',
				'request_body'   => $body,
				'status'         => KC_Logger::STATUS_RECEIVED,
				'message'        => __( 'Manual replay from admin.', 'keap-connect' ),
			)
		);

		$this->processor->process( $body, $correlation_id );

		wp_safe_redirect(
			add_query_arg(
				array(
					'tab'            => 'log',
					'correlation_id' => $correlation_id,
					'kc_notice'      => 'replay_done',
				),
				$this->tab_url( 'log' )
			)
		);
		exit;
	}

	/**
	 * Salva le impostazioni di connessione.
	 *
	 * @return void
	 */
	private function save_connection() {
		$method = isset( $_POST['auth_method'] ) ? sanitize_key( wp_unslash( $_POST['auth_method'] ) ) : KC_Settings::AUTH_OAUTH;
		if ( ! in_array( $method, array( KC_Settings::AUTH_OAUTH, KC_Settings::AUTH_PAT, KC_Settings::AUTH_BOTH ), true ) ) {
			$method = KC_Settings::AUTH_OAUTH;
		}

		$this->settings->update(
			array(
				'auth_method'         => $method,
				'oauth_client_id'     => isset( $_POST['oauth_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['oauth_client_id'] ) ) : '',
				'oauth_client_secret' => isset( $_POST['oauth_client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['oauth_client_secret'] ) ) : '',
				'pat_token'           => isset( $_POST['pat_token'] ) ? sanitize_text_field( wp_unslash( $_POST['pat_token'] ) ) : '',
			)
		);

		// Esegue subito una verifica per aggiornare il semaforo, se c'e' almeno un token disponibile.
		if ( $this->auth->get_tokens_to_try() ) {
			$this->run_connection_check();
		} else {
			$this->settings->update(
				array(
					'last_check_status'  => '',
					'last_check_time'    => 0,
					'last_check_message' => '',
					'last_check_token'   => '',
				)
			);
		}

		$this->redirect_notice( 'connessione', 'connection_saved' );
	}

	/**
	 * Avvia il flusso OAuth.
	 *
	 * @return void
	 */
	private function oauth_connect() {
		$client_id = $this->settings->get( 'oauth_client_id' );
		if ( empty( $client_id ) ) {
			$this->redirect_notice( 'connessione', 'oauth_missing_creds' );
		}
		$state = wp_generate_password( 20, false, false );
		set_transient( 'kc_oauth_state', $state, 15 * MINUTE_IN_SECONDS );
		$url = $this->auth->get_authorization_url( $this->oauth_redirect_uri(), $state );
		wp_redirect( $url );
		exit;
	}

	/**
	 * Gestisce il callback OAuth da Keap.
	 *
	 * @return void
	 */
	public function maybe_handle_oauth_callback() {
		if ( ! is_admin() || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		$correlation = KC_Logger::new_correlation_id();

		// Keap returned an error to the redirect URI instead of an authorization code.
		if ( isset( $_GET['error'] ) ) {
			$err  = sanitize_text_field( wp_unslash( $_GET['error'] ) );
			$desc = isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) ) : '';
			$this->logger->log(
				array(
					'correlation_id' => $correlation,
					'direction'      => KC_Logger::DIR_IN,
					'method'         => 'GET',
					'endpoint'       => $this->oauth_redirect_uri(),
					'status'         => KC_Logger::STATUS_ERROR,
					'message'        => 'OAuth callback error: ' . $err . ' ' . $desc,
				)
			);
			$this->redirect_notice( 'connessione', 'oauth_error' );
		}

		if ( ! isset( $_GET['code'] ) ) {
			return;
		}

		$state    = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$expected = get_transient( 'kc_oauth_state' );
		delete_transient( 'kc_oauth_state' );

		// Real CSRF mismatch: a state was stored but does not match the one returned.
		if ( ! empty( $expected ) && ! hash_equals( (string) $expected, $state ) ) {
			$this->logger->log(
				array(
					'correlation_id' => $correlation,
					'direction'      => KC_Logger::DIR_IN,
					'method'         => 'GET',
					'endpoint'       => $this->oauth_redirect_uri(),
					'status'         => KC_Logger::STATUS_ERROR,
					'message'        => 'OAuth state mismatch (possible CSRF or duplicate attempt).',
				)
			);
			$this->redirect_notice( 'connessione', 'oauth_state_error' );
		}

		// State transient missing (es. object cache non condivisa / scaduto): proseguiamo
		// perche' l'avvio del flusso era protetto da nonce, ma lo segnaliamo nel log.
		if ( empty( $expected ) ) {
			$this->logger->log(
				array(
					'correlation_id' => $correlation,
					'direction'      => KC_Logger::DIR_IN,
					'method'         => 'GET',
					'endpoint'       => $this->oauth_redirect_uri(),
					'status'         => KC_Logger::STATUS_RECEIVED,
					'message'        => 'OAuth state not found (missing or expired transient): proceeding with code exchange.',
				)
			);
		}

		$code   = sanitize_text_field( wp_unslash( $_GET['code'] ) );
		$result = $this->auth->exchange_code( $code, $this->oauth_redirect_uri() );

		if ( is_wp_error( $result ) ) {
			$this->logger->log(
				array(
					'correlation_id' => $correlation,
					'direction'      => KC_Logger::DIR_IN,
					'status'         => KC_Logger::STATUS_ERROR,
					'message'        => 'OAuth code exchange failed: ' . $result->get_error_message(),
				)
			);
			$this->redirect_notice( 'connessione', 'oauth_error' );
		}
		// Riconnesso: azzera la guardia degli alert OAuth.
		delete_transient( 'kc_oauth_alert_sent' );
		$this->redirect_notice( 'connessione', 'oauth_connected' );
	}

	/**
	 * Testa la connessione recuperando il modello.
	 *
	 * @return void
	 */
	private function test_connection() {
		$result = $this->run_connection_check();
		$this->redirect_notice( 'connessione', $result['success'] ? 'test_ok' : 'test_fail' );
	}

	/**
	 * Esegue una verifica live verso Keap e memorizza l'esito (per il semaforo).
	 *
	 * @return array Risultato della chiamata.
	 */
	private function run_connection_check() {
		$result = $this->client->get_contact_model( KC_Logger::new_correlation_id() );

		$message = '';
		if ( $result['success'] ) {
			$message = isset( $result['token_type'] ) ? $result['token_type'] : '';
		} else {
			$message = ( '' !== $result['error'] ) ? $result['error'] : ( $result['code'] ? 'HTTP ' . $result['code'] : 'error' );
		}

		$this->settings->update(
			array(
				'last_check_status'  => $result['success'] ? 'ok' : 'fail',
				'last_check_time'    => time(),
				'last_check_message' => is_string( $message ) ? $message : '',
				'last_check_token'   => isset( $result['token_type'] ) ? $result['token_type'] : '',
			)
		);

		return $result;
	}

	/**
	 * Salva le impostazioni dell'endpoint.
	 *
	 * @return void
	 */
	private function save_endpoint() {
		$notify_on = isset( $_POST['notify_on'] ) ? sanitize_key( wp_unslash( $_POST['notify_on'] ) ) : 'errors';
		if ( ! in_array( $notify_on, array( 'errors', 'all' ), true ) ) {
			$notify_on = 'errors';
		}

		$extra_email = '';
		if ( isset( $_POST['notify_extra_email'] ) ) {
			$candidate = sanitize_email( wp_unslash( $_POST['notify_extra_email'] ) );
			if ( '' !== $candidate && is_email( $candidate ) ) {
				$extra_email = $candidate;
			}
		}

		$this->settings->update(
			array(
				'require_bearer'        => ! empty( $_POST['require_bearer'] ),
				'enable_external_cron'  => ! empty( $_POST['enable_external_cron'] ),
				'create_if_missing'     => ! empty( $_POST['create_if_missing'] ),
				'log_retention_days'    => isset( $_POST['log_retention_days'] ) ? max( 0, (int) $_POST['log_retention_days'] ) : 30,
				'notifications_enabled' => ! empty( $_POST['notifications_enabled'] ),
				'notify_include_admin'  => ! empty( $_POST['notify_include_admin'] ),
				'notify_extra_email'    => $extra_email,
				'notify_on'             => $notify_on,
			)
		);
		$this->redirect_notice( 'endpoint', 'endpoint_saved' );
	}

	/**
	 * Salva la mappatura dei campi.
	 *
	 * @return void
	 */
	private function save_mapping() {
		$rows = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verificato in handle_actions.
		$raw  = isset( $_POST['kc_map'] ) ? wp_unslash( $_POST['kc_map'] ) : array();

		if ( is_array( $raw ) ) {
			foreach ( $raw as $item ) {
				$source = isset( $item['source'] ) ? sanitize_text_field( $item['source'] ) : '';
				$type   = isset( $item['target_type'] ) ? sanitize_key( $item['target_type'] ) : '';
				if ( '' === $source || ! in_array( $type, array( 'standard', 'custom', 'address' ), true ) ) {
					continue;
				}

				$target       = '';
				$address_type = '';
				switch ( $type ) {
					case 'standard':
						$target = isset( $item['target_standard'] ) ? sanitize_text_field( $item['target_standard'] ) : '';
						break;
					case 'custom':
						$target = isset( $item['target_custom'] ) ? sanitize_text_field( $item['target_custom'] ) : '';
						break;
					case 'address':
						$target       = isset( $item['target_address'] ) ? sanitize_text_field( $item['target_address'] ) : '';
						$address_type = isset( $item['address_type'] ) ? sanitize_text_field( $item['address_type'] ) : 'BILLING';
						break;
				}

				if ( '' === $target ) {
					continue;
				}

				$rows[] = array(
					'source'       => $source,
					'target_type'  => $type,
					'target'       => $target,
					'address_type' => $address_type,
				);
			}
		}

		$this->settings->save_field_map( $rows );
		$this->redirect_notice( 'mappatura', 'mapping_saved' );
	}

	/**
	 * Recupera i custom field da Keap e li salva.
	 *
	 * @return void
	 */
	private function refresh_fields() {
		$result = $this->client->get_contact_model( KC_Logger::new_correlation_id() );
		if ( ! $result['success'] || ! is_array( $result['data'] ) ) {
			$this->redirect_notice( 'mappatura', 'fields_error' );
		}

		$custom_fields = array();
		$source        = isset( $result['data']['custom_fields'] ) ? $result['data']['custom_fields'] : array();
		if ( is_array( $source ) ) {
			foreach ( $source as $cf ) {
				if ( ! is_array( $cf ) ) {
					continue;
				}
				$id = isset( $cf['id'] ) ? $cf['id'] : ( isset( $cf['field_id'] ) ? $cf['field_id'] : '' );
				if ( '' === $id ) {
					continue;
				}
				$label = '';
				foreach ( array( 'label', 'name', 'field_name' ) as $lk ) {
					if ( ! empty( $cf[ $lk ] ) ) {
						$label = $cf[ $lk ];
						break;
					}
				}
				$custom_fields[] = array(
					'id'         => (string) $id,
					'label'      => $label ? $label : (string) $id,
					'field_type' => isset( $cf['field_type'] ) ? $cf['field_type'] : ( isset( $cf['type'] ) ? $cf['type'] : '' ),
				);
			}
		}

		$this->settings->save_keap_model( array( 'custom_fields' => $custom_fields ) );
		$this->redirect_notice( 'mappatura', 'fields_refreshed' );
	}

	/**
	 * Reindirizza con una notice.
	 *
	 * @param string $tab    Scheda.
	 * @param string $notice Codice notice.
	 * @return void
	 */
	private function redirect_notice( $tab, $notice ) {
		wp_safe_redirect( add_query_arg( 'kc_notice', $notice, $this->tab_url( $tab ) ) );
		exit;
	}

	/**
	 * Stampa la notice corrente.
	 *
	 * @return void
	 */
	private function print_notice() {
		if ( ! isset( $_GET['kc_notice'] ) ) {
			return;
		}
		$code = sanitize_key( wp_unslash( $_GET['kc_notice'] ) );
		$map  = array(
			'connection_saved'   => array( 'success', __( 'Connection settings saved.', 'keap-connect' ) ),
			'oauth_connected'    => array( 'success', __( 'OAuth connected successfully.', 'keap-connect' ) ),
			'oauth_disconnected' => array( 'success', __( 'OAuth disconnected.', 'keap-connect' ) ),
			'oauth_error'        => array( 'error', __( 'Error during OAuth token exchange. Check the logs.', 'keap-connect' ) ),
			'oauth_state_error'  => array( 'error', __( 'OAuth state verification failed. Please try again.', 'keap-connect' ) ),
			'oauth_missing_creds' => array( 'error', __( 'Enter and save Client ID/Secret before connecting OAuth.', 'keap-connect' ) ),
			'test_ok'            => array( 'success', __( 'Connection to Keap successful.', 'keap-connect' ) ),
			'test_fail'          => array( 'error', __( 'Connection to Keap failed. Check the logs.', 'keap-connect' ) ),
			'endpoint_saved'     => array( 'success', __( 'Endpoint settings saved.', 'keap-connect' ) ),
			'bearer_rotated'     => array( 'success', __( 'Bearer token regenerated.', 'keap-connect' ) ),
			'cron_rotated'       => array( 'success', __( 'Cron secret regenerated.', 'keap-connect' ) ),
			'mapping_saved'      => array( 'success', __( 'Mapping saved.', 'keap-connect' ) ),
			'fields_refreshed'   => array( 'success', __( 'Custom fields updated from Keap.', 'keap-connect' ) ),
			'fields_error'       => array( 'error', __( 'Unable to fetch fields from Keap. Check the logs.', 'keap-connect' ) ),
			'logs_cleared'       => array( 'success', __( 'Logs cleared.', 'keap-connect' ) ),
			'replay_done'        => array( 'success', __( 'Lead replayed. See the new result below.', 'keap-connect' ) ),
			'replay_invalid'     => array( 'error', __( 'Invalid JSON body: nothing was sent.', 'keap-connect' ) ),
			'test_email_sent'    => array( 'success', __( 'Test email sent to the configured recipients.', 'keap-connect' ) ),
			'test_email_norcpt'  => array( 'error', __( 'No recipients configured: set the additional email and/or include the administrator.', 'keap-connect' ) ),
			'test_email_fail'    => array( 'error', __( 'Test email could not be sent. Check your site mail configuration.', 'keap-connect' ) ),
		);
		if ( ! isset( $map[ $code ] ) ) {
			return;
		}
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $map[ $code ][0] ),
			esc_html( $map[ $code ][1] )
		);
	}

	/**
	 * Render della pagina con le schede.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$tab = $this->current_tab();
		echo '<div class="wrap kc-wrap">';
		echo '<h1>' . esc_html__( 'Keap Connect', 'keap-connect' ) . '</h1>';
		$this->print_notice();

		$tabs = array(
			'connessione' => __( 'Connection', 'keap-connect' ),
			'endpoint'    => __( 'Endpoint', 'keap-connect' ),
			'mappatura'   => __( 'Mapping', 'keap-connect' ),
			'log'         => __( 'Log', 'keap-connect' ),
		);
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab %2$s">%3$s</a>',
				esc_url( $this->tab_url( $slug ) ),
				$slug === $tab ? 'nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</h2>';

		switch ( $tab ) {
			case 'endpoint':
				$this->render_endpoint_tab();
				break;
			case 'mappatura':
				$this->render_mapping_tab();
				break;
			case 'log':
				$this->render_log_tab();
				break;
			default:
				$this->render_connection_tab();
				break;
		}

		echo '</div>';
	}

	/**
	 * Scheda Connessione.
	 *
	 * @return void
	 */
	private function render_connection_tab() {
		$s = $this->settings->all();

		$check_status = isset( $s['last_check_status'] ) ? $s['last_check_status'] : '';
		if ( 'ok' === $check_status ) {
			$dot_class   = 'kc-dot-green';
			$dot_label   = __( 'Connection working', 'keap-connect' );
		} elseif ( 'fail' === $check_status ) {
			$dot_class   = 'kc-dot-red';
			$dot_label   = __( 'Connection not working', 'keap-connect' );
		} else {
			$dot_class   = 'kc-dot-grey';
			$dot_label   = __( 'Not tested yet', 'keap-connect' );
		}
		?>
		<div class="kc-status-panel">
			<span class="kc-dot <?php echo esc_attr( $dot_class ); ?>" title="<?php echo esc_attr( $dot_label ); ?>"></span>
			<strong><?php echo esc_html( $dot_label ); ?></strong>
			<?php if ( ! empty( $s['last_check_time'] ) ) : ?>
				<span class="description">
					<?php
					printf(
						/* translators: %s: date */
						esc_html__( 'Last test: %s', 'keap-connect' ),
						esc_html( wp_date( 'Y-m-d H:i', (int) $s['last_check_time'] ) )
					);
					if ( 'ok' === $check_status && ! empty( $s['last_check_token'] ) ) {
						echo ' &middot; ' . esc_html( strtoupper( $s['last_check_token'] ) );
					}
					?>
				</span>
				<?php if ( 'fail' === $check_status && ! empty( $s['last_check_message'] ) ) : ?>
					<span class="description kc-status-detail"><?php echo esc_html( $s['last_check_message'] ); ?></span>
				<?php endif; ?>
			<?php endif; ?>
		</div>

		<form method="post">
			<?php wp_nonce_field( 'kc_save_connection' ); ?>
			<input type="hidden" name="kc_action" value="save_connection" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Authentication method', 'keap-connect' ); ?></th>
					<td>
						<select name="auth_method">
							<option value="oauth" <?php selected( $s['auth_method'], 'oauth' ); ?>><?php esc_html_e( 'OAuth only', 'keap-connect' ); ?></option>
							<option value="pat" <?php selected( $s['auth_method'], 'pat' ); ?>><?php esc_html_e( 'PAT/SAK only', 'keap-connect' ); ?></option>
							<option value="both" <?php selected( $s['auth_method'], 'both' ); ?>><?php esc_html_e( 'Both (OAuth primary, PAT/SAK fallback)', 'keap-connect' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'With "Both", if the OAuth call fails authentication it is retried with the PAT/SAK.', 'keap-connect' ); ?></p>
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'OAuth', 'keap-connect' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Client ID', 'keap-connect' ); ?></th>
					<td><input type="text" class="regular-text" name="oauth_client_id" value="<?php echo esc_attr( $s['oauth_client_id'] ); ?>" autocomplete="off" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Client Secret', 'keap-connect' ); ?></th>
					<td><input type="password" class="regular-text" name="oauth_client_secret" value="<?php echo esc_attr( $s['oauth_client_secret'] ); ?>" autocomplete="off" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Redirect URI', 'keap-connect' ); ?></th>
					<td>
						<code><?php echo esc_html( $this->oauth_redirect_uri() ); ?></code>
						<p class="description"><?php esc_html_e( 'Register this URI in your Keap app settings.', 'keap-connect' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'OAuth status', 'keap-connect' ); ?></th>
					<td>
						<?php if ( $this->auth->is_oauth_connected() ) : ?>
							<span class="kc-badge kc-badge-ok"><?php esc_html_e( 'Connected', 'keap-connect' ); ?></span>
							<?php
							$exp = (int) $s['oauth_expires_at'];
							if ( $exp > 0 ) {
								printf(
									' <span class="description">%s %s</span>',
									esc_html__( 'Token expires:', 'keap-connect' ),
									esc_html( wp_date( 'Y-m-d H:i', $exp ) )
								);
							}
							?>
						<?php else : ?>
							<span class="kc-badge kc-badge-off"><?php esc_html_e( 'Not connected', 'keap-connect' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ( $this->auth->uses_oauth() ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Access token', 'keap-connect' ); ?></th>
					<td>
						<?php if ( ! empty( $s['oauth_access_token'] ) ) : ?>
							<input type="password" class="large-text code kc-secret" readonly value="<?php echo esc_attr( $s['oauth_access_token'] ); ?>" onfocus="this.select()" />
							<button type="button" class="button kc-reveal" data-shown="0"><?php esc_html_e( 'Show', 'keap-connect' ); ?></button>
						<?php else : ?>
							<em class="description"><?php esc_html_e( 'None', 'keap-connect' ); ?></em>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Refresh token', 'keap-connect' ); ?></th>
					<td>
						<?php if ( ! empty( $s['oauth_refresh_token'] ) ) : ?>
							<input type="password" class="large-text code kc-secret" readonly value="<?php echo esc_attr( $s['oauth_refresh_token'] ); ?>" onfocus="this.select()" />
							<button type="button" class="button kc-reveal" data-shown="0"><?php esc_html_e( 'Show', 'keap-connect' ); ?></button>
						<?php else : ?>
							<em class="description"><?php esc_html_e( 'None', 'keap-connect' ); ?></em>
						<?php endif; ?>
					</td>
				</tr>
				<?php endif; ?>
			</table>

			<h3><?php esc_html_e( 'PAT / SAK', 'keap-connect' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Token (Personal Access Token / Service Account Key)', 'keap-connect' ); ?></th>
					<td>
						<input type="password" class="regular-text" name="pat_token" value="<?php echo esc_attr( $s['pat_token'] ); ?>" autocomplete="off" />
						<p class="description"><a href="https://developer.infusionsoft.com/pat-and-sak/" target="_blank" rel="noopener">developer.infusionsoft.com/pat-and-sak</a></p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save connection', 'keap-connect' ) ); ?>
		</form>

		<div class="kc-actions-row">
			<form method="post" style="display:inline-block">
				<?php wp_nonce_field( 'kc_oauth_connect' ); ?>
				<input type="hidden" name="kc_action" value="oauth_connect" />
				<?php submit_button( __( 'Connect / reconnect OAuth', 'keap-connect' ), 'secondary', 'submit', false ); ?>
			</form>

			<?php if ( $this->auth->is_oauth_connected() ) : ?>
			<form method="post" style="display:inline-block">
				<?php wp_nonce_field( 'kc_oauth_disconnect' ); ?>
				<input type="hidden" name="kc_action" value="oauth_disconnect" />
				<?php submit_button( __( 'Disconnect OAuth', 'keap-connect' ), 'delete', 'submit', false ); ?>
			</form>
			<?php endif; ?>

			<form method="post" style="display:inline-block">
				<?php wp_nonce_field( 'kc_test_connection' ); ?>
				<input type="hidden" name="kc_action" value="test_connection" />
				<?php submit_button( __( 'Test connection', 'keap-connect' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>

		<?php $this->render_oauth_diagnostics(); ?>
		<?php
	}

	/**
	 * Pannello "Diagnostica OAuth": mostra gli ultimi errori OAuth registrati.
	 *
	 * @return void
	 */
	private function render_oauth_diagnostics() {
		$errors = $this->logger->get_recent_errors( 5, 'OAuth' );
		// Aggiunge anche gli errori della chiamata al token endpoint, non sempre etichettati "OAuth".
		if ( count( $errors ) < 5 ) {
			$token_errors = $this->logger->get_recent_errors( 5, 'token' );
			$seen         = wp_list_pluck( $errors, 'id' );
			foreach ( $token_errors as $te ) {
				if ( ! in_array( $te['id'], $seen, true ) ) {
					$errors[] = $te;
				}
			}
			usort(
				$errors,
				static function ( $a, $b ) {
					return (int) $b['id'] - (int) $a['id'];
				}
			);
			$errors = array_slice( $errors, 0, 5 );
		}
		?>
		<h3><?php esc_html_e( 'OAuth diagnostics', 'keap-connect' ); ?></h3>
		<?php if ( empty( $errors ) ) : ?>
			<p class="description"><?php esc_html_e( 'No OAuth errors recorded.', 'keap-connect' ); ?></p>
		<?php else : ?>
			<table class="widefat striped kc-diag-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'keap-connect' ); ?></th>
						<th><?php esc_html_e( 'Code', 'keap-connect' ); ?></th>
						<th><?php esc_html_e( 'Detail', 'keap-connect' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $errors as $e ) : ?>
						<tr>
							<td><?php echo esc_html( get_date_from_gmt( $e['created_at'], 'Y-m-d H:i:s' ) ); ?></td>
							<td><?php echo esc_html( $e['response_code'] ); ?></td>
							<td>
								<?php
								$detail = $e['message'];
								if ( '' === $detail && '' !== $e['response_body'] ) {
									$detail = $e['response_body'];
								}
								$detail = (string) $detail;
								if ( strlen( $detail ) > 220 ) {
									$detail = substr( $detail, 0, 220 ) . '...';
								}
								echo esc_html( $detail );
								?>
							</td>
							<td>
								<?php if ( ! empty( $e['correlation_id'] ) ) : ?>
									<a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'log', 'correlation_id' => $e['correlation_id'] ), $this->tab_url( 'log' ) ) ); ?>"><?php esc_html_e( 'details', 'keap-connect' ); ?></a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><a href="<?php echo esc_url( add_query_arg( 'status', KC_Logger::STATUS_ERROR, $this->tab_url( 'log' ) ) ); ?>"><?php esc_html_e( 'View all errors in the Log', 'keap-connect' ); ?></a></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Scheda Endpoint.
	 *
	 * @return void
	 */
	private function render_endpoint_tab() {
		$s = $this->settings->all();

		// Valori mostrati una sola volta subito dopo la rigenerazione (poi spariscono al reload).
		$uid          = get_current_user_id();
		$show_bearer  = get_transient( 'kc_show_bearer_' . $uid );
		if ( false !== $show_bearer ) {
			delete_transient( 'kc_show_bearer_' . $uid );
		}
		$show_cron_secret = get_transient( 'kc_show_cron_' . $uid );
		if ( false !== $show_cron_secret ) {
			delete_transient( 'kc_show_cron_' . $uid );
		}
		$cron_base_url = rest_url( 'keap-connect/v1/cron' );
		?>
		<form method="post">
			<?php wp_nonce_field( 'kc_save_endpoint' ); ?>
			<input type="hidden" name="kc_action" value="save_endpoint" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Endpoint URL', 'keap-connect' ); ?></th>
					<td><code><?php echo esc_html( $this->settings->intake_url() ); ?></code> <span class="description">(POST, JSON)</span></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Require Bearer', 'keap-connect' ); ?></th>
					<td>
						<label><input type="checkbox" name="require_bearer" value="1" <?php checked( $s['require_bearer'] ); ?> /> <?php esc_html_e( 'Protect the endpoint with a Bearer token', 'keap-connect' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Bearer token', 'keap-connect' ); ?></th>
					<td>
						<?php if ( false !== $show_bearer ) : ?>
							<input type="text" class="large-text code" readonly value="<?php echo esc_attr( $show_bearer ); ?>" onfocus="this.select()" />
							<button type="submit" form="kc-form-rotate-bearer" class="button"><?php esc_html_e( 'Regenerate', 'keap-connect' ); ?></button>
							<p class="description kc-show-once"><?php esc_html_e( 'Copy it now: it will no longer be shown after you reload the page.', 'keap-connect' ); ?></p>
						<?php else : ?>
							<input type="text" class="large-text code" value="••••••••••••••••••••" disabled />
							<button type="submit" form="kc-form-rotate-bearer" class="button"><?php esc_html_e( 'Regenerate', 'keap-connect' ); ?></button>
							<p class="description"><?php esc_html_e( 'For security the token is not shown. Click "Regenerate" to create and view a new one.', 'keap-connect' ); ?></p>
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'Send as header: Authorization: Bearer <token>', 'keap-connect' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Create if missing', 'keap-connect' ); ?></th>
					<td><label><input type="checkbox" name="create_if_missing" value="1" <?php checked( $s['create_if_missing'] ); ?> /> <?php esc_html_e( 'Create the contact if not found by email', 'keap-connect' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'External cron', 'keap-connect' ); ?></th>
					<td>
						<label><input type="checkbox" name="enable_external_cron" value="1" <?php checked( $s['enable_external_cron'] ); ?> /> <?php esc_html_e( 'Enable the endpoint for external cron (OAuth refresh)', 'keap-connect' ); ?></label>
						<?php if ( false !== $show_cron_secret ) : ?>
							<p class="description"><?php esc_html_e( 'URL to call (GET) - contains the secret:', 'keap-connect' ); ?></p>
							<input type="text" class="large-text code" readonly value="<?php echo esc_attr( add_query_arg( 'secret', rawurlencode( $show_cron_secret ), $cron_base_url ) ); ?>" onfocus="this.select()" />
							<button type="submit" form="kc-form-rotate-cron" class="button"><?php esc_html_e( 'Regenerate', 'keap-connect' ); ?></button>
							<p class="description kc-show-once"><?php esc_html_e( 'Copy it now: the secret will no longer be shown after you reload the page.', 'keap-connect' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'URL to call (GET) - the secret is hidden:', 'keap-connect' ); ?></p>
							<input type="text" class="large-text code" value="<?php echo esc_attr( $cron_base_url . '?secret=••••••' ); ?>" disabled />
							<button type="submit" form="kc-form-rotate-cron" class="button"><?php esc_html_e( 'Regenerate cron secret', 'keap-connect' ); ?></button>
							<p class="description"><?php esc_html_e( 'For security the secret is not shown. Click "Regenerate cron secret" to create and view a new one.', 'keap-connect' ); ?></p>
						<?php endif; ?>
						<p class="description">
							<?php
							printf(
								/* translators: %s: HTTP header name */
								esc_html__( 'Alternatively, send the secret as the %s header instead of the query string.', 'keap-connect' ),
								'<code>X-KC-Cron-Secret</code>'
							);
							?>
						</p>
						<p class="description"><?php esc_html_e( 'The Keap (OAuth) token is automatically refreshed every 6 hours via WP-Cron.', 'keap-connect' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Log retention (days)', 'keap-connect' ); ?></th>
					<td><input type="number" min="0" name="log_retention_days" value="<?php echo esc_attr( $s['log_retention_days'] ); ?>" class="small-text" /> <span class="description"><?php esc_html_e( '0 = never delete', 'keap-connect' ); ?></span></td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Email notifications', 'keap-connect' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable notifications', 'keap-connect' ); ?></th>
					<td><label><input type="checkbox" name="notifications_enabled" value="1" <?php checked( $s['notifications_enabled'] ); ?> /> <?php esc_html_e( 'Send email notifications for processed leads', 'keap-connect' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Notify on', 'keap-connect' ); ?></th>
					<td>
						<select name="notify_on">
							<option value="errors" <?php selected( $s['notify_on'], 'errors' ); ?>><?php esc_html_e( 'Errors only', 'keap-connect' ); ?></option>
							<option value="all" <?php selected( $s['notify_on'], 'all' ); ?>><?php esc_html_e( 'All processed leads', 'keap-connect' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Include site administrator', 'keap-connect' ); ?></th>
					<td>
						<label><input type="checkbox" name="notify_include_admin" value="1" <?php checked( $s['notify_include_admin'] ); ?> /> <?php esc_html_e( 'Also send to the site administrator email', 'keap-connect' ); ?></label>
						<p class="description"><?php echo esc_html( sprintf( /* translators: %s: admin email */ __( 'Site administrator: %s', 'keap-connect' ), (string) get_option( 'admin_email' ) ) ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Additional email', 'keap-connect' ); ?></th>
					<td>
						<input type="email" class="regular-text" name="notify_extra_email" value="<?php echo esc_attr( $s['notify_extra_email'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Optional. Leave empty to notify the administrator only.', 'keap-connect' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save endpoint', 'keap-connect' ) ); ?>
		</form>

		<?php // Form di rigenerazione referenziati dai pulsanti "Rigenera" accanto ai campi. ?>
		<form method="post" id="kc-form-rotate-bearer">
			<?php wp_nonce_field( 'kc_rotate_bearer' ); ?>
			<input type="hidden" name="kc_action" value="rotate_bearer" />
		</form>
		<form method="post" id="kc-form-rotate-cron">
			<?php wp_nonce_field( 'kc_rotate_cron_secret' ); ?>
			<input type="hidden" name="kc_action" value="rotate_cron_secret" />
		</form>

		<div class="kc-actions-row">
			<form method="post" style="display:inline-block">
				<?php wp_nonce_field( 'kc_send_test_email' ); ?>
				<input type="hidden" name="kc_action" value="send_test_email" />
				<?php submit_button( __( 'Send test email', 'keap-connect' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<p class="description"><?php esc_html_e( 'The test email uses the recipients configured above and is sent even if notifications are disabled.', 'keap-connect' ); ?></p>
		<?php
	}

	/**
	 * Scheda Mappatura.
	 *
	 * @return void
	 */
	private function render_mapping_tab() {
		$map           = $this->settings->get_field_map();
		$custom_fields = $this->settings->get_custom_fields();
		$model         = $this->settings->get_keap_model();
		?>
		<div class="kc-actions-row">
			<form method="post" style="display:inline-block">
				<?php wp_nonce_field( 'kc_refresh_fields' ); ?>
				<input type="hidden" name="kc_action" value="refresh_fields" />
				<?php submit_button( __( 'Refresh fields from Keap', 'keap-connect' ), 'secondary', 'submit', false ); ?>
			</form>
			<?php if ( ! empty( $model['fetched_at'] ) ) : ?>
				<span class="description">
					<?php
					printf(
						/* translators: %s: date */
						esc_html__( 'Fields last updated: %s', 'keap-connect' ),
						esc_html( wp_date( 'Y-m-d H:i', (int) $model['fetched_at'] ) )
					);
					echo ' (' . count( $custom_fields ) . ' ' . esc_html__( 'custom fields', 'keap-connect' ) . ')';
					?>
				</span>
			<?php else : ?>
				<span class="description"><?php esc_html_e( 'No custom fields fetched yet.', 'keap-connect' ); ?></span>
			<?php endif; ?>
		</div>

		<form method="post" id="kc-mapping-form">
			<?php wp_nonce_field( 'kc_save_mapping' ); ?>
			<input type="hidden" name="kc_action" value="save_mapping" />

			<p class="description"><?php esc_html_e( 'Define how the incoming body keys are mapped to Keap fields. The body "Tag" field (tag IDs, comma-separated) is handled automatically.', 'keap-connect' ); ?></p>

			<table class="widefat striped kc-map-table" id="kc-map-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Body key', 'keap-connect' ); ?></th>
						<th><?php esc_html_e( 'Target type', 'keap-connect' ); ?></th>
						<th><?php esc_html_e( 'Target field', 'keap-connect' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php
					if ( empty( $map ) ) {
						$map = array( array( 'source' => '', 'target_type' => 'standard', 'target' => '', 'address_type' => '' ) );
					}
					foreach ( $map as $i => $row ) {
						$this->render_mapping_row( $i, $row, $custom_fields );
					}
					?>
				</tbody>
			</table>

			<p>
				<button type="button" class="button" id="kc-add-row"><?php esc_html_e( '+ Add row', 'keap-connect' ); ?></button>
			</p>

			<?php submit_button( __( 'Save mapping', 'keap-connect' ) ); ?>
		</form>

		<script type="text/template" id="kc-row-template">
			<?php $this->render_mapping_row( '__INDEX__', array( 'source' => '', 'target_type' => 'standard', 'target' => '', 'address_type' => '' ), $custom_fields ); ?>
		</script>
		<?php
	}

	/**
	 * Render di una riga di mappatura.
	 *
	 * @param int|string $index         Indice riga.
	 * @param array      $row           Dati riga.
	 * @param array      $custom_fields Campi custom.
	 * @return void
	 */
	private function render_mapping_row( $index, array $row, array $custom_fields ) {
		$type         = isset( $row['target_type'] ) ? $row['target_type'] : 'standard';
		$target       = isset( $row['target'] ) ? $row['target'] : '';
		$address_type = isset( $row['address_type'] ) ? $row['address_type'] : 'BILLING';
		$name         = 'kc_map[' . $index . ']';
		?>
		<tr class="kc-map-row">
			<td>
				<input type="text" name="<?php echo esc_attr( $name ); ?>[source]" value="<?php echo esc_attr( isset( $row['source'] ) ? $row['source'] : '' ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Nome', 'keap-connect' ); ?>" />
			</td>
			<td>
				<select name="<?php echo esc_attr( $name ); ?>[target_type]" class="kc-target-type">
					<option value="standard" <?php selected( $type, 'standard' ); ?>><?php esc_html_e( 'Standard field', 'keap-connect' ); ?></option>
					<option value="custom" <?php selected( $type, 'custom' ); ?>><?php esc_html_e( 'Custom field', 'keap-connect' ); ?></option>
					<option value="address" <?php selected( $type, 'address' ); ?>><?php esc_html_e( 'Address', 'keap-connect' ); ?></option>
				</select>
			</td>
			<td>
				<span class="kc-target kc-target-standard" <?php echo 'standard' === $type ? '' : 'style="display:none"'; ?>>
					<select name="<?php echo esc_attr( $name ); ?>[target_standard]">
						<?php foreach ( KC_Mapper::standard_fields() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( 'standard' === $type ? $target : '', $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</span>
				<span class="kc-target kc-target-custom" <?php echo 'custom' === $type ? '' : 'style="display:none"'; ?>>
					<?php if ( empty( $custom_fields ) ) : ?>
						<em><?php esc_html_e( 'No custom fields: use "Refresh fields from Keap".', 'keap-connect' ); ?></em>
					<?php else : ?>
						<select name="<?php echo esc_attr( $name ); ?>[target_custom]">
							<?php foreach ( $custom_fields as $cf ) : ?>
								<option value="<?php echo esc_attr( $cf['id'] ); ?>" <?php selected( 'custom' === $type ? $target : '', $cf['id'] ); ?>>
									<?php echo esc_html( $cf['label'] . ' (#' . $cf['id'] . ')' ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					<?php endif; ?>
				</span>
				<span class="kc-target kc-target-address" <?php echo 'address' === $type ? '' : 'style="display:none"'; ?>>
					<select name="<?php echo esc_attr( $name ); ?>[target_address]">
						<?php foreach ( KC_Mapper::address_components() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( 'address' === $type ? $target : '', $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<select name="<?php echo esc_attr( $name ); ?>[address_type]">
						<?php foreach ( KC_Mapper::address_types() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $address_type, $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</span>
			</td>
			<td><button type="button" class="button kc-remove-row" aria-label="<?php esc_attr_e( 'Remove', 'keap-connect' ); ?>">&times;</button></td>
		</tr>
		<?php
	}

	/**
	 * Scheda Log.
	 *
	 * @return void
	 */
	private function render_log_tab() {
		$correlation = isset( $_GET['correlation_id'] ) ? sanitize_text_field( wp_unslash( $_GET['correlation_id'] ) ) : '';
		if ( '' !== $correlation ) {
			$this->render_log_detail( $correlation );
			return;
		}

		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;

		$data = $this->logger->query(
			array(
				'search'   => $search,
				'status'   => $status,
				'page'     => $paged,
				'per_page' => 30,
			)
		);
		?>
		<form method="get" class="kc-log-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
			<input type="hidden" name="tab" value="log" />
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search email, id, body...', 'keap-connect' ); ?>" />
			<select name="status">
				<option value=""><?php esc_html_e( 'All statuses', 'keap-connect' ); ?></option>
				<?php
				$statuses = array(
					KC_Logger::STATUS_RECEIVED => __( 'Received', 'keap-connect' ),
					KC_Logger::STATUS_SUCCESS  => __( 'Success', 'keap-connect' ),
					KC_Logger::STATUS_ERROR    => __( 'Error', 'keap-connect' ),
					KC_Logger::STATUS_SKIPPED  => __( 'Skipped', 'keap-connect' ),
				);
				foreach ( $statuses as $k => $v ) {
					printf( '<option value="%s" %s>%s</option>', esc_attr( $k ), selected( $status, $k, false ), esc_html( $v ) );
				}
				?>
			</select>
			<?php submit_button( __( 'Filter', 'keap-connect' ), 'secondary', '', false ); ?>
		</form>

		<form method="post" style="margin:10px 0">
			<?php wp_nonce_field( 'kc_clear_logs' ); ?>
			<input type="hidden" name="kc_action" value="clear_logs" />
			<?php submit_button( __( 'Clear all logs', 'keap-connect' ), 'delete', 'submit', false, array( 'onclick' => "return confirm('" . esc_js( __( 'Are you sure you want to delete all logs?', 'keap-connect' ) ) . "')" ) ); ?>
		</form>

		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'keap-connect' ); ?></th>
					<th><?php esc_html_e( 'Dir.', 'keap-connect' ); ?></th>
					<th><?php esc_html_e( 'Endpoint', 'keap-connect' ); ?></th>
					<th><?php esc_html_e( 'Code', 'keap-connect' ); ?></th>
					<th><?php esc_html_e( 'Status', 'keap-connect' ); ?></th>
					<th><?php esc_html_e( 'Email / Contact', 'keap-connect' ); ?></th>
					<th><?php esc_html_e( 'Correlation', 'keap-connect' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $data['rows'] ) ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No logs.', 'keap-connect' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $data['rows'] as $r ) : ?>
						<tr>
							<td><?php echo esc_html( get_date_from_gmt( $r['created_at'], 'd/m/Y H:i:s' ) ); ?></td>
							<td><?php echo esc_html( strtoupper( $r['direction'] ) ); ?></td>
							<td><span class="kc-ellipsis"><?php echo esc_html( $r['endpoint'] ); ?></span></td>
							<td><?php echo esc_html( $r['response_code'] ); ?></td>
							<td><span class="kc-status kc-status-<?php echo esc_attr( $r['status'] ); ?>"><?php echo esc_html( $r['status'] ); ?></span></td>
							<td><?php echo esc_html( $r['email'] ? $r['email'] : '' ); ?><?php echo $r['contact_id'] ? ' / ' . esc_html( $r['contact_id'] ) : ''; ?></td>
							<td>
								<?php if ( $r['correlation_id'] ) : ?>
									<a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'log', 'correlation_id' => $r['correlation_id'] ), $this->tab_url( 'log' ) ) ); ?>"><?php esc_html_e( 'details', 'keap-connect' ); ?></a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<?php
		if ( $data['pages'] > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			$base = add_query_arg(
				array(
					'page'   => self::PAGE_SLUG,
					'tab'    => 'log',
					's'      => $search,
					'status' => $status,
					'paged'  => '%#%',
				),
				admin_url( 'admin.php' )
			);
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => $base,
						'format'  => '',
						'current' => $paged,
						'total'   => $data['pages'],
					)
				)
			);
			echo '</div></div>';
		}
	}

	/**
	 * Render del dettaglio di una correlazione.
	 *
	 * @param string $correlation_id ID correlazione.
	 * @return void
	 */
	private function render_log_detail( $correlation_id ) {
		$rows = $this->logger->get_by_correlation( $correlation_id );
		?>
		<p><a href="<?php echo esc_url( $this->tab_url( 'log' ) ); ?>">&larr; <?php esc_html_e( 'Back to logs', 'keap-connect' ); ?></a></p>
		<h2><?php echo esc_html( $correlation_id ); ?></h2>
		<?php if ( empty( $rows ) ) : ?>
			<p><?php esc_html_e( 'No entries for this correlation.', 'keap-connect' ); ?></p>
		<?php else : ?>
			<?php foreach ( $rows as $r ) : ?>
				<div class="kc-log-entry">
					<div class="kc-log-entry-head">
						<strong><?php echo esc_html( strtoupper( $r['direction'] ) . ' ' . $r['method'] ); ?></strong>
						<span class="kc-status kc-status-<?php echo esc_attr( $r['status'] ); ?>"><?php echo esc_html( $r['status'] ); ?></span>
						<?php if ( '' !== $r['response_code'] && null !== $r['response_code'] ) : ?>
							<span class="kc-code"><?php echo esc_html( $r['response_code'] ); ?></span>
						<?php endif; ?>
						<span class="kc-time"><?php echo esc_html( get_date_from_gmt( $r['created_at'], 'd/m/Y H:i:s' ) ); ?></span>
					</div>
					<div class="kc-log-endpoint"><code><?php echo esc_html( $r['endpoint'] ); ?></code></div>
					<?php if ( $r['message'] ) : ?>
						<p class="kc-log-msg"><?php echo esc_html( $r['message'] ); ?></p>
					<?php endif; ?>
					<?php if ( $r['request_body'] ) : ?>
						<details><summary><?php esc_html_e( 'Request body', 'keap-connect' ); ?></summary><pre><?php echo esc_html( $r['request_body'] ); ?></pre></details>
					<?php endif; ?>
					<?php if ( $r['response_body'] ) : ?>
						<details><summary><?php esc_html_e( 'Response body', 'keap-connect' ); ?></summary><pre><?php echo esc_html( $r['response_body'] ); ?></pre></details>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>

		<?php
		$incoming = $this->logger->get_incoming_body( $correlation_id );
		if ( '' !== $incoming ) :
			?>
			<div class="kc-replay">
				<h3><?php esc_html_e( 'Replay this lead', 'keap-connect' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Review or fix the received body, then resend it to Keap. The result is logged as a new entry.', 'keap-connect' ); ?></p>
				<form method="post">
					<?php wp_nonce_field( 'kc_replay_lead' ); ?>
					<input type="hidden" name="kc_action" value="replay_lead" />
					<textarea name="kc_body" rows="12" class="large-text code" spellcheck="false"><?php echo esc_textarea( $incoming ); ?></textarea>
					<?php submit_button( __( 'Resend to Keap', 'keap-connect' ), 'primary', 'submit', true, array( 'onclick' => "return confirm('" . esc_js( __( 'Resend this lead to Keap?', 'keap-connect' ) ) . "')" ) ); ?>
				</form>
			</div>
		<?php endif; ?>
		<?php
	}
}
