<?php
/**
 * System pages: the pages the plugin relies on are chosen in Settings (not found by guessing) and marked in
 * the Pages list ("Directorist Network – Access"), so they are not mistaken for pages made by an administrator.
 */

namespace DirectoristNetwork;

defined( 'ABSPATH' ) || exit;

class SystemPages {

	const SLUG = 'dirnet-system-pages';

	/** key => array( label, kind, option/array-key ). kind 'opt' = its own option, 'map' = key inside the dirnet_pages option. */
	private static function items() {
		return array(
			'enter'      => array( __( 'Access', 'directorist-network' ), 'opt', 'dirnet_enter_page' ),
			'registration' => array( __( 'Registration', 'directorist-network' ), 'map', 'registration' ),
			'myspace'    => array( __( 'Dashboard (Bacheca)', 'directorist-network' ), 'opt', 'dirnet_myspace_page' ),
			'entities'   => array( __( 'Entities list', 'directorist-network' ), 'opt', Entities::PAGE_OPTION ),
			'territory'  => array( __( 'Territory', 'directorist-network' ), 'opt', Territory::PAGE_OPTION ),
			'about'      => array( __( 'What it is', 'directorist-network' ), 'map', 'about' ),
			'categories' => array( __( 'Categories', 'directorist-network' ), 'map', 'categories' ),
			'report'     => array( __( 'Social report', 'directorist-network' ), 'map', 'report' ),
			'entity'     => array( __( 'Entity profile', 'directorist-network' ), 'map', 'entity' ),
			'product'    => array( __( 'Product profile', 'directorist-network' ), 'map', 'product' ),
			'area'       => array( __( 'Subject area (self-certification)', 'directorist-network' ), 'map', 'area' ),
			'evaluator'  => array( __( 'Evaluators area', 'directorist-network' ), 'map', 'evaluator' ),
			'evalwizard' => array( __( 'Evaluation wizard (reviewer path)', 'directorist-network' ), 'map', 'evalwizard' ),
			'wizard'     => array( __( 'Guided self-certification (wizard)', 'directorist-network' ), 'map', 'wizard' ),
			'sourceprice' => array( __( 'Source price profile', 'directorist-network' ), 'map', 'sourceprice' ),
			'pgs'        => array( __( 'Participatory evaluation form', 'directorist-network' ), 'map', 'pgs' ),
			'entity_assign' => array( __( 'Entry assignment', 'directorist-network' ), 'map', 'entity_assign' ),
			'api'        => array( 'API', 'map', 'api' ),
		);
	}

	/** Shortcode each page must contain (the plugin creates no pages: make the page, paste the shortcode, assign it here). */
	private static function shortcodes() {
		return array(
			'myspace' => '[directorist_user_dashboard]', 'enter' => '[dirnet_access]', 'registration' => '[dirnet_registration]', 'entities' => '[dirnet_entities]', 'territory' => '[dirnet_territory]',
			'categories' => '[dirnet_categories]', 'report' => '[so-socialbalance]',
			'entity' => '[so-entity]', 'product' => '[so-products]', 'sourceprice' => '[so-sourceprice]', 'pgs' => '[so-pgs-process]', 'area' => '[so-subject-area]', 'wizard' => '[so-wizard]', 'evaluator' => '[so-evaluator-area]', 'evalwizard' => '[so-evaluator-wizard]',
			'entity_assign' => '[dirnet_assignment]', 'api' => '[so-api]',
		);
	}

	/** Published page that holds the shortcode of a system page not assigned yet (0 = none). */
	public static function find( $key ) {
		static $cache = array();
		$sc = self::shortcodes();
		if ( ! isset( $sc[ $key ] ) ) {
			return 0;
		}
		if ( ! isset( $cache[ $key ] ) ) {
			global $wpdb;
			$tag = trim( $sc[ $key ], '[]' );
			$id  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type='page' AND post_status='publish' AND post_content LIKE %s ORDER BY ID ASC LIMIT 1", '%[' . $wpdb->esc_like( $tag ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$cache[ $key ] = $id;
		}
		return $cache[ $key ];
	}

	private static function get( $it ) {
		if ( 'opt' === $it[1] ) {
			return (int) get_option( $it[2] );
		}
		$o = (array) get_option( Pages::OPTION, array() );
		return isset( $o[ $it[2] ] ) ? (int) $o[ $it[2] ] : 0;
	}

	private static function set( $it, $id ) {
		if ( 'opt' === $it[1] ) {
			update_option( $it[2], (int) $id, false );
			return;
		}
		$o            = (array) get_option( Pages::OPTION, array() );
		$o[ $it[2] ]  = (int) $id;
		update_option( Pages::OPTION, $o, false );
	}

