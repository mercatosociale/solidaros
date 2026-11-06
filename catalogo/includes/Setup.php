<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-click site setup: applies the chosen profiles, makes the first type the default,
 * removes Directorist's empty starter "General" type, sets the map provider, builds a menu.
 */
class Setup {

	/**
	 * @param string[] $profile_ids Profiles to apply, in order.
	 * @param array    $opts        remove_general, osm_map, menu (bool).
	 * @return string[] log lines; lines starting with "!" are errors/warnings.
	 */
	public static function run( array $profile_ids, array $opts ) {
		$log = array();
		if ( ! Generator::directorist_ready() ) {
			return array( '!' . __( 'Directorist is not active or is too old.', 'directorist-network' ) );
		}
		$first_term = 0;
		foreach ( $profile_ids as $id ) {
			$p = Profile::get( $id );
			if ( ! $p ) {
				continue;
			}
			if ( ! empty( $opts['skip_applied'] ) && ! empty( Generator::applied()[ $id ] ) ) {
				$log[] = sprintf( /* translators: %s: profile label */ __( '%s: already generated (use "Regenerate missing types" or "Restore defaults").', 'directorist-network' ), $p->label() );
				continue;
			}
			$log   = array_merge( $log, Generator::apply( $p, ! empty( $opts['overwrite'] ) ) );
			$mine  = Generator::applied();
			if ( ! $first_term && ! empty( $mine[ $id ] ) ) {
				$first_term = (int) reset( $mine[ $id ] );
			}
		}

		$total = 0;
		foreach ( Generator::applied() as $types ) {
			$total += count( (array) $types );
		}
		if ( $total > 1 ) {
			update_option( 'atbdp_has_multidirectory', true );
			if ( function_exists( 'update_directorist_option' ) ) {
				update_directorist_option( 'enable_multi_directory', 1 );
			}
		}

		if ( $first_term ) {
			self::set_default( $first_term );
			$log[] = __( 'Default catalogue type set.', 'directorist-network' );
		}
		if ( ! empty( $opts['remove_general'] ) ) {
			$log[] = self::remove_general( $first_term );
		}
		if ( ! empty( $opts['osm_map'] ) && function_exists( 'update_directorist_option' ) ) {
			update_directorist_option( 'select_listing_map', 'openstreet' );
			$log[] = __( 'Map provider set to OpenStreetMap (no API key needed).', 'directorist-network' );
		}
		if ( ! empty( $opts['self_register'] ) ) {
			$log[] = Register::configure();
			if ( Register::ensure_page() ) {
				$log[] = __( 'Registration page ready (shortcode [dirnet_register]).', 'directorist-network' );
			}
		}
		$log[] = Geo::refresh();
		Functions::seed();
		$icons = CategoryIcons::apply_all();
		if ( $icons ) {
			$log[] = $icons;
		}
		foreach ( Territories::seed() as $line ) {
			$log[] = $line;
		}
		$gs = Territories::seed_geo( 20 );
		$log[] = $gs[3];
		if ( $gs[0] ) {
			delete_option( 'dirnet_geo_seed_pending' );
		} else {
			update_option( 'dirnet_geo_seed_pending', 1, false ); // the Settings page goes on by itself until the load is complete
		}
		Territory::ensure_page();
		foreach ( Pages::ensure_all() as $line ) {
			$log[] = $line;
		}
		if ( Entities::ensure_page() ) {
			$log[] = __( 'Entities page ready (shortcode [dirnet_entities]).', 'directorist-network' );
		}
		if ( ! empty( $opts['menu'] ) ) {
			$log[] = self::menu();
		}
		flush_rewrite_rules( false );
		$log[] = __( 'Setup finished. Next: import your data from Import / export data.', 'directorist-network' );
		return $log;
	}

