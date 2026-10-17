<?php
/**
 * Spending analysis and saving suggestions.
 *
 * @package SavingsAdvisor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rule based analysis. Nothing leaves the server and no AI service is used.
 *
 * Saving estimates are rough rules of thumb (see the rate in each category), not guarantees.
 */
class SVA_Analyzer {

	private const MONTH_DAYS = 30.4375;

	/**
	 * Formats an amount as whole kroner.
	 *
	 * @param float $value Amount.
	 * @return string
	 */
	public static function kr( float $value ): string {
		return number_format_i18n( $value, 0 ) . ' kr';
	}

	/**
	 * Regexes for internal transfers that must not count as income or spending.
	 *
	 * @return array<int, string>
	 */
	private static function transfer_patterns(): array {
		$patterns = apply_filters( 'sva_transfer_patterns', array( '/^budget$/iu', '/^(overførsel|overfoersel) (til|fra) (egen )?konto/iu' ) );
		return array_values( array_filter( (array) $patterns, 'is_string' ) );
	}

	/**
	 * Regex for salary-like income.
	 *
	 * @return string
	 */
	private static function income_pattern(): string {
		return '/løn|pension|dagpenge|feriepenge|su-?støtte|udbetaling danmark|kontanthjælp|barselsdagpenge/iu';
	}

