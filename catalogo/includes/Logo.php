<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The entity's logo (profile field "logo") is shown where Directorist puts the publisher's avatar
 * on listing cards, and at the top of the single page. Without a logo nothing is shown (never the
 * publisher's personal avatar).
 */
class Logo {

	const META = '_custom-logo';

	public function boot() {
		add_filter( 'pre_get_avatar', array( $this, 'card_avatar' ), 10, 3 );
		add_filter( 'the_content', array( $this, 'single' ), 26 );
		add_action( 'wp_head', array( $this, 'css' ) );
	}

	/** Logo URL for a listing ('' if none). The field stores a URL or an attachment id. */
	public static function url( $post_id ) {
		$v = get_post_meta( $post_id, self::META, true );
		if ( is_array( $v ) ) {
			$v = reset( $v );
		}
		$v = trim( (string) $v );
		if ( '' === $v ) {
			return '';
		}
		if ( ctype_digit( $v ) ) {
			$u = wp_get_attachment_url( (int) $v );
			return $u ? $u : '';
		}
		return preg_match( '#^https?://#i', $v ) ? $v : '';
	}

	public static function img( $post_id, $size = 40 ) {
		$u = self::url( $post_id );
		if ( '' === $u ) {
			return '';
		}
		return '<img class="dirnet-logo" src="' . esc_url( $u ) . '" alt="' . esc_attr( get_the_title( $post_id ) ) . '" width="' . (int) $size . '" height="' . (int) $size . '" loading="lazy" />';
	}

	/** Inside the Directorist listing loop the author's avatar is replaced by the logo. */
	public function card_avatar( $avatar, $id_or_email, $args ) {
		$post = get_post();
		if ( null !== $avatar || ! $post || ATBDP_POST_TYPE !== $post->post_type || is_singular() || ! is_numeric( $id_or_email ) || (int) $id_or_email !== (int) $post->post_author ) {
			return $avatar;
		}
		return self::img( $post->ID, isset( $args['size'] ) ? (int) $args['size'] : 40 );
	}

	public function single( $content ) {
		if ( is_singular( ATBDP_POST_TYPE ) && in_the_loop() && is_main_query() ) {
			$h = self::img( get_the_ID(), 72 );
			if ( $h ) {
				return '<div class="dirnet-logo-single">' . $h . '</div>' . $content;
			}
		}
		return $content;
	}

	public function css() {
		echo '<style>.dirnet-logo{border-radius:50%;object-fit:contain;background:#fff;border:1px solid rgba(0,0,0,.12);padding:2px;box-sizing:border-box;width:40px;height:40px}'
			. '.dirnet-logo-single .dirnet-logo{width:72px;height:72px}.dirnet-logo-single{margin:0 0 10px}</style>' . "\n";
	}
}