	private static function set_default( $term_id ) {
		$terms = get_terms( array( 'taxonomy' => 'atbdp_listing_types', 'hide_empty' => false ) );
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				update_term_meta( $t->term_id, '_default', (int) $t->term_id === (int) $term_id );
			}
		}
		if ( function_exists( 'update_directorist_option' ) ) {
			update_directorist_option( 'atbdp_default_derectory', (int) $term_id );
		}
	}

	/** Same safety rule as Directorist's own setup wizard: only when it holds no listings. */
	private static function remove_general( $keep ) {
		$g = get_term_by( 'slug', 'general', 'atbdp_listing_types' );
		if ( ! $g || is_wp_error( $g ) ) {
			return __( 'No starter "General" catalogue type found.', 'directorist-network' );
		}
		if ( (int) $g->term_id === (int) $keep ) {
			return '!' . __( 'The "General" type was left in place because it is the default.', 'directorist-network' );
		}
		$ids = get_objects_in_term( $g->term_id, 'atbdp_listing_types' );
		if ( is_wp_error( $ids ) || ! empty( $ids ) || ! empty( $g->count ) ) {
			return '!' . __( 'The "General" type contains listings, so it was NOT deleted. Move or delete them, then run the setup again.', 'directorist-network' );
		}
		wp_delete_term( $g->term_id, 'atbdp_listing_types' );
		return __( 'Starter "General" catalogue type deleted.', 'directorist-network' );
	}

	private static function add_item( $menu_id, $title, $url, $parent = 0, $page_id = 0 ) {
		$args = array(
			'menu-item-title'     => $title,
			'menu-item-status'    => 'publish',
			'menu-item-parent-id' => $parent,
		);
		if ( $page_id ) {
			$args += array( 'menu-item-object-id' => $page_id, 'menu-item-object' => 'page', 'menu-item-type' => 'post_type' );
		} else {
			$args += array( 'menu-item-url' => $url, 'menu-item-type' => 'custom' );
		}
		$id = wp_update_nav_menu_item( $menu_id, 0, $args );
		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/** Top-level terms of a taxonomy, as a flat list. */
	private static function top_terms( $taxonomy ) {
		$t = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'parent' => 0 ) );
		return is_wp_error( $t ) ? array() : $t;
	}

	/** Simple menu: Home, Entities, Territories, Categories (plain links), then Register. */
	private static function menu() {
		$ent = (int) get_option( Entities::PAGE_OPTION );
		$ter = (int) get_option( Territory::PAGE_OPTION );
		if ( ! $ent && ! $ter ) {
			return '!' . __( 'No pages found for the menu.', 'directorist-network' );
		}
		$name = __( 'Catalogue', 'directorist-network' );
		$existing = wp_get_nav_menu_object( $name );
		if ( ! $existing ) {
			$existing = wp_get_nav_menu_object( __( 'Directory', 'directorist-network' ) ); // menu created by earlier versions
		}
		if ( $existing ) { // left as it is
			return __( 'Menu "Catalogue" already exists; left unchanged.', 'directorist-network' );
		}
		$menu_id = wp_create_nav_menu( $name );
		if ( is_wp_error( $menu_id ) ) {
			return '!' . $menu_id->get_error_message();
		}
		self::add_item( $menu_id, __( 'Home', 'directorist-network' ), home_url( '/' ) );
		if ( Pages::id( 'about' ) ) {
			self::add_item( $menu_id, '', '', 0, Pages::id( 'about' ) );
		}

		if ( $ent && get_post( $ent ) ) {
			self::add_item( $menu_id, __( 'Entities', 'directorist-network' ), '', 0, $ent );
		}
		if ( $ter && get_post( $ter ) ) {
			self::add_item( $menu_id, __( 'Territories', 'directorist-network' ), '', 0, $ter ); // one plain link: the Territories page shows the tiles
		}
		if ( $ent && get_post( $ent ) ) {
			if ( Pages::id( 'categories' ) ) { // one plain link: the Categories page shows the icons
				self::add_item( $menu_id, '', '', 0, Pages::id( 'categories' ) );
			} else {
				self::add_item( $menu_id, __( 'Categories', 'directorist-network' ), get_permalink( $ent ) );
			}
		}
		$reg = (int) get_option( Register::PAGE_OPTION );
		if ( $reg && get_post( $reg ) ) {
			self::add_item( $menu_id, '', '', 0, $reg );
		}
		if ( Pages::id( 'login' ) ) {
			self::add_item( $menu_id, '', '', 0, Pages::id( 'login' ) ); // right after Membership
		}
		$locs = get_nav_menu_locations();
		foreach ( array_keys( get_registered_nav_menus() ) as $loc ) {
			if ( empty( $locs[ $loc ] ) ) {
				$locs[ $loc ] = $menu_id;
				set_theme_mod( 'nav_menu_locations', $locs );
				return sprintf( /* translators: %s: menu location */ __( 'Menu "Catalogue" created and assigned to location "%s".', 'directorist-network' ), $loc );
			}
		}
		return __( 'Menu "Catalogue" created (all theme locations were already in use; assign it in Appearance → Menus).', 'directorist-network' );
	}
}
