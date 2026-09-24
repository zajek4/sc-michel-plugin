<?php
/**
 * Heuristic field scoring — deterministic, no AI, no external APIs.
 * The admin must always confirm the proposal.
 *
 * @package CPTSC
 */

namespace CPTSC\Discovery;

use CPTSC\Money;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scores candidate sources for price-like and auxiliary fields.
 */
class FieldScorer {

	/**
	 * Strong price name signals.
	 *
	 * @var string
	 */
	private $price_pattern = '/(^|[_\-\s.])(cijena|ciijena|price|iznos|amount|total|prodajna[_\-\s]?cijena|regular[_\-\s]?price|maloprodajna|retail|cost|worth|vrijednost)([_\-\s.]|$)/iu';

	/**
	 * Brand name signals.
	 *
	 * @var string
	 */
	private $brand_pattern = '/(^|[_\-\s.])(brand|marka|proizvodac|proizvođac|manufacturer|vendor|dobavljac|dobavljač)([_\-\s.]|$)/iu';

	/**
	 * Unit signals.
	 *
	 * @var string
	 */
	private $unit_pattern = '/(^|[_\-\s.])(jedinica|unit|mjera| uom| measure)([_\-\s.]|$)/iu';

	/**
	 * Barcode signals.
	 *
	 * @var string
	 */
	private $barcode_pattern = '/(^|[_\-\s.])(barcode|barkod|ean|gtin|upc)([_\-\s.]|$)/iu';

	/**
	 * Score all discovered sources.
	 *
	 * @param array $buckets {core, meta, registered, acf, taxonomy}.
	 * @param int[] $sample_ids Sample IDs for value validation.
	 * @return array {price: [], brand: [], unit: [], barcode: [], code: []}
	 */
	public function score( array $buckets, array $sample_ids ) {
		$result = array(
			'price'   => array(),
			'regular' => array(),
			'sale'    => array(),
			'brand'   => array(),
			'unit'    => array(),
			'barcode' => array(),
			'code'    => array(),
		);

		foreach ( $buckets as $source => $entries ) {
			if ( 'taxonomy' === $source ) {
				foreach ( (array) $entries as $tax ) {
					$label = $tax['label'] . ' ' . $tax['taxonomy'];
					$score = 0;
					$role  = '';
					if ( preg_match( $this->brand_pattern, $label ) ) {
						$score = 90;
						$role  = 'brand';
					} elseif ( preg_match( $this->unit_pattern, $label ) ) {
						$score = 70;
						$role  = 'unit';
					} elseif ( preg_match( $this->barcode_pattern, $label ) ) {
						$score = 70;
						$role  = 'barcode';
					} elseif ( $tax['hierarchical'] || false !== stripos( $tax['taxonomy'], 'categor' ) || false !== stripos( $tax['taxonomy'], 'kategor' ) ) {
						$score = 40;
						$role  = 'taxonomy_generic';
					}
					if ( $role ) {
						$result['brand'][] = array(
							'source'    => 'taxonomy',
							'key'       => $tax['taxonomy'],
							'label'     => $tax['label'],
							'role'      => $role,
							'score'     => $score,
							'confidence'=> $score >= 80 ? 'high' : ( $score >= 50 ? 'medium' : 'low' ),
							'samples'   => wp_list_pluck( array_slice( $tax['sample_usage'], 0, 3 ), 'name' ),
						);
					}
				}
				continue;
			}

			foreach ( (array) $entries as $entry ) {
				$key   = isset( $entry['key'] ) ? $entry['key'] : ( isset( $entry['name'] ) ? $entry['name'] : '' );
				if ( '' === $key || 'post_content' === $key || 'post_excerpt' === $key ) {
					continue;
				}
				$label = isset( $entry['label'] ) && $entry['label'] ? $entry['label'] : ( isset( $entry['name'] ) ? $entry['name'] : $key );
				$hay   = $key . ' ' . $label;
				$samples = isset( $entry['samples'] ) ? $entry['samples'] : array();

				// Numeric consistency from samples (meta buckets carry samples).
				$numeric_ratio = $this->numeric_ratio( $samples );
				$fill          = isset( $entry['count'] ) ? (int) $entry['count'] : 100;

				if ( preg_match( $this->price_pattern, $hay ) || ( $source !== 'core' && $numeric_ratio >= 0.9 && $this->looks_money( $samples ) ) ) {
					$role = 'price';
					if ( preg_match( '/regular|redovna|stara|old|osnovna/', $hay, $m ) ) {
						$role = 'regular';
					} elseif ( preg_match( '/sale|sniz|akci|popust|discount/', $hay, $m ) ) {
						$role = 'sale';
					}
					$score = $this->combine( $this->name_match_score( $hay, $this->price_pattern ), $numeric_ratio, $fill, $source );
					$result[ 'regular' === $role ? 'regular' : ( 'sale' === $role ? 'sale' : 'price' ) ][] = $this->entry(
						$source,
						$key,
						$label,
						$score,
						$samples,
						$numeric_ratio
					);
				} elseif ( preg_match( $this->brand_pattern, $hay ) ) {
					$result['brand'][] = $this->entry( $source, $key, $label, 85, $samples, $numeric_ratio );
				} elseif ( preg_match( $this->unit_pattern, $hay ) ) {
					$result['unit'][] = $this->entry( $source, $key, $label, 80, $samples, $numeric_ratio );
				} elseif ( preg_match( $this->barcode_pattern, $hay ) ) {
					$result['barcode'][] = $this->entry( $source, $key, $label, 88, $samples, $numeric_ratio );
				} elseif ( preg_match( '/(^|[_\-\s.])(sifra|šifra|sku|code|kataloski|artikl|item[_\-\s]?number)([_\-\s.]|$)/iu', $hay ) ) {
					$result['code'][] = $this->entry( $source, $key, $label, 82, $samples, $numeric_ratio );
				}
			}
		}

		foreach ( $result as $bucket => $entries ) {
			usort(
				$entries,
				function ( $a, $b ) {
					return $b['score'] - $a['score'];
				}
			);
			$result[ $bucket ] = array_slice( $entries, 0, 8 );
		}
		return $result;
	}

