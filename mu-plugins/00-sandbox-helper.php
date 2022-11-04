<?php
ini_set( 'xdebug.cli_color', 1 );
error_reporting( E_ERROR | E_WARNING | E_PARSE ); // Disable notices in logs.

foreach( array(
	__DIR__ . '/sandbox-wp-debugger/sandbox-wp-debugger.php',
	__DIR__ . '/wp-toolbar/wp-toolbar.php',
) as $mu_plugin_file ) {
	if ( file_exists( $mu_plugin_file ) ) {
		require_once $mu_plugin_file;
	} else {
		error_log( 'SANDBOX-HELPER: Missing File ' . $mu_plugin_file );
	}
}

if ( ! function_exists( 'vip_dump' ) ) {
	function vip_dump( $var = null ) {
		$old_setting = ini_get( 'html_errors' );
		ini_set( 'html_errors', false );
		ini_set( 'xdebug.cli_color', 2 );

		ob_start();
		var_dump( $var );
		$out1 = ob_get_contents();
		ob_end_clean();
		error_log( $out1 );

		ini_set( 'xdebug.cli_color', 1 );
		ini_set( 'html_errors', $old_setting );
	}
}

// Add Sandbox WP Debugger support for internal REST API requests.
add_action( 'rest_pre_dispatch', function( $result, $server, $request ) {
	if ( function_exists( 'swpd_log' ) && defined( 'SWPD_REST_DEBUG' ) ) {
		swpd_log( 'rest_do_request', $request->get_route(), $request->get_params(), [], true );
	}
}, 10, 3 );

// Stolen from https://github.com/rickhurst/vip-login-limit-debug/
/* Add message above login form */
function sh_vip_debug_login_message( $message ) {
	if ( isset( $_GET['clearcache'] ) ) {
		$ip_username = explode( '|', $_GET['clearcache'] );

		if ( 2 === count( $ip_username ) ) {
			$ip                    = $ip_username[0];
			$username              = $ip_username[1];
			$ip_username_cache_key = $_GET['clearcache'];
		}

		wp_cache_delete( $ip, CACHE_GROUP_LOGIN_LIMIT );
		wp_cache_delete( $ip_username_cache_key, CACHE_GROUP_LOGIN_LIMIT );
		wp_cache_delete( $username, CACHE_GROUP_LOGIN_LIMIT );
	}

	// check if the username is set as limited
	if (
		isset( $_POST['log'] ) &&
		'' !== $_POST['log'] &&
		wpcom_vip_username_is_limited( $_POST['log'], CACHE_GROUP_LOGIN_LIMIT )
	) {
		// phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders
		$ip                    = filter_var( $_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP, [ 'options' => [ 'default' => '' ] ] );
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
			$_SERVER['REQUEST_URI']
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
add_filter( 'login_message', 'sh_vip_debug_login_message', 10, 1 );

// Stolen from https://github.com/Automattic/vip-error-messages
function sh_vip_add_to_error_message($message, $error){
	$extra_info = '';
	$extra_info .= '<ul>';
	if(isset($error['message'])){
		$extra_info .= '<li>'.$error['message'].'</li>';
	}
	if(isset($error['file'])){
		$extra_info .= '<li>'.$error['file'].'</li>';
	}
	if(isset($error['line'])){
		$extra_info .= '<li>line: '.$error['line'].'</li>';
	}
	$extra_info .= '</ul>'; 

	return $message.$extra_info;
}
add_filter('wp_php_error_message', 'sh_vip_add_to_error_message', 10, 2);

function vip_timer_start( $name ) {
	global $vip_timer;

	$vip_timer[ $name ]['start'] = hrtime( true );
	$vip_timer[ $name ]['status'] = 'running';
}

function vip_timer_stop( $name ) {
	global $vip_timer;

	if ( is_array( $vip_timer ) ) {
		$vip_timer[ $name ]['stop']   = hrtime( true );
		$vip_timer[ $name ]['time']   = $vip_timer[ $name ]['stop'] - $vip_timer[ $name ]['start'];
		$vip_timer[ $name ]['status'] = 'stopped';

		return $vip_timer[ $name ]['time'];
	}

	return false;
}

function vip_timer_get( $name, $resolution = 'ms' ) {
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
