<?php
/**
 * URL Checker Command Class File
 *
 * This file contains the definition of the URL Checker Command Class, which is
 * designed to scan a WordPress site for potential issues by checking various URLs.
 */

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

/**
 * URL Checker Command Class
 *
 * This class extends WP_CLI_Command and provides capabilities to scan and check
 * different types of URLs on a WordPress site, including posts, taxonomies,
 * authors, and REST API routes, for potential issues after a PHP upgrade or other changes.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
 * phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv
 * phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
 * phpcs:disable WordPressVIPMinimum.Classes.RestrictedExtendClasses.wp_cli
 */
class VIP_URL_Checker_Command extends WP_CLI_Command {

	/**
	 * File path for caching URLs.
	 *
	 * @var string
	 */
	private $urls_cache_file;

	/**
	 * File path for logging unprocessed rules.
	 *
	 * @var string
	 */
	private $unprocessed_rules_file;

	/**
	 * File path for logging URL check responses.
	 *
	 * @var string
	 */
	private $response_log_file;

	/**
	 * Limit for the number of posts to retrieve URLs from.
	 *
	 * @var int
	 */
	private $max_posts;

	/**
	 * Limit for the number of taxonomy terms to retrieve URLs from.
	 *
	 * @var int
	 */
	private $max_terms;

	/**
	 * Search term for replacing URLs.
	 *
	 * @var string
	 */
	private $search;

	/**
	 * Replacement term for URLs.
	 *
	 * @var string
	 */
	private $replace;

	/**
	 * Constructs a new instance of the URL Checker Command.
	 *
	 * This constructor initializes the file paths for caching URLs, storing unprocessed rewrite rules,
	 * and logging URL responses. These files are used to persist data across multiple executions
	 * of the command.
	 */
	public function __construct() {
		$this->urls_cache_file        = 'site_name_urls_cache.txt';
		$this->unprocessed_rules_file = 'unprocessed_rewrite_rules.txt';
		$this->response_log_file      = 'url_response_log.csv';
	}