	public function boot() {
		add_filter( 'display_post_states', array( $this, 'states' ), 10, 2 );
		add_action( 'admin_menu', array( $this, 'menu' ), 30 );
		add_action( 'admin_post_dirnet_system_pages', array( $this, 'save' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/** Pages the plugin cannot work without. */
	private static function required() {
		return array( 'enter', 'myspace', 'entities', 'report', 'entity', 'product' );
	}

	/** Warning in the admin when a required system page is not set (or is trashed / not published). */
	public function notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && ( false !== strpos( (string) $screen->id, 'directorist-network-setup' ) || false !== strpos( (string) $screen->id, self::SLUG ) ) ) {
			return;
		}
		$missing = array();
		foreach ( self::items() as $k => $it ) {
			if ( ! in_array( $k, self::required(), true ) ) {
				continue;
			}
			$id = self::get( $it );
			if ( ! $id || 'publish' !== get_post_status( $id ) ) {
				$missing[] = $it[0];
			}
		}
		if ( ! $missing ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>Directorist Network:</strong> ' . esc_html( sprintf( /* translators: %s: page names */ __( 'these system pages are missing or not published: %s.', 'directorist-network' ), implode( ', ', $missing ) ) ) . ' <a href="' . esc_url( admin_url( 'edit.php?post_type=at_biz_dir&page=' . self::SLUG ) ) . '">' . esc_html__( 'Set them in System pages', 'directorist-network' ) . '</a></p></div>';
	}

	public function states( $states, $post ) {
		if ( 'page' !== $post->post_type ) {
			return $states;
		}
		$sol = array( 'area', 'wizard', 'evaluator', 'evalwizard', 'report', 'entity', 'product', 'sourceprice', 'pgs' ); // Solidaros pages, assigned here when DN hosts them
		foreach ( self::items() as $k => $it ) {
			if ( self::get( $it ) === (int) $post->ID ) {
				/* translators: %s: page role */
				$fmt = in_array( $k, $sol, true ) ? __( 'Solidaros – %s', 'directorist-network' ) : __( 'Directorist Network – %s', 'directorist-network' );
				$states[ 'dirnet_' . $it[2] ] = sprintf( $fmt, $it[0] );
			}
		}
		// A page holding one of our shortcodes is recognised even if it is not assigned yet.
		$ctn = (string) $post->post_content;
		foreach ( self::shortcodes() as $k => $sc ) {
			$items = self::items();
			if ( isset( $items[ $k ] ) && self::get( $items[ $k ] ) !== (int) $post->ID && has_shortcode( $ctn, trim( $sc, '[]' ) ) ) {
				/* translators: %s: page role */
				$states[ 'dirnet_unassigned_' . $k ] = sprintf( __( 'Directorist Network – %s (not assigned)', 'directorist-network' ), $items[ $k ][0] );
			}
		}
		// Directorist's own pages are recognised by the shortcode they contain.
		$content = (string) $post->post_content;
		foreach ( self::directorist_pages() as $tag => $label ) {
			if ( has_shortcode( $content, $tag ) ) {
				/* translators: %s: page role */
				$states[ 'directorist_' . $tag ] = sprintf( __( 'Directorist – %s', 'directorist-network' ), $label );
			}
		}
		return $states;
	}

	/** Directorist's own pages: shortcode => role label. */
	private static function directorist_pages() {
		return array(
			'directorist_add_listing'         => __( 'Add listing', 'directorist-network' ),
			'directorist_user_dashboard'      => __( 'Dashboard', 'directorist-network' ),
			'directorist_signin_signup'       => __( 'Sign-in', 'directorist-network' ),
			'directorist_user_login'          => __( 'Sign-in', 'directorist-network' ),
			'directorist_custom_registration' => __( 'Registration', 'directorist-network' ),
			'directorist_all_listing'         => __( 'All listings', 'directorist-network' ),
			'directorist_all_categories'      => __( 'All categories', 'directorist-network' ),
			'directorist_all_locations'       => __( 'All locations', 'directorist-network' ),
			'directorist_search_listing'      => __( 'Search form', 'directorist-network' ),
			'directorist_search_result'       => __( 'Search results', 'directorist-network' ),
			'directorist_category'            => __( 'Single category', 'directorist-network' ),
			'directorist_tag'                 => __( 'Single tag', 'directorist-network' ),
			'directorist_location'            => __( 'Single location', 'directorist-network' ),
			'directorist_author_profile'      => __( 'Author profile', 'directorist-network' ),
		);
	}

	public function menu() {
		add_submenu_page( 'edit.php?post_type=at_biz_dir', __( 'System pages', 'directorist-network' ), __( 'System pages', 'directorist-network' ), 'manage_options', self::SLUG, array( $this, 'page' ) );
	}

	public function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'System pages', 'directorist-network' ) . '</h1>';
		$this->body();
		echo '</div>';
	}

	/** The content of the page, shown inside Settings. */
	public function body() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<p>' . esc_html__( 'Choose which page does each job. The chosen pages are marked in the Pages list. Pages are not created or changed here.', 'directorist-network' ) . '</p>';
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Saved.', 'directorist-network' ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dirnet_system_pages" />' . wp_nonce_field( 'dirnet_system_pages', '_wpnonce', true, false ) . '<table class="form-table" role="presentation">';
		foreach ( self::items() as $k => $it ) {
			echo '<tr><th scope="row">' . esc_html( $it[0] ) . '</th><td>';
			wp_dropdown_pages( array( 'name' => 'sp[' . $k . ']', 'selected' => self::get( $it ), 'show_option_none' => __( '— none —', 'directorist-network' ), 'option_none_value' => 0 ) );
			$sc = self::shortcodes();
			if ( isset( $sc[ $k ] ) ) {
				echo ' <code>' . esc_html( $sc[ $k ] ) . '</code>';
			}
			echo '</td></tr>';
		}
		echo '</table><p><button class="button button-primary">' . esc_html__( 'Save', 'directorist-network' ) . '</button></p></form>';
	}

	public function save() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'dirnet_system_pages' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'directorist-network' ) );
		}
		$sp = isset( $_POST['sp'] ) ? (array) wp_unslash( $_POST['sp'] ) : array();
		foreach ( self::items() as $k => $it ) {
			if ( isset( $sp[ $k ] ) ) {
				$id = absint( $sp[ $k ] );
				self::set( $it, ( $id && 'page' === get_post_type( $id ) ) ? $id : 0 );
			}
		}
		wp_safe_redirect( admin_url( 'edit.php?post_type=at_biz_dir&page=' . self::SLUG . '&saved=1' ) );
		exit;
	}
}
