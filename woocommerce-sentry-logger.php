<?php
/**
 * Plugin Name: Sentry Logger for WooCommerce
 * Description: A simple WooCommerce log handler that sends logs to Sentry using the Sentry PHP SDK.
 * Version: 1.0.0
 * Author: MoPed
 * Author URI: https://moped.jepan.sk
 * Requires at least: 5.0
 * Tested up to: 6.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 10.2
 * Text Domain: woocommerce-sentry-logger
 * License: GPL v3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WC_Sentry_Logger_Plugin' ) ) {
    class WC_Sentry_Logger_Plugin
    {

        public const string VERSION = '1.0.0';

        private static $plugin_file;
        private static $plugin_basename;
        private static $plugin_dir;

        private static $instance = null;
        private static $handler_instance = null;

        public static function instance()
        {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        private function __construct()
        {
            self::$plugin_file     = __FILE__;
            self::$plugin_basename = plugin_basename( __FILE__ );
            self::$plugin_dir      = __DIR__;
            $this->init();
        }

        private function init()
        {
            add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );

            add_action( 'plugins_loaded', array( $this, 'plugins_loaded' ), 20 );
            register_activation_hook( __FILE__, array( $this, 'activate' ) );
            register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
        }

        /**
         * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS).
         */
        public function declare_hpos_compatibility()
        {
            if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                    'custom_order_tables',
                    self::$plugin_file,
                    true
                );
            }
        }
        public function plugins_loaded()
        {
            if ( ! $this->check_dependencies() ) {
                return;
            }

            $this->load_dependencies();
            $this->register_log_handler();
        }

        private function check_dependencies()
        {
            if ( ! class_exists( 'WooCommerce' ) ) {
                add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
                return false;
            }

            if ( ! defined( 'WP_SENTRY_PHP_DSN' ) || empty(WP_SENTRY_PHP_DSN) ) {
                add_action( 'admin_notices', array( $this, 'sentry_dsn_missing_notice' ) );
                return false;
            }

            if ( ! file_exists( self::$plugin_dir . '/vendor/autoload.php' ) ) {
                add_action( 'admin_notices', array( $this, 'composer_dependencies_missing_notice' ) );
                return false;
            }

            return true;
        }

        private function load_dependencies()
        {
            require_once self::$plugin_dir . '/vendor/autoload.php';

            // Ensure WooCommerce log handler interfaces are loaded
            if ( class_exists( 'WC_Logger' ) && ! interface_exists( 'WC_Log_Handler_Interface' ) ) {
                // Force load WooCommerce logging classes
                if ( function_exists( 'wc_get_logger' ) ) {
                    wc_get_logger();
                }
            }

            require_once self::$plugin_dir . '/includes/class-wc-sentry-log-handler.php';

            // Add debug notice if class wasn't loaded properly
            if ( ! class_exists( 'WC_Sentry_Log_Handler' ) && WP_DEBUG ) {
                add_action( 'admin_notices', array( $this, 'class_loading_debug_notice' ) );
            }
        }

        private function register_log_handler()
        {
            add_filter( 'woocommerce_register_log_handlers', array( $this, 'register_sentry_handler' ) );
            add_filter( 'woocommerce_logger_handler_options', array( $this, 'add_sentry_handler_option' ) );
        }

        public function register_sentry_handler( $handlers )
        {
            if ( class_exists( 'WC_Sentry_Log_Handler' ) ) {
                if ( null === self::$handler_instance ) {
                    self::$handler_instance = new WC_Sentry_Log_Handler();
                }

                // Check if our handler is already in the array to prevent duplicates
                $handler_exists = false;
                foreach ($handlers as $handler) {
                    if ($handler instanceof WC_Sentry_Log_Handler) {
                        $handler_exists = true;
                        break;
                    }
                }

                if (!$handler_exists) {
                    $handlers[] = self::$handler_instance;
                }
            }
            return $handlers;
        }

        public function add_sentry_handler_option( $handler_options )
        {
            if ( class_exists( 'WC_Sentry_Log_Handler' ) ) {
                $handler_options['WC_Sentry_Log_Handler'] = __( 'Sentry (error tracking service)', 'woocommerce-sentry-logger' );
            }
            return $handler_options;
        }

        public function activate()
        {
            if ( ! class_exists( 'WooCommerce' ) ) {
                deactivate_plugins( self::$plugin_basename );
                wp_die( __( 'WooCommerce Sentry Logger requires WooCommerce to be installed and activated.', 'woocommerce-sentry-logger' ) );
            }
        }

        public function deactivate() {}

        public function woocommerce_missing_notice()
        {
            echo '<div class="error notice"><p>';
            echo esc_html__( 'WooCommerce Sentry Logger requires WooCommerce to be installed and activated.', 'woocommerce-sentry-logger' );
            echo '</p></div>';
        }

        public function sentry_dsn_missing_notice()
        {
            echo '<div class="error notice"><p>';
            echo esc_html__( 'WooCommerce Sentry Logger requires WP_SENTRY_PHP_DSN to be defined in wp-config.php.', 'woocommerce-sentry-logger' );
            echo '</p></div>';
        }

        public function composer_dependencies_missing_notice()
        {
            echo '<div class="error notice"><p>';
            echo esc_html__( 'WooCommerce Sentry Logger requires composer dependencies to be installed. Please run "composer install" in the plugin directory.', 'woocommerce-sentry-logger' );
            echo '</p></div>';
        }

        public function class_loading_debug_notice()
        {
            echo '<div class="notice notice-warning"><p>';
            echo esc_html__( 'Debug: WC_Sentry_Log_Handler class could not be loaded. Check that WC_Log_Handler and WC_Log_Handler_Interface are available.', 'woocommerce-sentry-logger' );
            echo '</p></div>';
        }
    }

    WC_Sentry_Logger_Plugin::instance();
}
