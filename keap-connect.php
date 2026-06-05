<?php
/**
 * Plugin Name: Keap Connect
 * Plugin URI: https://ratne.dev
 * Description: Exposes a generic REST endpoint that receives leads and syncs them to Keap (REST v2) with OAuth and/or PAT-SAK authentication with fallback, configurable field mapping (standard, custom, address), tag application, full logging, and token refresh via WP-Cron + optional external cron.
 * Version: 1.0.2
 * Author: Ratne
 * Author URI: https://ratne.dev
 * License: GPL-2.0-or-later
 * Requires PHP: 8.0
 * Requires at least: 5.6
 * Text Domain: keap-connect
 *
 * @package KeapConnect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KC_VERSION', '1.0.2' );
define( 'KC_PLUGIN_FILE', __FILE__ );
define( 'KC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'KC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'KC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

define( 'KC_GITHUB_REPO', 'https://github.com/Ratne/keap-wordpress' );

/**
 * Inizializza il controllo aggiornamenti da GitHub (Plugin Update Checker).
 *
 * @return void
 */
function kc_init_updater() {
	$puc = KC_PLUGIN_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
	if ( ! is_readable( $puc ) ) {
		return;
	}
	require_once $puc;

	if ( ! class_exists( '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory' ) ) {
		return;
	}

	$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		KC_GITHUB_REPO,
		KC_PLUGIN_FILE,
		dirname( KC_PLUGIN_BASENAME )
	);

	// Branch usato come fallback se non ci sono release/tag.
	$checker->setBranch( 'main' );

	// Repo privata: imposta un token GitHub.
	if ( defined( 'KC_GITHUB_TOKEN' ) && KC_GITHUB_TOKEN ) {
		$checker->setAuthentication( KC_GITHUB_TOKEN );
	}
}
kc_init_updater();

/**
 * Carica una classe del plugin dato il suo nome (KC_*).
 *
 * @param string $class Nome classe.
 * @return void
 */
function kc_autoload( $class ) {
	if ( 0 !== strpos( $class, 'KC_' ) ) {
		return;
	}
	$slug = strtolower( str_replace( '_', '-', $class ) );
	$file = KC_PLUGIN_DIR . 'includes/class-' . $slug . '.php';
	if ( is_readable( $file ) ) {
		require_once $file;
	}
}
spl_autoload_register( 'kc_autoload' );

/**
 * Bootstrap principale del plugin.
 */
final class KC_Plugin {

	/**
	 * Istanza singleton.
	 *
	 * @var KC_Plugin|null
	 */
	private static $instance = null;

	/**
	 * @var KC_Settings
	 */
	public $settings;

	/**
	 * @var KC_Logger
	 */
	public $logger;

	/**
	 * @var KC_Auth
	 */
	public $auth;

	/**
	 * @var KC_Keap_Client
	 */
	public $client;

	/**
	 * @var KC_Mapper
	 */
	public $mapper;

	/**
	 * @var KC_Notifier
	 */
	public $notifier;

	/**
	 * @var KC_Processor
	 */
	public $processor;

	/**
	 * @var KC_Endpoint
	 */
	public $endpoint;

	/**
	 * @var KC_Cron
	 */
	public $cron;

	/**
	 * @var KC_Admin
	 */
	public $admin;

	/**
	 * Ritorna l'istanza singleton.
	 *
	 * @return KC_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Costruttore: istanzia i componenti e registra gli hook.
	 */
	private function __construct() {
		$this->settings  = new KC_Settings();
		$this->settings->maybe_migrate_secrets();
		$this->logger    = new KC_Logger();
		$this->auth      = new KC_Auth( $this->settings, $this->logger );
		$this->client    = new KC_Keap_Client( $this->auth, $this->logger );
		$this->mapper    = new KC_Mapper( $this->settings );
		$this->notifier  = new KC_Notifier( $this->settings );
		$this->processor = new KC_Processor( $this->settings, $this->client, $this->mapper, $this->logger, $this->notifier );
		$this->endpoint  = new KC_Endpoint( $this->settings, $this->processor, $this->logger );
		$this->cron      = new KC_Cron( $this->settings, $this->auth, $this->logger, $this->notifier );

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'rest_api_init', array( $this->endpoint, 'register_routes' ) );
		add_action( 'rest_api_init', array( $this->cron, 'register_routes' ) );

		$this->cron->register_hooks();

		if ( is_admin() ) {
			$this->admin = new KC_Admin( $this->settings, $this->auth, $this->client, $this->logger, $this->processor, $this->notifier );
			$this->admin->register_hooks();
		}
	}

	/**
	 * Carica le traduzioni.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'keap-connect', false, dirname( KC_PLUGIN_BASENAME ) . '/languages' );
	}

	/**
	 * Callback di attivazione: crea la tabella di log e schedula il cron.
	 *
	 * @return void
	 */
	public static function activate() {
		require_once KC_PLUGIN_DIR . 'includes/class-kc-logger.php';
		KC_Logger::install_table();

		require_once KC_PLUGIN_DIR . 'includes/class-kc-settings.php';
		$settings = new KC_Settings();
		$settings->maybe_seed_defaults();

		require_once KC_PLUGIN_DIR . 'includes/class-kc-cron.php';
		KC_Cron::schedule_events();
	}

	/**
	 * Callback di disattivazione: rimuove gli eventi cron.
	 *
	 * @return void
	 */
	public static function deactivate() {
		require_once KC_PLUGIN_DIR . 'includes/class-kc-cron.php';
		KC_Cron::clear_events();
	}
}

register_activation_hook( __FILE__, array( 'KC_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'KC_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'KC_Plugin', 'instance' ) );
