<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site search (also the live search of themes such as Blocksy) finds the directory entries too.
 * Only searches that look at posts or pages get them added; a search limited to something else (products, say) is left alone.
 * Disable with: add_filter( 'dirnet_search_listings', '__return_false' );
 */
class Search {

	public function boot() {
		add_action( 'pre_get_posts', array( $this, 'add' ), 20 );
	}

	public function add( $q ) {
		if ( ! defined( 'ATBDP_POST_TYPE' ) || '' === (string) $q->get( 's' ) ) {
			return;
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		if ( ! apply_filters( 'dirnet_search_listings', true, $q ) ) {
			return;
		}
		$pt = $q->get( 'post_type' );
		if ( ATBDP_POST_TYPE === $pt || ( is_array( $pt ) && in_array( ATBDP_POST_TYPE, $pt, true ) ) ) {
			return;
		}
		if ( '' === $pt || 'any' === $pt ) {
			// The default search already covers every searchable type; make sure ours is one of them.
			$q->set( 'post_type', array_values( array_unique( array_merge( get_post_types( array( 'exclude_from_search' => false ) ), array( ATBDP_POST_TYPE ) ) ) ) );
			return;
		}
		$list = (array) $pt;
		if ( in_array( 'post', $list, true ) || in_array( 'page', $list, true ) ) {
			$list[] = ATBDP_POST_TYPE;
			$q->set( 'post_type', array_values( array_unique( $list ) ) );
		}
	}
}