	/**
	 * Scan the site for potential PHP upgrade issues.
	 *
	 * This command scans the website for URLs that may have issues with a PHP version upgrade.
	 * It leverages a list of URLs generated from various components of the website, including posts,
	 * taxonomies, and author archives, and then checks each URL for compatibility.
	 *
	 * ## OPTIONS
	 *
	 * [--start=<number>]
	 * : The index from which to start the scan in the list of URLs. Defaults to 0.
	 *   This allows resuming scans without rechecking previously scanned URLs.
	 *
	 * [--max-posts=<number>]
	 * : The maximum number of URLs to check per post type. Defaults to 1000.
	 *   This limit prevents the scan from being too time-consuming or resource-intensive.
	 *
	 * [--max-terms=<number>]
	 * : The maximum number of URLs to check per taxonomy. Defaults to 10.
	 *   Similar to max-posts, this limits the scan's scope to manage resources.
	 *
	 * [--base-sleep=<number>]
	 * : The base sleep time in milliseconds between URL requests. Defaults to 10.
	 *   This helps to avoid overwhelming the server with rapid-fire requests.
	 *
	 * [--url-search-replace=<csv>]
	 * : Search and replace for URL values, useful for testing in a sandbox environment.
	 *   Accepts a comma-separated list of search and replace pairs.
	 *
	 * [--generate]
	 * : Forces regeneration of the URL list, even if a cached list is available.
	 *   Useful for ensuring the scan checks the most current state of the website.
	 *
	 * ## EXAMPLES
	 *
	 *     # Scan the site starting with the first URL.
	 *     wp url-checker scan
	 *
	 *     # Start scan from the 50th URL in the list.
	 *     wp url-checker scan --start=50
	 *
	 *     # Force URL list regeneration and start a fresh scan.
	 *     wp url-checker scan --generate
	 *
	 *     # Set specific thresholds for posts and terms and adjust the sleep time.
	 *     wp url-checker scan --max-posts=500 --max-terms=20 --base-sleep=20
	 *
	 *     # Use search and replace for URLs for sandbox testing.
	 *     wp url-checker scan --url-search-replace="http://example.com,http://sandbox.example.com"
	 *
	 * @param array $args       An indexed array of positional arguments.
	 * @param array $assoc_args An associative array containing the command's flags and their values.
	 */
	public function scan( $args, $assoc_args ) {
		$start               = isset( $assoc_args['start'] ) ? intval( $assoc_args['start'] ) : 0;
		$max_posts           = isset( $assoc_args['max-posts'] ) ? intval( $assoc_args['max-posts'] ) : 1000;
		$max_terms           = isset( $assoc_args['max-terms'] ) ? intval( $assoc_args['max-terms'] ) : 10;
		$base_sleep_duration = isset( $assoc_args['base_sleep_duration'] ) ? intval( $assoc_args['base_sleep_duration'] ) : 10;
		$generate            = isset( $assoc_args['generate'] );

		if ( ! empty( $assoc_args['url-search-replace'] ) ) {
			list( $this->search, $this->replace ) = explode( ',', $assoc_args['url-search-replace'], 2 );
		}

		$this->max_posts = $max_posts;
		$this->max_terms = $max_terms;

		if ( $generate || ! file_exists( $this->urls_cache_file ) ) {
			$urls = $this->get_all_urls();
			file_put_contents( $this->urls_cache_file, implode( PHP_EOL, $urls ) );
		} else {
			$urls = file( $this->urls_cache_file, FILE_IGNORE_NEW_LINES );
		}

		$progress = \WP_CLI\Utils\make_progress_bar( sprintf( 'Scanning %d URLs', number_format( count( $urls ) ) ), count( $urls ) );

		// This will hold the response times for the last 40 requests.
		$response_times         = array();
		$base_sleep_duration    = $base_sleep_duration * 1000; // Convert to microseconds.
		$current_sleep_duration = $base_sleep_duration;
		$initial_average        = null;
		$log_buffer             = array();

		if ( ! file_exists( $this->response_log_file ) ) {
			$headers = array( 'Index', 'URL', 'Status Code' );
			$fp      = fopen( $this->response_log_file, 'w' );
			fputcsv( $fp, $headers );
			fclose( $fp );
		}

		$previous_averages = array();  // Introduce this before the loop to track the previous rolling averages.

		$counter = 0;
		foreach ( $urls as $i => $url ) {
			// 1. Fetching a URL and Calculating its Response Time
			$result        = $this->fetch_url_and_get_response_time( $url );
			$response      = $result['response'];
			$response_time = $result['response_time'];

			// 2. Managing Response Times and Calculating Averages
			$this->manage_response_times( $response_times, $response_time, $initial_average );

			// Calculate rolling average of last 40 (Moved out from adjust_sleep_duration).
			$rolling_average = count( $response_times ) ? array_sum( $response_times ) / count( $response_times ) : 0;

			// 3. Adjusting Sleep Duration
			$this->adjust_sleep_duration( $response_times, $initial_average, $current_sleep_duration, $max_sleep_duration, $previous_averages, $base_sleep_duration );

			// 4. Log Handling
			$this->handle_logging( $response, $url, $i, $log_buffer, $this->response_log_file );

			// 5. Buffer Writing (If needed)
			if ( count( $log_buffer ) >= 40 ) {
				$this->flush_log_buffer( $log_buffer, $this->response_log_file );
			}

			// 6. Progress Update
			$this->update_progress( $progress, $counter, $urls, $initial_average, $rolling_average, $current_sleep_duration );

			++$counter;
		}

		// Write any remaining logs.
		if ( ! empty( $log_buffer ) ) {
			$this->flush_log_buffer( $log_buffer, $this->response_log_file );
		}

		$progress->finish();
		WP_CLI::success( 'URL scanning completed.' );
	}

