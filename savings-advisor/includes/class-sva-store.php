<?php
/**
 * Transaction storage.
 *
 * @package SavingsAdvisor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores imported transactions. Re-importing the same file never creates duplicates.
 */
class SVA_Store {

	/**
	 * Gets the transaction table name.
	 *
	 * @return string
	 */
	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'sva_transactions';
	}

	/**
	 * Creates or upgrades the database table.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE $table (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				source VARCHAR(60) NOT NULL,
				tx_date DATE NOT NULL,
				description VARCHAR(255) NOT NULL,
				amount DECIMAL(12,2) NOT NULL,
				tx_hash CHAR(40) NOT NULL,
				imported_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY sva_tx_hash_idx (tx_hash),
				KEY sva_tx_date_idx (tx_date)
			) $charset;"
		);

		update_option( 'sva_db_version', SVA_DB_VERSION );
	}

	/**
	 * Inserts parsed rows, skipping rows that already exist.
	 *
	 * @param string                           $source Source label, normally the file name.
	 * @param array<int, array<string, mixed>> $rows   Parsed rows.
	 * @return array{added: int, duplicates: int}
	 */
	public static function import( string $source, array $rows ): array {
		global $wpdb;
		$table      = self::table();
		$added      = 0;
		$duplicates = 0;
		$seen       = array();

		foreach ( $rows as $row ) {
			$key          = $source . '|' . $row['date'] . '|' . $row['description'] . '|' . number_format( (float) $row['amount'], 2, '.', '' );
			$seen[ $key ] = ( $seen[ $key ] ?? 0 ) + 1;
			$hash         = sha1( $key . '|' . $seen[ $key ] );

			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO $table (source, tx_date, description, amount, tx_hash, imported_at) VALUES (%s, %s, %s, %f, %s, %s)",
					$source,
					$row['date'],
					$row['description'],
					$row['amount'],
					$hash,
					current_time( 'mysql' )
				)
			);

			if ( $wpdb->rows_affected > 0 ) {
				++$added;
			} else {
				++$duplicates;
			}
		}

		return array(
			'added'      => $added,
			'duplicates' => $duplicates,
		);
	}

	/**
	 * Gets all transactions, oldest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_all(): array {
		global $wpdb;
		$table = self::table();

		$rows = $wpdb->get_results( "SELECT tx_date AS date, description, amount FROM $table ORDER BY tx_date ASC, id ASC", ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'date'        => (string) $row['date'],
				'description' => (string) $row['description'],
				'amount'      => (float) $row['amount'],
			);
		}
		return $out;
	}

	/**
	 * Summarises imported sources.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_sources(): array {
		global $wpdb;
		$table = self::table();

		$rows = $wpdb->get_results( "SELECT source, COUNT(*) AS cnt, MIN(tx_date) AS first_date, MAX(tx_date) AS last_date FROM $table GROUP BY source ORDER BY source ASC", ARRAY_A );
		return (array) $rows;
	}

	/**
	 * Deletes all stored transactions.
	 *
	 * @return void
	 */
	public static function delete_all(): void {
		global $wpdb;
		$table = self::table();
		$wpdb->query( "TRUNCATE TABLE $table" );
	}
}
