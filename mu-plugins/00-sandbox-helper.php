<?php
/**
 * Primary file for Sandbox Helper.
 */

// phpcs:ignore WordPress.PHP.IniSet.Risky
ini_set( 'xdebug.cli_color', 1 ); // Enable colors in CLI var_dump() output.

// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting
error_reporting( E_ERROR | E_WARNING | E_PARSE ); // Disable notices in logs.

if ( version_compare( PHP_VERSION, '8.0.0', '>=' ) ) {
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
}
