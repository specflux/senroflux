<?php
/**
 * PHPStan bootstrap: declares the few WordPress constants src/ relies on that
 * wordpress-stubs does not provide.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/senroflux-phpstan/' );
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
