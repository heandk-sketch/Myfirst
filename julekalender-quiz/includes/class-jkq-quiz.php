<?php
/**
 * Daily Christmas quiz.
 *
 * @package JulekalenderQuiz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides the daily quiz shortcode and its settings.
 */
class JKQ_Quiz {

	private const OPTION_NAME    = 'jkq_settings';
	private const ANSWERS_OPTION = 'jkq_daily_answers';
	private const SCORES_OPTION  = 'jkq_scores';
	private const CONSENT_OPTION = 'jkq_participant_consent';
	private const STANDINGS_OPTION = 'jkq_standings_page_id';
	private const ACCESS_KEY     = 'julekalender-quiz';
	private const SETTINGS_SLUG  = 'julekalender-quiz-settings';
	private const HIDE_INTRO_META = 'jkq_hide_intro';

	/**
	 * Registers the plugin hooks and Plugin Controller settings page.
	 *
	 * @return void
	 */
	public function init(): void {
		add_option(
			self::OPTION_NAME,
			array(
				'intro'    => self::default_intro(),
				'demo_day' => '0',
			),
			'',
			false
		);
		add_shortcode( 'julekalender_quiz', array( $this, 'render_shortcode' ) );
		add_shortcode( 'julequiz_stilling', array( $this, 'render_standings_shortcode' ) );
		add_action( 'init', array( $this, 'handle_answer' ) );
		add_action( 'init', array( $this, 'handle_intro' ) );
		add_action( 'init', array( $this, 'handle_consent' ) );
		$this->ensure_standings_page();
		wp_register_style(
			'jkq-quiz',
			plugins_url( '../assets/css/julekalender-quiz.css', __FILE__ ),
			array(),
			'1.0.1'
		);
		wp_register_script(
			'jkq-quiz',
			plugins_url( '../assets/js/julekalender-quiz.js', __FILE__ ),
			array(),
			'1.0.0',
			true
		);

		pc_register_access_plugin(
			self::ACCESS_KEY,
			__( 'Julekalender Quiz', 'julekalender-quiz' ),
			'julekalender_quiz',
			array(
				'192' => JKQ_PLUGIN_URL . 'assets/images/icon-192.png',
				'512' => JKQ_PLUGIN_URL . 'assets/images/icon-512.png',
			)
		);
		add_action( 'admin_notices', array( $this, 'render_reset_notice' ) );
		add_action( 'admin_post_jkq_reset_answers', array( $this, 'reset_answers' ) );
		add_action( 'admin_post_jkq_restore_intro', array( $this, 'restore_intro' ) );

		pc_register_settings_page(
			array(
				'slug'        => self::SETTINGS_SLUG,
				'title'       => __( 'Julekalender Quiz', 'julekalender-quiz' ),
				'option_name' => self::OPTION_NAME,
				'fields'      => array(
					array(
						'id'          => 'intro',
						'label'       => __( 'Intro tekst', 'julekalender-quiz' ),
						'type'        => 'textarea',
						'description' => __( 'Vises på introduktionssiden før quizzen.', 'julekalender-quiz' ),
					),
					array(
						'id'          => 'demo_day',
						'label'       => __( 'Eksempeldag', 'julekalender-quiz' ),
						'type'        => 'select',
						'options'     => array(
							'0' => __( 'Brug dagens dato', 'julekalender-quiz' ),
							'1' => __( 'Dag 1: Julemandens adresse', 'julekalender-quiz' ),
							'2' => __( 'Dag 2: Lucia', 'julekalender-quiz' ),
							'3' => __( 'Dag 3: Rudolf', 'julekalender-quiz' ),
						),
						'description' => __( 'Vælg en dag for at vise et eksempel uden for december.', 'julekalender-quiz' ),
					),
				),
			)
		);
	}