	/**
	 * Category definitions. First match wins, so order matters.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function categories(): array {
		return array(
			'ev_charging'   => array(
				'label'   => __( 'Elbil-opladning', 'savings-advisor' ),
				'pattern' => '/uno-x ev|clever|andel energi|fastspeed|ionity|spirii|\bhpc\b|e\.on drive|plugsurfing/iu',
				'rate'    => 0.10,
				'tip'     => __( 'Lynladning (HPC) er markant dyrere pr. kWh end opladning hjemme eller med et ladeabonnement. Flyt så meget opladning som muligt hjem og sammenlign prisen pr. kWh.', 'savings-advisor' ),
			),
			'transport'     => array(
				'label'   => __( 'Transport', 'savings-advisor' ),
				'pattern' => '/rejsekort|\bdsb\b|movia|\bq8\b|circle k|shell|ok a\.m\.b\.a|ok plus|parkering|q-?park|easypark|taxa|dott|voi\b/iu',
				'rate'    => 0.10,
				'tip'     => __( 'Mange små rejsekort-køb kan ofte erstattes af et periodekort eller pendlerkort, hvis du rejser fast. Tjek også om en samlet månedlig billet er billigere.', 'savings-advisor' ),
			),
			'groceries'     => array(
				'label'   => __( 'Dagligvarer', 'savings-advisor' ),
				'pattern' => '/meny|føtex|foetex|netto|rema ?1000|irma|lidl|fakta|bilka|superbrugsen|brugsen|kvickly|aldi|nemlig\.com|\b3-?f\b/iu',
				'rate'    => 0.10,
				'tip'     => __( 'Discountkæder (fx Netto, Rema 1000, Lidl) er typisk 10-20 % billigere end supermarkeder som Meny. En madplan og indkøbsliste reducerer også impulskøb og madspild.', 'savings-advisor' ),
			),
			'dining'        => array(
				'label'   => __( 'Restaurant, café og take-away', 'savings-advisor' ),
				'pattern' => '/caf[eé]|restaurant|caterly|grill|larsens plads|pizza|sushi|burger|mcdonald|starbucks|bager|just eat|wolt|foodora|risiko|sisko|\bbar\b/iu',
				'rate'    => 0.20,
				'tip'     => __( 'Spisning ude og take-away er ofte det nemmeste sted at skære. Sæt en fast månedlig ramme, og pak madpakke på hverdage.', 'savings-advisor' ),
			),
			'subscriptions' => array(
				'label'   => __( 'Abonnementer og medier', 'savings-advisor' ),
				'pattern' => '/netflix|politiken|yousee|cbb mobil|hiper|one\.com|spotify|hbo|disney|viaplay|tv ?2|telia|telenor|icloud|youtube|storytel|vpn|github/iu',
				'rate'    => 0.25,
				'tip'     => '',
			),
			'insurance'     => array(
				'label'   => __( 'Forsikringer', 'savings-advisor' ),
				'pattern' => '/tryg|forsikring|alka|topdanmark|codan|first marin|dansk sundhed|gjensidige|lb forsikring|falck/iu',
				'rate'    => 0.10,
				'tip'     => '',
			),
			'union'         => array(
				'label'   => __( 'Fagforening og a-kasse', 'savings-advisor' ),
				'pattern' => '/a-kasse|fag og arbejde|\bfoa\b|ida ingeni|\b3f\b|\bhk\b|kristelig fagbev/iu',
				'rate'    => 0.0,
				'tip'     => '',
			),
			'donations'     => array(
				'label'   => __( 'Gaver og donationer', 'savings-advisor' ),
				'pattern' => '/kræftens|røde kors|unicef|plan børnefonden|dyrenes beskyttelse|ngo/iu',
				'rate'    => 0.0,
				'tip'     => '',
			),
			'housing'       => array(
				'label'   => __( 'Bolig og hjem', 'savings-advisor' ),
				'pattern' => '/ejerf|sommerhus|servicehuset|vvs|bauhaus|jysk|ikea|silvan|stark|ørsted|radius|el-?sparer|gladsaxe ko/iu',
				'rate'    => 0.0,
				'tip'     => '',
			),
			'car'           => array(
				'label'   => __( 'Bil', 'savings-advisor' ),
				'pattern' => '/andersen biler|fdm|wash world|synsbasen|dæk|bilvask|motor opkræv/iu',
				'rate'    => 0.0,
				'tip'     => '',
			),
			'travel'        => array(
				'label'   => __( 'Rejser og ferie', 'savings-advisor' ),
				'pattern' => '/msc cruises|hotel|booking\.com|airbnb|ryanair|\bsas\b|norwegian|dsb udland|westwind|cruise|krydstogt|eurowings|momondo|expedia/iu',
				'rate'    => 0.05,
				'tip'     => __( 'Rejser er ofte den største enkeltpost. Book tidligt, sammenlign på flere sider og vær fleksibel på afrejsedato for at spare nogle procent.', 'savings-advisor' ),
			),
			'entertainment' => array(
				'label'   => __( 'Oplevelser og spil', 'savings-advisor' ),
				'pattern' => '/cirkusrevyen|dyrehavsbakken|billetten|ticketmaster|tickster|biograf|tivoli|bet25|fuegodance|koncert|spil/iu',
				'rate'    => 0.15,
				'tip'     => __( 'Billetter og oplevelser kan ofte købes billigere i god tid, via medlemstilbud eller på tilbudsdage. Sæt et årligt budget for spil og underholdning.', 'savings-advisor' ),
			),
			'clothing'      => array(
				'label'   => __( 'Tøj og sko', 'savings-advisor' ),
				'pattern' => '/swingshoes|tøj|hepcats|zalando|h&m|bestseller|jack ?& ?jones|footway/iu',
				'rate'    => 0.15,
				'tip'     => __( 'Vent på udsalg, brug prissammenligning, og overvej brugt eller reparation, før du køber nyt.', 'savings-advisor' ),
			),
			'health'        => array(
				'label'   => __( 'Sundhed', 'savings-advisor' ),
				'pattern' => '/tandl[æe]ge|apotek|lægehus|fysio|matas|synoptik/iu',
				'rate'    => 0.0,
				'tip'     => '',
			),
			'cash_fees'     => array(
				'label'   => __( 'Kontanter og gebyrer', 'savings-advisor' ),
				'pattern' => '/pengeautomat|gebyr|kontoføring|overtræk|rykker/iu',
				'rate'    => 0.0,
				'tip'     => '',
			),
			'mobilepay'     => array(
				'label'   => __( 'MobilePay (øvrige)', 'savings-advisor' ),
				'pattern' => '/mobilepay/iu',
				'rate'    => 0.0,
				'tip'     => '',
			),
			'other'         => array(
				'label'   => __( 'Øvrigt', 'savings-advisor' ),
				'pattern' => '',
				'rate'    => 0.0,
				'tip'     => '',
			),
		);
	}

	/**
	 * Finds the category slug for a description.
	 *
	 * @param string $description Transaction text.
	 * @return string
	 */
	public static function categorize( string $description ): string {
		foreach ( self::categories() as $slug => $def ) {
			if ( '' !== $def['pattern'] && preg_match( $def['pattern'], $description ) ) {
				return $slug;
			}
		}
		return 'other';
	}

