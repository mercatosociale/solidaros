<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Social report available / not available" marker on listing cards, the single page and the Entities page.
 * The link comes from the profile field "social_report" (URL).
 */
class SocialReport {

	const META = '_custom-social-report';

	public function boot() {
		add_action( 'directorist_loop_grid_info_after_excerpt', array( $this, 'card' ) );
		add_action( 'directorist_loop_list_info_after_excerpt', array( $this, 'card' ) );
		add_filter( 'the_content', array( $this, 'single' ), 25 );
		add_action( 'wp_head', array( $this, 'css' ) );
	}

	/** Does this listing belong to a type whose profile defines the social_report field? */
	private static function applies( $post_id ) {
		$t = Relations::type_of( $post_id );
		if ( ! $t ) {
			return false;
		}
		$defs = $t[0]->types();
		foreach ( isset( $defs[ $t[1] ]['fields'] ) ? $defs[ $t[1] ]['fields'] : array() as $f ) {
			if ( isset( $f['key'] ) && 'social_report' === $f['key'] ) {
				return true;
			}
		}
		return false;
	}

	/** The booklet published by Solidaros wins; the link entered by hand is the fallback. */
	public static function url( $post_id ) {
		$url = SolidarosBridge::report_url( $post_id ); // the published booklet wins: a new year replaces the old one
		return '' !== $url ? $url : trim( (string) get_post_meta( $post_id, self::META, true ) );
	}

	public static function html( $post_id ) {
		if ( ! self::applies( $post_id ) ) {
			return '';
		}
		$url = self::url( $post_id );
		if ( '' !== $url ) {
			return '<a class="dirnet-sr dirnet-sr-yes" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Social report available', 'directorist-network' ) . '</a>';
		}
		return '<span class="dirnet-sr dirnet-sr-no">' . esc_html__( 'Social report not available', 'directorist-network' ) . '</span>';
	}

	public function card() {
		$h = self::html( get_the_ID() );
		if ( $h ) {
			echo '<div class="dirnet-sr-wrap">' . $h . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	/** Earlier questionnaires of the entity, linked from its page. */
	public static function history_html( $post_id ) {
		$rows = SolidarosBridge::history( $post_id );
		if ( ! $rows ) {
			return '';
		}
		$h = '<details class="dirnet-sr-hist"><summary>' . esc_html__( 'Earlier questionnaires', 'directorist-network' ) . '</summary>';
		foreach ( $rows as $title => $items ) {
			$h .= '<h4>' . esc_html( $title ) . '</h4><ul>';
			foreach ( $items as $it ) {
				$h .= '<li><a href="' . esc_url( $it[1] ) . '" target="_blank" rel="noopener">' . esc_html( $it[0] ? (string) $it[0] : __( 'Booklet', 'directorist-network' ) ) . '</a></li>';
			}
			$h .= '</ul>';
		}
		return $h . '</details>';
	}

	public function single( $content ) {
		if ( is_singular( ATBDP_POST_TYPE ) && in_the_loop() && is_main_query() && ! Widgets::placed( 'dirnet_report' ) && ! Widgets::placed( 'dirnet_selfcert' ) ) {
			$h = self::html( get_the_ID() );
			if ( $h ) {
				return '<div class="dirnet-sr-wrap dirnet-sr-single">' . $h . self::history_html( get_the_ID() ) . '</div>' . $content; // phpcs:ignore WordPress.Security.EscapeOutput
			}
		}
		return $content;
	}

	public function css() {
		echo '<style>.dirnet-sr-wrap{margin:8px 0}.dirnet-sr{display:inline-block;padding:3px 10px;border-radius:999px;font-size:.85em;text-decoration:none;border:1px solid currentColor}'
			. '.dirnet-sr-yes{background:#2f6f5e;color:#fff;border-color:#2f6f5e}.dirnet-sr-yes:hover{color:#fff;opacity:.9}.dirnet-sr-no{opacity:.65}</style>' . "\n";
	}
}
