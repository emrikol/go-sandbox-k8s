<?php
/**
 * General helper functions for debugging in sandboxes.
 */

if ( ! function_exists( 'vip_dump' ) ) {
	/**
	 * Debug helper that acts like var_dump() but outputs to the error log.
	 *
	 * @param  mixed $var_to_dump The object to dump.
	 *
	 * @return void
	 */
	function vip_dump( $var_to_dump = null ) {
		if ( 0 === ob_get_level() ) {
			$old_setting = ini_get( 'html_errors' );
			ini_set( 'html_errors', false ); // phpcs:ignore WordPress.PHP.IniSet.Risky
			ini_set( 'xdebug.cli_color', 2 ); // phpcs:ignore WordPress.PHP.IniSet.Risky

			ob_start();
			var_dump( $var_to_dump ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_dump
			$out1 = ob_get_contents();
			ob_end_clean();
			error_log( $out1 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions

			ini_set( 'xdebug.cli_color', 1 ); // phpcs:ignore WordPress.PHP.IniSet.Risky
			ini_set( 'html_errors', $old_setting ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		} else {
			error_log( var_export( $var_to_dump, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log, WordPress.PHP.DevelopmentFunctions.error_log_var_export
		}
	}
}

/**
 * Starts a timer.
 *
 * @param  string $name Timer name.
 *
 * @return void
 */
function vip_timer_start( string $name ): void {
	global $vip_timer;

	$vip_timer[ $name ]['start']  = hrtime( true );
	$vip_timer[ $name ]['status'] = 'running';
}

/**
 * Stops a timer.
 *
 * @param  string $name Timer name.
 *
 * @return bool|array  The timer array, or false if the timer is missing.
 */
function vip_timer_stop( string $name ): bool|array {
	global $vip_timer;

	if ( is_array( $vip_timer ) ) {
		$vip_timer[ $name ]['stop']   = hrtime( true );
		$vip_timer[ $name ]['time']   = $vip_timer[ $name ]['stop'] - $vip_timer[ $name ]['start'];
		$vip_timer[ $name ]['status'] = 'stopped';

		return $vip_timer[ $name ]['time'];
	}

	return false;
}

/**
 * Returns timer information as a string.
 *
 * @param  string $name Timer name.
 * @param  string $resolution Time resolution, either 'ms' or 'ns', defaults to 'ms'.
 *
 * @return string             Timer information.
 */
function vip_timer_get( string $name, string $resolution = 'ms' ): string {
	global $vip_timer;

	if ( ! is_array( $vip_timer ) || ! is_array( $vip_timer[ $name ] ) ) {
		return 'Unknown Timer';
	}

	if ( 'stopped' === $vip_timer[ $name ]['status'] ) {
		$time = $vip_timer[ $name ]['time'];
	} else {
		$time = hrtime( true ) - $vip_timer[ $name ]['start'];
	}

	switch ( $resolution ) {
		case 'ns':
			$time = $time;
			break;
		case 'ms':
			$time /= 1e+6;
			break;
		default:
			return 'Unknown Resolution: ' . $resolution;
	}

	$time .= $resolution;

	if ( 'running' === $vip_timer[ $name ]['status'] ) {
		$time .= '+';
	}

	return sprintf( 'Timer %s: %s', $name, $time );
}

/**
 * Adds information about failed and blocked logins.
 *
 * @see https://github.com/rickhurst/vip-login-limit-debug/
 *
 * @param  string $message Login message text.
 *
 * @return string          Login text with additional information.
 */
function vip_debug_login_message( string $message ): string {
	if ( isset( $_GET['clearcache'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ip_username = explode( '|', $_GET['clearcache'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( 2 === count( $ip_username ) ) {
			$ip                    = $ip_username[0];
			$username              = $ip_username[1];
			$ip_username_cache_key = $_GET['clearcache']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		wp_cache_delete( $ip, CACHE_GROUP_LOGIN_LIMIT );
		wp_cache_delete( $ip_username_cache_key, CACHE_GROUP_LOGIN_LIMIT );
		wp_cache_delete( $username, CACHE_GROUP_LOGIN_LIMIT );
	}

	// check if the username is set as limited.
	if (
		isset( $_POST['log'] ) && // phpcs:ignore WordPress.Security.NonceVerification.Missing
		'' !== $_POST['log'] && // phpcs:ignore WordPress.Security.NonceVerification.Missing
		wpcom_vip_username_is_limited( $_POST['log'], CACHE_GROUP_LOGIN_LIMIT ) // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	) {
		// phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders
		$ip                    = filter_var( $_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP, array( 'options' => array( 'default' => '' ) ) ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__, WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders
		$username              = $_POST['log']; //phpcs:ignore
		$ip_username_cache_key = $ip . '|' . $username;

		$login_vars = array(
			'ip'                    => $ip,
			'ip_username_cache_key' => $ip_username_cache_key,
			'ip_count'              => wp_cache_get( $ip, CACHE_GROUP_LOGIN_LIMIT ),
			'ip_username_count'     => wp_cache_get( $ip_username_cache_key, CACHE_GROUP_LOGIN_LIMIT ),
			'username_count'        => wp_cache_get( $username, CACHE_GROUP_LOGIN_LIMIT ),
			'ip_username_threshold' => apply_filters( 'wpcom_vip_ip_username_login_threshold', 5, $ip, $username ),
			'ip_threshold'          => apply_filters( 'wpcom_vip_ip_login_threshold', 50, $ip ),
			'username_threshold'    => apply_filters( 'wpcom_vip_username_login_threshold', 5 * apply_filters( 'wpcom_vip_ip_username_login_threshold', 5, $ip, $username ), $username ),
		);

		ob_start();
		var_dump($login_vars); //phpcs:ignore
		$debug_info = ob_get_contents();
		ob_end_clean();

		$clear_cache_link = add_query_arg(
			array(
				'clearcache' => $ip_username_cache_key,
			),
			$_SERVER['REQUEST_URI'] // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		);

		$message .= '<style>#login{max-width: 960px; width: initial !important;}</style><div class="message"><b>VIP Debug Info</b>'.$debug_info.'<br/><p><a href="' . esc_url( $clear_cache_link ) . '">Clear User/IP Cache</a></p></div>'; //phpcs:ignore

		if ( $login_vars['ip_username_count'] >= $login_vars['ip_username_threshold'] ) {
			$message .= '<p class="message">Reached IP Username Threshold</p>';
		}

		if ( $login_vars['ip_count'] >= $login_vars['ip_threshold'] ) {
			$message .= '<p class="message">Reached IP Threshold</p>';
		}

		if ( $login_vars['username_count'] >= $login_vars['username_threshold'] ) {
			$message .= '<p class="message">Reached IP Threshold</p>';
		}
	}

	return $message;
}
add_filter( 'login_message', 'vip_debug_login_message', 10, 1 );

/**
 * Adds more details to the core error PHP error messages when available.
 *
 * @see https://github.com/Automattic/vip-error-messages
 *
 * @param  string $message HTML error message to display.
 * @param  array  $error   Error information retrieved from error_get_last().
 *
 * @return string          HTML error message to display.
 */
function vip_add_to_error_message( string $message, array $error ): string {
	// We only want to output errors if WP_DEBUG is enabled.
	if ( ! defined( 'WP_DEBUG' ) || true !== WP_DEBUG ) {
		return $message;
	}

	$error_type    = 'E_UNKNOWN';
	$error_message = isset( $error['message'] ) ? $error['message'] : 'Unknown Message';
	$error_file    = isset( $error['file'] ) ? $error['file'] : 'Unknown File';
	$error_line    = isset( $error['line'] ) ? $error['line'] : 'Unknown Line';

	if ( isset( $error['type'] ) ) {
		switch ( $error['type'] ) {
			case E_ERROR: // 1.
				$error_type = 'E_ERROR';
				break;
			case E_WARNING: // 2.
				$error_type = 'E_WARNING';
				break;
			case E_PARSE: // 4.
				$error_type = 'E_PARSE';
				break;
			case E_NOTICE: // 8.
				$error_type = 'E_NOTICE';
				break;
			case E_CORE_ERROR: // 16.
				$error_type = 'E_CORE_ERROR';
				break;
			case E_CORE_WARNING: // 32.
				$error_type = 'E_CORE_WARNING';
				break;
			case E_COMPILE_ERROR: // 64.
				$error_type = 'E_COMPILE_ERROR';
				break;
			case E_COMPILE_WARNING: // 128.
				$error_type = 'E_COMPILE_WARNING';
				break;
			case E_USER_ERROR: // 256.
				$error_type = 'E_USER_ERROR';
				break;
			case E_USER_WARNING: // 512.
				$error_type = 'E_USER_WARNING';
				break;
			case E_USER_NOTICE: // 1024.
				$error_type = 'E_USER_NOTICE';
				break;
			case E_STRICT: // 2048.
				$error_type = 'E_STRICT';
				break;
			case E_RECOVERABLE_ERROR: // 4096.
				$error_type = 'E_RECOVERABLE_ERROR';
				break;
			case E_DEPRECATED: // 8192.
				$error_type = 'E_DEPRECATED';
				break;
			case E_USER_DEPRECATED: // 16384.
				$error_type = 'E_USER_DEPRECATED';
				break;
		}
	}

	$extra_message = sprintf(
		'<h3>Error Details</h3><div style="%s"><strong>%s:</strong> <code>%s</code><br /><br /><code>%s</code> Line <code>%s</code></div>',
		'padding: 15px; background-color: rgba( 0,0,0,.05);',
		$error_type,
		$error_message,
		$error_file,
		$error_line
	);

	return $message . $extra_message;
}
add_filter( 'wp_php_error_message', 'vip_add_to_error_message', 10, 2 );