	/**
	 * Formats a time interval given in microseconds to a human-readable string.
	 *
	 * This function converts microseconds into a formatted string with the appropriate
	 * unit. Units may be microseconds (us), milliseconds (ms), or seconds (s), depending
	 * on the length of the interval.
	 *
	 * @param float $microseconds The time interval in microseconds to be formatted.
	 *
	 * @return string Returns the formatted time interval as a string, with the unit appended.
	 */
	private function format_microseconds( $microseconds ) {
		if ( $microseconds < 1000 ) {
			return number_format( $microseconds ) . 'us';
		} elseif ( $microseconds < 1000000 ) {
			return number_format( round( $microseconds / 1000, 2 ) ) . 'ms';
		} else {
			return number_format( round( $microseconds / 1000000, 2 ), 3 ) . 's';
		}
	}

	/**
	 * Fetches a URL and calculates the response time.
	 *
	 * This function takes a URL, optionally replaces its hostname with a new one if specified,
	 * sends an HTTP GET request, and calculates the time taken to get the response.
	 *
	 * @param string $url The URL to fetch.
	 *
	 * @return array Returns an associative array with the 'response' key containing the response object,
	 *               and the 'response_time' key containing the time taken to get the response in milliseconds.
	 */
	private function fetch_url_and_get_response_time( $url ) {
		// Apply the search and replace if they have been set.
		if ( ! empty( $this->search ) && ! empty( $this->replace ) ) {
			$url = str_replace( $this->search, $this->replace, $url );
		}

		$start_time = microtime( true );

		error_log( '=== Currently Scanning URL: ' . $url . ' ===' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		$response = wp_remote_get( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get
			$url,
			array(
				'timeout' => 30, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
				'cookies' => array(
					new WP_Http_Cookie(
						array(
							'name'  => 'wordpress_logged_in_test123',
							'value' => '1',
						)
					),
				),
				'sslverify' => false,
			)
		);

		$end_time = microtime( true );
		return array(
			'response'      => $response,
			'response_time' => ( $end_time - $start_time ) * 1000,
		);
	}

	/**
	 * Manages response times and calculates initial average.
	 *
	 * @param array      $response_times    Reference to the response times array.
	 * @param float      $response_time     The new response time to be added.
	 * @param float|null $initial_average The initial average to be calculated once after 40 requests.
	 */
	private function manage_response_times( &$response_times, $response_time, &$initial_average ) {
		// Append the new response time.
		$response_times[] = $response_time;

		// If we have more than 40 response times, remove the oldest.
		if ( count( $response_times ) > 40 ) {
			array_shift( $response_times );
		}

		// Calculate the initial average once after 40 requests.
		if ( is_null( $initial_average ) && count( $response_times ) >= 40 ) {
			$initial_average = array_sum( $response_times ) / 40;
		}
	}

	/**
	 * Adjusts the sleep duration based on the rolling average of response times.
	 *
	 * @param array $response_times           The array containing response times.
	 * @param float $initial_average          The initial average of the response times.
	 * @param float $current_sleep_duration   Reference to the current sleep duration to be modified.
	 * @param float $max_sleep_duration       Reference to the max sleep duration cap.
	 * @param array $previous_averages        Reference to the array storing previous rolling averages.
	 * @param float $base_sleep_duration      The base sleep duration.
	 */
	private function adjust_sleep_duration( $response_times, $initial_average, &$current_sleep_duration, &$max_sleep_duration, &$previous_averages, $base_sleep_duration ) {
		// Calculate rolling average of last 40.
		$rolling_average = array_sum( $response_times ) / count( $response_times );

		// If the rolling average is more than 25% of the initial average, adjust sleep.
		if ( count( $response_times ) >= 40 ) {
			if ( $rolling_average > 1.25 * $initial_average ) {
				usleep( $current_sleep_duration );
				$current_sleep_duration = min( $current_sleep_duration * 1.25, $max_sleep_duration );
			} else {
				$current_sleep_duration = max( $base_sleep_duration, $current_sleep_duration * 0.75 ); // Gradual decrease mechanism.
			}

			// Save the latest rolling average.
			$previous_averages[] = $rolling_average;
			if ( count( $previous_averages ) > 5 ) {  // Maintain only the last 5 values.
				array_shift( $previous_averages );
			}
		}

		// After sleeping, check if the average is still growing and if we need to adjust the cap.
		if ( $current_sleep_duration >= $max_sleep_duration ) {
			$is_growing = true;
			for ( $j = count( $previous_averages ) - 1; $j > 0; $j-- ) {
				if ( $previous_averages[ $j ] <= $previous_averages[ $j - 1 ] ) {
					$is_growing = false;
					break;
				}
			}

			if ( $is_growing ) {
				$max_sleep_duration = 10000000;  // adjust the cap to 10 seconds.
			}
		}
	}

	/**
	 * Handles logging of the responses.
	 *
	 * @param array  $response          The response to log.
	 * @param string $url               The URL associated with the response.
	 * @param int    $index             The index of the URL.
	 * @param array  $log_buffer        Reference to the log buffer to be updated.
	 * @param string $response_log_file The file path for logging responses.
	 */
	private function handle_logging( $response, $url, $index, &$log_buffer, $response_log_file ) {
		// Handle wp_remote_get errors.
		if ( is_wp_error( $response ) ) {
			$status_code = $response->get_error_message();
		} else {
			$status_code = wp_remote_retrieve_response_code( $response );
		}

		$log_entry    = array( $index, $url, $status_code );
		$log_buffer[] = $log_entry;

		// Buffer writes and flush them every 40 iterations.
		if ( count( $log_buffer ) >= 40 ) {
			$this->flush_log_buffer( $log_buffer, $response_log_file );
		}
	}

	/**
	 * Flushes the log buffer to a log file.
	 *
	 * This method takes the accumulated log entries in the log buffer and writes them
	 * to the specified log file in CSV format. After writing, it clears the buffer.
	 *
	 * @param array  $log_buffer Reference to the log buffer array containing log entries to write.
	 * @param string $response_log_file The file path where log entries will be written.
	 *
	 * @return void
	 */
	private function flush_log_buffer( &$log_buffer, $response_log_file ) {
		$fp = fopen( $response_log_file, 'a' );
		foreach ( $log_buffer as $line ) {
			fputcsv( $fp, $line );
		}
		fclose( $fp );
		$log_buffer = array();
	}

	/**
	 * Updates the progress of URL scanning.
	 *
	 * This method updates the progress bar with the current status, including the
	 * number of URLs scanned, the base average response time, the current rolling
	 * average response time, and the current sleep duration.
	 *
	 * @param WP_CLI\Progress\Bar $progress The WP_CLI progress bar instance.
	 * @param int                 $i The current index of the URL being processed.
	 * @param array               $urls The array of URLs to scan.
	 * @param float               $initial_average The initial average response time for comparison.
	 * @param float               $rolling_average The current rolling average response time.
	 * @param int                 $current_sleep_duration The current sleep duration in microseconds.
	 *
	 * @return void
	 */
	private function update_progress( $progress, $i, $urls, $initial_average, $rolling_average, $current_sleep_duration ) {
		$message = sprintf( 'Scanning %d:%d URLs (Base: %s, Avg: %s, Sleep: %s)', $i, number_format( count( $urls ) ), $this->format_microseconds( $initial_average * 1000 ), $this->format_microseconds( $rolling_average * 1000 ), $this->format_microseconds( $current_sleep_duration ) );
		$progress->tick( 1, $message );
	}

	/**
	 * Retrieves URLs for all public post types.
	 *
	 * @return array An array of URLs for public post types.
	 */
	private function get_post_type_urls() {
		$urls            = array();
		$post_types      = get_post_types( array( 'public' => true ) );
		$public_statuses = get_post_stati( array( 'public' => true ), 'names', 'and' );

		foreach ( $post_types as $post_type ) {
			$recent_posts = get_posts(
				array(
					'post_type'      => $post_type,
					'post_status'    => $public_statuses,
					'posts_per_page' => $this->max_posts,
					'orderby'        => 'modified',
					'order'          => 'DESC',
				)
			);

			foreach ( $recent_posts as $post ) {
				// First, try to use get_permalink.
				$permalink = get_permalink( $post->ID );

				if ( ! empty( $permalink ) ) {
					$urls[] = $permalink;
				} else {
					// Fallback to non-pretty link.
					$urls[] = add_query_arg( 'p', $post->ID, home_url( 'index.php' ) );
				}
			}
		}

		return $urls;
	}

	/**
	 * Retrieves URLs for all public taxonomies.
	 *
	 * @return array An array of URLs for public taxonomies.
	 */
	private function get_taxonomy_urls() {
		$urls       = array();
		$taxonomies = get_taxonomies( array( 'public' => true ) );

		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'number'     => $this->max_terms,
					'hide_empty' => false,
				)
			);

