<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Minimal CSV reader: BOM-safe, comma or semicolon delimiter (auto-detected). */
class Csv {

	/** @return array{0:string[],1:array[]} header (raw) and rows (list of raw cell lists) */
	public static function parse( $text ) {
		$text = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $text );
		$first = strtok( $text, "\r\n" );
		$delim = substr_count( (string) $first, ';' ) > substr_count( (string) $first, ',' ) ? ';' : ',';

		$h = fopen( 'php://temp', 'r+' );
		fwrite( $h, $text );
		rewind( $h );
		$header = fgetcsv( $h, 0, $delim );
		$rows   = array();
		if ( false === $header ) {
			fclose( $h );
			return array( array(), array() );
		}
		while ( false !== ( $r = fgetcsv( $h, 0, $delim ) ) ) {
			if ( 1 === count( $r ) && ( null === $r[0] || '' === trim( $r[0] ) ) ) {
				continue; // blank line
			}
			$rows[] = $r;
		}
		fclose( $h );
		return array( $header, $rows );
	}

	public static function norm( $s ) {
		return trim( strtolower( preg_replace( '/[\s_\-]+/', ' ', (string) $s ) ) );
	}
}
