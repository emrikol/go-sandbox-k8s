<?php
/**
 * WP-CLI Commands for Sandbox Helper.
 *
 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
 * phpcs:disable WordPressVIPMinimum.Classes.RestrictedExtendClasses.wp_cli
 * phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag
 */

if ( ! class_exists( 'WP_CLI_Command' ) ) {
	return;
}

/**
 * VIP_Go_Sandbox_Helpers_Command Class.
 */
class VIP_Go_Sandbox_Helpers_Command extends WP_CLI_Command {
	/**
	 * Profiles DB performance by running SQL queries and returning timing statistics.
	 */
	public function db_profile( $args, $assoc_args ) {
		$format = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		$site_sql_lines = <<<END
SHOW TABLES LIKE 'wp_a8c_cron_control_jobs';
END;

		$lines = explode( PHP_EOL, $site_sql_lines );

		global $wpdb;

		$stats         = array();
		$total_time_us = 0;
		$runs          = 100;
		$progress      = \WP_CLI\Utils\make_progress_bar( sprintf( 'Running %s Queries', number_format( count( $lines ) * $runs ) ), count( $lines ) * $runs );

		for ( $run = 1; $run <= $runs; $run++ ) {
			foreach ( $lines as $index => $line ) {
				if ( ! isset( $stats[ $index ] ) || ! is_array( $stats[ $index ] ) ) {
					$stats[ $index ] = array(
						'query' => $line,
						'runs'  => array(),
					);
				}

				$start_time = hrtime( true );
				$results    = count( $wpdb->get_results( $line, ARRAY_N ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
				$end_time   = hrtime( true );
				$time_us    = ( $end_time - $start_time ) / 1000;

				$total_time_us += $time_us;

				$stats[ $index ]['runs'][ $run ] = $time_us;
				$progress->tick();
			}
		}

		$data = array();

		foreach ( $stats as $index => $stat ) {
			$stats[ $index ]['stats'] = array(
				'min' => min( $stats[ $index ]['runs'] ),
				'max' => max( $stats[ $index ]['runs'] ),
				'avg' => array_sum( $stats[ $index ]['runs'] ) / count( $stats[ $index ]['runs'] ),
			);

			$data[ $index ] = array(
				'Query'  => $stat['query'],
				'Min'    => number_format( (float) $stats[ $index ]['stats']['min'] / 1000, 3 ),
				'Max'    => number_format( (float) $stats[ $index ]['stats']['max'] / 1000, 3 ),
				'Avg'    => number_format( (float) $stats[ $index ]['stats']['avg'] / 1000, 3 ),
				'StdDev' => number_format( (float) $this->stats_standard_deviation( $stats[ $index ]['runs'] ) / 1000, 3 ),
			);

			if ( 'csv' === $format ) {
				$data[ $index ]['Runs'] = wp_json_encode( $stats[ $index ]['runs'] );
			}
		}

		$progress->finish();

		// Output data.
		WP_CLI\Utils\format_items( $format, $data, array_keys( $data[0] ) );

		// Output totals if we're not piping somewhere.
		if ( ! WP_CLI\Utils\isPiped() ) {
			WP_CLI::success(
				sprintf(
					'Total queries ran: %s, DB Time Taken: %s',
					WP_CLI::colorize( '%g' . number_format( count( $lines ) * $runs, 0 ) . '%n' ),
					WP_CLI::colorize( '%g' . $this->convert_to_human_readable( $total_time_us ) . '%n' ),
				)
			);
		}
	}

	/**
	 * This user-land implementation follows the implementation quite strictly;
	 * it does not attempt to improve the code or algorithm in any way. It will
	 * raise a warning if you have fewer than 2 values in your array, just like
	 * the extension does (although as an E_USER_WARNING, not E_WARNING).
	 *
	 * @see https://www.php.net/manual/en/function.stats-standard-deviation.php#114473
	 *
	 * @param array $a      The array to use.
	 * @param bool  $sample Defaults to false.
	 * @return float|bool   The standard deviation or false on error.
	 */
	private function stats_standard_deviation( array $a, bool $sample = false ): mixed {
		$n = count( $a );

		if ( 0 === $n ) {
			trigger_error( 'The array has zero elements', E_USER_WARNING ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
			return false;
		}

		if ( $sample && 1 === $n ) {
			trigger_error( 'The array has only 1 element', E_USER_WARNING ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
			return false;
		}

		$mean  = array_sum( $a ) / $n;
		$carry = 0.0;

		foreach ( $a as $val ) {
			$d      = ( (float) $val ) - $mean;
			$carry += $d * $d;
		};

		if ( $sample ) {
			--$n;
		}

		return sqrt( $carry / $n );
	}


	/**
	 * Converts microseconds to human readable duration.
	 *
	 * @param  float $microseconds Microseconds duration.
	 *
	 * @return string              Human readable duration.
	 */
	private function convert_to_human_readable( $microseconds ): string {
		$seconds      = $microseconds / 1000000;
		$minutes      = (int) ( $seconds / 60 );
		$seconds      = $seconds % 60;
		$milliseconds = $microseconds % 1000000 / 1000;

		$time = '';
		if ( $minutes > 0 ) {
			$time .= $minutes . 'm';
		}

		if ( $minutes > 0 && $seconds > 0 ) {
			$time .= ', ';
		}

		$time .= $seconds . 's';

		if ( $seconds > 0 && $milliseconds > 0 ) {
			$time .= ', ';
		}

		$time .= $milliseconds . 'ms';

		return $time;
	}

	/**
	 * Undeletes an image from the VIP Go files service.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : The URL of the image to undelete.
	 */
	public function undelete( $args, $assoc_args ) {
		if ( ! defined( 'FILES_CLIENT_SITE_ID' ) || ! defined( 'FILES_ACCESS_TOKEN' ) ) {
			WP_CLI::error( 'Missing VIP constants!' );
		}

		$url = esc_url_raw( $args[0] );

		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			WP_CLI::error( 'Not a valid URL: %s', $url );
		}

		$response = wp_remote_request(
			$url,
			array(
				'method'  => 'DELETE',
				'timeout' => 10, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
				'headers' => array(
					'X-Client-Site-ID' => FILES_CLIENT_SITE_ID,
					'X-Action'         => 'undelete',
					'X-Client-Host'    => wp_parse_url( $url, PHP_URL_HOST ),
					'X-Access-Token'   => FILES_ACCESS_TOKEN,
				),
			)
		);

		if ( $response instanceof WP_Error ) {
			var_dump( $response ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_dump
			WP_CLI::error( sprintf( 'Could not undelete %s', $url ) );
		}

		if ( 200 !== (int) $response['response']['code'] ) {
			WP_CLI::error( sprintf( 'Could not undelete %s, %d HTTP Status returned!', $url, $response['response']['code'] ) );
		}

		WP_CLI::success( sprintf( 'Undeleted %s', $url ) );
	}

	/**
	 * Gets size of database tables for the current site.
	 *
	 * ## OPTIONS
	 *
	 * [--subsite]
	 * : Outputs the size of individual subsites as a whole in a multisite installation.
	 *
	 * [--raw]
	 * : Outputs full size in bytes instead of human readable sizes.
	 *
	 * [--order_by=<Total>]
	 * : Allows custom ordering of the data.
	 * ---
	 * default: Total
	 * options:
	 *   - Table
	 *   - Data Size
	 *   - Index Size
	 *   - Total
	 * ---
	 *
	 * [--order=<asc>]
	 * : Allows custom ordering direction of the data.
	 * ---
	 * default: asc
	 * options:
	 *   - asc
	 *   - desc
	 * ---
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - count
	 *   - yaml
	 * ---
	 *
	 * @subcommand db-size
	 */
	public function db_size( $args, $assoc_args ) {
		global $wpdb;

		$output   = array();
		$db_size  = array(
			'data'  => 0,
			'index' => 0,
		);
		$order_by = WP_CLI\Utils\get_flag_value( $assoc_args, 'order_by', 'Total' );
		$order    = WP_CLI\Utils\get_flag_value( $assoc_args, 'order', 'asc' );
		$format   = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		$raw      = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'raw', false );
		$subsite  = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'subsite', false );

		// Fetch list of tables from database.
		$tables = array_map(
			function( $val ) {
				return $val[0];
			},
			$wpdb->get_results( 'SHOW TABLES;', ARRAY_N ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		);

		// Initialize an array to store subsite sizes.
		$subsite_sizes = array();

		// Fetch table information from database.
		foreach ( $tables as $table ) {
			$table_info = $wpdb->get_row( "SHOW TABLE STATUS LIKE '$table'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			// Keep a running total of sizes.
			$db_size['data']  += $table_info->Data_length; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$db_size['index'] += $table_info->Index_length; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

			if ( true === $subsite ) {
				// Extract subsite ID from table name.
				preg_match( '/^wp_(\d+)_/', $table_info->Name, $matches );
				$subsite_prefix = isset( $matches[1] ) ? "wp_{$matches[1]}_" : 'wp_';

				// Calculate total size for each subsite.
				if ( ! isset( $subsite_sizes[ $subsite_prefix ] ) ) {
					$subsite_sizes[ $subsite_prefix ] = array(
						'Data Size'  => 0,
						'Index Size' => 0,
						'Total'      => 0,
					);
				}
				$subsite_sizes[ $subsite_prefix ]['Data Size']  += $table_info->Data_length;
				$subsite_sizes[ $subsite_prefix ]['Index Size'] += $table_info->Index_length;
				$subsite_sizes[ $subsite_prefix ]['Total']      += $table_info->Data_length + $table_info->Index_length;
			} else {
				// Format output for WP-CLI's format_items function.
				$output[] = array(
					'Table'      => $table_info->Name, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					'Data Size'  => $table_info->Data_length, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					'Index Size' => $table_info->Index_length, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					'Total'      => $table_info->Data_length + $table_info->Index_length, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				);
			}
		}

		// Format output for WP-CLI's format_items function.
		if ( true === $subsite ) {
			foreach ( $subsite_sizes as $prefix => $sizes ) {
				$output[] = array(
					'Subsite Prefix' => $prefix,
					'Data Size'      => $sizes['Data Size'],
					'Index Size'     => $sizes['Index Size'],
					'Total'          => $sizes['Total'],
				);
			}
		}

		// Sort table data.
		self::sort_table_by( $order_by, $output, $order );

		if ( ! $raw ) {
			// Make data human readable.
			foreach ( array_keys( $output ) as $key ) {
				$output[ $key ]['Data Size']  = size_format( $output[ $key ]['Data Size'] );
				$output[ $key ]['Index Size'] = size_format( $output[ $key ]['Index Size'] );
				$output[ $key ]['Total']      = size_format( $output[ $key ]['Total'] );
			}
		}

		// Output data.
		if ( true === $subsite ) {
			WP_CLI\Utils\format_items( $format, $output, array( 'Subsite Prefix', 'Data Size', 'Index Size', 'Total' ) );
		} else {
			WP_CLI\Utils\format_items( $format, $output, array( 'Table', 'Data Size', 'Index Size', 'Total' ) );
		}

		// Output totals if we're not piping somewhere.
		if ( ! WP_CLI\Utils\isPiped() ) {
			WP_CLI::success(
				sprintf(
					'Total size of the database for %s is %s. Data: %s; Index: %s',
					home_url(),
					WP_CLI::colorize( '%g' . size_format( $db_size['data'] + $db_size['index'] ) . '%n' ),
					WP_CLI::colorize( '%g' . size_format( $db_size['data'] ) . '%n' ),
					WP_CLI::colorize( '%g' . size_format( $db_size['index'] ) . '%n' )
				)
			);
		}
	}

	/**
	 * Sorts a table by a specific field and direction.
	 *
	 * @param string $field     The field to order by.
	 * @param array  $array     The table array to sort.
	 * @param string $direction The direction to sort. Ascending ('asc') or descending ('desc').
	 */
	private function sort_table_by( $field, &$array, $direction ) {
		// Taken from https://joshtronic.com/2013/09/23/sorting-associative-array-specific-key/ Thanks!
		usort(
			$array,
			function ( $a, $b ) use ( $field, $direction ) {
				$a = $a[ $field ];
				$b = $b[ $field ];

				if ( $a === $b ) {
					return 0;
				}

				switch ( $direction ) {
					case 'asc':
						return ( $a < $b ) ? -1 : 1;
					case 'desc':
						return ( $a > $b ) ? -1 : 1;
					default:
						return 0;
				}
			}
		);
		return true;
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'vip sh', 'VIP_Go_Sandbox_Helpers_Command' );
}