			foreach ( $terms as $term ) {
				$urls[] = get_term_link( $term, $taxonomy );
			}
		}

		return $urls;
	}

	/**
	 * Retrieves URLs for author archives.
	 *
	 * @return array An array of URLs for author archives.
	 */
	private function get_author_urls() {
		$urls = array();

		// Retrieve all public post types.
		$public_post_types = get_post_types( array( 'public' => true ), 'names' );

		// Query for users who have published posts in any of the public post types.
		$authors = get_users( array( 'has_published_posts' => $public_post_types ) );

		foreach ( $authors as $author ) {
			$urls[] = get_author_posts_url( $author->ID );
		}

		return $urls;
	}


	/**
	 * Retrieves miscellaneous URLs such as home, 404, REST API.
	 *
	 * @return array An array of miscellaneous URLs.
	 */
	private function get_misc_urls() {
		$urls = array();
		// Home URL.
		$urls[] = home_url();

		// Site URL.
		$urls[] = site_url();

		// One random URL for 404.
		$urls[] = home_url( wp_generate_password( 12, false ) );

		// REST API.
		$urls[] = get_rest_url();

		return $urls;
	}

	/**
	 * Retrieve all REST API GET route URLs from all namespaces.
	 *
	 * @return array An array of all GET route URLs.
	 */
	private function get_all_rest_routes() {
		global $wp_rest_server;

		// Initialize the REST API server if not already done.
		if ( empty( $wp_rest_server ) ) {
			require_once ABSPATH . 'wp-includes/rest-api.php';
			$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
			do_action( 'rest_api_init', $wp_rest_server ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		}

		$all_routes = array();

		// Retrieve all namespaces.
		$namespaces = $wp_rest_server->get_namespaces();

		// Iterate over all namespaces to retrieve routes.
		foreach ( $namespaces as $namespace ) {
			// Get all routes for the current namespace.
			$routes = $wp_rest_server->get_routes( $namespace );

			// Iterate over retrieved routes.
			foreach ( $routes as $route => $endpoints ) {
				foreach ( $endpoints as $endpoint ) {
					// Check if the endpoint accepts GET method.
					if ( in_array( 'GET', array_keys( $endpoint['methods'] ), true ) ) {
						// Store the GET route URL.
						if ( ! str_contains( $route, '<' ) && ! str_contains( $route, '>' ) && ! str_contains( $route, '?' ) ) {
							$all_routes[] = rest_url( $route );
						}
					}
				}
			}
		}

		// Return the compiled list of GET route URLs.
		return $all_routes;
	}


	/**
	 * Compiles a complete list of all URLs to be checked.
	 *
	 * @return array An array of all compiled URLs.
	 */
	private function get_all_urls() {
		$urls = array_merge(
			$this->get_post_type_urls(),
			$this->get_taxonomy_urls(),
			$this->get_author_urls(),
			$this->get_all_rest_routes(),
			$this->get_misc_urls(),
		);

		// Deduplicate URLs.
		$urls = array_unique( $urls );

		return $urls;
	}
}

WP_CLI::add_command( 'url-checker', 'VIP_URL_Checker_Command' );