	/**
	 * Build a scored entry.
	 *
	 * @param string $source       Source type.
	 * @param string $key          Key.
	 * @param string $label        Label.
	 * @param int    $score        Score 0–100.
	 * @param array  $samples      Samples.
	 * @param float  $numeric_ratio Numeric ratio.
	 * @return array
	 */
	private function entry( $source, $key, $label, $score, array $samples, $numeric_ratio ) {
		$score     = (int) max( 0, min( 100, $score ) );
		$confidence = $score >= 80 ? 'high' : ( $score >= 55 ? 'medium' : 'low' );
		return array(
			'source'        => $source === 'registered' ? 'meta' : $source,
			'key'           => $key,
			'label'         => $label,
			'score'         => $score,
			'confidence'    => $confidence,
			'numeric_ratio' => round( $numeric_ratio, 2 ),
			'samples'       => array_slice( array_values( $samples ), 0, 5 ),
			'display'       => self::display_label( $source === 'registered' ? 'meta' : $source, $label ),
		);
	}

	/**
	 * Human display: "ACF → Cijena".
	 *
	 * @param string $source Source.
	 * @param string $label  Label.
	 * @return string
	 */
	public static function display_label( $source, $label ) {
		$prefixes = array(
			'core'      => 'Core',
			'meta'      => 'Meta',
			'acf'       => 'ACF',
			'taxonomy'  => 'Taxonomija',
			'constant'  => 'Konstanta',
			'manual'    => 'Ručno',
			'adapter'   => 'Adapter',
		);
		$prefix = isset( $prefixes[ $source ] ) ? $prefixes[ $source ] : $source;
		return $prefix . ' → ' . $label;
	}

	/**
	 * Fraction of samples parseable as money.
	 *
	 * @param array $samples Samples.
	 * @return float
	 */
	private function numeric_ratio( array $samples ) {
		if ( empty( $samples ) ) {
			return 0.0;
		}
		$ok = 0;
		foreach ( $samples as $s ) {
			if ( null !== Money::to_string( $s ) ) {
				$ok++;
			}
		}
		return $ok / count( $samples );
	}

	/**
	 * Samples look like monetary amounts (not years/IDs).
	 *
	 * @param array $samples Samples.
	 * @return bool
	 */
	private function looks_money( array $samples ) {
		$moneyish = 0;
		foreach ( $samples as $s ) {
			$clean = Money::to_string( $s );
			if ( null === $clean ) {
				continue;
			}
			// Has a decimal part or plausible price magnitude.
			if ( false !== strpos( $clean, '.' ) || (float) $clean >= 0.5 ) {
				$moneyish++;
			}
		}
		return $moneyish >= max( 1, (int) ceil( count( $samples ) * 0.7 ) );
	}

	/**
	 * Score boost from name match.
	 *
	 * @param string $hay     Haystack.
	 * @param string $pattern Pattern.
	 * @return int
	 */
	private function name_match_score( $hay, $pattern ) {
		return preg_match( $pattern, $hay ) ? 85 : 50;
	}

	/**
	 * Combine signals.
	 *
	 * @param int   $name_score    Name score.
	 * @param float $numeric_ratio Numeric ratio.
	 * @param int   $fill          Fill count.
	 * @param string $source       Source.
	 * @return int
	 */
	private function combine( $name_score, $numeric_ratio, $fill, $source ) {
		$score = $name_score;
		if ( $numeric_ratio >= 0.9 ) {
			$score += 10;
		} elseif ( $numeric_ratio >= 0.5 ) {
			$score += 4;
		}
		if ( $fill >= 10 ) {
			$score += 3;
		}
		if ( 'acf' === $source ) {
			$score += 2;
		}
		return min( 100, $score );
	}
}