	/**
	 * Reduces a bank text to a merchant name so repeated payments group together.
	 *
	 * @param string $description Transaction text.
	 * @return string
	 */
	public static function merchant( string $description ): string {
		$text = preg_replace( '/\b(?:Nota nr\.?|Notanr|Nota)\s*\S+/iu', '', $description );
		$text = preg_replace( '/Aftalenr\.?\s*\d+/iu', '', (string) $text );
		$text = preg_replace( '/Trans\.?\s*nr\.?\s*\S+/iu', '', (string) $text );
		$text = preg_replace( '/^"/', '', (string) $text );

		do {
			$before = $text;
			$text   = preg_replace( '/^(?:kontaktløs Dankort|Dankort-køb|Visa\/Dankort|MobilePay køb|MobilePay:?|Betalingsservice)\s+/iu', '', (string) $text );
		} while ( $before !== $text );

		$text = explode( ',', (string) $text )[0];
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

		return '' === $text ? $description : mb_substr( $text, 0, 40 );
	}

	/**
	 * Runs the full analysis on transactions.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows with date, description and amount.
	 * @return array<string, mixed> Empty when there is nothing to analyse.
	 */
	public static function analyze( array $rows ): array {
		$patterns = self::transfer_patterns();
		$income_p = self::income_pattern();

		$clean = array();
		foreach ( $rows as $row ) {
			$skip = false;
			foreach ( $patterns as $pattern ) {
				if ( preg_match( $pattern, trim( (string) $row['description'] ) ) ) {
					$skip = true;
					break;
				}
			}
			if ( ! $skip ) {
				$clean[] = $row;
			}
		}
		if ( empty( $clean ) ) {
			return array();
		}
		usort(
			$clean,
			static fn( array $a, array $b ): int => strcmp( (string) $a['date'], (string) $b['date'] )
		);

		$latest = max( array_column( $clean, 'date' ) );
		$cutoff = ( new DateTimeImmutable( $latest ) )->modify( '-1 year' )->modify( '+1 day' )->format( 'Y-m-d' );

		$income      = 0.0;
		$net         = array();
		$months      = array();
		$merchants   = array();
		$tx_list     = array();
		$first_date  = $latest;
		$recent_from = ( new DateTimeImmutable( $latest ) )->modify( '-90 days' )->format( 'Y-m-d' );
		$recent_cat  = array();
		$prior_cat   = array();
		$fees        = 0.0;

		foreach ( $clean as $row ) {
			if ( $row['date'] < $cutoff ) {
				continue;
			}

			$amount = (float) $row['amount'];
			$desc   = (string) $row['description'];
			$ym     = substr( (string) $row['date'], 0, 7 );
			$first_date = min( $first_date, (string) $row['date'] );

			if ( ! isset( $months[ $ym ] ) ) {
				$months[ $ym ] = array(
					'income'   => 0.0,
					'spending' => 0.0,
				);
			}

			if ( $amount > 0 && preg_match( $income_p, $desc ) ) {
				$income                  += $amount;
				$months[ $ym ]['income'] += $amount;
				continue;
			}

			$cat                       = self::categorize( $desc );
			$net[ $cat ]               = ( $net[ $cat ] ?? 0.0 ) - $amount;
			$months[ $ym ]['spending'] -= $amount;

			if ( $row['date'] >= $recent_from ) {
				$recent_cat[ $cat ] = ( $recent_cat[ $cat ] ?? 0.0 ) - $amount;
			} else {
				$prior_cat[ $cat ] = ( $prior_cat[ $cat ] ?? 0.0 ) - $amount;
			}

			if ( 'cash_fees' === $cat && $amount < 0 ) {
				$fees -= $amount;
			}

			if ( $amount < 0 ) {
				$name = self::merchant( $desc );
				if ( ! isset( $merchants[ $name ] ) ) {
					$merchants[ $name ] = array(
						'name'    => $name,
						'cat'     => $cat,
						'total'   => 0.0,
						'count'   => 0,
						'months'  => array(),
						'amounts' => array(),
					);
				}
				$merchants[ $name ]['total']        -= $amount;
				$merchants[ $name ]['count']++;
				$merchants[ $name ]['months'][ $ym ] = true;
				$merchants[ $name ]['amounts'][]     = -$amount;

				$tx_list[] = array(
					'date'        => (string) $row['date'],
					'description' => $desc,
					'amount'      => -$amount,
				);
			}
		}

		ksort( $months );
		$days      = max( 1, (int) ( ( strtotime( $latest ) - strtotime( $first_date ) ) / DAY_IN_SECONDS ) + 1 );
		$month_eq  = max( 1.0, $days / self::MONTH_DAYS );
		$spending  = array_sum( array_column( $months, 'spending' ) );
		$categories = self::build_categories( $net, $spending );
		$recurring = self::find_recurring( $merchants, $month_eq );

		usort(
			$tx_list,
			static fn( array $a, array $b ): int => $b['amount'] <=> $a['amount']
		);

		$result = array(
			'from'        => $first_date,
			'to'          => $latest,
			'month_eq'    => $month_eq,
			'income'      => $income,
			'spending'    => $spending,
			'months'      => $months,
			'categories'  => $categories,
			'recurring'   => $recurring,
			'biggest'     => array_slice( $tx_list, 0, 10 ),
			'suggestions' => array(),
		);

		$result['suggestions'] = self::build_suggestions( $result, $merchants, $recent_cat, $prior_cat, $days, $fees );
		return $result;
	}

