<?php
/**
 * Frontend inspector: finds candidate price elements during setup and performs
 * verified insertion at runtime. Uses a small CSS→XPath subset — no JS, no full-page scanning.
 *
 * @package CPTSC
 */

namespace CPTSC\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DOM inspector.
 */
final class Inspector {

	/**
	 * Money regex for candidate discovery (HR/EU formats).
	 */
	const MONEY = '/(?:^|\s)(?:€|eur\s*)?\d{1,3}(?:[.\s]\d{3})*(?:,\d{2})(?:\s*€)?|(?:^|\s)\d+,\d{2}(?:\s*€)?/u';

	/**
	 * Restrict selectors to a safe subset.
	 *
	 * @param string $selector Selector.
	 * @return bool
	 */
	public static function is_safe_selector( $selector ) {
		if ( ! is_string( $selector ) || strlen( $selector ) > 200 ) {
			return false;
		}
		// Allow tag, #id, .class, combinations, child/descendant combinators, basic attributes.
		return (bool) preg_match( '/^[a-zA-Z0-9\-_>#\.\s\[\]=\"\':,\(\)\+~]+$/', $selector );
	}

	/**
	 * Extract candidate price elements from a rendered HTML snippet.
	 *
	 * @param string $html HTML.
	 * @return array[] {selector, tag, text, class}
	 */
	public static function candidates( $html ) {
		$dom = self::load_dom( $html );
		if ( ! $dom ) {
			return array();
		}
		$out    = array();
		$seen   = array();
		$xpath  = new \DOMXPath( $dom );
		foreach ( $xpath->query( '//*' ) as $el ) {
			if ( ! $el instanceof \DOMElement ) {
				continue;
			}
			$text = trim( preg_replace( '/\s+/', ' ', (string) $el->textContent ) );
			if ( '' === $text || strlen( $text ) > 80 ) {
				continue;
			}
			if ( ! preg_match( self::MONEY, $text ) ) {
				continue;
			}
			// Skip containers holding many children (lists/tables).
			if ( $el->getElementsByTagName( '*' )->length > 6 ) {
				continue;
			}
			$selector = self::css_for( $el );
			if ( ! $selector || isset( $seen[ $selector ] ) ) {
				continue;
			}
			$seen[ $selector ] = true;
			$class = $el->getAttribute( 'class' );
			$score = 0;
			if ( preg_match( '/price|cijena|iznos|amount|total/i', $class . ' ' . $el->getAttribute( 'id' ) . ' ' . $el->getAttribute( 'itemprop' ) ) ) {
				$score += 50;
			}
			if ( preg_match( '/price|cijena/i', $el->getAttribute( 'itemprop' ) ) ) {
				$score += 20;
			}
			$out[] = array(
				'selector' => $selector,
				'tag'      => $el->tagName,
				'text'     => $text,
				'class'    => $class,
				'score'    => $score,
			);
			if ( count( $out ) >= 12 ) {
				break;
			}
		}
		usort(
			$out,
			function ( $a, $b ) {
				return $b['score'] - $a['score'];
			}
		);
		return $out;
	}

