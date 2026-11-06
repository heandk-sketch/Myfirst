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
	private const ACCESS_KEY     = 'julekalender-quiz';
	private const SETTINGS_SLUG  = 'julekalender-quiz-settings';

	/**
	 * Registers the plugin hooks and Plugin Controller settings page.
	 *
	 * @return void
	 */
	public function init(): void {
		add_shortcode( 'julekalender_quiz', array( $this, 'render_shortcode' ) );
		add_action( 'init', array( $this, 'handle_answer' ) );
		wp_register_style(
			'jkq-quiz',
			plugins_url( '../assets/css/julekalender-quiz.css', __FILE__ ),
			array(),
			'1.0.0'
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
			'julekalender_quiz'
		);
		add_action( 'admin_notices', array( $this, 'render_reset_notice' ) );
		add_action( 'admin_post_jkq_reset_answers', array( $this, 'reset_answers' ) );

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
						'description' => __( 'Vises over dagens spørgsmål.', 'julekalender-quiz' ),
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
		}

		$return_url = isset( $_POST['jkq_return_url'] ) && is_string( $_POST['jkq_return_url'] )
			? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['jkq_return_url'] ) ), home_url( '/' ) )
			: home_url( '/' );
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

		$day      = $this->get_current_day();
		$questions = $this->get_questions();
		$settings = get_option( self::OPTION_NAME, array() );
		$intro    = isset( $settings['intro'] ) && is_string( $settings['intro'] )
			? $settings['intro']
			: __( 'Åbn dagens låge og test din juleviden.', 'julekalender-quiz' );

		if ( 0 === $day ) {
			return '<div class="jkq-card"><p>' . esc_html__( 'Julekalenderen åbner den 1. december.', 'julekalender-quiz' ) . '</p></div>';
		}

		if ( ! isset( $questions[ $day ] ) ) {
			return '<div class="jkq-card"><p>' . esc_html__( 'Denne eksempeldag har endnu ikke fået et spørgsmål.', 'julekalender-quiz' ) . '</p></div>';
		}

		$question     = $questions[ $day ];
		$request_uri  = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] )
			? wp_unslash( $_SERVER['REQUEST_URI'] )
			: '/';
		$return_url   = home_url( $request_uri );
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
				<p class="jkq-intro"><?php echo esc_html( $intro ); ?></p>
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
		?>
		<div class="notice notice-info">
			<p>
				<strong><?php esc_html_e( 'Testværktøj:', 'julekalender-quiz' ); ?></strong>
				<?php esc_html_e( 'Nulstil gemte svar, hvis du vil svare på dagens spørgsmål igen.', 'julekalender-quiz' ); ?>
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
		<?php
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