	/**
	 * Builds the category list with shares.
	 *
	 * @param array<string, float> $net      Net spending per category.
	 * @param float                $spending Total spending.
	 * @return array<int, array<string, mixed>>
	 */
	private static function build_categories( array $net, float $spending ): array {
		$defs = self::categories();
		$out  = array();
		foreach ( $net as $slug => $value ) {
			if ( $value <= 0 ) {
				continue;
			}
			$out[] = array(
				'slug'  => $slug,
				'label' => $defs[ $slug ]['label'],
				'total' => $value,
				'share' => $spending > 0 ? $value / $spending : 0.0,
			);
		}
		usort(
			$out,
			static fn( array $a, array $b ): int => $b['total'] <=> $a['total']
		);
		return $out;
	}

	/**
	 * Finds payments that repeat roughly monthly with a stable amount.
	 *
	 * @param array<string, array<string, mixed>> $merchants Merchant aggregates.
	 * @param float                               $month_eq  Months covered.
	 * @return array<int, array<string, mixed>>
	 */
	private static function find_recurring( array $merchants, float $month_eq ): array {
		$out = array();
		foreach ( $merchants as $m ) {
			$month_count = count( $m['months'] );
			if ( $month_count < 3 || $m['count'] > $month_count * 1.5 ) {
				continue;
			}
			// Stable means most payments are close to the median; occasional extra bills are tolerated.
			$median = self::median( $m['amounts'] );
			if ( $median <= 0 ) {
				continue;
			}
			$close = 0;
			foreach ( $m['amounts'] as $amount ) {
				if ( abs( $amount - $median ) / $median <= 0.5 ) {
					++$close;
				}
			}
			if ( $close / $m['count'] < 0.7 ) {
				continue;
			}
			$out[] = array(
				'name'    => $m['name'],
				'cat'     => $m['cat'],
				'monthly' => $m['total'] / $month_eq,
				'yearly'  => $m['total'] / $month_eq * 12,
				'first'   => self::median( array_slice( $m['amounts'], 0, 3 ) ),
				'last'    => self::median( array_slice( $m['amounts'], -3 ) ),
			);
		}
		usort(
			$out,
			static fn( array $a, array $b ): int => $b['yearly'] <=> $a['yearly']
		);
		return $out;
	}

	/**
	 * Gets the median of a list of numbers.
	 *
	 * @param array<int, float> $values Values.
	 * @return float
	 */
	private static function median( array $values ): float {
		if ( empty( $values ) ) {
			return 0.0;
		}
		sort( $values );
		$mid = intdiv( count( $values ), 2 );
		return 0 === count( $values ) % 2 ? ( $values[ $mid - 1 ] + $values[ $mid ] ) / 2 : (float) $values[ $mid ];
	}

