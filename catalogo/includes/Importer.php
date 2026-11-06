<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates / updates Directorist listings from CSV rows, following what
 * Directorist's own importer (class-tools.php, handle_import_listings) writes:
 * post type at_biz_dir, meta "_directory_type" + term in ATBDP_DIRECTORY_TYPE,
 * field values in post meta "_<field_key>", terms for category/location/tags,
 * categories and locations assigned to the directory type.
 */
class Importer {

	private $profile;
	private $slug;
	private $dir_id;
	private $cols;
	private $key_col;
	private $opts;
	private $cache = array();

	public function __construct( Profile $profile, $slug, $dir_id, array $opts = array() ) {
		$this->profile = $profile;
		$this->slug    = $slug;
		$this->dir_id  = (int) $dir_id;
		$this->cols    = ColumnMap::for_type( $profile, $slug );
		$this->opts    = $opts + array( 'status' => 'publish', 'create_terms' => true, 'geocode' => false, 'images' => false );

		$types = $profile->types();
		$def   = isset( $types[ $slug ] ) ? $types[ $slug ] : array();
		$key   = isset( $def['key'] ) ? $def['key'] : ( isset( $profile->data['key'] ) ? $profile->data['key'] : 'title' );
		$this->key_col = isset( $this->cols[ $key ] ) ? $key : 'title';
	}

	public function columns() {
		return $this->cols;
	}

	/**
	 * Import $rows[$from .. ) until the time budget runs out.
	 *
	 * @param array $header_map column index => canonical key|null
	 * @return array{next:int,created:int,updated:int,errors:string[]}
	 */
	public function run( array $rows, array $header_map, $from, $seconds = 20 ) {
		$t0 = microtime( true );
		$r  = array( 'next' => $from, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => array() );
		$n  = count( $rows );

		Owners::$importing = true;
		for ( $i = $from; $i < $n; $i++ ) {
			if ( $i > $from && ( microtime( true ) - $t0 ) > $seconds ) {
				break;
			}
			$line = array();
			foreach ( $header_map as $idx => $key ) {
				if ( $key && isset( $rows[ $i ][ $idx ] ) ) {
					$line[ $key ] = trim( (string) $rows[ $i ][ $idx ] );
				}
			}
			$res = $this->import_row( $line );
			if ( is_wp_error( $res ) ) {
				$r['errors'][] = sprintf( /* translators: 1: CSV row number, 2: message */ __( 'Row %1$d: %2$s', 'directorist-network' ), $i + 2, $res->get_error_message() );
			} elseif ( 'skipped' === $res ) {
				$r['skipped']++;
			} elseif ( 'created' === $res ) {
				$r['created']++;
			} else {
				$r['updated']++;
			}
			$r['next'] = $i + 1;
		}
		Owners::$importing = false;
		return $r;
	}

