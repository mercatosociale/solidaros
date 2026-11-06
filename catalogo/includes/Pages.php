<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The site pages created by the setup (Entities, Territories and Register are created by their own classes):
 * What it is, Categories (index), Membership, Social Report, Entity profile, Product profile.
 * The texts are drafts to be completed by the site owner. Page ids are kept in the option dirnet_pages.
 */
class Pages {

	const OPTION = 'dirnet_pages';

	public function boot() {
		add_action( 'pre_get_posts', array( $this, 'search_types' ) );
		add_shortcode( 'dirnet_categories', array( $this, 'categories' ) );
		add_shortcode( 'dirnet_social_report', array( $this, 'social_report' ) );
		if ( ! SolidarosBridge::delegated() ) {
			add_shortcode( 'dirnet_login', array( $this, 'login' ) ); // standalone: the old sign-in; otherwise [dirnet_access] answers to this tag too
		}
		add_action( 'template_redirect', array( $this, 'gate' ) );
		add_action( 'template_redirect', array( $this, 'generic_add' ), 5 );
		add_action( 'init', array( $this, 'rewrite' ), 11 );
		add_action( 'init', array( $this, 'flush_once' ), 99 );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_filter( 'redirect_canonical', array( $this, 'keep_url' ), 11, 2 );
	}

	/** Pretty category URLs: /<categories-page>/<category-slug>/ (the old ?cat= form keeps working). */
	public function rewrite() {
		$id = self::id( 'categories' );
		if ( $id ) {
			$uri = get_page_uri( $id );
			if ( $uri ) {
				add_rewrite_rule( '^' . preg_quote( $uri, '#' ) . '/([^/]+)/?$', 'index.php?page_id=' . $id . '&dirnet_cat=$matches[1]', 'top' );
			}
		}
	}

	/** Rewrite rules are saved once per plugin version, so the new URLs work without visiting Settings > Permalinks. */
	public function flush_once() {
		if ( get_option( 'dirnet_rewrite_ver' ) !== DIRNET_VERSION ) {
			flush_rewrite_rules( false );
			update_option( 'dirnet_rewrite_ver', DIRNET_VERSION, false );
		}
	}

	public function query_vars( $vars ) {
		$vars[] = 'dirnet_cat';
		return $vars;
	}

	public function keep_url( $redirect, $requested ) {
		return get_query_var( 'dirnet_cat' ) ? false : $redirect;
	}

	/** Address of a category page. */
	private static function cat_url( $page_url, $slug ) {
		if ( get_option( 'permalink_structure' ) ) {
			return trailingslashit( $page_url ) . rawurlencode( $slug ) . '/';
		}
		return add_query_arg( 'cat', $slug, $page_url );
	}

	/** Site search (also Blocksy's live search) includes the Directorist entries. */
	public function search_types( $q ) {
		if ( is_admin() || ! $q->is_search() || ! $q->is_main_query() ) {
			return;
		}
		$add = array_filter( array( defined( 'ATBDP_POST_TYPE' ) ? ATBDP_POST_TYPE : 'at_biz_dir' ), 'post_type_exists' );
		$pt  = $q->get( 'post_type' );
		if ( empty( $pt ) || 'any' === $pt ) {
			$pt = array_values( array_unique( array_merge( get_post_types( array( 'exclude_from_search' => false ) ), $add ) ) );
		} else {
			$pt = array_values( array_unique( array_merge( (array) $pt, $add ) ) );
		}
		$q->set( 'post_type', $pt );
	}

	public static function id( $key ) {
		if ( 'login' === $key ) { // the way in is the Access page; an old sign-in page that is no longer published must not be used
			$e = (int) get_option( 'dirnet_enter_page' );
			if ( $e && 'publish' === get_post_status( $e ) ) {
				return $e;
			}
		}
		$o = (array) get_option( self::OPTION, array() );
		$i = isset( $o[ $key ] ) ? (int) $o[ $key ] : 0;
		if ( $i && get_post( $i ) && 'trash' !== get_post_status( $i ) ) {
			return $i;
		}
		return SystemPages::find( $key ); // not assigned: a published page holding the shortcode counts
	}

