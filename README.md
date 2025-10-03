# WooCommerce Sentry Logger v1.0.0

A production-ready WordPress plugin that adds a custom WooCommerce log handler to send logs directly to Sentry using the official Sentry PHP SDK.

## Features

### Core Functionality
- **Full WooCommerce Integration** - Extends WC_Log_Handler with proper WC_Log_Handler_Interface implementation
- **All Log Levels** - Supports emergency, alert, critical, error, warning, notice, info, debug
- **Duplicate Prevention** - Advanced singleton pattern prevents duplicate log entries
- **Production Ready** - Clean, optimized code with proper error handling

### Context Enrichment
- **WordPress Environment** - Version, language, debug settings, multisite info, theme details
- **Server Information** - PHP version, server software, database version, memory usage
- **User Context** - User ID, roles, registration (with PII controls)
- **WooCommerce Data** - Version, current page type, product info
- **Plugin Information** - Configurable plugin statistics and detailed lists
- **System Context** - Caching systems, memory usage, request details

### Configuration & Security
- **Flexible Configuration** - Environment detection, PII controls, plugin logging options
- **Security Focused** - Configurable PII handling, safe data collection
- **Modern PHP** - 7.4+ compatibility with latest Sentry SDK integration

## Requirements

- WordPress 5.0+
- WooCommerce 5.0+
- PHP 7.4+
- Composer (for installing dependencies)

## Installation

1. Upload the plugin to `/wp-content/plugins/woocommerce-sentry-logger/`
2. Install dependencies:
   ```bash
   cd /wp-content/plugins/woocommerce-sentry-logger/
   composer install
   ```
3. Add Sentry configuration to `wp-config.php`:
   ```php
   define('WP_SENTRY_PHP_DSN', 'your-sentry-dsn-here');
   define('WP_SENTRY_ENV', 'production'); // Optional, defaults to 'production'
   ```
4. Activate the plugin through the WordPress admin
5. Configure WooCommerce to use the Sentry log handler

## Configuration

### wp-config.php

Add these constants to your `wp-config.php` file:

```php
// Required: Your Sentry DSN
define('WP_SENTRY_PHP_DSN', 'https://your-dsn@sentry.io/project-id');

// Optional: Environment name 
// Fallback order: WP_SENTRY_ENV → wp_get_environment_type() → 'production'
define('WP_SENTRY_ENV', 'production');

// Optional: Send user PII data (username, email, display name, IP addresses)
// Default: false - only user ID is sent
define('WP_SENTRY_SEND_DEFAULT_PII', false);

// Optional: Plugin logging configuration
// STATS: Only plugin counts (total, active, inactive, updates_needed)
// ALL: Complete plugin list with names, versions, authors + stats
// Not set: No plugin information sent
define('WP_SENTRY_PLUGIN_LOGGING', 'STATS'); // or 'ALL'
```

### WooCommerce Logger Settings

The plugin automatically registers itself as a selectable WooCommerce log handler.

#### Setting as Default Handler

1. Go to **WooCommerce > Settings > Advanced > Logs**
2. In the **Default log handler** dropdown, select **"Sentry (error tracking service)"**
3. Save changes

Once set as the default handler, all WooCommerce logs will automatically be sent to Sentry.

**Note:** The Sentry handler will only appear in the dropdown if:
- WooCommerce is installed and activated
- The plugin's composer dependencies are installed (`composer install`)
- `WP_SENTRY_PHP_DSN` is defined in `wp-config.php`

#### Using Specific Handler in Code

You can also specify the Sentry handler explicitly in your code:

```php
// Get WooCommerce logger
$logger = wc_get_logger();

// Log messages using the Sentry handler specifically
$logger->info('Order processed successfully', 'sentry', ['order_id' => 123]);
$logger->error('Payment failed', 'sentry', ['error' => $error_message]);
$logger->debug('Debug information', 'sentry', ['data' => $debug_data]);
```

The second parameter specifies the log handler to use ('sentry' for this plugin).

## Usage Examples

### Basic Logging

