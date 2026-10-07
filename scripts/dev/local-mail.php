<?php
/**
 * Plugin Name: Soulmarke Local Development Mail Capture
 * Description: Prevents outgoing mail in the local development instance only.
 */
defined( 'ABSPATH' ) || exit;
add_filter( 'pre_wp_mail', static function ( $return, $atts ) {
	$recipients = is_array( $atts['to'] ?? null ) ? $atts['to'] : explode( ',', (string) ( $atts['to'] ?? '' ) );
	$recipients = array_values( array_filter( array_map( 'sanitize_email', $recipients ) ) );
	$record = array(
		'time'       => gmdate( 'c' ),
		'recipients' => $recipients,
		'subject'    => sanitize_text_field( (string) ( $atts['subject'] ?? '' ) ),
	);
	$path = '/tmp/soulmarke-local-mail.jsonl';
	file_put_contents( $path, wp_json_encode( $record ) . "\n", FILE_APPEND | LOCK_EX );
	@chmod( $path, 0600 );
	return true;
}, PHP_INT_MAX, 2 );
