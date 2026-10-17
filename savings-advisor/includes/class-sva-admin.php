<?php
/**
 * Admin page: upload, analysis and results.
 *
 * @package SavingsAdvisor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Plugin Controller admin page and handles uploads.
 */
class SVA_Admin {

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	private const PAGE_SLUG = 'savings-advisor';

	/**
	 * Maximum upload size per file in bytes.
	 *
	 * @var int
	 */
	private const MAX_BYTES = 5242880;

	/**
	 * Registers hooks and the Plugin Controller page.
	 *
	 * @return void
	 */
	public function init(): void {
		if ( SVA_DB_VERSION !== get_option( 'sva_db_version' ) ) {
			SVA_Store::install();
		}

		pc_register_admin_page(
			array(
				'slug'       => self::PAGE_SLUG,
				'title'      => __( 'Savings Advisor', 'savings-advisor' ),
				'capability' => 'manage_options',
				'callback'   => array( $this, 'render_page' ),
			)
		);

		add_action( 'admin_post_sva_import', array( $this, 'handle_import' ) );
		add_action( 'admin_post_sva_delete_all', array( $this, 'handle_delete_all' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Loads the stylesheet on the plugin page only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, self::PAGE_SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'sva-admin', SVA_PLUGIN_URL . 'assets/css/sva-admin.css', array(), SVA_PLUGIN_VERSION );
	}

	/**
	 * Gets the URL of the plugin page with optional extra arguments.
	 *
	 * @param array<string, string|int> $args Extra query arguments.
	 * @return string
	 */
	private function page_url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Imports uploaded CSV files.
	 *
	 * @return void
	 */
	public function handle_import(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Du har ikke tilladelse til at importere data.', 'savings-advisor' ) );
		}
		check_admin_referer( 'sva_import', 'sva_nonce' );

		$added      = 0;
		$duplicates = 0;
		$invalid    = 0;
		$files      = 0;
		$rejected   = 0;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer() above.
		$upload = isset( $_FILES['sva_files'] ) && is_array( $_FILES['sva_files'] ) ? $_FILES['sva_files'] : array();
		$names  = isset( $upload['name'] ) && is_array( $upload['name'] ) ? $upload['name'] : array();

		foreach ( $names as $i => $name ) {
			$tmp   = $upload['tmp_name'][ $i ] ?? '';
			$error = (int) ( $upload['error'][ $i ] ?? UPLOAD_ERR_NO_FILE );
			$size  = (int) ( $upload['size'][ $i ] ?? 0 );

			if ( UPLOAD_ERR_NO_FILE === $error ) {
				continue;
			}

			$extension = strtolower( pathinfo( sanitize_file_name( wp_unslash( (string) $name ) ), PATHINFO_EXTENSION ) );
			if (
				UPLOAD_ERR_OK !== $error ||
				$size < 1 ||
				$size > self::MAX_BYTES ||
				! in_array( $extension, array( 'csv', 'txt' ), true ) ||
				! is_string( $tmp ) ||
				! is_uploaded_file( $tmp )
			) {
				++$rejected;
				continue;
			}

			$contents = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $contents ) {
				++$rejected;
				continue;
			}

			$parsed  = SVA_Parser::parse( $contents );
			$invalid += $parsed['invalid'];
			if ( empty( $parsed['rows'] ) ) {
				++$rejected;
				continue;
			}

			$source = mb_substr( sanitize_text_field( pathinfo( sanitize_file_name( wp_unslash( (string) $name ) ), PATHINFO_FILENAME ) ), 0, 60 );
			$result = SVA_Store::import( $source, $parsed['rows'] );

			$added      += $result['added'];
			$duplicates += $result['duplicates'];
			++$files;
		}

