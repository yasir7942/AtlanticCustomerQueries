<?php
/**
 * HTTP client for POST /api/v1/submissions using the WordPress HTTP API.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AIQ_Client {

	/**
	 * @return array{ok:bool,retryable:bool,status:int,inquiry_id:?int,code:string,message:string,fields:array,retry_after:?int}
	 */
	public static function send( array $payload ) {
		$endpoint = AIQ_Settings::endpoint();
		$key      = AIQ_Settings::api_key();
		if ( '' === $endpoint || '' === $key ) {
			return self::result( false, false, 0, 'not_configured', 'Endpoint or API key is not configured.' );
		}
		$body = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $body ) {
			return self::result( false, false, 0, 'encode_failed', 'Payload could not be encoded as JSON.' );
		}
		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout'     => (int) AIQ_Settings::get( 'timeout' ),
				'redirection' => 0,
				'headers'     => array(
					'Authorization'   => 'Bearer ' . $key,
					'Content-Type'    => 'application/json',
					'Accept'          => 'application/json',
					'Idempotency-Key' => (string) $payload['submission_id'],
					'User-Agent'      => 'AtlanticInquiryConnector/' . AIQ_CONNECTOR_VERSION . '; ' . home_url( '/' ),
				),
				'body'        => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			return self::result( false, true, 0, 'network_error', $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$data   = is_array( $data ) ? $data : array();
		if ( 200 === $status || 201 === $status ) {
			$r               = self::result( true, false, $status, isset( $data['status'] ) ? (string) $data['status'] : 'created', '' );
			$r['inquiry_id'] = isset( $data['inquiry_id'] ) ? (int) $data['inquiry_id'] : null;
			return $r;
		}
		$code    = isset( $data['error']['code'] ) ? (string) $data['error']['code'] : 'http_' . $status;
		$message = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : 'HTTP ' . $status;
		$r       = self::result( false, 429 === $status || $status >= 500, $status, $code, $message );
		$r['fields']      = isset( $data['error']['fields'] ) && is_array( $data['error']['fields'] ) ? $data['error']['fields'] : array();
		$retry_after      = wp_remote_retrieve_header( $response, 'retry-after' );
		$r['retry_after'] = is_numeric( $retry_after ) ? (int) $retry_after : null;
		return $r;
	}

	private static function result( $ok, $retryable, $status, $code, $message ) {
		return array(
			'ok'          => $ok,
			'retryable'   => $retryable,
			'status'      => $status,
			'inquiry_id'  => null,
			'code'        => $code,
			'message'     => $message,
			'fields'      => array(),
			'retry_after' => null,
		);
	}
}
