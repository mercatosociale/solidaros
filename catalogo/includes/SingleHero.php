<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Category and territory under the title in the theme's title band on an entity page. */
class SingleHero {

	/** Listing ids whose territory line has already been printed (it must appear once). */
	private static $placed = array();

	public function boot() {
		add_action( 'blocksy:hero:title:after', array( $this, 'meta' ) );
		// Directorist's own single page does not use the theme's title band: print the territory under its title...
		add_action( 'directorist_single_listing_after_title', array( $this, 'place' ), 20 );
		// ...and, when neither spot is rendered, above the description.
		add_filter( 'directorist_the_content', array( $this, 'place_in_content' ), 5 );
	}

	/** "Region › Province › City" of an entity, empty when it has no location term. */
	private static function place_line( $id ) {
		return implode( ' › ', Entities::place_parts( (int) $id ) );
	}

	public function place( $id = 0 ) {
		$id = $id ? (int) $id : (int) get_the_ID();
		if ( ! $id || isset( self::$placed[ $id ] ) ) {
			return;
		}
		$line = self::place_line( $id );
		if ( '' === $line ) {
			return;
		}
		self::$placed[ $id ] = true;
		echo '<p class="dirnet-place" style="margin:6px 0 12px;opacity:.85">' . esc_html( $line ) . '</p>';
	}

	public function place_in_content( $content ) {
		if ( ! defined( 'ATBDP_POST_TYPE' ) || ! is_singular( ATBDP_POST_TYPE ) ) {
			return $content;
		}
		$id = (int) get_the_ID();
		if ( ! $id || isset( self::$placed[ $id ] ) ) {
			return $content;
		}
		$line = self::place_line( $id );
		if ( '' === $line ) {
			return $content;
		}
		self::$placed[ $id ] = true;
		return '<p class="dirnet-place" style="margin:0 0 12px;opacity:.85">' . esc_html( $line ) . '</p>' . $content;
	}

	/** Roles with their parent, e.g. "Commercio › Bottega equosolidale"; several roles separated by commas. */
	public static function roles_line( $id ) {
		if ( ! taxonomy_exists( Functions::TAX ) ) {
			return '';
		}
		$terms = wp_get_object_terms( $id, Functions::TAX );
		if ( is_wp_error( $terms ) || ! $terms ) {
			return '';
		}
		$ids = wp_list_pluck( $terms, 'term_id' );
		$out = array();
		foreach ( $terms as $t ) {
			$has_child = false;
			foreach ( $terms as $c ) {
				if ( (int) $c->parent === (int) $t->term_id ) {
					$has_child = true;
				}
			}
			if ( $has_child ) {
				continue; // the parent is shown through its sub-role
			}
			$path = array( $t->name );
			if ( $t->parent && in_array( (int) $t->parent, array_map( 'intval', $ids ), true ) ) {
				$p = get_term( (int) $t->parent, Functions::TAX );
				if ( $p && ! is_wp_error( $p ) ) {
					array_unshift( $path, $p->name );
				}
			}
			$out[] = implode( ' › ', $path );
		}
		return implode( ', ', $out );
	}

	public function meta() {
		if ( ! defined( 'ATBDP_POST_TYPE' ) || ! is_singular( ATBDP_POST_TYPE ) ) {
			return;
		}
		$id    = get_the_ID();
		$cats  = wp_get_post_terms( $id, ATBDP_CATEGORY, array( 'fields' => 'names' ) );
		$cats  = is_wp_error( $cats ) ? array() : $cats;
		$place = array_reverse( Entities::place_parts( $id ) );
		$roles = self::roles_line( $id );
		$parts = array_filter( array( $roles, implode( ', ', $cats ), implode( ' › ', $place ) ) );
		if ( $place ) {
			self::$placed[ $id ] = true;
		}
		if ( $parts ) {
			echo '<div class="dirnet-hero-meta" style="margin-top:10px;font-size:1.05em;opacity:.95">' . esc_html( implode( ' · ', $parts ) ) . '</div>';
		}
	}
}