	/**
	 * Records the participant's consent to appear in the shared standings.
	 *
	 * @return void
	 */
	public function handle_consent(): void {
		if ( ! isset( $_POST['jkq_consent_submit'] ) ) {
			return;
		}

		if ( ! $this->user_has_access() || ! function_exists( 'pc_get_local_access_user_id' ) || ! pc_get_local_access_user_id() ) {
			wp_die( esc_html__( 'Du har ikke adgang til Julekalender Quiz.', 'julekalender-quiz' ), '', array( 'response' => 403 ) );
		}

		$nonce = isset( $_POST['jkq_consent_nonce'] ) && is_string( $_POST['jkq_consent_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['jkq_consent_nonce'] ) )
			: '';
		if ( ! wp_verify_nonce( $nonce, 'jkq_consent' ) ) {
			wp_die( esc_html__( 'Sikkerhedstjekket fejlede. Genindlæs siden, og prøv igen.', 'julekalender-quiz' ), '', array( 'response' => 403 ) );
		}

		if ( ! isset( $_POST['jkq_consent_confirm'] ) || '1' !== $_POST['jkq_consent_confirm'] ) {
			wp_die( esc_html__( 'Du skal acceptere deltagelse i stillingen, før du kan fortsætte.', 'julekalender-quiz' ), '', array( 'response' => 400 ) );
		}

		$user_id = pc_get_local_access_user_id();
		$consent = get_option( self::CONSENT_OPTION, array() );
		$consent = is_array( $consent ) ? $consent : array();
		$consent[ $user_id ] = current_time( 'mysql' );
		update_option( self::CONSENT_OPTION, $consent, false );

		$return_url = isset( $_POST['jkq_consent_return_url'] ) && is_string( $_POST['jkq_consent_return_url'] )
			? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['jkq_consent_return_url'] ) ), home_url( '/' ) )
			: home_url( '/' );
		wp_safe_redirect( add_query_arg( 'jkq_consent', 'accepted', $return_url ) );
		exit;
	}

	private static function default_intro(): string {
		return __( 'Velkommen til julequizzen! Åbn dagens låge, og se, hvor meget du ved om julens traditioner og fortællinger. Vælg dit svar med omhu: Du kan kun svare én gang på hvert spørgsmål. God fornøjelse og glædelig jul!', 'julekalender-quiz' );
	}

	/**
	 * Saves the local user's introduction preference and continues to the quiz.
	 *
	 * @return void
	 */
	public function handle_intro(): void {
		if ( ! isset( $_POST['jkq_intro_submit'] ) ) {
			return;
		}

		if ( ! $this->user_has_access() || ! function_exists( 'pc_get_local_access_user_id' ) || ! pc_get_local_access_user_id() ) {
			wp_die( esc_html__( 'Du har ikke adgang til Julekalender Quiz.', 'julekalender-quiz' ), '', array( 'response' => 403 ) );
		}

		$nonce = isset( $_POST['jkq_intro_nonce'] ) && is_string( $_POST['jkq_intro_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['jkq_intro_nonce'] ) )
			: '';
		if ( ! wp_verify_nonce( $nonce, 'jkq_intro' ) ) {
			wp_die( esc_html__( 'Sikkerhedstjekket fejlede. Genindlæs siden, og prøv igen.', 'julekalender-quiz' ), '', array( 'response' => 403 ) );
		}

		$user_id = pc_get_local_access_user_id();
		$hidden  = isset( $_POST['jkq_hide_intro'] ) && is_string( $_POST['jkq_hide_intro'] ) && '1' === wp_unslash( $_POST['jkq_hide_intro'] );
		$saved   = '1' === get_user_meta( $user_id, self::HIDE_INTRO_META, true );
		if ( $hidden !== $saved ) {
			if ( $hidden ) {
				$updated = update_user_meta( $user_id, self::HIDE_INTRO_META, '1' );
			} else {
				$updated = delete_user_meta( $user_id, self::HIDE_INTRO_META );
			}
			if ( false === $updated ) {
				wp_die( esc_html__( 'Dit valg kunne ikke gemmes. Prøv igen.', 'julekalender-quiz' ) );
			}
		}

		$return_url = isset( $_POST['jkq_intro_return_url'] ) && is_string( $_POST['jkq_intro_return_url'] )
			? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['jkq_intro_return_url'] ) ), home_url( '/' ) )
			: home_url( '/' );
		wp_safe_redirect( add_query_arg( 'jkq_intro', 'continue', $return_url ) );
		exit;
	}

	/**
	 * Processes an answer submitted from the shortcode.
	 *
	 * @return void
	 */
	public function handle_answer(): void {
		if ( ! isset( $_POST['jkq_submit'], $_POST['jkq_answer'], $_POST['jkq_nonce'] ) ) {
			return;
		}

		if ( ! $this->user_has_access() ) {
			return;
		}

		if ( ! $this->has_consent() ) {
			wp_die( esc_html__( 'Du skal acceptere deltagelse i stillingen, før du kan svare.', 'julekalender-quiz' ), '', array( 'response' => 403 ) );
		}

		if ( ! is_string( $_POST['jkq_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['jkq_nonce'] ) ), 'jkq_answer' ) ) {
			return;
		}

		if ( ! is_string( $_POST['jkq_answer'] ) ) {
			return;
		}

		$answer = sanitize_key( wp_unslash( $_POST['jkq_answer'] ) );
		$day    = $this->get_current_day();
		$quiz   = $this->get_questions();

		if ( ! isset( $quiz[ $day ], $quiz[ $day ]['answers'][ $answer ] ) ) {
			return;
		}

		$stored_answer = $this->get_answer_for_day( $day );
		if ( '' !== $stored_answer ) {
			$result = $quiz[ $day ]['correct'] === $stored_answer ? 'correct' : 'wrong';
		} else {
			$this->save_answer( $day, $answer );
			$result = $quiz[ $day ]['correct'] === $answer ? 'correct' : 'wrong';
			$this->save_score( $day, $result );
		}

		$return_url = $this->get_standings_url();
		$url        = add_query_arg(
			array(
				'jkq_result' => $result,
				'jkq_day'    => $day,
			),
			$return_url
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Renders the daily question.
	 *
	 * @return string
	 */
	public function render_shortcode(): string {
		wp_enqueue_style( 'jkq-quiz' );
		wp_enqueue_script( 'jkq-quiz' );

		if ( ! function_exists( 'pc_get_local_access_user_id' ) || ! function_exists( 'pc_render_local_access_form' ) || ! function_exists( 'pc_local_user_can_access' ) ) {
			return '<p>' . esc_html__( 'Julekalender Quiz kræver, at Plugin Controller er aktivt.', 'julekalender-quiz' ) . '</p>';
		}

		if ( 0 === pc_get_local_access_user_id() ) {
			return pc_render_local_access_form();
		}

		if ( ! pc_local_user_can_access( self::ACCESS_KEY ) ) {
			return '<p>' . esc_html__( 'Din bruger har ikke adgang til Julekalender Quiz.', 'julekalender-quiz' ) . '</p>';
		}

		if ( ! $this->has_consent() ) {
			return $this->render_consent();
		}

		$settings = get_option( self::OPTION_NAME, array() );
		$intro    = isset( $settings['intro'] ) && is_string( $settings['intro'] )
			? $settings['intro']
			: '';
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] )
			? wp_unslash( $_SERVER['REQUEST_URI'] )
			: '/';
		$return_url  = home_url( $request_uri );
		$intro_state = isset( $_GET['jkq_intro'] ) && is_string( $_GET['jkq_intro'] )
			? sanitize_key( wp_unslash( $_GET['jkq_intro'] ) )
			: '';
		$hidden      = '1' === get_user_meta( pc_get_local_access_user_id(), self::HIDE_INTRO_META, true );
		$intro_url   = add_query_arg( 'jkq_intro', 'show', $return_url );

		if ( '' !== trim( $intro ) && ( 'show' === $intro_state || ( ! $hidden && 'continue' !== $intro_state ) ) ) {
			ob_start();
			?>
			<section class="jkq-card" aria-labelledby="jkq-intro-title">
				<p class="jkq-eyebrow"><?php esc_html_e( 'Den store julequiz', 'julekalender-quiz' ); ?></p>
				<h2 id="jkq-intro-title"><?php esc_html_e( 'Velkommen til julequizzen', 'julekalender-quiz' ); ?></h2>
				<p class="jkq-intro"><?php echo esc_html( $intro ); ?></p>
				<form method="post">
					<?php wp_nonce_field( 'jkq_intro', 'jkq_intro_nonce' ); ?>
					<input type="hidden" name="jkq_intro_return_url" value="<?php echo esc_url( remove_query_arg( 'jkq_intro', $return_url ) ); ?>">
					<label class="jkq-intro-choice">
						<input type="checkbox" name="jkq_hide_intro" value="1" <?php checked( $hidden ); ?>>
						<?php esc_html_e( 'Vis ikke introduktionen automatisk igen', 'julekalender-quiz' ); ?>
					</label>
					<button type="submit" name="jkq_intro_submit" value="1" class="jkq-submit"><?php esc_html_e( 'Fortsæt til quizzen', 'julekalender-quiz' ); ?></button>
				</form>
			</section>
			<?php
			return (string) ob_get_clean();
		}

		$intro_link = '' !== trim( $intro )
			? '<p class="jkq-intro-link"><a href="' . esc_url( $intro_url ) . '">' . esc_html__( 'Vis introduktion', 'julekalender-quiz' ) . '</a></p>'
			: '';
		$day      = $this->get_current_day();
		$questions = $this->get_questions();

		if ( 0 === $day ) {
			return '<div class="jkq-card"><p>' . esc_html__( 'Julekalenderen åbner den 1. december.', 'julekalender-quiz' ) . '</p>' . $intro_link . '</div>';
		}

		if ( ! isset( $questions[ $day ] ) ) {
			return '<div class="jkq-card"><p>' . esc_html__( 'Denne eksempeldag har endnu ikke fået et spørgsmål.', 'julekalender-quiz' ) . '</p>' . $intro_link . '</div>';
		}

		$question     = $questions[ $day ];
		$stored_answer = $this->get_answer_for_day( $day );
		$result       = '' !== $stored_answer
			? ( $question['correct'] === $stored_answer ? 'correct' : 'wrong' )
			: '';

		ob_start();
		?>
		<section class="jkq-card" aria-labelledby="jkq-title">
			<div class="jkq-garland" aria-hidden="true"><span>●</span><span>●</span><span>●</span><span>●</span><span>●</span><span>●</span><span>●</span></div>
			<div class="jkq-card__header">
				<div class="jkq-card__icon" aria-hidden="true">🎄</div>
				<div>
					<p class="jkq-eyebrow"><?php esc_html_e( 'Den store julequiz', 'julekalender-quiz' ); ?></p>
					<p class="jkq-day"><?php echo esc_html( sprintf( __( 'Eksempellåge %d af 24', 'julekalender-quiz' ), $day ) ); ?></p>
				</div>
			</div>
			<div class="jkq-door" aria-hidden="false">
				<button type="button" class="jkq-door__button" aria-controls="jkq-title" aria-expanded="false">
					<span class="jkq-door__number"><?php echo esc_html( (string) $day ); ?></span>
					<span class="jkq-door__label"><?php esc_html_e( 'Åbn dagens låge', 'julekalender-quiz' ); ?></span>
					<span class="jkq-door__handle" aria-hidden="true"></span>
				</button>
			</div>
			<div class="jkq-question-panel">
				<h2 id="jkq-title" tabindex="-1"><?php echo esc_html( $question['question'] ); ?></h2>
				<?php if ( 'correct' === $result ) : ?>
					<p class="jkq-result jkq-result--correct" role="status"><?php esc_html_e( 'Rigtigt! Du svarede korrekt. Glædelig jul!', 'julekalender-quiz' ); ?></p>
				<?php elseif ( 'wrong' === $result ) : ?>
					<p class="jkq-result jkq-result--wrong" role="status"><?php echo esc_html( sprintf( __( 'Forkert. Det rigtige svar er: %s', 'julekalender-quiz' ), $question['answers'][ $question['correct'] ] ) ); ?></p>
				<?php endif; ?>
				<?php if ( '' === $stored_answer ) : ?>
					<form method="post">
						<?php wp_nonce_field( 'jkq_answer', 'jkq_nonce' ); ?>
						<input type="hidden" name="jkq_return_url" value="<?php echo esc_url( $return_url ); ?>">
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Vælg dit svar', 'julekalender-quiz' ); ?></legend>
							<?php foreach ( $question['answers'] as $key => $answer ) : ?>
								<label class="jkq-answer">
									<input type="radio" name="jkq_answer" value="<?php echo esc_attr( $key ); ?>" required>
									<span><?php echo esc_html( $answer ); ?></span>
								</label><br>
							<?php endforeach; ?>
						</fieldset>
						<button type="submit" name="jkq_submit" class="jkq-submit"><?php esc_html_e( 'Svar på spørgsmålet', 'julekalender-quiz' ); ?> <span aria-hidden="true">🎁</span></button>
					</form>
				<?php else : ?>
					<p class="jkq-locked"><?php esc_html_e( 'Du har allerede svaret på dagens spørgsmål. Kom tilbage i morgen!', 'julekalender-quiz' ); ?></p>
				<?php endif; ?>
			</div>
			<?php echo $intro_link; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Link and label are escaped above. ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Renders the public standings.
	 *
	 * @return string
	 */
	public function render_standings_shortcode(): string {
		wp_enqueue_style( 'jkq-quiz' );

		if ( ! function_exists( 'pc_get_local_access_user_id' ) || ! function_exists( 'pc_render_local_access_form' ) || ! function_exists( 'pc_local_user_can_access' ) ) {
			return '<p>' . esc_html__( 'Julekalender Quiz kræver, at Plugin Controller er aktivt.', 'julekalender-quiz' ) . '</p>';
		}

		if ( 0 === pc_get_local_access_user_id() ) {
			return pc_render_local_access_form();
		}

		if ( ! pc_local_user_can_access( self::ACCESS_KEY ) ) {
			return '<p>' . esc_html__( 'Din bruger har ikke adgang til Julekalender Quiz.', 'julekalender-quiz' ) . '</p>';
		}

		$consent = get_option( self::CONSENT_OPTION, array() );
		$consent = is_array( $consent ) ? $consent : array();
		if ( ! isset( $consent[ pc_get_local_access_user_id() ] ) ) {
			return '<p>' . esc_html__( 'Du skal først acceptere deltagelse i julequizzen.', 'julekalender-quiz' ) . '</p>';
		}

		$scores = get_option( self::SCORES_OPTION, array() );
		$scores = is_array( $scores ) ? $scores : array();
		$rows   = array();

		foreach ( $consent as $user_id => $consent_date ) {
			$user = get_userdata( absint( $user_id ) );
			if ( ! $user ) {
				continue;
			}

			$user_scores = isset( $scores[ $user_id ] ) && is_array( $scores[ $user_id ] ) ? $scores[ $user_id ] : array();
			$answered    = count( $user_scores );
			$points      = count(
				array_filter(
					$user_scores,
					static function ( $score ): bool {
						return 'correct' === $score;
					}
				)
			);
			$rows[] = array(
				'name'     => $user->display_name,
				'points'   => $points,
				'answered' => $answered,
			);
		}

		usort(
			$rows,
			static function ( array $left, array $right ): int {
				return $right['points'] <=> $left['points'] ?: $right['answered'] <=> $left['answered'] ?: strcasecmp( $left['name'], $right['name'] );
			}
		);

		$result = isset( $_GET['jkq_result'] ) && is_string( $_GET['jkq_result'] )
			? sanitize_key( wp_unslash( $_GET['jkq_result'] ) )
			: '';

		ob_start();
		?>
		<section class="jkq-card jkq-standings" aria-labelledby="jkq-standings-title">
			<p class="jkq-eyebrow"><?php esc_html_e( 'Den store julequiz', 'julekalender-quiz' ); ?></p>
			<h2 id="jkq-standings-title"><?php esc_html_e( 'Stillingen', 'julekalender-quiz' ); ?></h2>
			<?php if ( 'correct' === $result ) : ?>
				<p class="jkq-result jkq-result--correct" role="status"><?php esc_html_e( 'Dit svar er gemt — du fik 1 point!', 'julekalender-quiz' ); ?></p>
			<?php elseif ( 'wrong' === $result ) : ?>
				<p class="jkq-result jkq-result--wrong" role="status"><?php esc_html_e( 'Dit svar er gemt — denne gang blev det ikke til et point.', 'julekalender-quiz' ); ?></p>
			<?php endif; ?>
			<p class="jkq-intro"><?php esc_html_e( 'Her kan alle deltagere se hinandens samlede resultat.', 'julekalender-quiz' ); ?></p>
			<?php if ( empty( $rows ) ) : ?>
				<p class="jkq-locked"><?php esc_html_e( 'Der er endnu ingen deltagere i stillingen.', 'julekalender-quiz' ); ?></p>
			<?php else : ?>
				<div class="jkq-standings-table-wrap">
					<table class="jkq-standings-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Deltager', 'julekalender-quiz' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Point', 'julekalender-quiz' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Besvaret', 'julekalender-quiz' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $index => $row ) : ?>
								<tr>
									<th scope="row"><?php echo esc_html( sprintf( '%d. %s', $index + 1, $row['name'] ) ); ?></th>
									<td><?php echo esc_html( (string) $row['points'] ); ?></td>
									<td><?php echo esc_html( (string) $row['answered'] ); ?>/24</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
			<p class="jkq-intro-link"><a href="<?php echo esc_url( $this->get_quiz_url() ); ?>"><?php esc_html_e( 'Gå til dagens spørgsmål', 'julekalender-quiz' ); ?></a></p>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Renders the mandatory participation consent form.
	 *
	 * @return string
	 */
	private function render_consent(): string {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		ob_start();
		?>
		<section class="jkq-card jkq-consent" aria-labelledby="jkq-consent-title">
			<p class="jkq-eyebrow"><?php esc_html_e( 'Den store julequiz', 'julekalender-quiz' ); ?></p>
			<h2 id="jkq-consent-title"><?php esc_html_e( 'Før du deltager', 'julekalender-quiz' ); ?></h2>
			<p class="jkq-intro"><?php esc_html_e( 'For at deltage skal du acceptere, at du indgår i stillingen. Alle deltagere kan se alle deltageres samlede point og antal besvarede spørgsmål.', 'julekalender-quiz' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'jkq_consent', 'jkq_consent_nonce' ); ?>
				<input type="hidden" name="jkq_consent_return_url" value="<?php echo esc_url( home_url( $request_uri ) ); ?>">
				<label class="jkq-intro-choice">
					<input type="checkbox" name="jkq_consent_confirm" value="1" required>
					<?php esc_html_e( 'Jeg accepterer at deltage i stillingen og at andre deltagere kan se mit samlede resultat.', 'julekalender-quiz' ); ?>
				</label>
				<button type="submit" name="jkq_consent_submit" value="1" class="jkq-submit"><?php esc_html_e( 'Accepter og deltag', 'julekalender-quiz' ); ?> <span aria-hidden="true">🎄</span></button>
			</form>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Displays the reset action on the existing quiz settings page.
	 *
	 * @return void
	 */
	public function render_reset_notice(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] )
			? sanitize_key( wp_unslash( $_GET['page'] ) )
			: '';
		if ( self::SETTINGS_SLUG !== $page ) {
			return;
		}

		$reset = isset( $_GET['jkq_reset'] ) && '1' === sanitize_key( wp_unslash( $_GET['jkq_reset'] ) );
		$intro_restored = isset( $_GET['jkq_intro_restored'] ) && '1' === sanitize_key( wp_unslash( $_GET['jkq_intro_restored'] ) );
		?>
		<div class="notice notice-info">
			<p><?php esc_html_e( 'Gendan standardteksten, hvis du vil bruge den danske introduktion. Dette erstatter den nuværende intro-tekst.', 'julekalender-quiz' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="jkq_restore_intro">
				<?php wp_nonce_field( 'jkq_restore_intro', 'jkq_restore_intro_nonce' ); ?>
				<?php submit_button( __( 'Gendan standardintro', 'julekalender-quiz' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<div class="notice notice-info">
			<p>
				<strong><?php esc_html_e( 'Testværktøj:', 'julekalender-quiz' ); ?></strong>
				<?php esc_html_e( 'Nulstil gemte svar og point, hvis du vil teste spørgsmålene igen.', 'julekalender-quiz' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="jkq_reset_answers">
				<?php wp_nonce_field( 'jkq_reset_answers', 'jkq_reset_nonce' ); ?>
				<?php submit_button( __( 'Nulstil alle svar', 'julekalender-quiz' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php if ( $reset ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Alle quizsvar er nulstillet.', 'julekalender-quiz' ); ?></p></div>
		<?php endif; ?>
		<?php if ( $intro_restored ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Standardintroen er gendannet.', 'julekalender-quiz' ); ?></p></div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Restores the default introduction without changing other settings.
	 *
	 * @return void
	 */
	public function restore_intro(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Du har ikke tilladelse til at ændre quizindstillingerne.', 'julekalender-quiz' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'jkq_restore_intro', 'jkq_restore_intro_nonce' );
		$settings = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $settings ) ) {
			wp_die( esc_html__( 'Quizindstillingerne kunne ikke læses.', 'julekalender-quiz' ) );
		}
		$settings['intro'] = self::default_intro();
		update_option( self::OPTION_NAME, $settings );
		if ( get_option( self::OPTION_NAME ) !== $settings ) {
			wp_die( esc_html__( 'Standardintroen kunne ikke gemmes.', 'julekalender-quiz' ) );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => self::SETTINGS_SLUG, 'jkq_intro_restored' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Deletes all stored answers.
	 *
	 * @return void
	 */
	public function reset_answers(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Du har ikke tilladelse til at nulstille quizsvar.', 'julekalender-quiz' ) );
		}

		check_admin_referer( 'jkq_reset_answers', 'jkq_reset_nonce' );
		delete_option( self::ANSWERS_OPTION );
		delete_option( self::SCORES_OPTION );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => self::SETTINGS_SLUG,
					'jkq_reset'  => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Checks whether the current local user has accepted the standings terms.
	 *
	 * @return bool
	 */
	private function has_consent(): bool {
		$user_id = function_exists( 'pc_get_local_access_user_id' ) ? pc_get_local_access_user_id() : 0;
		$consent = get_option( self::CONSENT_OPTION, array() );
		return $user_id > 0 && is_array( $consent ) && isset( $consent[ $user_id ] );
	}

	/**
	 * Stores the result for the current user and day.
	 *
	 * @param int    $day    Quiz day.
	 * @param string $result Answer result.
	 * @return void
	 */
	private function save_score( int $day, string $result ): void {
		$user_id = pc_get_local_access_user_id();
		$scores  = get_option( self::SCORES_OPTION, array() );
		$scores  = is_array( $scores ) ? $scores : array();
		$scores[ $user_id ][ $day ] = 'correct' === $result ? 'correct' : 'wrong';
		update_option( self::SCORES_OPTION, $scores, false );
	}

	/**
	 * Gets the automatically managed standings page URL.
	 *
	 * @return string
	 */
	private function get_standings_url(): string {
		$page_id = absint( get_option( self::STANDINGS_OPTION, 0 ) );
		$url     = $page_id ? get_permalink( $page_id ) : '';
		return is_string( $url ) && '' !== $url ? $url : home_url( '/' );
	}

	/**
	 * Gets a page containing the quiz shortcode.
	 *
	 * @return string
	 */
	private function get_quiz_url(): string {
		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				'suppress_filters' => false,
			)
		);
		foreach ( $pages as $page ) {
			if ( has_shortcode( $page->post_content, 'julekalender_quiz' ) ) {
				$url = get_permalink( $page );
				return is_string( $url ) ? $url : home_url( '/' );
			}
		}
		return home_url( '/' );
	}

	/**
	 * Creates the single public standings page when it does not exist.
	 *
	 * @return void
	 */
	private function ensure_standings_page(): void {
		$page_id = absint( get_option( self::STANDINGS_OPTION, 0 ) );
		if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
			return;
		}

		$existing = get_page_by_path( 'julequiz-stilling' );
		if ( $existing instanceof WP_Post ) {
			update_option( self::STANDINGS_OPTION, $existing->ID, false );
			return;
		}

		$created = wp_insert_post(
			array(
				'post_title'   => __( 'Julequiz stilling', 'julekalender-quiz' ),
				'post_name'    => 'julequiz-stilling',
				'post_content' => '[julequiz_stilling]',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			),
			true
		);
		if ( ! is_wp_error( $created ) ) {
			update_option( self::STANDINGS_OPTION, $created, false );
		}
	}

	/**
	 * Checks whether the current local user may submit quiz answers.
	 *
	 * @return bool
	 */
	private function user_has_access(): bool {
		return function_exists( 'pc_local_user_can_access' ) && pc_local_user_can_access( self::ACCESS_KEY );
	}

	/**
	 * Gets the current quiz day, or the configured demo day.
	 *
	 * @return int
	 */
	private function get_current_day(): int {
		$settings = get_option( self::OPTION_NAME, array() );
		$demo_day = isset( $settings['demo_day'] ) ? absint( $settings['demo_day'] ) : 0;
		if ( $demo_day >= 1 && $demo_day <= 24 ) {
			return $demo_day;
		}

		$month = (int) wp_date( 'n' );
		$day   = (int) wp_date( 'j' );
		return 12 === $month && $day <= 24 ? $day : 0;
	}

	/**
	 * Gets the current local user's answer for a day.
	 *
	 * @param int $day Quiz day.
	 * @return string
	 */
	private function get_answer_for_day( int $day ): string {
		$user_id = function_exists( 'pc_get_local_access_user_id' ) ? pc_get_local_access_user_id() : 0;
		if ( 0 === $user_id ) {
			return '';
		}

		$answers = get_option( self::ANSWERS_OPTION, array() );
		$answers = is_array( $answers ) ? $answers : array();
		return isset( $answers[ $user_id ][ $day ] ) && is_string( $answers[ $user_id ][ $day ] )
			? sanitize_key( $answers[ $user_id ][ $day ] )
			: '';
	}

	/**
	 * Saves the current local user's answer for a day.
	 *
	 * @param int    $day    Quiz day.
	 * @param string $answer Answer key.
	 * @return void
	 */
	private function save_answer( int $day, string $answer ): void {
		$user_id = pc_get_local_access_user_id();
		$answers = get_option( self::ANSWERS_OPTION, array() );
		$answers = is_array( $answers ) ? $answers : array();
		$answers[ $user_id ][ $day ] = sanitize_key( $answer );
		update_option( self::ANSWERS_OPTION, $answers, false );
	}

	/**
	 * Returns the example question set.
	 *
	 * @return array<int, array{question: string, answers: array<string, string>, correct: string}>
	 */
	private function get_questions(): array {
		return array(
			1 => array(
				'question' => __( 'Hvor bor julemanden ifølge den klassiske fortælling?', 'julekalender-quiz' ),
				'answers'  => array(
					'a' => __( 'På Nordpolen', 'julekalender-quiz' ),
					'b' => __( 'På Månen', 'julekalender-quiz' ),
					'c' => __( 'I Alperne', 'julekalender-quiz' ),
				),
				'correct'  => 'a',
			),
			2 => array(
				'question' => __( 'Hvilken dato fejres Lucia traditionelt?', 'julekalender-quiz' ),
				'answers'  => array(
					'a' => '1. december',
					'b' => '13. december',
					'c' => '24. december',
				),
				'correct'  => 'b',
			),
			3 => array(
				'question' => __( 'Hvad hedder rensdyret med den lysende røde tud?', 'julekalender-quiz' ),
				'answers'  => array(
					'a' => 'Rudolf',
					'b' => 'Buller',
					'c' => 'Kvik',
				),
				'correct'  => 'a',
			),
		);
	}
}
