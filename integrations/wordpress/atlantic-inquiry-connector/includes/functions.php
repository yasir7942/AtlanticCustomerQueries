<?php
/**
 * Public helpers for themes and custom PHP forms.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Forward an inquiry from your own PHP form handler.
 *
 *   $result = aiq_forward_inquiry( array(
 *       'form_type' => 'quote',
 *       'form_id'   => 'theme-quote-form',
 *       'full_name' => sanitize_text_field( $_POST['name'] ),
 *       'email'     => sanitize_email( $_POST['email'] ),
 *       'message'   => sanitize_textarea_field( $_POST['message'] ),
 *       'products'  => array( array( 'name' => 'Valve DN50', 'quantity' => 10 ) ),
 *       'fields'    => array( 'deadline' => array( 'label' => 'Needed by', 'value' => '2026-12-01' ) ),
 *   ) );
 *
 * The payload follows the Inquiry Manager v1 contract. A submission_id is generated if missing;
 * pass your own stable ID if your form can be re-submitted. The inquiry is stored in the outbox
 * first, so a failed delivery is retried by WP-Cron with the same ID.
 *
 * @return array{ok:bool,queued:bool,status:int,code:string,message:string,inquiry_id:?int,fields:array}|WP_Error
 */
function aiq_forward_inquiry( array $data, array $args = array() ) {
	if ( ! AIQ_Settings::is_configured() ) {
		return new WP_Error( 'aiq_not_configured', 'Inquiry Connector: endpoint or API key missing.' );
	}
	if ( empty( $data['submission_id'] ) ) {
		$data['submission_id'] = AIQ_Mapper::new_submission_id();
	}
	if ( empty( $data['form_type'] ) ) {
		$data['form_type'] = 'contact';
	}
	if ( empty( $data['source']['page_url'] ) && wp_get_referer() ) {
		$data['source']['page_url'] = wp_get_referer();
	}
	$label = isset( $args['label'] ) ? (string) $args['label'] : ( isset( $data['form_id'] ) ? (string) $data['form_id'] : 'custom' );
	return AIQ_Outbox::submit( $data, 'custom', $label );
}

/**
 * True when the plugin already forwards this form, so theme snippets can skip it
 * (prevents double forwarding when both methods are present).
 *
 * @param string $source 'cf7' or 'elementor'
 * @param string $form   CF7 form ID/hash/title or Elementor form name/ID
 */
function aiq_connector_handles( $source, $form ) {
	return null !== AIQ_Settings::find_rule( (string) $source, array( (string) $form ) );
}