	/**
	 * Whether a selector resolves inside rendered post content.
	 *
	 * @param string $selector Selector.
	 * @param int    $post_id  Post.
	 * @return bool
	 */
	/**
	 * Does the selector appear in a full rendered HTML page?
	 *
	 * @param string $selector Selector.
	 * @param string $html     Full page HTML.
	 * @return bool
	 */
	public static function selector_in_html( $selector, $html ) {
		if ( ! self::is_safe_selector( $selector ) || '' === (string) $html ) {
			return false;
		}
		$dom = self::load_dom( $html );
		if ( ! $dom ) {
			return false;
		}
		try {
			$xpath = new \DOMXPath( $dom );
			$nodes = $xpath->query( self::css_to_xpath( $selector ) );
			return ! empty( $nodes );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	public static function selector_in_content( $selector, $post_id ) {
		$content = self::rendered_content( $post_id );
		if ( '' === $content ) {
			return false;
		}
		$dom = self::load_dom( $content );
		if ( ! $dom ) {
			return false;
		}
		$xpath = new \DOMXPath( $dom );
		return self::query( $xpath, $selector ) !== null;
	}

	/**
	 * Insert HTML after the first match of selector inside content.
	 * Returns original content when nothing matches.
	 *
	 * @param string $content  Content.
	 * @param string $selector Selector.
	 * @param string $snippet  HTML to insert (already escaped/rendered).
	 * @return string
	 */
	public static function insert_after_selector( $content, $selector, $snippet ) {
		if ( '' === trim( (string) $snippet ) ) {
			return $content;
		}
		$dom = self::load_dom( $content );
		if ( ! $dom ) {
			return $content;
		}
		$xpath = new \DOMXPath( $dom );
		$el    = self::query( $xpath, $selector );
		if ( ! $el ) {
			return $content;
		}
		$holder = $dom->createElement( 'span' );
		// Import rendered snippet via fragment.
		$tmp = new \DOMDocument();
		$ok  = @$tmp->loadHTML( '<html><body>' . $snippet . '</body></html>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD ); // phpcs:ignore
		if ( $ok ) {
			foreach ( iterator_to_array( $tmp->getElementsByTagName( 'body' )->item(0)->childNodes ) as $child ) {
				$holder->appendChild( $dom->importNode( $child, true ) );
			}
		} else {
			return $content;
		}

		$insert = $holder;
		if ( 'before' === Settings::get( 'frontend.insert', 'after' ) ) {
			$el->parentNode->insertBefore( $insert, $el );
		} else {
			if ( $el->nextSibling ) {
				$el->parentNode->insertBefore( $insert, $el->nextSibling );
			} else {
				$el->parentNode->appendChild( $insert );
			}
		}
		return self::body_inner( $dom );
	}

	/**
	 * Build a CSS selector for an element.
	 *
	 * @param \DOMElement $el Element.
	 * @return string|null
	 */
	public static function css_for( \DOMElement $el ) {
		$id = $el->getAttribute( 'id' );
		if ( '' !== $id && preg_match( '/^[a-zA-Z][\w\-]*$/', $id ) ) {
			return '#' . $id;
		}
		$classes = preg_split( '/\s+/', trim( $el->getAttribute( 'class' ) ) );
		$classes = array_filter(
			(array) $classes,
			function ( $c ) {
				return (bool) preg_match( '/^[a-zA-Z][\w\-]*$/', $c );
			}
		);
		if ( ! empty( $classes ) ) {
			$sel = $el->tagName;
			foreach ( array_slice( $classes, 0, 2 ) as $c ) {
				$sel .= '.' . $c;
			}
			return $sel;
		}
		// Weak fallback: tag with itemprop.
		$itemprop = $el->getAttribute( 'itemprop' );
		if ( '' !== $itemprop && preg_match( '/^[a-zA-Z][\w\-]*$/', $itemprop ) ) {
			return $el->tagName . '[itemprop="' . $itemprop . '"]';
		}
		return null;
	}

	/**
	 * Convert the supported CSS subset to an XPath expression.
	 *
	 * @param string $selector Selector.
	 * @return string|null
	 */
	public static function css_to_xpath( $selector ) {
		$selector = trim( (string) $selector );
		if ( '' === $selector || ! self::is_safe_selector( $selector ) ) {
			return null;
		}
		// Split on combinators while keeping them.
		$parts = preg_split( '/\s*(>)\s*|\s+/', $selector, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		if ( ! $parts ) {
			return null;
		}
		$xpath = '';
		foreach ( $parts as $i => $part ) {
			if ( '>' === $part ) {
				$xpath .= '/';
				continue;
			}
			if ( $i > 0 && '/' !== substr( $xpath, -1 ) ) {
				$xpath .= '//';
			}
			$xpath .= self::simple_to_xpath( $part );
		}
		return $xpath ? '/' . ltrim( $xpath, '/' ) : null;
	}

	/**
	 * Single compound selector (tag#id.class.attr).
	 *
	 * @param string $part Part.
	 * @return string
	 */
	private static function simple_to_xpath( $part ) {
		$expr   = '';
		$rest   = $part;
		// Leading tag.
		if ( preg_match( '/^([a-zA-Z][\w\-]*)/', $rest, $m ) ) {
			$expr = strtolower( $m[1] );
			$rest = substr( $rest, strlen( $m[1] ) );
		} else {
			$expr = '*';
		}
		// #id
		if ( preg_match( '/^#([\w\-]+)/', $rest, $m ) ) {
			$expr .= "[@id='" . $m[1] . "']";
			$rest  = substr( $rest, strlen( $m[0] ) );
		}
		// .class (one or more)
		while ( preg_match( '/^\.([\w\-]+)/', $rest, $m ) ) {
			$expr .= "[contains(concat(' ', normalize-space(@class), ' '), ' " . $m[1] . " ')]";
			$rest  = substr( $rest, strlen( $m[0] ) );
		}
		// [attr="value"]
		while ( preg_match( '/^\[([\w\-]+)=["\']([^"\']*)["\']\]/', $rest, $m ) ) {
			$expr .= "[@" . $m[1] . "='" . $m[2] . "']";
			$rest  = substr( $rest, strlen( $m[0] ) );
		}
		return $expr;
	}

	/**
	 * Query first element for selector.
	 *
	 * @param \DOMXPath $xpath    XPath.
	 * @param string    $selector CSS.
	 * @return \DOMElement|null
	 */
	private static function query( \DOMXPath $xpath, $selector ) {
		$expr = self::css_to_xpath( $selector );
		if ( ! $expr ) {
			return null;
		}
		$nodes = @$xpath->query( $expr ); // phpcs:ignore
		if ( $nodes && $nodes->length > 0 ) {
			$first = $nodes->item( 0 );
			return $first instanceof \DOMElement ? $first : null;
		}
		return null;
	}

	/**
	 * Rendered content for a post (the_content pipeline, shortcodes expanded).
	 *
	 * @param int $post_id Post.
	 * @return string
	 */
	public static function rendered_content( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		setup_postdata( $post ); // phpcs:ignore
		$content = apply_filters( 'the_content', $post->post_content );
		wp_reset_postdata(); // phpcs:ignore
		return (string) $content;
	}

	/**
	 * Load HTML into DOMDocument (utf-8 safe wrapper).
	 *
	 * @param string $html HTML.
	 * @return \DOMDocument|null
	 */
	private static function load_dom( $html ) {
		if ( ! class_exists( 'DOMDocument' ) || ! is_string( $html ) || '' === trim( $html ) ) {
			return null;
		}
		$dom = new \DOMDocument( '1.0', 'UTF-8' );
		$prev = libxml_use_internal_errors( true );
		$ok   = $dom->loadHTML(
			'<?xml encoding="utf-8" ?><html><body>' . $html . '</body></html>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		return $ok ? $dom : null;
	}

	/**
	 * Body inner HTML of a DOM.
	 *
	 * @param \DOMDocument $dom DOM.
	 * @return string
	 */
	private static function body_inner( \DOMDocument $dom ) {
		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body ) {
			return '';
		}
		$out = '';
		foreach ( $body->childNodes as $child ) {
			$out .= $dom->saveHTML( $child );
		}
		return $out;
	}
}
