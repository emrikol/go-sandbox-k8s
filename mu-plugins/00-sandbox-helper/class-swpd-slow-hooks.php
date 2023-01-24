<?php
/**
 * Sandbox WP Debugger Helper to output the 10 slowest hooks.
 */

/**
 * SWPD_Slow_Hooks Class.
 */
class SWPD_Slow_Hooks {
	/**
	 * Name of the SWPD Debugger running.
	 *
	 * @var string
	 */
	public string $debugger_name = 'Slow Hooks';

	/**
	 * Constructor; set up all of the necessary WordPress hooks.
	 */
	public function __construct() {
		add_action( 'all', array( $this, 'all_hooks' ), 10 );
		add_action( 'shutdown', array( $this, 'shutdown' ), PHP_INT_MAX );
	}

	/**
	 * Runs for every single hook to start or restart a timer.
	 *
	 * @param  mixed $value If it's a filter, the value being filtered.
	 *
	 * @return mixed        If it's a filter, the value being filtered, unchanged.
	 */
	public function all_hooks( mixed $value ): mixed {
		global $wp_current_filter, $vip_filter_timers;

		// Grab the current filter out of the list of running fitlers.
		$current_filter = $wp_current_filter[ count( $wp_current_filter ) - 1 ] ?? 'UNKNOWN';

		if ( is_array( $vip_filter_timers ) && isset( $vip_filter_timers[ $current_filter ] ) && 'running' === $vip_filter_timers[ $current_filter ]['status'] ) {
			// The filter is currently already running. Are we nested? Let's restart the timer just in case.
			$vip_filter_timers[ $current_filter ]['time'] += hrtime( true ) - $vip_filter_timers[ $current_filter ]['start'];
			$vip_filter_timers[ $current_filter ]['start'] = hrtime( true );
		} else {
			// Starting a new filter timer.
			$vip_filter_timers[ $current_filter ]['start']  = hrtime( true );
			$vip_filter_timers[ $current_filter ]['status'] = 'running';
			$vip_filter_timers[ $current_filter ]['time']   = isset( $vip_filter_timers[ $current_filter ]['time'] ) ? $vip_filter_timers[ $current_filter ]['time'] : 0;

			// We only want to add one stop per filter run.
			if ( ! has_action( $current_filter, 'vip_hook_timer_stop' ) ) {
				// And to make sure we capture the very last shutdown, let's run it at one less than max.
				add_action( $current_filter, array( $this, 'hook_timer_stop' ), PHP_INT_MAX - 1, 1 );
			}
		}
		return $value;
	}

	/**
	 * Shutdown hook to gather the timer data and output to SWPD.
	 *
	 * @return void
	 */
	public function shutdown(): void {
		global $vip_filter_timers;

		$timers = array();
		foreach ( $vip_filter_timers as $hook_name => $vip_filter_timer ) {
			$timers[ $hook_name ] = $this->change_resolution( $vip_filter_timer['time'] );
		}
		asort( $timers );

		$timers = array_slice(
			array: $timers,
			offset: count( $timers ) - 10,
			length: 10,
			preserve_keys: true
		);

		foreach ( $timers as $hook => $time_ms ) {
			$timers[ $hook ] = $time_ms . 'ms';
		}

		$this->log( 'Top 10 Slowest Hooks', $timers );
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

	/**
	 * Stops the current hook's timer and records the time taken.
	 *
	 * @param  mixed ...$value If it's a filter, the value being filtered.
	 *
	 * @return mixed           If it's a filter, the value being filtered, unchanged
	 */
	public function hook_timer_stop( ...$value ): mixed {
		global $wp_current_filter, $vip_filter_timers;

		$current_filter = $wp_current_filter[ count( $wp_current_filter ) - 1 ] ?? 'UNKNOWN';

		if ( is_array( $vip_filter_timers ) && 'running' === $vip_filter_timers[ $current_filter ]['status'] ) {
			$vip_filter_timers[ $current_filter ]['time']  += hrtime( true ) - $vip_filter_timers[ $current_filter ]['start'];
			$vip_filter_timers[ $current_filter ]['status'] = 'stopped';
			unset( $vip_filter_timers[ $current_filter ]['start'] );
		}

		// Wut? Why?
		if ( ! isset( $value[0] ) ) {
			if ( empty( $value[0] ) ) {
				return null;
			}
		}

		// Don't EVER change!
		if ( is_array( $value ) && null === $value[0] ) {
			return null;
		}

		return $value[0];
	}

	/**
	 * Change the resolution of the timer.
	 *
	 * @param  int    $time       Time taken in nanoseconds.
	 * @param  string $resolution Resolution to output. Currently accepts 'ms' and 'ns'.
	 *
	 * @return float              Time taken in the new resolution.
	 */
	public function change_resolution( int $time, string $resolution = 'ms' ): float {
		global $vip_timer;

		switch ( $resolution ) {
			case 'ns':
				$time = $time;
				break;
			case 'ms':
				$time /= 1e+6;
				break;
			default:
				return (float) $time;
		}

		return (float) round( $time, 3 );
	}

}

new SWPD_Slow_Hooks();
