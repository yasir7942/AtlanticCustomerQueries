<?php
/**
 * Settings storage. The API key may be defined in wp-config.php (preferred):
 *   define( 'AIQ_API_KEY', 'aiq_...' );
 *   define( 'AIQ_ENDPOINT', 'https://inquiries.example.com/api/v1/submissions' );
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AIQ_Settings {

	const OPTION = 'aiq_settings';

	const SOURCES = array(
		'cf7'       => 'Contact Form 7',
		'elementor' => 'Elementor Pro Forms',
	);

	const FORM_TYPES = array(
		'contact' => 'Contact',
		'quote'   => 'Quote',
		'sample'  => 'Sample',
		'other'   => 'Other',
	);

	public static function defaults() {
		return array(
			'endpoint'         => '',
			'api_key'          => '',
			'timeout'          => 6,
			'send_visitor_ip'  => 1,
			'forward_unmapped' => 1,
			'rules'            => array(),
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function endpoint() {
		if ( defined( 'AIQ_ENDPOINT' ) && AIQ_ENDPOINT ) {
			return (string) AIQ_ENDPOINT;
		}
		return (string) self::get( 'endpoint' );
	}

	public static function api_key() {
		if ( defined( 'AIQ_API_KEY' ) && AIQ_API_KEY ) {
			return (string) AIQ_API_KEY;
		}
		return (string) self::get( 'api_key' );
	}

	public static function key_from_constant() {
		return defined( 'AIQ_API_KEY' ) && AIQ_API_KEY;
	}

	/** Masked representation, e.g. "aiq_ab12cd34…wxyz". Never print the full key. */
	public static function masked_key() {
		$key = self::api_key();
		if ( '' === $key ) {
			return '';
		}
		$parts = explode( '_', $key );
		$id    = isset( $parts[1] ) ? substr( $parts[1], 0, 8 ) : '';
		return 'aiq_' . $id . '…' . substr( $key, -4 );
	}

	public static function is_configured() {
		return '' !== self::endpoint() && '' !== self::api_key();
	}

	/** Active rules, each: source, form, form_type, map (array source_field => target), enabled. */
	public static function rules() {
		$rules = self::get( 'rules' );
		$out   = array();
		foreach ( is_array( $rules ) ? $rules : array() as $rule ) {
			if ( empty( $rule['enabled'] ) || empty( $rule['form'] ) ) {
				continue;
			}
			$rule['map'] = AIQ_Mapper::parse_map( isset( $rule['map'] ) ? (string) $rule['map'] : '' );
			$out[]       = $rule;
		}
		return $out;
	}

	/** First enabled rule for a source whose form identifier matches any of $identifiers. */
	public static function find_rule( $source, array $identifiers ) {
		$identifiers = array_filter( array_map( 'strval', $identifiers ), 'strlen' );
		foreach ( self::rules() as $rule ) {
			if ( $rule['source'] !== $source ) {
				continue;
			}
			foreach ( $identifiers as $id ) {
				if ( 0 === strcasecmp( trim( $rule['form'] ), trim( $id ) ) ) {
					return $rule;
				}
			}
		}
		return null;
	}

	/**
	 * Sanitize callback for register_setting(). A blank API key field keeps the stored key.
	 */
	public static function sanitize( $input ) {
		$old = self::all();
		$out = self::defaults();
		$in  = is_array( $input ) ? $input : array();

		$endpoint = isset( $in['endpoint'] ) ? esc_url_raw( trim( (string) $in['endpoint'] ), array( 'https', 'http' ) ) : '';
		if ( '' !== $endpoint && 0 !== strpos( $endpoint, 'https://' ) && false === strpos( $endpoint, '//localhost' ) && false === strpos( $endpoint, '//127.0.0.1' ) ) {
			add_settings_error( self::OPTION, 'aiq_https', 'The endpoint must use https://.' );
			$endpoint = $old['endpoint'];
		}
		$out['endpoint'] = $endpoint;

		$new_key = isset( $in['api_key'] ) ? trim( (string) $in['api_key'] ) : '';
		if ( ! empty( $in['clear_key'] ) ) {
			$out['api_key'] = '';
		} elseif ( '' === $new_key ) {
			$out['api_key'] = $old['api_key'];
		} elseif ( preg_match( '/^aiq_[a-z0-9]{16}_[A-Za-z0-9_-]{43}$/', $new_key ) ) {
			$out['api_key'] = $new_key;
		} else {
			add_settings_error( self::OPTION, 'aiq_key', 'That does not look like an Inquiry Manager key (aiq_…). The previous key was kept.' );
			$out['api_key'] = $old['api_key'];
		}

		$out['timeout']          = max( 3, min( 15, absint( isset( $in['timeout'] ) ? $in['timeout'] : 6 ) ) );
		$out['send_visitor_ip']  = empty( $in['send_visitor_ip'] ) ? 0 : 1;
		$out['forward_unmapped'] = empty( $in['forward_unmapped'] ) ? 0 : 1;

		$rules = array();
		$seen  = array();
		foreach ( isset( $in['rules'] ) && is_array( $in['rules'] ) ? $in['rules'] : array() as $rule ) {
			$form = isset( $rule['form'] ) ? sanitize_text_field( $rule['form'] ) : '';
			if ( '' === $form ) {
				continue;
			}
			$source = isset( $rule['source'] ) && isset( self::SOURCES[ $rule['source'] ] ) ? $rule['source'] : 'cf7';
			$key    = $source . '|' . strtolower( $form );
			if ( isset( $seen[ $key ] ) ) {
				add_settings_error( self::OPTION, 'aiq_dupe', sprintf( 'Duplicate rule for %s form "%s" was removed: one rule per form prevents double forwarding.', self::SOURCES[ $source ], $form ) );
				continue;
			}
			$seen[ $key ] = true;
			$rules[]      = array(
				'source'    => $source,
				'form'      => $form,
				'form_type' => isset( $rule['form_type'] ) && isset( self::FORM_TYPES[ $rule['form_type'] ] ) ? $rule['form_type'] : 'contact',
				'map'       => isset( $rule['map'] ) ? sanitize_textarea_field( $rule['map'] ) : '',
				'enabled'   => empty( $rule['enabled'] ) ? 0 : 1,
			);
		}
		$out['rules'] = $rules;
		return $out;
	}
}
