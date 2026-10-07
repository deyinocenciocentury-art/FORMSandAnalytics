<?php
$_SERVER['HTTP_HOST']      = '127.0.0.1:8080';
$_SERVER['SERVER_NAME']    = '127.0.0.1';
$_SERVER['SERVER_PORT']    = '8080';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/';
define( 'WP_INSTALLING', true );
define( 'WP_HOME', 'http://127.0.0.1:8080' );
define( 'WP_SITEURL', 'http://127.0.0.1:8080' );
require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
$password = trim( stream_get_contents( STDIN ) );
if ( strlen( $password ) < 24 ) {
	fwrite( STDERR, "A generated local admin password is required.\n" );
	exit( 1 );
}
if ( ! is_blog_installed() ) {
	wp_install( 'Soulmarke Forms Development', 'soulmarke_admin', 'andrea@soulmarke.com', 0, '', $password );
	update_option( 'home', 'http://127.0.0.1:8080' );
	update_option( 'siteurl', 'http://127.0.0.1:8080' );
	echo "WordPress installed successfully.\n";
}
