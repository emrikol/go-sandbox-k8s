<?php
ini_set( 'xdebug.cli_color', 1 );
error_reporting( E_ERROR | E_WARNING | E_PARSE ); // Disable notices in logs.

// Uncomment to enable some specific Sandbox Helper debugs:
//define( 'SWPD_REST_DEBUG', true ); // REST Requests.
//define( 'SWPD_SQL_DEBUG', true ); // SQL Queries.
//define( 'SWPD_MEMCACHE_DEBUG', true ); // Memcache.

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
		swpd_log(
			function: 'rest_do_request',
			message: 'REST Route: ' . $request->get_route(),
			data: $request->get_params()
		);
	}
}, PHP_INT_MAX, 3 );

add_action( 'shutdown', function() {
	if ( function_exists( 'swpd_log' ) && defined( 'SWPD_SQL_DEBUG' ) ) {
		$slow_queries = new SlowQueries();
		swpd_log(
			function: 'Query Summary',
			message: $slow_queries->render_sql_queries() . 'Query Summary: ' . PHP_EOL . $slow_queries->render_sql_queries(),
			data: array(),
			backtrace: false
		);
	}
}, PHP_INT_MAX, 3 );

add_action( 'shutdown', function() {
	if ( function_exists( 'swpd_log' ) && defined( 'SWPD_MEMCACHE_DEBUG' ) ) {

		global $wp_object_cache;

		$total_memcache_time = 'Total memcache query time: ' . number_format_i18n( sprintf( '%0.1f', $wp_object_cache->time_total * 1000 ), 1 ) . ' ms';
		$total_memcache_size = 'Total memcache size: ' . size_format( $wp_object_cache->size_total, 2 );

		$memcache_stats = array();

		foreach ( $wp_object_cache->stats as $stat => $n ) {
			if ( empty( $n ) ) {
				continue;
			}

			$memcache_stats[] = sprintf( '%s %s', $stat, $n );
		}

		$data = array_map(function($key, $value) {
			return array($key,$value);
		}, array_keys( $wp_object_cache->stats), $wp_object_cache->stats );
		$data = array_merge( array( array( 'Method', 'Calls' ) ), $data );

		$calls_table = vip_array_to_ascii_table( $data );


		$groups = array_keys( $wp_object_cache->group_ops );
		usort( $groups, 'strnatcasecmp' );

		$active_group = $groups[0];
		// Always show `slow-ops` first
		if ( in_array( 'slow-ops', $groups ) ) {
			$slow_ops_key = array_search( 'slow-ops', $groups );
			$slow_ops = $groups[ $slow_ops_key ];
			unset( $groups[ $slow_ops_key ] );
			array_unshift( $groups, $slow_ops );
			$active_group = 'slow-ops';
		}

		$total_ops = 0;
		$group_titles = array();
		$groups_table = array( array( 'Group Name', 'Ops', 'Size', 'Time' ) );
		foreach ( $groups as $group ) {
			$group_name = $group;
			if ( empty( $group_name ) ) {
				$group_name = 'default';
			}
			$group_ops = count( $wp_object_cache->group_ops[ $group ] );
			$group_size = size_format( array_sum( array_map( function ( $op ) { return $op[2]; }, $wp_object_cache->group_ops[ $group ] ) ), 2 );
			$group_time = number_format_i18n( sprintf( '%0.1f', array_sum( array_map( function ( $op ) { return $op[3]; }, $wp_object_cache->group_ops[ $group ] ) ) * 1000 ), 1 );
			$total_ops += $group_ops;
			$group_title = "{$group_name} [$group_ops][$group_size][{$group_time} ms]";
			$group_titles[ $group ] = $group_title;
			//echo "\t<li><a href='#' onclick='memcachedToggleVisibility( \"object-cache-stats-menu-target-" . esc_js( $group_name ) . "\", \"object-cache-stats-menu-target-\" );'>" . esc_html( $group_title ) . "</a></li>\n";
			$groups_table[] = array(
				$group_name,
				$group_ops,
				$group_size,
				$group_time . 'ms',
			);
		}

		$groups_table = vip_array_to_ascii_table( $groups_table );

		$group_details_table = array( array( 'Group Details' ) );
		foreach ( $groups as $group ) {
			$group_name = $group;
			if ( empty( $group_name ) ) {
				$group_name = 'default';
			}
			//$current = $active_group == $group ? 'style="display: block"' : 'style="display: none"';
			//echo "<div id='object-cache-stats-menu-target-" . esc_attr( $group_name ) . "' class='object-cache-stats-menu-target' $current>\n";
			//echo '<h3>' . esc_html( $group_titles[ $group ] ) . '</h3>' . "\n";
			$group_ops_line = '';
			foreach ( $wp_object_cache->group_ops[ $group ] as $index => $arr ) {
				$group_ops_line .= sprintf( '%3d ', $index );
				$group_ops_line .= vip_get_group_ops_line( $index, $arr );
			}
			$group_details_table[] = array(
				wordwrap( $group_titles[ $group ], 50, PHP_EOL, true ),
				wordwrap( $group_ops_line, 50, PHP_EOL, true ),
			);

		}
	//$groups_table = vip_array_to_ascii_table( $group_details_table );
	vip_dump( $group_details_table );
		//echo "</div>";

		if ( function_exists( 'swpd_log' ) && defined( 'WP_DEBUG' ) ) {
			swpd_log(
				function: 'Object Cache Summary',
				message: 'Memcache Stats:' . PHP_EOL . $total_memcache_time . PHP_EOL . $total_memcache_size . PHP_EOL . PHP_EOL . $calls_table . PHP_EOL . PHP_EOL . $groups_table,
				data: array(),
				backtrace: false
			);
		}
	}
}, PHP_INT_MAX, 3 );

	function vip_get_group_ops_line( $index, $arr ) {
		// operation
		$line = "{$arr[0]} ";

		// key
		$json_encoded_key = json_encode( $arr[1] );
		$line .= $json_encoded_key . " ";

		// comment
		if ( ! empty( $arr[4] ) ) {
			$line .= "{$arr[4]} ";
		}

		// size
		if ( isset( $arr[2] ) ) {
			$line .= '(' . size_format( $arr[2], 2 ) . ') ';
		}

		// time
		if ( isset( $arr[3] ) ) {
			$line .= '(' . number_format_i18n( sprintf( '%0.1f', $arr[3] * 1000 ), 1 ) . ' ms)';
		}

		// backtrace
		$bt_link = '';
		if ( isset( $arr[6] ) ) {
			$key_hash = md5( $index . $json_encoded_key );
			$bt_link .= $arr[6];
		}

		return $line;

		return $this->colorize_debug_line( $line, $bt_link );
	}

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