	private function import_row( array $line ) {
		$title = isset( $line['title'] ) ? $line['title'] : '';
		if ( '' === $title ) {
			return new \WP_Error( 'no_title', __( 'missing title.', 'directorist-network' ) );
		}
		// Separate region / province / city columns become one hierarchical location path.
		if ( empty( $line['location'] ) ) {
			$geo = array();
			foreach ( array( 'region', 'province', 'city', 'district' ) as $g ) {
				if ( ! empty( $line[ $g ] ) ) {
					$geo[] = trim( $line[ $g ] );
				}
			}
			if ( $geo ) {
				$line['location'] = implode( ' > ', $geo );
			}
		}
		$post_id = $this->find_existing( $line );
		$args    = array(
			'post_title'  => wp_strip_all_tags( $title ),
			'post_type'   => ATBDP_POST_TYPE,
			'post_status' => $this->opts['status'],
		);
		if ( isset( $line['description'] ) ) {
			$args['post_content'] = wp_kses_post( $line['description'] );
		}
		if ( isset( $line['excerpt'] ) ) {
			$args['post_excerpt'] = wp_strip_all_tags( $line['excerpt'] );
		}
		if ( $post_id && ( ! empty( $this->opts['link_only'] ) || ( get_post_meta( $post_id, Owners::EDITED, true ) && empty( $this->opts['overwrite'] ) ) ) ) {
			// Already there (edited by its owner, or "link only"): the CSV changes nothing, it only adds the relations (e.g. the campaign).
			foreach ( $this->cols as $key => $col ) {
				if ( 'relation' === $col['kind'] && ! empty( $line[ $key ] ) ) {
					$new = preg_split( '/\s*[;|]\s*/', $line[ $key ], -1, PREG_SPLIT_NO_EMPTY );
					$old = (array) get_post_meta( $post_id, '_dirnet_pending_' . $key, true );
					Relations::add_pending( $post_id, $key, array_merge( array_filter( $old ), $new ) );
				}
			}
			return 'skipped';
		}
		if ( $post_id ) {
			$args['ID'] = $post_id;
			$id         = wp_update_post( $args, true );
			$result     = 'updated';
		} else {
			$id     = wp_insert_post( $args, true );
			$result = 'created';
		}
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		update_post_meta( $id, '_directory_type', $this->dir_id );
		wp_set_object_terms( $id, $this->dir_id, ATBDP_DIRECTORY_TYPE );
		if ( 'created' === $result ) {
			if ( function_exists( 'calc_listing_expiry_date' ) ) {
				update_post_meta( $id, '_expiry_date', calc_listing_expiry_date( '', '', $this->dir_id ) );
			}
			update_post_meta( $id, '_featured', 0 );
			update_post_meta( $id, '_listing_status', 'post_status' );
		}

		foreach ( $this->cols as $key => $col ) {
			if ( ! isset( $line[ $key ] ) ) {
				continue;
			}
			$raw = $line[ $key ];
			switch ( $col['kind'] ) {
				case 'meta':
					update_post_meta( $id, '_' . $col['meta'], sanitize_text_field( $raw ) );
					break;
				case 'choice':
					update_post_meta( $id, '_' . $col['meta'], ColumnMap::choice_value( $col, $raw ) );
					break;
				case 'tax_category':
					$this->set_terms( $id, ATBDP_CATEGORY, $raw, true );
					break;
				case 'geo':
					break; // folded into "location" above
				case 'tax_location':
					$this->set_terms( $id, ATBDP_LOCATION, $raw, true );
					break;
				case 'tax_tag':
					$this->set_terms( $id, ATBDP_TAGS, $raw, false );
					break;
				case 'tax_function':
					Functions::assign( $id, $raw, $this->slug );
					break;
				case 'relation':
					Relations::add_pending( $id, $key, preg_split( '/\s*[;|]\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY ) );
					break;
				case 'image':
					if ( ! empty( $this->opts['images'] ) && '' !== $raw ) {
						$this->images( $id, $raw );
					}
					break;
			}
		}
		foreach ( $line as $k => $v ) {
			if ( 0 === strpos( $k, 'fdesc:' ) ) {
				Functions::set_desc( $id, substr( $k, 6 ), $v );
			}
		}
		if ( ! isset( $line['functions'] ) ) {
			Functions::assign( $id, '', $this->slug ); // none given: the function of the directory type
		}
		if ( ! empty( $line['email'] ) ) {
			Owners::attach( $id, $line['email'] );
		}

		if ( ! empty( $this->opts['geocode'] ) && ! get_post_meta( $id, '_manual_lat', true ) ) {
			$this->geocode( $id, $line );
		}
		return $result;
	}

	private static $names = null;

	/** Name without case, punctuation and legal words ("Azienda agricola", "srl"...). */
	private static function norm( $t ) {
		$t = remove_accents( html_entity_decode( (string) $t, ENT_QUOTES, 'UTF-8' ) );
		$t = strtolower( preg_replace( '/[^a-zA-Z0-9 ]+/', ' ', $t ) );
		$t = preg_replace( '/\b(azienda|az|agricola|agr|agricoltura|societa|soc|societ|cooperativa|coop|sociale|srl|sas|snc|ss|ssa|sa|spa|di|del|della|dei|de|e|l|la|il|lo|le|the)\b/', ' ', $t );
		return preg_replace( '/\s+/', '', $t );
	}

	/** An entity of any directory type whose normalised name is the same (the same business entered with other wording or type). */
	private function similar( $title ) {
		if ( null === self::$names ) {
			global $wpdb;
			self::$names = array();
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft')", ATBDP_POST_TYPE ) ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$n = self::norm( $r->post_title );
				if ( strlen( $n ) >= 5 && ! isset( self::$names[ $n ] ) ) {
					self::$names[ $n ] = (int) $r->ID;
				}
			}
		}
		$n = self::norm( $title );
		return strlen( $n ) >= 5 && isset( self::$names[ $n ] ) ? self::$names[ $n ] : 0;
	}

	/** Existing listing of this directory type with the same key value. */
	private function find_existing( array $line ) {
		if ( empty( $line[ $this->key_col ] ) ) {
			// No tax code in the row: recognise the entity by its name, so importing the file again does not duplicate it.
			if ( empty( $line['title'] ) ) {
				return 0;
			}
			$id = self::lookup( $this->profile, $this->slug, $this->dir_id, $line['title'], false );
			return $id ? $id : $this->similar( $line['title'] );
		}
		return self::lookup( $this->profile, $this->slug, $this->dir_id, $line[ $this->key_col ], true );
	}

	/**
	 * Find a listing of a directory type by its key field (e.g. tax_code) and,
	 * unless $key_only, by title as a fallback (used to resolve relations).
	 */
	public static function lookup( Profile $profile, $slug, $dir_id, $value, $key_only = false ) {
		global $wpdb;
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return 0;
		}
		$cols = ColumnMap::for_type( $profile, $slug );
		$types = $profile->types();
		$def   = isset( $types[ $slug ] ) ? $types[ $slug ] : array();
		$kc    = isset( $def['key'] ) ? $def['key'] : ( isset( $profile->data['key'] ) ? $profile->data['key'] : 'title' );
		$kc    = isset( $cols[ $kc ] ) ? $kc : 'title';
		$base  = "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_directory_type' AND m.meta_value = %d";
		$where = " WHERE p.post_type = %s AND p.post_status NOT IN ('trash','auto-draft')";
		$id    = 0;
		if ( 'title' !== $kc ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( $base . " INNER JOIN {$wpdb->postmeta} k ON k.post_id = p.ID AND k.meta_key = %s AND k.meta_value = %s" . $where . ' LIMIT 1', $dir_id, '_' . $cols[ $kc ]['meta'], $value, ATBDP_POST_TYPE ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		if ( ! $id && ( 'title' === $kc || ! $key_only ) ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( $base . $where . ' AND p.post_title = %s LIMIT 1', $dir_id, ATBDP_POST_TYPE, $value ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		return $id;
	}

	/** Download image URLs into the media library: first = preview, the others = gallery. */
	private function images( $post_id, $raw ) {
		if ( get_post_meta( $post_id, '_listing_prv_img', true ) ) {
			return; // keep what is already there (re-imports must not duplicate files)
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$ids = array();
		foreach ( preg_split( '/\s*[;|]\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY ) as $url ) {
			if ( ! preg_match( '#^https?://#i', $url ) ) {
				continue;
			}
			$tmp = download_url( $url, 20 );
			if ( is_wp_error( $tmp ) ) {
				continue;
			}
			$name = basename( wp_parse_url( $url, PHP_URL_PATH ) );
			$aid  = media_handle_sideload( array( 'name' => $name ? $name : 'image.jpg', 'tmp_name' => $tmp ), $post_id );
			if ( is_wp_error( $aid ) ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				continue;
			}
			$ids[] = (int) $aid;
		}
		if ( $ids ) {
			update_post_meta( $post_id, '_listing_prv_img', $ids[0] );
			array_shift( $ids );
			if ( $ids ) {
				update_post_meta( $post_id, '_listing_img', $ids );
			}
		}
	}

	private function set_terms( $post_id, $tax, $raw, $assign_directory ) {
		$ids = array();
		foreach ( ColumnMap::term_paths( $raw ) as $path ) {
			if ( ATBDP_LOCATION === $tax ) { // place it exactly in region > province > municipality of the GitHub list
				$loc = Geo::locate( $path );
				if ( $loc ) {
					$ids[] = $loc;
					continue;
				}
			}
			$parent = 0;
			foreach ( $path as $name ) {
				$tid = $this->term( $name, $tax, $parent, $assign_directory );
				if ( ! $tid ) {
					$parent = 0;
					break;
				}
				$parent = $tid;
			}
			if ( $parent ) {
				$ids[] = $parent; // the leaf
			}
		}
		if ( $ids ) {
			wp_set_object_terms( $post_id, $ids, $tax, false );
		}
	}

	private function term( $name, $tax, $parent, $assign_directory ) {
		$ck = $tax . '|' . $parent . '|' . strtolower( $name );
		if ( isset( $this->cache[ $ck ] ) ) {
			return $this->cache[ $ck ];
		}
		if ( ATBDP_LOCATION === $tax && $parent ) { // a province under a region is written "Bologna (BO)", as in the GitHub list
			$pt = get_term( $parent, ATBDP_LOCATION );
			if ( $pt && ! is_wp_error( $pt ) && ! $pt->parent ) {
				$lab = Geo::province_label( $pt->name, $name );
				if ( '' !== $lab ) {
					$name = $lab;
					$ck   = $tax . '|' . $parent . '|' . strtolower( $name );
					if ( isset( $this->cache[ $ck ] ) ) {
						return $this->cache[ $ck ];
					}
				}
			}
		}
		$found = get_terms( array( 'taxonomy' => $tax, 'name' => $name, 'parent' => $parent, 'hide_empty' => false, 'number' => 1, 'fields' => 'ids' ) );
		$tid   = ( ! is_wp_error( $found ) && $found ) ? (int) $found[0] : 0;
		if ( ! $tid && $this->opts['create_terms'] ) {
			$t   = wp_insert_term( $name, $tax, $parent ? array( 'parent' => $parent ) : array() );
			$tid = is_wp_error( $t ) ? 0 : (int) $t['term_id'];
		}
		if ( $tid && $assign_directory && function_exists( 'directorist_update_term_directory' ) ) {
			directorist_update_term_directory( $tid, array( $this->dir_id ), true );
		}
		return $this->cache[ $ck ] = $tid;
	}

	/** OpenStreetMap Nominatim, max 1 request/second, results cached 30 days. */
	private function geocode( $post_id, array $line ) {
		$q = array();
		foreach ( array( 'address', 'zip' ) as $k ) {
			if ( ! empty( $line[ $k ] ) ) {
				$q[] = $line[ $k ];
			}
		}
		if ( ! empty( $line['location'] ) ) {
			$paths = ColumnMap::term_paths( $line['location'] );
			if ( $paths ) {
				$q[] = implode( ', ', array_reverse( $paths[0] ) );
			}
		}
		if ( ! $q ) {
			return;
		}
		$query = implode( ', ', $q );
		$ck    = 'dirnet_geo_' . md5( $query );
		$hit   = get_transient( $ck );
		if ( false === $hit ) {
			usleep( 1100000 );
			$res = wp_remote_get(
				add_query_arg( array( 'format' => 'json', 'limit' => 1, 'q' => $query ), 'https://nominatim.openstreetmap.org/search' ),
				array( 'timeout' => 10, 'user-agent' => 'DirectoristNetwork/' . DIRNET_VERSION . ' (WordPress)' )
			);
			$hit = array();
			if ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) ) {
				$j = json_decode( wp_remote_retrieve_body( $res ), true );
				if ( ! empty( $j[0]['lat'] ) ) {
					$hit = array( (string) $j[0]['lat'], (string) $j[0]['lon'] );
				}
			}
			set_transient( $ck, $hit, 30 * DAY_IN_SECONDS );
		}
		if ( $hit ) {
			update_post_meta( $post_id, '_manual_lat', $hit[0] );
			update_post_meta( $post_id, '_manual_lng', $hit[1] );
		}
	}
}
