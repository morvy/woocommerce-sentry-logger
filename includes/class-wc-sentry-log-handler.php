<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WC_Sentry_Log_Handler' ) && class_exists( 'WC_Log_Handler' ) && interface_exists( 'WC_Log_Handler_Interface' ) ) {

    class WC_Sentry_Log_Handler extends WC_Log_Handler
    {

        private $initialized = false;

        public function __construct()
        {
            $this->init_sentry();
        }

        private function init_sentry()
        {
            if ( $this->initialized ) {
                return;
            }

            if ( ! function_exists( '\Sentry\init' ) ) {
                return;
            }

            $dsn = defined( 'WP_SENTRY_PHP_DSN' ) ? WP_SENTRY_PHP_DSN : '';
            if ( empty($dsn) ) {
                return;
            }

            $environment = $this->get_environment();
            $default_pii = defined( 'WP_SENTRY_SEND_DEFAULT_PII' ) ? WP_SENTRY_SEND_DEFAULT_PII : false;
            \Sentry\init( [
                'dsn'              => $dsn,
                'environment'      => $environment,
                'enable_logs'      => true,
                'send_default_pii' => $default_pii,
                'tags'             => [
                    'plugin'  => 'woocommerce-sentry-logger',
                    'version' => WC_Sentry_Logger_Plugin::VERSION
                ]
            ] );

            $this->initialized = true;
        }

        /**
         * Handle a log entry.
         *
         * @param int    $timestamp Log timestamp.
         * @param string $level     emergency|alert|critical|error|warning|notice|info|debug.
         * @param string $message   Log message.
         * @param array  $context   Additional information for log handlers.
         *
         * @return bool False if value was not handled and true if value was handled.
         */
        public function handle( $timestamp, $level, $message, $context )
        {
            if ( ! $this->initialized ) {
                return false;
            }

            $context = (array) $context;

            $formatted_message = $this->format_message( $message, $context );
            $attributes        = $this->prepare_attributes( $context, $timestamp );

            switch ( $level ) {
                case 'emergency':
                case 'alert':
                case 'critical':
                    \Sentry\logger()->fatal( $formatted_message, attributes: $attributes );
                    break;
                case 'error':
                    \Sentry\logger()->error( $formatted_message, attributes: $attributes );
                    break;
                case 'warning':
                    \Sentry\logger()->warn( $formatted_message, attributes: $attributes );
                    break;
                case 'notice':
                case 'info':
                    \Sentry\logger()->info( $formatted_message, attributes: $attributes );
                    break;
                case 'debug':
                    \Sentry\logger()->debug( $formatted_message, attributes: $attributes );
                    break;
                default:
                    \Sentry\logger()->info( $formatted_message, attributes: $attributes );
                    break;
            }

            return true;
        }

        private function format_message( $message, $context = [] )
        {
            if ( empty($context) ) {
                return $message;
            }

            $replace = [];
            foreach ( $context as $key => $val ) {
                if ( ! is_array( $val ) && ! is_object( $val ) ) {
                    $replace['{' . $key . '}'] = $val;
                }
            }

            return strtr( $message, $replace );
        }

        private function prepare_attributes( $context = [], $timestamp = null )
        {
            if ( empty($context) ) {
                $context = [];
            }

            $attributes = [];

            // Process original context
            foreach ( $context as $key => $value ) {
                if ( is_scalar( $value ) || is_null( $value ) ) {
                    $attributes[$key] = $value;
                } elseif ( is_array( $value ) || is_object( $value ) ) {
                    $attributes[$key] = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
                } else {
                    $attributes[$key] = (string) $value;
                }
            }

            // Basic timing and source
            if ( $timestamp ) {
                $attributes['timestamp'] = static::format_time( $timestamp );
            } else {
                $attributes['timestamp'] = current_time( 'c' );
            }

            // Respect provided source in context; otherwise infer from backtrace
            if ( empty( $attributes['source'] ) ) {
                $attributes['source'] = $this->infer_source_from_backtrace();
            }

            // WordPress environment context
            $attributes = array_merge( $attributes, $this->get_wordpress_context() );

            // System and runtime context
            $attributes = array_merge( $attributes, $this->get_system_context() );

            // User context (enhanced)
            $attributes = array_merge( $attributes, $this->get_user_context() );

            // WooCommerce specific context
            $attributes = array_merge( $attributes, $this->get_woocommerce_context() );

            return $attributes;
        }

        /**
         * Infer a sensible 'source' value from the call stack.
         * Falls back to the first non-internal frame's filename; returns 'unknown' if not detectable.
         *
         * @return string
         */
        private function infer_source_from_backtrace()
        {
            if ( ! function_exists( 'debug_backtrace' ) ) {
                return 'unknown';
            }

            $trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS );
            if ( empty( $trace ) || ! is_array( $trace ) ) {
                return 'unknown';
            }

            $skip_substrings = [
                'class-wc-sentry-log-handler.php', // this class
                'class-wc-logger',                 // WooCommerce logger internals
                'woocommerce/includes/wc-logger',  // Woo internal
                'vendor/sentry',                    // Sentry internals
            ];

            foreach ( $trace as $frame ) {
                if ( empty( $frame['file'] ) ) {
                    continue;
                }
                $file = (string) $frame['file'];

                $skip = false;
                foreach ( $skip_substrings as $needle ) {
                    if ( $needle !== '' && strpos( $file, $needle ) !== false ) {
                        $skip = true;
                        break;
                    }
                }
                if ( $skip ) {
                    continue;
                }

                return basename( $file );
            }

            return 'unknown';
        }

        /**
         * Get environment type with fallback
         */
        private function get_environment()
        {
            // Check for explicit WP_SENTRY_ENV constant first
            $environment = defined( 'WP_SENTRY_ENV' ) ? WP_SENTRY_ENV : null;

            // Fallback to WordPress's environment type function
            if ( $environment === null && function_exists( 'wp_get_environment_type' ) ) {
                $environment = wp_get_environment_type();
            }

            // Final fallback to 'production'
            return $environment ?? 'production';
        }

        /**
         * Get WordPress environment context
         */
        private function get_wordpress_context()
        {
            global $wp_version;

            $context = [];

            // WordPress version
            if ( ! empty($wp_version) ) {
                $context['wp_version'] = $wp_version;
            } elseif ( function_exists( 'get_bloginfo' ) ) {
                $context['wp_version'] = get_bloginfo( 'version' );
            }

            // Site language
            if ( function_exists( 'get_bloginfo' ) ) {
                $context['wp_language'] = get_bloginfo( 'language' );
                $context['wp_charset']  = get_bloginfo( 'charset' );
            }

            // WordPress debug info
            $context['wp_debug']         = defined( 'WP_DEBUG' ) && WP_DEBUG;
            $context['wp_debug_log']     = defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;
            $context['wp_debug_display'] = defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY;

            // Multisite
            $context['is_multisite'] = is_multisite();
            if ( is_multisite() && function_exists( 'get_current_blog_id' ) ) {
                $context['blog_id']    = get_current_blog_id();
                $context['network_id'] = get_current_network_id();
            }

            // Active theme
            if ( function_exists( 'get_stylesheet' ) ) {
                $context['theme_active'] = get_stylesheet();
                if ( function_exists( 'wp_get_theme' ) ) {
                    $theme                    = wp_get_theme();
                    $context['theme_version'] = $theme->get( 'Version' );
                }
            }

            // Memory limit
            if ( function_exists( 'wp_convert_hr_to_bytes' ) && ini_get( 'memory_limit' ) ) {
                $context['php_memory_limit'] = ini_get( 'memory_limit' );
                $context['wp_memory_limit']  = defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : 'default';
            }

            return $context;
        }

        /**
         * Get system and runtime context
         */
        private function get_system_context()
        {
            $context = [];

            // PHP version and info
            $context['php_version'] = PHP_VERSION;
            $context['php_sapi']    = PHP_SAPI;

            // Server info
            if ( isset($_SERVER['SERVER_SOFTWARE']) ) {
                $context['server_software'] = sanitize_text_field( $_SERVER['SERVER_SOFTWARE'] );
            }

            // Memory usage (human-readable)
            $usage = memory_get_usage( true );
            $peak  = memory_get_peak_usage( true );
            if ( function_exists( 'size_format' ) ) {
                $context['memory_usage'] = size_format( $usage, 2 );
                $context['memory_peak']  = size_format( $peak, 2 );
            } else {
                $context['memory_usage'] = $this->format_bytes( $usage );
                $context['memory_peak']  = $this->format_bytes( $peak );
            }

            // Caching detection
            $context = array_merge( $context, $this->detect_caching_systems() );

            // Database info
            if ( function_exists( 'mysql_get_server_info' ) || function_exists( 'mysqli_get_server_info' ) ) {
                global $wpdb;
                if ( isset($wpdb) && method_exists( $wpdb, 'db_version' ) ) {
                    $context['mysql_version'] = $wpdb->db_version();
                }
            }

            // Request info (if available and PII allowed)
            $send_pii = defined( 'WP_SENTRY_SEND_DEFAULT_PII' ) ? WP_SENTRY_SEND_DEFAULT_PII : false;

            if ( isset($_SERVER['REQUEST_METHOD']) ) {
                $context['request_method'] = sanitize_text_field( $_SERVER['REQUEST_METHOD'] );
            }

            if ( $send_pii ) {
                if ( isset($_SERVER['REQUEST_URI']) ) {
                    $context['request_uri'] = sanitize_text_field( $_SERVER['REQUEST_URI'] );
                }
                if ( isset($_SERVER['HTTP_USER_AGENT']) ) {
                    $context['user_agent'] = sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] );
                }
            }

            return $context;
        }

        /**
         * Detect caching systems
         */
        private function detect_caching_systems()
        {
            $context = [];

            // Object cache
            $context['object_cache'] = wp_using_ext_object_cache();

            // Common caching plugins
            $caching_plugins = [];

            if ( defined( 'WP_CACHE' ) && WP_CACHE ) {
                $caching_plugins[] = 'WP_CACHE';
            }

            if ( function_exists( 'w3tc_flush_all' ) ) {
                $caching_plugins[] = 'W3 Total Cache';
            }

            if ( function_exists( 'wp_cache_clear_cache' ) ) {
                $caching_plugins[] = 'WP Super Cache';
            }

            if ( class_exists( 'WP_Rocket\Logger\Logger' ) ) {
                $caching_plugins[] = 'WP Rocket';
            }

            if ( function_exists( 'cachify_flush_cache' ) ) {
                $caching_plugins[] = 'Cachify';
            }

            if ( class_exists( 'LiteSpeed_Cache' ) ) {
                $caching_plugins[] = 'LiteSpeed Cache';
            }

            if ( ! empty($caching_plugins) ) {
                $context['caching_plugins'] = implode( ', ', $caching_plugins );
            }

            // Redis/Memcached
            if ( class_exists( 'Redis' ) ) {
                $context['redis_available'] = true;
            }
            if ( class_exists( 'Memcached' ) ) {
                $context['memcached_available'] = true;
            }

            return $context;
        }

        /**
         * Fallback formatter for bytes to human-readable string.
         *
         * @param float|int $bytes
         * @return string e.g. "12.34 MB"
         */
        private function format_bytes( $bytes )
        {
            $units = [ 'B', 'KB', 'MB', 'GB', 'TB', 'PB' ];
            $bytes = max( 0, (float) $bytes );
            $pow   = $bytes > 0 ? floor( log( $bytes, 1024 ) ) : 0;
            $pow   = (int) min( $pow, count( $units ) - 1 );
            $bytes = $bytes / pow( 1024, $pow );
            return round( $bytes, 2 ) . ' ' . $units[$pow];
        }

        /**
         * Get enhanced user context
         */
        private function get_user_context()
        {
            $context = [];

            if ( is_user_logged_in() ) {
                $user               = wp_get_current_user();
                $context['user_id'] = $user->ID;

                // Enhanced user info based on PII settings
                $send_pii = defined( 'WP_SENTRY_SEND_DEFAULT_PII' ) ? WP_SENTRY_SEND_DEFAULT_PII : false;

                if ( $send_pii ) {
                    $context['user_login']        = $user->user_login;
                    $context['user_email']        = $user->user_email;
                    $context['user_display_name'] = $user->display_name;
                }

                // User roles and capabilities (only if PII allowed)
                if ( $send_pii ) {
                    $context['user_roles'] = implode( ', ', $user->roles );
                    $context['user_level'] = isset($user->user_level) ? $user->user_level : 0;

                    // Registration info
                    if ( ! empty($user->user_registered) ) {
                        $context['user_registered'] = $user->user_registered;
                    }
                }
            } else {
                $context['user_logged_in'] = false;
            }

            return $context;
        }

        /**
         * Get WooCommerce specific context
         */
        private function get_woocommerce_context()
        {
            $context = [];

            // WooCommerce version
            if ( class_exists( 'WooCommerce' ) ) {
                $context['wc_version'] = WC()->version;
            }

            // Current WooCommerce page
            if ( function_exists( 'wc_get_page_id' ) ) {
                if ( is_shop() ) {
                    $context['wc_page'] = 'shop';
                } elseif ( is_cart() ) {
                    $context['wc_page'] = 'cart';
                } elseif ( is_checkout() ) {
                    $context['wc_page'] = 'checkout';
                } elseif ( is_account_page() ) {
                    $context['wc_page'] = 'account';
                } elseif ( is_product() ) {
                    $context['wc_page'] = 'product';
                    if ( function_exists( 'get_the_ID' ) ) {
                        $context['product_id'] = get_the_ID();
                    }
                } elseif ( is_product_category() ) {
                    $context['wc_page'] = 'product_category';
                } elseif ( is_product_tag() ) {
                    $context['wc_page'] = 'product_tag';
                }
            }

            return $context;
        }

        public function __destruct()
        {
            if ( $this->initialized && function_exists( '\Sentry\logger' ) ) {
                \Sentry\logger()->flush();
            }
        }
    }
}
