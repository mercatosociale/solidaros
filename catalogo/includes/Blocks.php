<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Blocks for the entity sidebar (Appearance > Widgets): data of the entity, category and territory,
 * "Suggest an edit". Nothing is placed automatically and no template is changed: the editor adds them by hand.
 */
class Blocks {

	public function boot() {
		add_action( 'init', array( $this, 'register' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'editor' ) );
	}

	private function is_entity() {
		return defined( 'ATBDP_POST_TYPE' ) && is_singular( ATBDP_POST_TYPE );
	}

	public function register() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		foreach ( array( 'identity', 'place', 'suggest', 'relations' ) as $k ) {
			register_block_type( 'dirnet/' . $k, array( 'api_version' => 2, 'render_callback' => array( $this, 'render_' . $k ) ) );
		}
	}

	public function render_identity() {
		if ( ! $this->is_entity() ) {
			return '';
		}
		$rows = Entities::identity_rows( get_the_ID() );
		if ( ! $rows ) {
			return '';
		}
		$h = '<div class="dirnet-identity"><h3 class="widget-title">' . esc_html__( 'Subject data', 'directorist-network' ) . '</h3><dl style="margin:0">';
		foreach ( $rows as $l => $v ) {
			$h .= '<dt style="font-weight:700;margin-top:8px">' . esc_html( $l ) . '</dt><dd style="margin:0">' . $v . '</dd>';
		}
		return $h . '</dl></div>';
	}

	public function render_place() {
		if ( ! $this->is_entity() ) {
			return '';
		}
		$id    = get_the_ID();
		$cats  = wp_get_post_terms( $id, ATBDP_CATEGORY, array( 'fields' => 'names' ) );
		$cats  = is_wp_error( $cats ) ? array() : $cats;
		$place = array_reverse( Entities::place_parts( $id ) );
		$parts = array_filter( array( implode( ', ', $cats ), implode( ' › ', $place ) ) );
		return $parts ? '<p class="dirnet-place"><strong>' . esc_html( implode( ' · ', $parts ) ) . '</strong></p>' : '';
	}

	public function render_relations() {
		if ( ! $this->is_entity() ) {
			return '';
		}
		return ( new Relations() )->html( get_the_ID() );
	}

	public function render_suggest() {
		if ( ! $this->is_entity() || ! apply_filters( 'dirnet_show_suggest_button', true, get_the_ID() ) ) {
			return '';
		}
		return Suggestions::button( get_the_ID() );
	}

	public function editor() {
		$labels = array(
			'identity' => __( 'Subject data', 'directorist-network' ),
			'place'    => __( 'Entity category and territory', 'directorist-network' ),
			'suggest'  => __( 'Suggest an edit', 'directorist-network' ),
			'relations' => __( 'Relations of the entity', 'directorist-network' ),
		);
		$js = '( function ( wp ) { if ( ! wp || ! wp.blocks ) { return; } var el = wp.element.createElement, L = ' . wp_json_encode( $labels ) . ';'
			. 'Object.keys( L ).forEach( function ( k ) { wp.blocks.registerBlockType( "dirnet/" + k, { apiVersion: 2, title: L[ k ], category: "widgets", icon: "admin-multisite", supports: { html: false },'
			. 'edit: function () { var p = wp.blockEditor.useBlockProps( { style: { padding: "10px", border: "1px dashed #999" } } ); return el( "div", p, L[ k ] + " (" + ' . wp_json_encode( __( 'shown on the entity page', 'directorist-network' ) ) . ' + ")" ); },'
			. 'save: function () { return null; } } ); } ); } )( window.wp );';
		wp_register_script( 'dirnet-blocks', '', array( 'wp-blocks', 'wp-element', 'wp-block-editor' ), DIRNET_VERSION, true );
		wp_enqueue_script( 'dirnet-blocks' );
		wp_add_inline_script( 'dirnet-blocks', $js );
	}
}