	private static function definitions() {
		return array(
			'about'      => array(
				__( 'What it is', 'directorist-network' ),
				'<h2>' . __( 'What it is', 'directorist-network' ) . '</h2><p>' . __( 'Describe here what the network is, who promotes it and who it is for.', 'directorist-network' ) . '</p>'
				. '<h2>' . __( 'How it works', 'directorist-network' ) . '</h2><p>' . __( 'Explain how to find entities, how to join and what the membership commits to.', 'directorist-network' ) . '</p>',
			),
			'categories' => array(
				__( 'Categories', 'directorist-network' ),
				'[dirnet_categories]',
			),
			'membership' => array(
				__( 'Membership', 'directorist-network' ),
				'<h2>' . __( 'Membership conditions', 'directorist-network' ) . '</h2><p>' . __( 'Write here the conditions to join. This page is linked from the registration form.', 'directorist-network' ) . '</p>'
				. '<h3>' . __( 'Social report', 'directorist-network' ) . '</h3><p>' . __( 'Describe the commitment to complete the social report.', 'directorist-network' ) . '</p>'
				. '<h3>' . __( 'Entity profile', 'directorist-network' ) . '</h3><p>' . __( 'Describe the commitment to complete the entity profile.', 'directorist-network' ) . '</p>'
				. '<h3>' . __( 'Product profile', 'directorist-network' ) . '</h3><p>' . __( 'Describe the commitment to complete the product profile.', 'directorist-network' ) . '</p>',
			),
			'login'      => array(
				__( 'Enter', 'directorist-network' ),
				'[dirnet_access]',
			),
			'report'     => array(
				__( 'Social Report', 'directorist-network' ),
				'[so-socialbalance]',
			),
			'entity'     => array(
				__( 'Entity profile', 'directorist-network' ),
				'[so-entity]',
			),
			'sourceprice' => array(
				__( 'Source price profile', 'directorist-network' ),
				'[so-sourceprice]',
			),
			'pgs'        => array(
				__( 'Participatory evaluation form', 'directorist-network' ),
				'[so-pgs-process]',
			),
			'product'    => array(
				__( 'Product profile', 'directorist-network' ),
				'[so-products]',
			),
		);
	}

	/** Create the missing pages; returns log lines. */
	public static function ensure_all() {
		return array(); // pages are never created: assign them in System pages
	}

