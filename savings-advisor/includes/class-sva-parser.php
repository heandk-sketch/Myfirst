<?php
/**
 * Bank CSV parser.
 *
 * @package SavingsAdvisor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses semicolon separated bank exports: date;text;amount;currency.
 *
 * Dates are dd-mm-yyyy and amounts use Danish formatting, e.g. -1.563,00.
 */
class SVA_Parser {

	/**
	 * Parses CSV contents.
	 *
	 * @param string $contents Raw file contents.
	 * @return array{rows: array<int, array<string, mixed>>, invalid: int}
	 */
	public static function parse( string $contents ): array {
		if ( str_starts_with( $contents, "\xEF\xBB\xBF" ) ) {
			$contents = substr( $contents, 3 );
		}
		if ( ! mb_check_encoding( $contents, 'UTF-8' ) ) {
			$contents = mb_convert_encoding( $contents, 'UTF-8', 'Windows-1252' );
		}

		$rows    = array();
		$invalid = 0;
		$lines   = preg_split( '/\R/', $contents );

		foreach ( (array) $lines as $line ) {
			if ( '' === trim( (string) $line ) ) {
				continue;
			}

			$cols = str_getcsv( (string) $line, ';', '"', '' );
			if ( count( $cols ) < 3 ) {
				++$invalid;
				continue;
			}

			$date   = self::parse_date( trim( (string) $cols[0] ) );
			$amount = self::parse_amount( trim( (string) $cols[2] ) );
			$curr   = isset( $cols[3] ) ? strtoupper( trim( (string) $cols[3] ) ) : 'DKK';

			if ( null === $date || null === $amount || ( '' !== $curr && 'DKK' !== $curr ) ) {
				++$invalid;
				continue;
			}

			$rows[] = array(
				'date'        => $date,
				'description' => mb_substr( trim( (string) $cols[1] ), 0, 255 ),
				'amount'      => $amount,
			);
		}

		return array(
			'rows'    => $rows,
			'invalid' => $invalid,
		);
	}

	/**
	 * Converts dd-mm-yyyy to Y-m-d.
	 *
	 * @param string $value Date text.
	 * @return string|null
	 */
	private static function parse_date( string $value ): ?string {
		if ( ! preg_match( '/^(\d{2})-(\d{2})-(\d{4})$/', $value, $m ) ) {
			return null;
		}
		if ( ! checkdate( (int) $m[2], (int) $m[1], (int) $m[3] ) ) {
			return null;
		}
		return $m[3] . '-' . $m[2] . '-' . $m[1];
	}

	/**
	 * Converts a Danish formatted number to float.
	 *
	 * @param string $value Amount text.
	 * @return float|null
	 */
	private static function parse_amount( string $value ): ?float {
		if ( ! preg_match( '/^-?\d{1,3}(?:\.\d{3})*(?:,\d+)?$|^-?\d+(?:,\d+)?$/', $value ) ) {
			return null;
		}
		return (float) str_replace( array( '.', ',' ), array( '', '.' ), $value );
	}
}
