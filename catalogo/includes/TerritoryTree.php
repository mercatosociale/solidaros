<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps territories and categories aggregated (an entry in a subcategory also belongs to the category). For territories: an entry placed in a municipality also belongs to its province and region,
 * one placed in a province also belongs to its region. Runs whenever the location terms of an entry are set
 * (frontend form, admin, import, Membership form). It only adds terms: choosing a new location replaces the
 * old set and the ancestors of the new one are added.
 */
class TerritoryTree {

	private static $busy = false;

	public function boot() {
		add_action( 'set_object_terms', array( $this, 'add_ancestors' ), 20, 6 );
	}

	public function add_ancestors( $object_id, $terms, $tt_ids, $taxonomy ) {
		if ( self::$busy || ! defined( 'ATBDP_LOCATION' ) || ! defined( 'ATBDP_CATEGORY' ) || ! in_array( $taxonomy, array( ATBDP_LOCATION, ATBDP_CATEGORY, Functions::TAX ), true ) || ! defined( 'ATBDP_POST_TYPE' ) || ATBDP_POST_TYPE !== get_post_type( $object_id ) ) {
			return;
		}
		$have = wp_get_object_terms( $object_id, $taxonomy, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $have ) || ! $have ) {
			return;
		}
		$want = array();
		foreach ( $have as $tid ) {
			foreach ( get_ancestors( (int) $tid, $taxonomy, 'taxonomy' ) as $a ) {
				$want[ (int) $a ] = 1;
			}
		}
		$missing = array_values( array_diff( array_keys( $want ), array_map( 'intval', $have ) ) );
		if ( $missing ) {
			self::$busy = true;
			wp_set_object_terms( $object_id, $missing, $taxonomy, true );
			self::$busy = false;
		}
	}
}