```php
$logger = wc_get_logger();

// Different log levels
$logger->emergency('System is unusable', 'sentry');
$logger->alert('Action must be taken immediately', 'sentry');
$logger->critical('Critical conditions', 'sentry');
$logger->error('Error conditions', 'sentry');
$logger->warning('Warning conditions', 'sentry');
$logger->notice('Normal but significant condition', 'sentry');
$logger->info('Informational messages', 'sentry');
$logger->debug('Debug-level messages', 'sentry');
```

### With Context Data

```php
$logger = wc_get_logger();

$context = [
    'user_id' => get_current_user_id(),
    'order_id' => $order->get_id(),
    'payment_method' => $order->get_payment_method(),
    'total' => $order->get_total()
];

$logger->error(
    'Payment processing failed for order {order_id}',
    'sentry',
    $context
);
```

### Hook into WooCommerce Events

```php
// Log when orders are created
add_action('woocommerce_new_order', function($order_id) {
    $logger = wc_get_logger();
    $order = wc_get_order($order_id);
    
    $logger->info(
        'New order created: {order_id}',
        'sentry',
        [
            'order_id' => $order_id,
            'total' => $order->get_total(),
            'status' => $order->get_status()
        ]
    );
});
```

## Context Enrichment

The plugin automatically adds comprehensive contextual information to all logs, providing rich debugging context in Sentry:

### WordPress Environment (`wp`)
- **Version & Language** - WordPress version, language, charset
- **Debug Settings** - WP_DEBUG, WP_DEBUG_LOG, WP_DEBUG_DISPLAY status
- **Multisite Info** - Blog ID, network ID (if applicable)
- **Memory Limits** - WP_MEMORY_LIMIT configuration

### Theme Information (`theme`)
- **Active Theme** - Current theme name and version
- **Parent Theme** - Parent theme info for child themes

### Server Environment (`server`)
- **PHP Details** - Version, SAPI, memory limit
- **Web Server** - Server software (nginx, Apache, etc.)
- **Database** - MySQL/MariaDB version

### System Runtime
- **Memory Usage** - Current and peak memory consumption
- **Caching Systems** - Object cache, plugin detection (W3TC, WP Rocket, etc.)
- **Request Info** - HTTP method, URI, user agent (PII controlled)

### User Context (`user`)
- **Basic Info** - User ID (always included), logged-in status
- **PII Data** (when `WP_SENTRY_SEND_DEFAULT_PII` enabled):
  - Login, email, display name, roles, registration date

### WooCommerce Context (`wc`)
- **Version** - WooCommerce version
- **Page Type** - shop, cart, checkout, account, product, category
- **Product Info** - Product ID on product pages

### Plugin Information (`plugins`)
Plugin data is controlled by `WP_SENTRY_PLUGIN_LOGGING`:

**STATS Mode:**
```json
{
  "total": 45,
  "active": 28,
  "inactive": 17,
  "updates_needed": 3
}
```

**ALL Mode:**
```json
{
  "total": 45,
  "active": 28,
  "inactive": 17,
  "updates_needed": 3,
  "list": "{\"active\":[{\"name\":\"WooCommerce\",\"version\":\"8.1.0\",\"author\":\"Automattic\"}...]}"
}
```

### IP Address Context (`ip`)
When PII is enabled, includes:
- Remote address, X-Forwarded-For, X-Real-IP headers

### Technical Metadata
- **Timestamp** - Log entry timestamp
- **Source** - Inferred source file from backtrace
- **Plugin Tags** - Plugin name and version

## Error Handling

The plugin includes comprehensive error handling:

- Graceful degradation if Sentry SDK is not available
- Admin notices for missing dependencies
- Fallback behavior when DSN is not configured
- Safe handling of complex data types in log context

## Development

### Directory Structure

```
woocommerce-sentry-logger/
├── woocommerce-sentry-logger.php         # Main plugin file
├── composer.json                         # Composer dependencies
├── includes/                             # Plugin classes
│   └── class-wc-sentry-log-handler.php  # Log handler extending WC_Log_Handler
├── vendor/                               # Composer dependencies (after install)
└── README.md                             # This file
```

### Log Level Mapping

WooCommerce → Sentry mapping:

