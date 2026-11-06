<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Links to the questionnaire results: "Entity profile" (one link) and "Product profile"
 * (a drop-down when there are several). Shown on the single page only, and only when
 * the entity has them.
 *
 * Values come from the profile fields company_sheet (URL) and product_sheets (one "Name | URL" per
 * line, or separated by ";" in a CSV cell). Other plugins (e.g. Solidaros) can supply them with the
 * filters dirnet_company_sheet_url( $url, $post_id ) and dirnet_product_sheets( array( array( name, url ), … ), $post_id ).
 */
class Sheets {

	const META_COMPANY  = '_custom-company-sheet';
	const META_PRODUCTS = '_custom-product-sheets';

	public function boot() {
		add_filter( 'the_content', array( $this, 'single' ), 24 );
		add_action( 'wp_head', array( $this, 'css' ) );
	}

	/** @return array<int,array{0:string,1:string}> */
	public static function parse_products( $raw ) {
		$out = array();
		foreach ( preg_split( '/[\r\n;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$parts = array_map( 'trim', explode( '|', $line, 2 ) );
			$url   = count( $parts ) > 1 ? $parts[1] : $parts[0];
			$name  = count( $parts ) > 1 && '' !== $parts[0] ? $parts[0] : preg_replace( '#^https?://(www\.)?#', '', rtrim( $url, '/' ) );
			if ( preg_match( '#^https?://#i', $url ) ) {
				$out[] = array( $name, $url );
			}
		}
		return $out;
	}

	/**
	 * Category slugs (option dirnet_sheet_categories, comma separated) that carry company/product
	 * sheets. Empty = no restriction. A listing qualifies when it has one of them or a child of one.
	 */
	public static function allowed( $post_id ) {
		$slugs = array_filter( array_map( 'sanitize_title', explode( ',', (string) get_option( 'dirnet_sheet_categories', '' ) ) ) );
		if ( ! $slugs ) {
			return true;
		}
		foreach ( (array) wp_get_post_terms( $post_id, ATBDP_CATEGORY ) as $t ) {
			if ( is_wp_error( $t ) ) {
				continue;
			}
			if ( in_array( $t->slug, $slugs, true ) ) {
				return true;
			}
			foreach ( get_ancestors( $t->term_id, ATBDP_CATEGORY, 'taxonomy' ) as $a ) {
				$at = get_term( $a, ATBDP_CATEGORY );
				if ( $at && ! is_wp_error( $at ) && in_array( $at->slug, $slugs, true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** Only the entity link ('company') or only the product links ('products'); '' otherwise 'all'. */
	public static function html_part( $post_id, $part = 'all' ) {
		return self::html( $post_id, $part );
	}

	public static function html( $post_id, $part = 'all' ) {
		if ( ! self::allowed( $post_id ) ) {
			return '';
		}
		$company = trim( (string) get_post_meta( $post_id, self::META_COMPANY, true ) );
		$company = (string) apply_filters( 'dirnet_company_sheet_url', $company, $post_id );
		$items   = self::parse_products( get_post_meta( $post_id, self::META_PRODUCTS, true ) );
		$items   = (array) apply_filters( 'dirnet_product_sheets', $items, $post_id );
		$source  = (string) apply_filters( 'dirnet_sourceprice_sheet_url', '', $post_id );
		$pgs     = apply_filters( 'dirnet_pgs_badge', null, $post_id, 'entity' );
		if ( 'company' === $part ) {
			$items  = array();
			$source = '';
		} elseif ( 'products' === $part ) {
			$company = '';
		}
		if ( '' === $company && ! $items && '' === $source ) {
			return '';
		}
		$h = '<div class="dirnet-sheets">';
		if ( '' !== $company ) {
			$h .= '<a class="dirnet-sheet" href="' . esc_url( $company ) . '" target="_blank" rel="noopener">' . esc_html__( 'Entity profile', 'directorist-network' ) . '</a>' . self::badge( $pgs );
		}
		if ( '' !== $source ) {
			$h .= '<a class="dirnet-sheet" href="' . esc_url( $source ) . '" target="_blank" rel="noopener">' . esc_html__( 'Source price (declared by the producer)', 'directorist-network' ) . '</a>';
		}
		if ( 1 === count( $items ) ) {
			$h .= '<a class="dirnet-sheet" href="' . esc_url( $items[0][1] ) . '" target="_blank" rel="noopener">' . esc_html( sprintf( /* translators: %s: product name */ __( 'Product profile: %s', 'directorist-network' ), $items[0][0] ) ) . '</a>' . self::badge( ! empty( $items[0][2] ) ? array( true, '' ) : null );
		} elseif ( count( $items ) > 1 ) {
			$h .= '<select class="dirnet-products" aria-label="' . esc_attr__( 'Product profile', 'directorist-network' ) . '" onchange="if(this.value){window.open(this.value,\'_blank\',\'noopener\');this.selectedIndex=0}">';
			$h .= '<option value="">' . esc_html( sprintf( /* translators: %d: number of products */ __( 'Product profile (%d)', 'directorist-network' ), count( $items ) ) ) . '</option>';
			foreach ( $items as $it ) {
				$h .= '<option value="' . esc_url( $it[1] ) . '">' . esc_html( $it[0] . ( ! empty( $it[2] ) ? ' — PGS ✓' : '' ) ) . '</option>';
			}
			$h .= '</select>';
		}
		return $h . '</div>';
	}

	/** The PGS badge: shown only for "Garantito"; links to the evaluation PDF when there is one. */
	private static function badge( $b ) {
		if ( ! is_array( $b ) || empty( $b[0] ) ) {
			return '';
		}
		$label = esc_html__( 'PGS guaranteed', 'directorist-network' );
		return ! empty( $b[1] )
			? '<a class="dirnet-pgs" href="' . esc_url( $b[1] ) . '" target="_blank" rel="noopener">✓ ' . $label . '</a>'
			: '<span class="dirnet-pgs">✓ ' . $label . '</span>';
	}

	public function single( $content ) {
		if ( is_singular( ATBDP_POST_TYPE ) && in_the_loop() && is_main_query() ) {
			if ( Widgets::placed( 'dirnet_selfcert' ) ) {
				return $content; // the sidebar block carries the questionnaires
			}
			$part = Widgets::placed( 'dirnet_company' ) ? ( Widgets::placed( 'dirnet_products' ) ? '' : 'products' ) : ( Widgets::placed( 'dirnet_products' ) ? 'company' : 'all' );
			if ( '' === $part ) {
				return $content;
			}
			$h = self::html( get_the_ID(), $part );
			if ( $h ) {
				return '<div class="dirnet-sheets-wrap dirnet-sheets-single">' . $h . '</div>' . $content;
			}
		}
		return $content;
	}

	public function css() {
		echo '<style>.dirnet-sheets{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:6px 0}.dirnet-sheet{display:inline-block;padding:3px 10px;border-radius:6px;font-size:.85em;text-decoration:none;border:1px solid currentColor}'
			. '.dirnet-pgs{display:inline-block;padding:3px 10px;border-radius:6px;font-size:.85em;text-decoration:none;background:#1a7f37;color:#fff}'
			. '.dirnet-products{font-size:.85em;padding:3px 6px;max-width:100%}</style>' . "\n";
	}
}
