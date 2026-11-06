<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies a profile to the site: seeds categories/tags/locations and creates
 * (or updates) one Directorist directory type per profile type, through
 * Directorist's own Multi_Directory_Manager::add_directory() — the same call
 * its setup wizard and its "import directory" screen use.
 */
class Generator {

	const OPTION = 'dirnet_applied';

	public static function directorist_ready() {
		return class_exists( '\Directorist\Multi_Directory\Multi_Directory_Manager' )
			&& defined( 'DIRECTORIST_ASSETS_DIR' )
			&& defined( 'ATBDP_CATEGORY' );
	}

	public static function applied() {
		$o = get_option( self::OPTION, array() );
		return is_array( $o ) ? $o : array();
	}

	/** @return string[] human-readable log lines; lines starting with "!" are errors */
	/** Category term IDs seeded/found for the profile being applied. */
	private static $category_ids = array();

	/**
	 * @param bool $overwrite When false (default) directory types that already exist are left untouched, so
	 *                        fields added/removed by the admin in the Directory Builder are never lost.
	 */
	public static function apply( Profile $profile, $overwrite = false ) {
		self::$category_ids = array();
		$log = array();
		if ( ! self::directorist_ready() ) {
			return array( '!' . __( 'Directorist is not active or is too old.', 'directorist-network' ) );
		}

		$log = array_merge( $log, self::seed_terms( $profile ) );

		$builder = new DirectoryBuilder( DIRECTORIST_ASSETS_DIR );
		$manager = '\Directorist\Multi_Directory\Multi_Directory_Manager';
		$manager::load_builder_data();

		$applied = self::applied();
		$mine    = isset( $applied[ $profile->id ] ) ? (array) $applied[ $profile->id ] : array();

		foreach ( $profile->types() as $slug => $def ) {
			$name   = $profile->t( isset( $def['label'] ) ? $def['label'] : $slug );
			$config = $builder->build( $profile, $slug );
			if ( ! $config ) {
				$log[] = '!' . sprintf( /* translators: %s: type name */ __( '%s: could not read Directorist\'s sample files.', 'directorist-network' ), $name );
				continue;
			}
			$term_id = isset( $mine[ $slug ] ) ? (int) $mine[ $slug ] : 0;
			if ( $term_id && ! term_exists( $term_id, 'atbdp_listing_types' ) ) {
				$term_id = 0;
			}
			if ( $term_id && ! $overwrite ) {
				$log[] = sprintf( /* translators: %s: type name */ __( '%s: already exists, left untouched (your changes in the Directory Builder are kept).', 'directorist-network' ), $name );
				continue;
			}
			$res = $manager::add_directory(
				array(
					'term_id'        => $term_id,
					'directory_name' => $name,
					'fields_value'   => $config,
					'is_json'        => false,
				)
			);
			if ( ! empty( $res['status']['success'] ) ) {
				$mine[ $slug ] = (int) $res['term_id'];
				self::assign_categories( $profile, $def, (int) $res['term_id'] );
				$log[]         = sprintf( /* translators: %s: type name */ __( '%s: catalogue type saved.', 'directorist-network' ), $name );
			} elseif ( ! empty( $res['status']['term_id'] ) ) {
				// Name already exists (made by hand): do not overwrite it silently.
				$log[] = '!' . sprintf( /* translators: %s: type name */ __( '%s: a catalogue type with this name already exists and was not created by this plugin; rename or delete it, then retry.', 'directorist-network' ), $name );
			} else {
				$msgs = array();
				foreach ( isset( $res['status']['status_log'] ) ? $res['status']['status_log'] : array() as $l ) {
					$msgs[] = isset( $l['message'] ) ? $l['message'] : '';
				}
				$log[] = '!' . $name . ': ' . implode( ' ', array_filter( $msgs ) );
			}
		}

		$applied[ $profile->id ] = $mine;
		update_option( self::OPTION, $applied, false );

		if ( ! empty( $mine ) && Territory::ensure_page() ) {
			$log[] = __( 'Territory page ready (shortcode [dirnet_territory]).', 'directorist-network' );
		}
		if ( count( $mine ) > 1 ) {
			update_option( 'atbdp_has_multidirectory', true );
			if ( function_exists( 'update_directorist_option' ) ) {
				update_directorist_option( 'enable_multi_directory', 1 );
			}
		}
		return $log;
	}

	/** Make the profile's categories belong to the types that offer a category field (as Directorist's importer does). */
	private static function assign_categories( Profile $profile, array $def, $dir_term_id ) {
		$presets = isset( $def['presets'] ) ? $def['presets'] : DirectoryBuilder::default_presets();
		$has     = false;
		foreach ( $presets as $p ) {
			if ( 'category' === $p['name'] ) {
				$has = true;
			}
		}
		if ( ! $has || ! function_exists( 'directorist_update_term_directory' ) ) {
			return;
		}
		foreach ( array_unique( self::$category_ids ) as $tid ) {
			directorist_update_term_directory( $tid, array( $dir_term_id ), true );
		}
	}

	private static function seed_terms( Profile $profile ) {
		$log  = array();
		$map  = array(
			'categories' => ATBDP_CATEGORY,
			'tags'       => defined( 'ATBDP_TAGS' ) ? ATBDP_TAGS : '',
			'locations'  => defined( 'ATBDP_LOCATION' ) ? ATBDP_LOCATION : '',
		);
		foreach ( $map as $key => $tax ) {
			if ( ! $tax || ! taxonomy_exists( $tax ) || empty( $profile->data[ $key ] ) && ! ( 'categories' === $key && null !== Categories::stored() ) ) {
				continue;
			}
			$n     = 0;
			$items = (array) $profile->data[ $key ];
			if ( 'categories' === $key ) {
				$own = Categories::stored(); // the admin's own list replaces the profile's example categories
				if ( null !== $own ) {
					$items = $own;
					$log[] = __( 'Categories come from your saved list (Network categories).', 'directorist-network' );
				}
			}
			$walk = function ( $list, $parent_id ) use ( &$walk, $profile, $tax, &$n ) {
				foreach ( $list as $item ) {
					$name = is_array( $item ) ? $item['name'] : $item;
					$id   = self::ensure_term( $profile->t( $name ), $tax, $parent_id, $n );
					if ( ATBDP_CATEGORY === $tax && $id ) {
						self::$category_ids[] = $id;
					}
					if ( $id && is_array( $item ) && ! empty( $item['children'] ) ) {
						$walk( (array) $item['children'], $id );
					}
				}
			};
			$walk( $items, 0 );
			$log[] = sprintf( /* translators: 1: number of terms, 2: kind */ __( '%1$d new %2$s created.', 'directorist-network' ), $n, $key );
		}
		return $log;
	}

	private static function ensure_term( $name, $tax, $parent, &$created ) {
		$existing = term_exists( $name, $tax, $parent );
		if ( $existing ) {
			return (int) ( is_array( $existing ) ? $existing['term_id'] : $existing );
		}
		$t = wp_insert_term( $name, $tax, $parent ? array( 'parent' => $parent ) : array() );
		if ( is_wp_error( $t ) ) {
			return 0;
		}
		$created++;
		return (int) $t['term_id'];
	}
}
