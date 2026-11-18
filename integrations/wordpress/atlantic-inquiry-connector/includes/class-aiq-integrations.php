<?php
/**
 * Form plugin hooks. Nothing here changes or blocks the forms' own email behaviour:
 * - Contact Form 7: runs on wpcf7_mail_sent / wpcf7_mail_failed, i.e. after CF7 has handled its mail.
 * - Elementor Pro: runs on elementor_pro/forms/new_record, which fires after the form's actions (Email etc.).
 * Only forms with an enabled rule on Settings > Inquiry Connector are forwarded.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AIQ_Integrations {

	/** @var bool|null Elementor email outcome for the current request. */
	private static $elementor_mail_sent = null;

	/** @var array submission ids handled in this request (guards against double hooks). */
	private static $handled = array();

	public static function register() {
		add_action( 'wpcf7_mail_sent', array( __CLASS__, 'cf7_mail_sent' ), 20, 1 );
		add_action( 'wpcf7_mail_failed', array( __CLASS__, 'cf7_mail_failed' ), 20, 1 );
		add_action( 'elementor_pro/forms/mail_sent', array( __CLASS__, 'elementor_mail_sent' ), 10, 2 );
		add_action( 'elementor_pro/forms/new_record', array( __CLASS__, 'elementor_new_record' ), 20, 2 );
	}

	public static function cf7_mail_sent( $contact_form ) {
		self::cf7( $contact_form, true );
	}

	public static function cf7_mail_failed( $contact_form ) {
		self::cf7( $contact_form, false );
	}

	private static function cf7( $contact_form, $mail_sent ) {
		if ( ! class_exists( 'WPCF7_Submission' ) || ! is_object( $contact_form ) ) {
			return;
		}
		$identifiers = array( (string) $contact_form->id(), $contact_form->title() );
		if ( method_exists( $contact_form, 'hash' ) ) {
			$identifiers[] = $contact_form->hash();
		}
		$rule = AIQ_Settings::find_rule( 'cf7', $identifiers );
		if ( null === $rule ) {
			return;
		}
		$submission = WPCF7_Submission::get_instance();
		if ( ! $submission ) {
			return;
		}
		$guard = 'cf7-' . $contact_form->id() . '-' . md5( wp_json_encode( $submission->get_posted_data() ) );
		if ( isset( self::$handled[ $guard ] ) ) {
			return;
		}
		self::$handled[ $guard ] = true;

		$fields = array();
		foreach ( (array) $submission->get_posted_data() as $name => $value ) {
			$fields[ $name ] = array(
				'label' => $name,
				'value' => $value,
			);
		}
		$context = array(
			'source'     => 'cf7',
			'form_id'    => $contact_form->id(),
			'form_name'  => $contact_form->title(),
			'page_url'   => (string) $submission->get_meta( 'url' ),
			'ip'         => (string) $submission->get_meta( 'remote_ip' ),
			// When the form has "skip_mail", CF7 reports mail_sent without sending; report that as unknown.
			'email_sent' => $contact_form->in_demo_mode() ? null : (bool) $mail_sent,
		);
		$payload = AIQ_Mapper::build( $fields, $rule, $context );
		AIQ_Outbox::submit( $payload, 'cf7', $contact_form->title() . ' (#' . $contact_form->id() . ')' );
	}

	public static function elementor_mail_sent( $settings, $record ) {
		self::$elementor_mail_sent = true;
	}

	/**
	 * @param \ElementorPro\Modules\Forms\Classes\Form_Record $record
	 */
	public static function elementor_new_record( $record, $handler ) {
		if ( ! is_object( $record ) || ! method_exists( $record, 'get_form_settings' ) ) {
			return;
		}
		$form_name = (string) $record->get_form_settings( 'form_name' );
		$form_id   = (string) $record->get_form_settings( 'id' );
		$rule      = AIQ_Settings::find_rule( 'elementor', array( $form_name, $form_id ) );
		if ( null === $rule ) {
			return;
		}
		$fields = array();
		foreach ( (array) $record->get( 'fields' ) as $id => $field ) {
			$fields[ (string) $id ] = array(
				'label' => isset( $field['title'] ) && '' !== $field['title'] ? (string) $field['title'] : (string) $id,
				'value' => isset( $field['value'] ) ? $field['value'] : '',
			);
		}
		$meta     = (array) $record->get( 'meta' );
		$page_url = isset( $meta['page_url']['value'] ) ? (string) $meta['page_url']['value'] : (string) wp_get_referer();
		$ip       = isset( $meta['remote_ip']['value'] ) ? (string) $meta['remote_ip']['value'] : ( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' );

		$context = array(
			'source'     => 'elementor',
			'form_id'    => '' !== $form_id ? $form_id : $form_name,
			'form_name'  => $form_name,
			'page_url'   => $page_url,
			'ip'         => $ip,
			'email_sent' => self::$elementor_mail_sent,
		);
		$payload = AIQ_Mapper::build( $fields, $rule, $context );
		AIQ_Outbox::submit( $payload, 'elementor', $form_name );
	}
}