	/**
	 * Creates saving suggestions, biggest estimated saving first.
	 *
	 * @param array<string, mixed>                $result     Analysis so far.
	 * @param array<string, array<string, mixed>> $merchants  Merchant aggregates.
	 * @param array<string, float>                $recent_cat Spending per category in the last 90 days.
	 * @param array<string, float>                $prior_cat  Spending per category before that.
	 * @param int                                 $days       Days covered.
	 * @param float                               $fees       Total fees.
	 * @return array<int, array<string, mixed>>
	 */
	private static function build_suggestions( array $result, array $merchants, array $recent_cat, array $prior_cat, int $days, float $fees ): array {
		$defs     = self::categories();
		$yearly   = 12 / $result['month_eq'];
		$out      = array();
		$recurring_by_cat = array();

		foreach ( $result['recurring'] as $r ) {
			$recurring_by_cat[ $r['cat'] ][] = $r;
		}

		// Recurring subscriptions and insurance: one suggestion per group, listing each item.
		foreach ( array( 'subscriptions', 'insurance' ) as $slug ) {
			if ( empty( $recurring_by_cat[ $slug ] ) ) {
				continue;
			}
			$total = array_sum( array_column( $recurring_by_cat[ $slug ], 'yearly' ) );
			$items = array_map(
				static fn( array $r ): string => $r['name'] . ' (' . self::kr( $r['yearly'] ) . '/år)',
				$recurring_by_cat[ $slug ]
			);
			$title = 'subscriptions' === $slug
				? __( 'Gennemgå dine abonnementer', 'savings-advisor' )
				: __( 'Indhent tilbud på forsikringerne', 'savings-advisor' );
			$text  = 'subscriptions' === $slug
				? __( 'Opsig det du ikke bruger ugentligt, og tjek om der er billigere eller gratis alternativer.', 'savings-advisor' )
				: __( 'Forsikringer bør sammenlignes hvert 1-2 år. Spørg også om rabat for samlet kundeforhold, og tjek at du ikke er dobbeltforsikret.', 'savings-advisor' );

			$out[] = array(
				'title'  => $title,
				'detail' => $text . ' ' . implode( ', ', $items ) . '.',
				'saving' => $total * $defs[ $slug ]['rate'],
				'basis'  => sprintf(
					/* translators: 1: yearly cost, 2: saving rate in percent. */
					__( 'Skøn: %2$s %% af %1$s/år', 'savings-advisor' ),
					self::kr( $total ),
					number_format_i18n( $defs[ $slug ]['rate'] * 100, 0 )
				),
			);
		}

		// Spending categories with a general tip.
		foreach ( $result['categories'] as $cat ) {
			$def = $defs[ $cat['slug'] ];
			if ( $def['rate'] <= 0 || '' === $def['tip'] || $cat['total'] < 1500 ) {
				continue;
			}

			$top = '';
			$top_total = 0.0;
			foreach ( $merchants as $m ) {
				if ( $m['cat'] === $cat['slug'] && $m['total'] > $top_total ) {
					$top       = $m['name'];
					$top_total = $m['total'];
				}
			}

			$detail = sprintf(
				/* translators: 1: category, 2: total amount, 3: top merchant, 4: merchant total. */
				__( '%1$s: %2$s i perioden. Størst: %3$s (%4$s). ', 'savings-advisor' ),
				$def['label'],
				self::kr( $cat['total'] ),
				'' !== $top ? $top : '-',
				self::kr( $top_total )
			) . $def['tip'];

			$out[] = array(
				'title'  => sprintf(
					/* translators: %s: category name. */
					__( 'Skær ned på: %s', 'savings-advisor' ),
					$def['label']
				),
				'detail' => $detail,
				'saving' => $cat['total'] * $def['rate'],
				'basis'  => sprintf(
					/* translators: 1: total amount, 2: saving rate in percent. */
					__( 'Skøn: %2$s %% af %1$s', 'savings-advisor' ),
					self::kr( $cat['total'] ),
					number_format_i18n( $def['rate'] * 100, 0 )
				),
			);
		}

		// Rising categories (last 90 days vs the period before).
		if ( $days >= 180 ) {
			foreach ( array( 'groceries', 'dining', 'ev_charging', 'transport', 'entertainment', 'clothing', 'mobilepay' ) as $slug ) {
				$recent_month = ( $recent_cat[ $slug ] ?? 0.0 ) / 90 * self::MONTH_DAYS;
				$prior_month  = ( $prior_cat[ $slug ] ?? 0.0 ) / max( 1, $days - 90 ) * self::MONTH_DAYS;
				if ( $prior_month > 0 && $recent_month >= $prior_month * 1.25 && $recent_month - $prior_month >= 300 ) {
					$out[] = array(
						'title'  => sprintf(
							/* translators: %s: category name. */
							__( 'Stigende forbrug: %s', 'savings-advisor' ),
							$defs[ $slug ]['label']
						),
						'detail' => sprintf(
							/* translators: 1: recent monthly spend, 2: earlier monthly spend. */
							__( 'De seneste 3 måneder bruger du i snit %1$s om måneden, mod %2$s tidligere. Find ud af hvad der har ændret sig.', 'savings-advisor' ),
							self::kr( $recent_month ),
							self::kr( $prior_month )
						),
						'saving' => null,
						'basis'  => '',
					);
				}
			}
		}

		// Recurring payments that became more expensive.
		foreach ( $result['recurring'] as $r ) {
			if ( ! in_array( $r['cat'], array( 'subscriptions', 'insurance', 'housing', 'union', 'car' ), true ) ) {
				continue;
			}
			if ( $r['last'] > $r['first'] * 1.05 && $r['last'] - $r['first'] >= 20 ) {
				$out[] = array(
					'title'  => sprintf(
						/* translators: %s: merchant name. */
						__( 'Prisstigning: %s', 'savings-advisor' ),
						$r['name']
					),
					'detail' => sprintf(
						/* translators: 1: old amount, 2: new amount. */
						__( 'Betalingen er steget fra %1$s til %2$s. Spørg om rabat eller sammenlign med andre udbydere.', 'savings-advisor' ),
						self::kr( $r['first'] ),
						self::kr( $r['last'] )
					),
					'saving' => null,
					'basis'  => '',
				);
			}
		}

		// Many small purchases at the same place.
		$frequent = array_filter(
			$merchants,
			static fn( array $m ): bool => $m['count'] >= 25 && $m['total'] >= 1500
		);
		usort(
			$frequent,
			static fn( array $a, array $b ): int => $b['total'] <=> $a['total']
		);
		foreach ( array_slice( $frequent, 0, 4 ) as $m ) {
			$out[] = array(
				'title'  => sprintf(
					/* translators: %s: merchant name. */
					__( 'Mange køb hos %s', 'savings-advisor' ),
					$m['name']
				),
				'detail' => sprintf(
					/* translators: 1: purchase count, 2: average, 3: total. */
					__( '%1$d køb à ca. %2$s, i alt %3$s. Små, hyppige køb summerer hurtigt op. Saml dem eller sæt en månedlig ramme.', 'savings-advisor' ),
					$m['count'],
					self::kr( $m['total'] / $m['count'] ),
					self::kr( $m['total'] )
				),
				'saving' => null,
				'basis'  => '',
			);
		}

		if ( $fees > 0 ) {
			$out[] = array(
				'title'  => __( 'Gebyrer og kontanthævninger', 'savings-advisor' ),
				'detail' => sprintf(
					/* translators: %s: fee total. */
					__( 'Du har betalt %s i gebyrer eller hævet kontanter i automat. Brug kort/MobilePay i stedet.', 'savings-advisor' ),
					self::kr( $fees )
				),
				'saving' => $fees * $yearly * 0.8,
				'basis'  => __( 'Skøn: 80 % kan undgås', 'savings-advisor' ),
			);
		}

		if ( $result['income'] > 0 ) {
			$rate = ( $result['income'] - $result['spending'] ) / $result['income'];
			if ( $rate < 0.05 ) {
				$out[] = array(
					'title'  => __( 'Lav opsparingsrate', 'savings-advisor' ),
					'detail' => sprintf(
						/* translators: 1: income, 2: spending. */
						__( 'Du har brugt %2$s af en indkomst på %1$s. Opret en automatisk opsparing på lønningsdagen, så beløbet er sat til side, før du bruger det.', 'savings-advisor' ),
						self::kr( $result['income'] ),
						self::kr( $result['spending'] )
					),
					'saving' => null,
					'basis'  => '',
				);
			}
		}

		usort(
			$out,
			static function ( array $a, array $b ): int {
				return ( $b['saving'] ?? -1.0 ) <=> ( $a['saving'] ?? -1.0 );
			}
		);
		return $out;
	}
}
