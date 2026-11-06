<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The Mercato Sociale logo is the image of every entry that has none (cards and pages). */
class Placeholder {

	const FILE = 'assets/placeholders/mercato-sociale.png';
	const DONE = 'dirnet_placeholder_done';

	public static function url() {
		return plugins_url( self::FILE, DIRNET_FILE );
	}

	public function boot() {
		add_action( 'admin_init', array( $this, 'apply' ) );
	}

	/** Once per version: the directory types already created use it too (unless an administrator chose another image). */
	public function apply() {
		if ( get_option( self::DONE ) === DIRNET_VERSION || ! taxonomy_exists( 'atbdp_listing_types' ) ) {
			return;
		}
		$own   = plugins_url( 'assets/placeholders/', DIRNET_FILE );
		$terms = get_terms( array( 'taxonomy' => 'atbdp_listing_types', 'hide_empty' => false, 'fields' => 'ids' ) );
		foreach ( is_wp_error( $terms ) ? array() : $terms as $tid ) {
			$cfg = get_term_meta( $tid, 'general_config', true );
			if ( ! is_array( $cfg ) ) {
				$cfg = array();
			}
			$cur = isset( $cfg['preview_image'] ) ? (string) $cfg['preview_image'] : '';
			if ( '' === $cur || false !== strpos( $cur, '/assets/placeholders/' ) || 0 === strpos( $cur, $own ) ) { // empty, or one of our own placeholders (whatever folder the plugin had)
				$cfg['preview_image'] = self::url();
				update_term_meta( $tid, 'general_config', $cfg );
			}
		}
		update_option( self::DONE, DIRNET_VERSION, false );
	}
}
