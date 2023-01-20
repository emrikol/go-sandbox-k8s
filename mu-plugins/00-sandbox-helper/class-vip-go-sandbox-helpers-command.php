<?php
/**
 * WP-CLI Commands for Sandbox Helper.
 */

if ( ! class_exists( 'WP_CLI_Command' ) ) {
	return;
}

// phpcs:disable WordPressVIPMinimum.Classes.RestrictedExtendClasses.wp_cli, Squiz.Commenting.FunctionComment.MissingParamTag

/**
 * VIP_Go_Sandbox_Helpers_Command Class.
 */
class VIP_Go_Sandbox_Helpers_Command extends WP_CLI_Command {
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
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'vip sh', 'VIP_Go_Sandbox_Helpers_Command' );
}