// https://stackoverflow.com/a/73692927
function vip_unicode_safe_str_pad( string $string, int $length, string $pad_string = ' ' ): string {
	//$multiline_string = "This is a very long string that needs to be wrapped to a specific number of characters.
	//It's a multiline string and it will be used to get the length of the longest line.";

	$lines = explode("\n", $string);
	$lengths = array_map('strlen', $lines);

	$max_length = max($lengths);



	$times = $length - mb_strlen($string) >=0 ? $length - mb_strlen($string) : 0;
	$times = $length - $max_length;
	return $string . str_repeat( $pad_string, $times );
}

function vip_array_to_ascii_table( array $rows = array() ): string {
	if ( count( $rows ) === 0 ) {
		return '';
	}

	$widths = array();

	foreach ( $rows as $cells ) {
		foreach ($cells as $j => $cell) {
			if (($width = strlen($cell) + 2) >= ($widths[$j] ?? 0)) {
				$widths[$j] = $width;
			}
		}
	}

	$hBar = str_repeat('─', array_sum($widths) + count($widths) - 1);
	$topB = sprintf("┌%s┐", $hBar);
	$midB = sprintf("├%s┤", $hBar);
	$botB = sprintf("└%s┘", $hBar);

	$result[] = $topB;

	foreach ($rows as $i => $cells) {
		$result[] = sprintf("│%s│", implode('│', array_map(function ($c, $w): string {
			return vip_unicode_safe_str_pad(" {$c} ", $w);
		}, $cells, $widths)));
		if ($i === 0) {
			$result[] = $midB;
		}
	}
	$result[] = $botB;

	return implode(PHP_EOL, $result);
}

add_filter( 'rest_attachment_query', function( $args, $request ) {
	//$args['es'] = true; // Offloads to Enterprise Search, very fast.
	$args['no_found_rows'] = true; // Removes SQL_CALC_FOUND_ROWS and reduces query to under 1ms.

	return $args;
}, 10, 2 );
