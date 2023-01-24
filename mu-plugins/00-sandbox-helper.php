<?php
/**
 * Primary file for Sandbox Helper.
 */

// phpcs:ignore WordPress.PHP.IniSet.Risky
ini_set( 'xdebug.cli_color', 1 ); // Enable colors in CLI var_dump() output.

// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting
error_reporting( E_ERROR | E_WARNING | E_PARSE ); // Disable notices in logs.

/**
 * Set to true to enable some specific Sandbox Helper debugs:
 */
define( 'VIP_SWPD_REST_DEBUG', false ); // REST Requests.
define( 'VIP_SWPD_SQL_DEBUG', false ); // SQL Queries.
define( 'VIP_SWPD_MEMCACHE_DEBUG', false ); // Memcache.
define( 'VIP_SWPD_SLOW_HOOKS_DEBUG', false ); // Slow Hooks.

foreach ( array(
	__DIR__ . '/00-sandbox-helper/sandbox-wp-debugger/sandbox-wp-debugger.php',
	__DIR__ . '/00-sandbox-helper/sandbox-wp-debugger.php',
	__DIR__ . '/00-sandbox-helper/class-vip-go-sandbox-helpers-command.php',
	__DIR__ . '/00-sandbox-helper/debugging-helpers.php',
) as $vip_mu_plugin_file ) {
	if ( file_exists( $vip_mu_plugin_file ) ) {
		require_once $vip_mu_plugin_file;
	} else {
		error_log( 'SANDBOX-HELPER: Missing File ' . $vip_mu_plugin_file ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}

if ( defined( 'VIP_SWPD_SLOW_HOOKS_DEBUG' ) && true === VIP_SWPD_SLOW_HOOKS_DEBUG ) {
	require_once __DIR__/ . '/00-sandbox-helper/class-swpd-slow-hooks.php'
}
