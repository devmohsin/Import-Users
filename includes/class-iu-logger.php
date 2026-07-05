<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin logging wrapper. Row-level errors belong in the iu_import_records
 * table (error_message column); this is for developer-facing diagnostics
 * that don't have a specific row to attach to.
 */
class IU_Logger {

	public static function error( $message, array $context = array() ) {
		self::write( 'ERROR', $message, $context );
	}

	public static function info( $message, array $context = array() ) {
		self::write( 'INFO', $message, $context );
	}

	private static function write( $level, $message, array $context ) {
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return;
		}

		$line = sprintf( '[Import Users][%s] %s', $level, $message );

		if ( ! empty( $context ) ) {
			$line .= ' ' . wp_json_encode( $context );
		}

		error_log( $line );
	}
}
