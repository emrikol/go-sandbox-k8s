<?php
/**
 * Sandbox WP Debugger Helper for REST Requests.
 */

/**
 * SWPD_REST_Requests Class.
 */
class SWPD_REST_Requests {
	/**
	 * Name of the SWPD Debugger running.
	 *
	 * @var string
	 */
	public string $debugger_name = 'REST Requests';

	/**
	 * Constructor; set up all of the necessary WordPress hooks.
	 */
	public function __construct() {
		// Uncomment this if you need pre-dispatch. For instance, if something else is returning pre-dispatch early.
		//add_filter( 'rest_pre_dispatch', array( $this, 'rest_pre_dispatch' ), PHP_INT_MIN, 3 );

		add_filter( 'rest_post_dispatch', array( $this, 'rest_post_dispatch' ), PHP_INT_MAX, 3 );
	}

	public function rest_pre_dispatch( mixed $result, WP_REST_Server $server, WP_REST_Request $request ): mixed {
		global $swpd_timers_rest;

		if ( ! is_array( $swpd_timers_rest ) ) {
			$swpd_timers_rest = array();
		}

		$swpd_timers_rest[ $request->get_route() ] = hrtime( true );

		$data = array_merge(
			array(
				'Method' => $request->get_method(),
			),
			$request->get_params(),
		);

		$debug_data = array(
			'home_url' => home_url(),
			'site_url' => site_url(),
		);

		$this->log( 'Route: ' . $request->get_route(), $data, $debug_data );

		return null;
	}

	public function rest_post_dispatch( WP_HTTP_Response $result, WP_REST_Server $server, WP_REST_Request $request ) {
		global $swpd_timers_rest;

		$time  = hrtime( true ) - $swpd_timers_rest[ $request->get_route() ];
		$time /= 1e+6; // Convert from ns to ms.

		unset( $swpd_timers_rest[ $request->get_route() ] );

		$data = array_merge(
			array(
				'Time Taken' => round( $time, 3 ) . 'ms',
				'Method'     => $request->get_method(),
			),
			$request->get_params(),
		);

		$debug_data = array(
			'home_url' => home_url(),
			'site_url' => site_url(),
		);

		$this->log( 'Post Dispatch Route: ' . $request->get_route(), $data, $debug_data );

		return $result;
	}

	/**
	 * Logs data to the error log via swpd_log().
	 *
	 * @param  string       $message    The message being sent.
	 * @param  array        $data       An associative array of data to output.
	 * @param  array        $debug_data An associative array of extra data to output.
	 * @param  bool|boolean $backtrace  Output a backtrace, default to false.
	 *
	 * @return void
	 */
	public function log( string $message = '', array $data = array(), array $debug_data = array(), bool $backtrace = false ): void {
		swpd_log(
			function:   $this->debugger_name,
			message:    $message,
			data:       $data,
			debug_data: $debug_data,
			backtrace:  false
		);
	}

}

new SWPD_REST_Requests();