		wp_safe_redirect(
			$this->page_url(
				array(
					'sva_status'   => 'imported',
					'sva_files'    => $files,
					'sva_added'    => $added,
					'sva_dups'     => $duplicates,
					'sva_invalid'  => $invalid,
					'sva_rejected' => $rejected,
				)
			)
		);
		exit;
	}

	/**
	 * Deletes all stored transactions.
	 *
	 * @return void
	 */
	public function handle_delete_all(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Du har ikke tilladelse til at slette data.', 'savings-advisor' ) );
		}
		check_admin_referer( 'sva_delete_all', 'sva_delete_nonce' );

		SVA_Store::delete_all();

		wp_safe_redirect( $this->page_url( array( 'sva_status' => 'deleted' ) ) );
		exit;
	}

	/**
	 * Renders a number from the query string.
	 *
	 * @param string $key Query argument.
	 * @return int
	 */
	private function query_int( string $key ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status display.
		return isset( $_GET[ $key ] ) ? absint( wp_unslash( $_GET[ $key ] ) ) : 0;
	}

	/**
	 * Renders the admin page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Du har ikke tilladelse til at se denne side.', 'savings-advisor' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status display.
		$status   = isset( $_GET['sva_status'] ) && is_string( $_GET['sva_status'] ) ? sanitize_key( wp_unslash( $_GET['sva_status'] ) ) : '';
		$sources  = SVA_Store::get_sources();
		$analysis = empty( $sources ) ? array() : SVA_Analyzer::analyze( SVA_Store::get_all() );
		?>
		<div class="wrap sva-wrap">
			<h1><?php esc_html_e( 'Savings Advisor', 'savings-advisor' ); ?></h1>

			<?php $this->render_notices( $status ); ?>
			<?php $this->render_upload_box( $sources ); ?>

			<?php if ( ! empty( $analysis ) ) : ?>
				<?php $this->render_summary( $analysis ); ?>
				<?php $this->render_suggestions( $analysis ); ?>
				<?php $this->render_categories( $analysis ); ?>
				<?php $this->render_recurring( $analysis ); ?>
				<?php $this->render_months( $analysis ); ?>
				<?php $this->render_biggest( $analysis ); ?>
				<p class="description">
					<?php esc_html_e( 'Analysen er regelbaseret og beregnes på din egen server. Besparelserne er skøn baseret på tommelfingerregler, ikke garantier. Overførsler mellem egne konti (fx "Budget") er udeladt.', 'savings-advisor' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders status notices after import or delete.
	 *
	 * @param string $status Status key.
	 * @return void
	 */
	private function render_notices( string $status ): void {
		if ( 'imported' === $status ) {
			$rejected = $this->query_int( 'sva_rejected' );
			$message  = sprintf(
				/* translators: 1: files, 2: new rows, 3: duplicate rows, 4: invalid lines. */
				__( '%1$d fil(er) importeret: %2$d nye posteringer, %3$d fandtes allerede, %4$d linjer kunne ikke læses.', 'savings-advisor' ),
				$this->query_int( 'sva_files' ),
				$this->query_int( 'sva_added' ),
				$this->query_int( 'sva_dups' ),
				$this->query_int( 'sva_invalid' )
			);
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
			if ( $rejected > 0 ) {
				echo '<div class="notice notice-error"><p>' . esc_html(
					sprintf(
						/* translators: %d: rejected file count. */
						__( '%d fil(er) blev afvist (skal være .csv/.txt under 5 MB med gyldige linjer: dato;tekst;beløb;DKK).', 'savings-advisor' ),
						$rejected
					)
				) . '</p></div>';
			}
		} elseif ( 'deleted' === $status ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Alle importerede data er slettet.', 'savings-advisor' ) . '</p></div>';
		}
	}

	/**
	 * Renders the upload form and imported source list.
	 *
	 * @param array<int, array<string, mixed>> $sources Imported sources.
	 * @return void
	 */
	private function render_upload_box( array $sources ): void {
		?>
		<div class="sva-card">
			<h2><?php esc_html_e( 'Importér kontoudtog', 'savings-advisor' ); ?></h2>
			<p><?php esc_html_e( 'Vælg én eller flere CSV-filer fra din bank (semikolonseparerede: dato;tekst;beløb;valuta). Samme fil kan importeres igen uden dubletter.', 'savings-advisor' ); ?></p>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'sva_import', 'sva_nonce' ); ?>
				<input type="hidden" name="action" value="sva_import" />
				<input type="file" name="sva_files[]" accept=".csv,.txt" multiple required />
				<?php submit_button( __( 'Importér og analysér', 'savings-advisor' ), 'primary', 'submit', false ); ?>
			</form>

			<?php if ( ! empty( $sources ) ) : ?>
				<table class="widefat striped sva-sources">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Kilde', 'savings-advisor' ); ?></th>
							<th><?php esc_html_e( 'Posteringer', 'savings-advisor' ); ?></th>
							<th><?php esc_html_e( 'Periode', 'savings-advisor' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $sources as $source ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $source['source'] ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (float) $source['cnt'], 0 ) ); ?></td>
								<td><?php echo esc_html( (string) $source['first_date'] . ' → ' . (string) $source['last_date'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Slet alle importerede posteringer?', 'savings-advisor' ) ); ?>');">
					<?php wp_nonce_field( 'sva_delete_all', 'sva_delete_nonce' ); ?>
					<input type="hidden" name="action" value="sva_delete_all" />
					<?php submit_button( __( 'Slet alle importerede data', 'savings-advisor' ), 'delete', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders the headline figures.
	 *
	 * @param array<string, mixed> $a Analysis.
	 * @return void
	 */
	private function render_summary( array $a ): void {
		$balance = $a['income'] - $a['spending'];
		$cards   = array(
			__( 'Periode', 'savings-advisor' )         => $a['from'] . ' → ' . $a['to'],
			__( 'Indkomst (løn m.m.)', 'savings-advisor' ) => SVA_Analyzer::kr( $a['income'] ),
			__( 'Forbrug', 'savings-advisor' )         => SVA_Analyzer::kr( $a['spending'] ),
			__( 'Gennemsnit pr. måned', 'savings-advisor' ) => SVA_Analyzer::kr( $a['spending'] / $a['month_eq'] ),
			__( 'Indkomst minus forbrug', 'savings-advisor' ) => SVA_Analyzer::kr( $balance ),
		);

		$potential = 0.0;
		foreach ( $a['suggestions'] as $s ) {
			$potential += (float) ( $s['saving'] ?? 0.0 );
		}
		$cards[ __( 'Skønnet sparepotentiale pr. år', 'savings-advisor' ) ] = SVA_Analyzer::kr( $potential );
		?>
		<div class="sva-cards">
			<?php foreach ( $cards as $label => $value ) : ?>
				<div class="sva-stat">
					<span class="sva-stat-label"><?php echo esc_html( (string) $label ); ?></span>
					<span class="sva-stat-value"><?php echo esc_html( (string) $value ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Renders saving suggestions.
	 *
	 * @param array<string, mixed> $a Analysis.
	 * @return void
	 */
	private function render_suggestions( array $a ): void {
		?>
		<div class="sva-card">
			<h2><?php esc_html_e( 'Forslag til besparelser', 'savings-advisor' ); ?></h2>
			<?php if ( empty( $a['suggestions'] ) ) : ?>
				<p><?php esc_html_e( 'Ingen oplagte besparelser fundet.', 'savings-advisor' ); ?></p>
			<?php endif; ?>
			<ol class="sva-suggestions">
				<?php foreach ( $a['suggestions'] as $s ) : ?>
					<li>
						<strong><?php echo esc_html( (string) $s['title'] ); ?></strong>
						<?php if ( null !== $s['saving'] ) : ?>
							<span class="sva-saving"><?php echo esc_html( '≈ ' . SVA_Analyzer::kr( (float) $s['saving'] ) . ' ' . __( 'pr. år', 'savings-advisor' ) ); ?></span>
						<?php endif; ?>
						<p><?php echo esc_html( (string) $s['detail'] ); ?></p>
						<?php if ( '' !== $s['basis'] ) : ?>
							<p class="description"><?php echo esc_html( (string) $s['basis'] ); ?></p>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>
		</div>
		<?php
	}

	/**
	 * Renders the category breakdown.
	 *
	 * @param array<string, mixed> $a Analysis.
	 * @return void
	 */
	private function render_categories( array $a ): void {
		?>
		<div class="sva-card">
			<h2><?php esc_html_e( 'Forbrug pr. kategori', 'savings-advisor' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Kategori', 'savings-advisor' ); ?></th>
						<th><?php esc_html_e( 'I alt', 'savings-advisor' ); ?></th>
						<th><?php esc_html_e( 'Pr. måned', 'savings-advisor' ); ?></th>
						<th><?php esc_html_e( 'Andel', 'savings-advisor' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $a['categories'] as $c ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $c['label'] ); ?></td>
							<td><?php echo esc_html( SVA_Analyzer::kr( (float) $c['total'] ) ); ?></td>
							<td><?php echo esc_html( SVA_Analyzer::kr( (float) $c['total'] / (float) $a['month_eq'] ) ); ?></td>
							<td>
								<span class="sva-bar"><span style="width: <?php echo esc_attr( (string) round( (float) $c['share'] * 100 ) ); ?>%"></span></span>
								<?php echo esc_html( number_format_i18n( (float) $c['share'] * 100, 0 ) . ' %' ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Renders recurring payments.
	 *
	 * @param array<string, mixed> $a Analysis.
	 * @return void
	 */
	private function render_recurring( array $a ): void {
		if ( empty( $a['recurring'] ) ) {
			return;
		}
		$defs = SVA_Analyzer::categories();
		?>
		<div class="sva-card">
			<h2><?php esc_html_e( 'Faste, tilbagevendende udgifter', 'savings-advisor' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Betaling', 'savings-advisor' ); ?></th>
						<th><?php esc_html_e( 'Kategori', 'savings-advisor' ); ?></th>
						<th><?php esc_html_e( 'Pr. måned', 'savings-advisor' ); ?></th>
						<th><?php esc_html_e( 'Pr. år', 'savings-advisor' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $a['recurring'] as $r ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $r['name'] ); ?></td>
							<td><?php echo esc_html( (string) $defs[ $r['cat'] ]['label'] ); ?></td>
							<td><?php echo esc_html( SVA_Analyzer::kr( (float) $r['monthly'] ) ); ?></td>
							<td><?php echo esc_html( SVA_Analyzer::kr( (float) $r['yearly'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Renders the month-by-month overview.
	 *
	 * @param array<string, mixed> $a Analysis.
	 * @return void
	 */
	private function render_months( array $a ): void {
		$max = 1.0;
		foreach ( $a['months'] as $m ) {
			$max = max( $max, (float) $m['spending'], (float) $m['income'] );
		}
		?>
		<div class="sva-card">
			<h2><?php esc_html_e( 'Måned for måned', 'savings-advisor' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Måned', 'savings-advisor' ); ?></th>
						<th><?php esc_html_e( 'Indkomst', 'savings-advisor' ); ?></th>
						<th><?php esc_html_e( 'Forbrug', 'savings-advisor' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $a['months'] as $ym => $m ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $ym ); ?></td>
							<td><?php echo esc_html( SVA_Analyzer::kr( (float) $m['income'] ) ); ?></td>
							<td><?php echo esc_html( SVA_Analyzer::kr( (float) $m['spending'] ) ); ?></td>
							<td><span class="sva-bar"><span style="width: <?php echo esc_attr( (string) round( max( 0.0, (float) $m['spending'] ) / $max * 100 ) ); ?>%"></span></span></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Renders the biggest single purchases.
	 *
	 * @param array<string, mixed> $a Analysis.
	 * @return void
	 */
	private function render_biggest( array $a ): void {
		?>
		<div class="sva-card">
			<h2><?php esc_html_e( 'Største enkeltudgifter', 'savings-advisor' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Dato', 'savings-advisor' ); ?></th>
						<th><?php esc_html_e( 'Tekst', 'savings-advisor' ); ?></th>
						<th><?php esc_html_e( 'Beløb', 'savings-advisor' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $a['biggest'] as $t ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $t['date'] ); ?></td>
							<td><?php echo esc_html( (string) $t['description'] ); ?></td>
							<td><?php echo esc_html( SVA_Analyzer::kr( (float) $t['amount'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
