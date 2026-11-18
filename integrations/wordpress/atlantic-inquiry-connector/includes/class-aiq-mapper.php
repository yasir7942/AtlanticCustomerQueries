<?php
/**
 * Turns form fields into the Inquiry Manager payload.
 *
 * Map syntax (one per line):  form-field-name = target
 * Targets: full_name, company, email, phone, country, address, city, postcode, message, locale,
 *          products[N].name | products[N].sku | products[N].variant | products[N].quantity | products[N].unit | products[N].notes,
 *          fields.Any label, ignore
 * Mapping two fields to the same text target (e.g. first + last name -> full_name) joins them with a space.
 * Unmapped fields become custom answers unless "Forward unmapped fields" is switched off.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AIQ_Mapper {

	const TEXT_TARGETS = array( 'full_name', 'company', 'email', 'phone', 'country', 'address', 'city', 'postcode', 'message', 'locale' );

	/** Field names that are never forwarded (spam tokens, consent internals). */
	const SKIP = array( 'g-recaptcha-response', 'cf-turnstile-response', 'h-captcha-response', '_wpcf7', 'form_id', 'post_id', 'queried_id', 'referer_title', 'referrer' );

	/** @return array<string,string> */
	public static function parse_map( $text ) {
		$map = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) || false === strpos( $line, '=' ) ) {
				continue;
			}
			list( $field, $target ) = array_map( 'trim', explode( '=', $line, 2 ) );
			if ( '' !== $field && '' !== $target ) {
				$map[ $field ] = $target;
			}
		}
		return $map;
	}

	/**
	 * @param array  $fields   field_name => array( 'label' => string, 'value' => string|array )
	 * @param array  $rule     matched rule (form_type, map)
	 * @param array  $context  form_id, form_name, page_url, ip, email_sent (bool|null), source
	 * @return array payload for AIQ_Client::send()
	 */
	public static function build( array $fields, array $rule, array $context ) {
		$map     = isset( $rule['map'] ) && is_array( $rule['map'] ) ? $rule['map'] : array();
		$payload = array(
			'submission_id' => self::new_submission_id(),
			'form_type'     => isset( $rule['form_type'] ) ? $rule['form_type'] : 'contact',
			'form_id'       => self::form_key( $context ),
			'form_name'     => mb_substr( (string) ( isset( $context['form_name'] ) ? $context['form_name'] : '' ), 0, 150 ),
			'submitted_at'  => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'locale'        => self::locale(),
		);
		$products = array();
		$custom   = array();

		foreach ( $fields as $name => $field ) {
			$name = (string) $name;
			if ( '' === $name || 0 === strpos( $name, '_' ) || in_array( $name, self::SKIP, true ) ) {
				continue;
			}
			$value  = isset( $field['value'] ) ? $field['value'] : '';
			$label  = isset( $field['label'] ) && '' !== $field['label'] ? (string) $field['label'] : $name;
			$target = isset( $map[ $name ] ) ? $map[ $name ] : null;
			if ( 'ignore' === $target ) {
				continue;
			}
			if ( null !== $target && in_array( $target, self::TEXT_TARGETS, true ) ) {
				$text = self::to_text( $value );
				if ( '' === $text ) {
					continue;
				}
				$payload[ $target ] = isset( $payload[ $target ] ) && '' !== $payload[ $target ] && 'locale' !== $target
					? $payload[ $target ] . ( 'message' === $target ? "\n" : ' ' ) . $text
					: $text;
				continue;
			}
			if ( null !== $target && preg_match( '/^products\[(\d+)\]\.(name|sku|variant|quantity|unit|notes)$/', $target, $m ) ) {
				$text = self::to_text( $value );
				if ( '' !== $text ) {
					$products[ (int) $m[1] ][ $m[2] ] = $text;
				}
				continue;
			}
			if ( null !== $target && 0 === strpos( $target, 'fields.' ) ) {
				$label = substr( $target, 7 );
			} elseif ( null !== $target || empty( AIQ_Settings::all()['forward_unmapped'] ) ) {
				continue;
			}
			if ( self::is_empty( $value ) ) {
				continue;
			}
			$key            = self::field_key( $label );
			$custom[ $key ] = array(
				'label' => mb_substr( $label, 0, 150 ),
				'value' => is_array( $value ) ? array_values( array_map( 'strval', $value ) ) : (string) $value,
			);
		}

		ksort( $products );
		foreach ( $products as $p ) {
			if ( empty( $p['name'] ) && empty( $p['sku'] ) ) {
				continue;
			}
			if ( isset( $p['quantity'] ) ) {
				$q = str_replace( ',', '.', preg_replace( '/[^0-9.,]/', '', $p['quantity'] ) );
				if ( is_numeric( $q ) ) {
					$p['quantity'] = 0 + $q;
				} else {
					unset( $p['quantity'] );
				}
			}
			$payload['products'][] = $p;
		}
		if ( $custom ) {
			$payload['fields'] = array_slice( $custom, 0, 100, true );
		}
		if ( isset( $payload['email'] ) && ! is_email( $payload['email'] ) ) {
			// Keep the raw value visible to the team instead of failing the whole submission.
			$payload['fields']['email_as_entered'] = array( 'label' => 'Email (as entered)', 'value' => $payload['email'] );
			unset( $payload['email'] );
		}

		$source = array();
		if ( ! empty( $context['page_url'] ) && wp_http_validate_url( $context['page_url'] ) ) {
			$source['page_url'] = substr( (string) $context['page_url'], 0, 2048 );
		}
		if ( ! empty( AIQ_Settings::all()['send_visitor_ip'] ) && ! empty( $context['ip'] ) && filter_var( $context['ip'], FILTER_VALIDATE_IP ) ) {
			$source['ip'] = (string) $context['ip'];
		}
		if ( isset( $context['email_sent'] ) && null !== $context['email_sent'] ) {
			$source['email'] = array(
				'sent'     => (bool) $context['email_sent'],
				'attempts' => 1,
			);
			if ( $context['email_sent'] ) {
				$source['email']['sent_at'] = gmdate( 'Y-m-d\TH:i:s\Z' );
			}
		}
		if ( $source ) {
			$payload['source'] = $source;
		}
		/**
		 * Filter the payload before it is queued. Return the array (with the same submission_id).
		 */
		return apply_filters( 'aiq_payload', $payload, $fields, $rule, $context );
	}

	public static function new_submission_id() {
		$site = substr( md5( home_url( '/' ) ), 0, 8 );
		return 'wp-' . $site . '-' . wp_generate_uuid4();
	}

	private static function form_key( array $context ) {
		$raw = ( isset( $context['source'] ) ? $context['source'] : 'wp' ) . '-' . ( isset( $context['form_id'] ) ? $context['form_id'] : 'form' );
		$key = preg_replace( '/[^A-Za-z0-9 _.:\-]/', '-', $raw );
		return substr( trim( $key ), 0, 100 );
	}

	private static function field_key( $label ) {
		$key = trim( preg_replace( '/\s+/', ' ', (string) $label ) );
		return '' === $key ? 'field' : mb_substr( $key, 0, 100 );
	}

	private static function locale() {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		$locale = str_replace( '_', '-', (string) $locale );
		return preg_match( '/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $locale ) ? substr( $locale, 0, 16 ) : 'en';
	}

	private static function to_text( $value ) {
		if ( is_array( $value ) ) {
			$value = implode( ', ', array_filter( array_map( 'strval', $value ), 'strlen' ) );
		}
		return trim( (string) $value );
	}

	private static function is_empty( $value ) {
		return is_array( $value ) ? 0 === count( array_filter( array_map( 'strval', $value ), 'strlen' ) ) : '' === trim( (string) $value );
	}
}