- `emergency`, `alert`, `critical` → `fatal()`
- `error` → `error()`
- `warning` → `warn()`
- `notice`, `info` → `info()`
- `debug` → `debug()`

### Technical Implementation

**Handler Registration:**
- Uses `woocommerce_register_log_handlers` filter to register handler instances
- Uses `woocommerce_logger_handler_options` filter to make handler selectable in admin
- Handler extends `WC_Log_Handler` and implements the required `handle()` method
- **Singleton pattern prevents duplicate handler registration** - same instance reused across filter calls

**Duplicate Prevention Logic:**
```php
// Singleton handler instance
private static $handler_instance = null;

public function register_sentry_handler( $handlers ) {
    if ( class_exists( 'WC_Sentry_Log_Handler' ) ) {
        // Create singleton instance
        if ( null === self::$handler_instance ) {
            self::$handler_instance = new WC_Sentry_Log_Handler();
        }

        // Only add if not already present in handlers array
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
```

**Key Implementation Details:**
```php
// Environment detection with fallback (like wp-sentry)
private function get_environment() {
    $environment = defined('WP_SENTRY_ENV') ? WP_SENTRY_ENV : null;

    if ($environment === null && function_exists('wp_get_environment_type')) {
        $environment = wp_get_environment_type();
    }

    return $environment ?? 'production';
}

// Sentry initialization with duplicate prevention
private function init_sentry() {
    if ( $this->initialized ) {
        return; // Prevent multiple initializations
    }

    \Sentry\init([
        'dsn' => WP_SENTRY_PHP_DSN,
        'environment' => $this->get_environment(),
        'enable_logs' => true,
        'send_default_pii' => defined('WP_SENTRY_SEND_DEFAULT_PII') ? WP_SENTRY_SEND_DEFAULT_PII : false,
        'tags' => [
            'plugin' => 'woocommerce-sentry-logger',
            'version' => WC_Sentry_Logger_Plugin::VERSION
        ]
    ]);

    $this->initialized = true;
}
```

## Troubleshooting

### Plugin Not Working

1. Ensure WooCommerce is installed and activated
2. Check that `WP_SENTRY_PHP_DSN` is defined in `wp-config.php`
3. Verify composer dependencies are installed (`composer install`)
4. Check WordPress admin for error notices

### Logs Not Appearing in Sentry

1. Verify your Sentry DSN is correct
2. Check Sentry project settings and quotas
3. Ensure the environment matches your Sentry configuration
4. Test with a simple log message to isolate the issue

### Duplicate Logs in Sentry

**This issue has been resolved** in the current version through:
- Singleton pattern for handler instances
- Duplicate detection in handler registration
- Prevention of multiple Sentry SDK initializations

If you still experience duplicates, ensure you're running the latest version of the plugin.

### Handler Interface Errors

If you see errors about `WC_Log_Handler_Interface`, this usually indicates a loading order issue:

1. The plugin loads with priority 20 on `plugins_loaded` to ensure WooCommerce is fully loaded
2. Enable `WP_DEBUG` to see debug notices if class loading fails
3. Ensure WooCommerce is activated before this plugin
4. Check that WooCommerce version is 5.0 or higher

### Performance Considerations

- **Duplicate Prevention** - Singleton pattern prevents duplicate handlers and Sentry calls
- **Memory Optimization** - Efficient context collection with size limits (50 plugins max per type)
- **JSON Encoding** - Complex data safely encoded for Sentry transmission
- **Single Initialization** - Sentry SDK initialized only once per request
- **Graceful Degradation** - Continues working even if Sentry is unavailable

## Version History

### v1.0.0 (2025-09-26)
- **Initial Release** - Production-ready WooCommerce Sentry integration
- **Core Features** - Full log level support, duplicate prevention, rich context
- **Plugin Logging** - Configurable plugin statistics and detailed lists
- **PII Controls** - Granular privacy controls for user data
- **Production Optimized** - Clean code, proper error handling, memory efficient

### v1.0.1 (2025-10-03)
- **FIX** - fatal error in const VERSION

## License

This plugin is licensed under the GPL v3 or later.

## Support

For issues and support, please create an issue in the project repository.
