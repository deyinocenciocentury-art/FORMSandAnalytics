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
	// Body capture is opt-in, local-only, and limited to one integration run's
	// random marker. Routine development mail logs never retain respondent text.
	$capture_run = get_option( 'soulmarke_local_mail_capture_run', '' );
	$message = (string) ( $atts['message'] ?? '' );
	if ( wp_get_environment_type() === 'local' && is_string( $capture_run )
		&& preg_match( '/^qa_[a-f0-9]{20}$/', $capture_run )
		&& strpos( $message, $capture_run ) !== false ) {
		$record['capture_run'] = $capture_run;
		$record['message'] = $message;
	}
	$path = '/tmp/soulmarke-local-mail.jsonl';
	$previous_umask = umask( 0077 );
	file_put_contents( $path, wp_json_encode( $record ) . "\n", FILE_APPEND | LOCK_EX );
	umask( $previous_umask );
	@chmod( $path, 0600 );
	return true;
}, PHP_INT_MAX, 2 );