	/**
	 * [dirnet_social_report]: the page where the social report is filled in or edited.
	 * The form itself comes from the plugin that provides it, through the filter dirnet_social_report_form.
	 */
	public function social_report() {
		$sc = trim( (string) get_option( 'dirnet_social_report_shortcode', '' ) );
		$sc = '' !== $sc ? $sc : '[so-socialbalance]'; // Solidaros: its own shortcode handles login, tokens and permissions
		if ( preg_match( '/^\[([\w-]+)/', $sc, $m ) && shortcode_exists( $m[1] ) ) {
			return do_shortcode( $sc );
		}
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Log in to fill in or edit your social report.', 'directorist-network' ) . '</p>';
		}
		$form = (string) apply_filters( 'dirnet_social_report_form', '', get_current_user_id() );
		return '' !== $form ? $form : '<p>' . esc_html__( 'The social report form is not connected yet.', 'directorist-network' ) . '</p>';
	}

	/**
	 * Directorist's generic "Add listing" page cannot be deleted (it also serves the editing of an entry), but creating
	 * a new entry no longer happens there: it goes to the page of the kind (producer, GAS, network), or to the choice.
	 */
	public function generic_add() {
		$add = function_exists( 'get_directorist_option' ) ? (int) get_directorist_option( 'add_listing_page' ) : 0;
		if ( ! $add || ! is_page( $add ) || 'GET' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET' ) ) {
			return;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( false !== strpos( $uri, '/edit/' ) ) {
			return;
		}
		foreach ( array( 'listing_id', 'post_id', 'atbdp_action', 'p', 'action' ) as $k ) {
			if ( isset( $_GET[ $k ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				return; // editing, or a Directorist action
			}
		}
		$to = 0;
		if ( ! $to ) {
			$to = (int) get_option( 'dirnet_myspace_page' ) ? (int) get_option( 'dirnet_myspace_page' ) : (int) get_option( 'dirnet_enter_page' );
		}
		if ( $to && $to !== $add ) {
			wp_safe_redirect( (string) get_permalink( $to ) );
			exit;
		}
	}

	/** Social report, entity profile and product profile pages are for signed-in users only: others are sent to the sign-in page. */
	public function gate() {
		if ( is_user_logged_in() || ! is_page() || ! empty( $_GET['solidaros_token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- a one-time Solidaros link is checked by Solidaros itself
			return;
		}
		$here = get_queried_object_id();
		$gate = array( // the tool pages (Tools, social report, company, products, source price, price list, PGS) are public: they show their description and the "Accesso riservato" button themselves
			'add'     => function_exists( 'get_directorist_option' ) ? (int) get_directorist_option( 'add_listing_page' ) : 0, // listing creation
			'e0'      => self::id( 'entity_start' ),
			'n0'      => self::id( 'entity_next' ),
			'n1'      => self::id( 'entity_assign' ),
			'eval'    => (int) get_option( 'dirnet_evaluator_page' ), // reviewer access: sign-in/registration first, the sm_evaluator role is granted by an admin
		);
		foreach ( $gate as $key => $pid ) {
			if ( $here && $pid && $pid === $here ) {
				$to = self::id( 'login' ) ? get_permalink( self::id( 'login' ) ) : wp_login_url();
				wp_safe_redirect( add_query_arg( 'redirect_to', rawurlencode( get_permalink( $here ) ), $to ) );
				exit;
			}
		}
	}

	/** [dirnet_login]: Solidaros' sign-in ([so-login], also for people without an account) when present, else the WordPress form. */
	public function login() {
		if ( shortcode_exists( 'so-login' ) ) {
			return do_shortcode( '[so-login]' );
		}
		if ( is_user_logged_in() ) {
			return '<p>' . esc_html__( 'You are signed in.', 'directorist-network' ) . ' <a href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">' . esc_html__( 'Sign out', 'directorist-network' ) . '</a></p>';
		}
		return wp_login_form( array( 'echo' => false, 'redirect' => home_url( '/' ) ) );
	}

	/** Number of published entities in a category (children included). */
	private static function count( $term_id ) {
		$q = new \WP_Query( array(
			'post_type'      => ATBDP_POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'tax_query'      => array( array( 'taxonomy' => ATBDP_CATEGORY, 'field' => 'term_id', 'terms' => (int) $term_id, 'include_children' => true ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
		) );
		return (int) $q->found_posts;
	}

	/** Icon + colour of a category (term meta set by CategoryIcons; subcategories fall back to their parent). */
	private static function look( $term ) {
		$icon  = (string) get_term_meta( $term->term_id, 'category_icon', true );
		$color = (string) get_term_meta( $term->term_id, CategoryIcons::COLOR, true );
		if ( $term->parent && ( ! $icon || ! $color ) ) {
			$icon  = $icon ? $icon : (string) get_term_meta( $term->parent, 'category_icon', true );
			$color = $color ? $color : (string) get_term_meta( $term->parent, CategoryIcons::COLOR, true );
		}
		return array( $icon ? $icon : 'las la-tag', preg_match( '/^#[0-9a-f]{3,8}$/i', $color ) ? $color : '#546e7a' );
	}

	/** Categories (of a parent) that have at least one published entity, children included. */
	private static function filled( $parent ) {
		$out  = array();
		$list = get_terms( array( 'taxonomy' => ATBDP_CATEGORY, 'parent' => (int) $parent, 'hide_empty' => false, 'orderby' => 'name' ) );
		foreach ( is_wp_error( $list ) ? array() : $list as $t ) {
			$n = self::count( $t->term_id );
			if ( $n > 0 ) {
				$out[] = array( $t, $n );
			}
		}
		return $out;
	}

	/**
	 * [dirnet_categories]: without ?cat= an icon tile for every category that has content; with ?cat=slug the category page:
	 * its list, then one list per subcategory that has content.
	 */
	public function categories() {
		$h = $this->categories_html();
		return '' === trim( (string) $h ) ? '' : '<div class="dirnet-block">' . $h . '</div>';
	}

	private function categories_html() {
		if ( ! Generator::directorist_ready() || ! taxonomy_exists( ATBDP_CATEGORY ) ) {
			return '';
		}
		$cp  = self::id( 'categories' );
		$url = $cp ? get_permalink( $cp ) : '';
		if ( '' === $url ) {
			return '';
		}
		$css = '<style>' . Entities::tile_css()
			. '.dirnet-cathead i{display:inline-flex;align-items:center;justify-content:center;width:60px;height:60px;border-radius:12px;color:#fff;font-size:30px}.dirnet-cathead small{opacity:.7}'
			. '.dirnet-cathead{display:flex;align-items:center;gap:14px;margin:8px 0 16px}.dirnet-cathead h2{margin:0}</style>';
		$slug = sanitize_title( (string) get_query_var( 'dirnet_cat' ) );
		if ( '' === $slug && isset( $_GET['cat'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$slug = sanitize_title( wp_unslash( $_GET['cat'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		$term = $slug ? get_term_by( 'slug', $slug, ATBDP_CATEGORY ) : false;

		if ( ! $term ) { // index
			$tops = self::filled( 0 );
			if ( ! $tops ) {
				return '<p>' . esc_html__( 'No categories with content yet.', 'directorist-network' ) . '</p>';
			}
			$h = $css . '<div class="dirnet-cats">';
			foreach ( $tops as $row ) {
				list( $t, $n ) = $row;
				list( $icon, $color ) = self::look( $t );
				$h .= Entities::tile( self::cat_url( $url, $t->slug ), '<i class="' . esc_attr( $icon ) . '" aria-hidden="true"></i>', $color, $t->name, (string) (int) $n, 'dirnet-cat' );
			}
			return $h . '</div>';
		}

		// category page
		list( $icon, $color ) = self::look( $term );
		$h  = $css . '<p><a href="' . esc_url( $url ) . '">&larr; ' . esc_html__( 'All categories', 'directorist-network' ) . '</a>';
		if ( $term->parent && ( $par = get_term( $term->parent, ATBDP_CATEGORY ) ) && ! is_wp_error( $par ) ) {
			$h .= ' / <a href="' . esc_url( self::cat_url( $url, $par->slug ) ) . '">' . esc_html( $par->name ) . '</a>';
		}
		$h .= '</p><div class="dirnet-cathead"><i class="' . esc_attr( $icon ) . '" style="background:' . esc_attr( $color ) . '" aria-hidden="true"></i><h2>' . esc_html( $term->name ) . '</h2><small>' . (int) self::count( $term->term_id ) . '</small></div>';
		if ( $term->description ) {
			$h .= '<p>' . esc_html( $term->description ) . '</p>';
		}
		$subs = self::filled( $term->term_id );
		if ( $subs ) { // tiles of the subcategories: the way into them
			$h .= '<div class="dirnet-cats">';
			foreach ( $subs as $row ) {
				list( $t, $n ) = $row;
				list( $ic, $co ) = self::look( $t );
				$h .= Entities::tile( self::cat_url( $url, $t->slug ), '<i class="' . esc_attr( $ic ) . '" aria-hidden="true"></i>', $co, $t->name, (string) (int) $n, 'dirnet-cat' );
			}
			$h .= '</div>';
		}
		// the list shows every entity of the category, those of its subcategories included
		$h .= do_shortcode( '[dirnet_entities category="' . esc_attr( $term->slug ) . '" filters="0" bar="1" tiles="0"]' );
		return $h;
	}
}
