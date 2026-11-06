<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Entity page: uses the theme template and fills Directorist's listing sidebar (a WordPress widget area)
 * with Map, Entity data and the contact form. The "Entity page" admin page can redo it at any time.
 */
class Sidebar {

	const AREA   = 'right-sidebar-listing';
	const OPTION = 'dirnet_sidebar_seeded4';

	public function boot() {
		add_action( 'admin_menu', array( $this, 'menu' ), 30 );
		add_action( 'admin_post_dirnet_entity_page', array( $this, 'handle' ) );
		add_action( 'init', array( $this, 'drop_relations_widget' ), 20 );
	}

	public function menu() {
	}

	public function render( $embedded = false ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tpl = function_exists( 'get_directorist_option' ) ? get_directorist_option( 'single_listing_template' ) : '';
		echo $embedded ? '<hr /><h2>' . esc_html__( 'Listing template', 'directorist-network' ) . '</h2>' : '<div class="wrap"><h1>' . esc_html__( 'Listing template', 'directorist-network' ) . '</h1>';
		echo '<p>' . esc_html__( 'Template used by the listing page:', 'directorist-network' ) . ' <code>' . esc_html( (string) $tpl ) . '</code></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'dirnet_entity_page' );
		echo '<input type="hidden" name="action" value="dirnet_entity_page" />';
		submit_button( __( 'Set up the listing template', 'directorist-network' ), 'primary', 'dirnet_do', false );
		echo ' ';
		submit_button( __( 'Back to Directorist default', 'directorist-network' ), 'secondary', 'dirnet_reset', false );
		echo '</form>' . ( $embedded ? '' : '</div>' );
	}

	public function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'directorist-network' ) );
		}
		check_admin_referer( 'dirnet_entity_page' );
		if ( isset( $_POST['dirnet_reset'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- checked above
			$this->reset();
		} else {
			$this->template( true );
			$this->apply( true );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=dirnet-repairs' ) );
		exit;
	}

	/** Directorist's own page again: its template, an empty listing sidebar, none of our widgets. */
	public function reset() {
		update_option( self::OPTION, 1 );
		$o                            = (array) get_option( 'atbdp_option', array() );
		$o['single_listing_template'] = 'directorist_template';
		update_option( 'atbdp_option', $o );
		$sw = get_option( 'sidebars_widgets', array() );
		$sw[ self::AREA ] = array();
		update_option( 'sidebars_widgets', $sw );
		delete_option( 'widget_dirnet_identity' );
	}

	/** One-off: the self-certification block replaces the social report block, right under the entity data. */
	private function swap_report_widget() {
		if ( get_option( 'dirnet_selfcert_swapped' ) ) {
			return;
		}
		update_option( 'dirnet_selfcert_swapped', 1 );
		$sw = get_option( 'sidebars_widgets', array() );
		if ( empty( $sw[ self::AREA ] ) || ! is_array( $sw[ self::AREA ] ) ) {
			return;
		}
		$list = array_values( array_filter( $sw[ self::AREA ], function ( $w ) {
			return 0 !== strpos( (string) $w, 'dirnet_report-' );
		} ) );
		$new  = $this->add( 'dirnet_selfcert', array( 'title' => '' ) );
		$pos  = 0;
		foreach ( $list as $i => $w ) {
			if ( 0 === strpos( (string) $w, 'dirnet_identity-' ) ) {
				$pos = $i + 1;
				break;
			}
		}
		array_splice( $list, $pos, 0, array( $new ) );
		$sw[ self::AREA ] = $list;
		update_option( 'sidebars_widgets', $sw );
	}

	/** One-off: relations now live in the body of the sheet, not in the sidebar. */
	public function drop_relations_widget() {
		$this->swap_report_widget();
		if ( get_option( 'dirnet_rel_in_body' ) ) {
			return;
		}
		update_option( 'dirnet_rel_in_body', 1 );
		$sw = get_option( 'sidebars_widgets', array() );
		if ( ! empty( $sw[ self::AREA ] ) && is_array( $sw[ self::AREA ] ) ) {
			$sw[ self::AREA ] = array_values( array_filter( $sw[ self::AREA ], function ( $w ) {
				return 0 !== strpos( (string) $w, 'dirnet_relations_w-' );
			} ) );
			update_option( 'sidebars_widgets', $sw );
		}
	}

	public function seed() {
		$this->drop_relations_widget();
		if ( get_option( self::OPTION ) ) {
			return;
		}
		update_option( self::OPTION, 1 );
		$this->template( false );
		$this->apply( false );
	}

	private function template( $force ) {
		$o = (array) get_option( 'atbdp_option', array() );
		if ( $force || empty( $o['single_listing_template'] ) || 'directorist_template' === $o['single_listing_template'] ) {
			$o['single_listing_template'] = 'current_theme_template';
			update_option( 'atbdp_option', $o );
		}
	}

	private function add( $base, $inst ) {
		$o = get_option( 'widget_' . $base, array() );
		if ( ! is_array( $o ) ) {
			$o = array();
		}
		$i = 1;
		while ( isset( $o[ $i ] ) ) {
			$i++;
		}
		$o[ $i ]           = $inst;
		$o['_multiwidget'] = 1;
		update_option( 'widget_' . $base, $o );
		return $base . '-' . $i;
	}

	public function apply( $force ) {
		$sw = get_option( 'sidebars_widgets', array() );
		if ( ! $force && ! empty( $sw[ self::AREA ] ) ) {
			return;
		}
		$ids = array(
			$this->add( 'bdmw_widget', array( 'title' => __( 'Map', 'directorist-network' ), 'zoom' => 16 ) ),
			$this->add( 'dirnet_identity', array( 'title' => __( 'Subject data', 'directorist-network' ) ) ),
			$this->add( 'dirnet_selfcert', array( 'title' => '' ) ),
			$this->add( 'bdco_widget', array( 'title' => __( 'Contact the entity', 'directorist-network' ) ) ),
			$this->add( 'dirnet_suggest', array( 'title' => '' ) ),
		);
		$sw[ self::AREA ] = $ids;
		update_option( 'sidebars_widgets', $sw );
	}
}

class Identity_Widget extends \WP_Widget {
	public function __construct() {
		parent::__construct( 'dirnet_identity', __( 'Subject data', 'directorist-network' ), array( 'description' => __( 'Type, legal form, where, phone, website of the entity.', 'directorist-network' ) ) );
	}

	public function widget( $args, $inst ) {
		if ( ! defined( 'ATBDP_POST_TYPE' ) || ! is_singular( ATBDP_POST_TYPE ) ) {
			return;
		}
		$rows = Entities::identity_rows( get_the_ID() );
		if ( ! $rows ) {
			return;
		}
		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput
		$t = ! empty( $inst['title'] ) ? $inst['title'] : __( 'Subject data', 'directorist-network' );
		echo $args['before_title'] . esc_html( $t ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<dl class="dirnet-identity">';
		foreach ( $rows as $l => $v ) {
			echo '<dt style="font-weight:700;margin-top:8px">' . esc_html( $l ) . '</dt><dd style="margin:0">' . $v . '</dd>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</dl>';
		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public function form( $inst ) {
		echo '<p>' . esc_html__( 'Shows the entity data.', 'directorist-network' ) . '</p>';
	}
}
