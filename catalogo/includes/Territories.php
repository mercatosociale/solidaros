<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The top-level territories (Italy + the regions), kept in a JSON file you can edit:
 * uploads/directorist-network/territories.json (copied from data/territories.json on first setup and never
 * overwritten by plugin updates). Provinces and municipalities are not listed here: they come from the CSV import
 * and from the Membership form (see Geo), and are created under their region as needed.
 */
class Territories {

	private static function file() {
		$up = wp_upload_dir( null, false );
		return empty( $up['basedir'] ) ? '' : trailingslashit( $up['basedir'] ) . 'directorist-network/territories.json';
	}

	/** Italy + regions: from the GitHub list (Geo); until the first download, from the bundled file. */
	public static function names() {
		$regions = Geo::regions(); // taken from the GitHub list once downloaded
		if ( $regions ) {
			return array_merge( array( 'Italia' ), $regions );
		}
		foreach ( array( self::file(), DIRNET_PATH . 'data/territories.json' ) as $f ) {
			if ( $f && is_readable( $f ) ) {
				$d = json_decode( (string) file_get_contents( $f ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( is_array( $d ) && ! empty( $d['territories'] ) ) {
					return array_values( array_filter( array_map( 'trim', array_map( 'strval', $d['territories'] ) ) ) );
				}
			}
		}
		return array();
	}

	/** Create the territories (and keep an editable copy of the JSON). Returns log lines. */
	public static function seed() {
		if ( ! defined( 'ATBDP_LOCATION' ) || ! taxonomy_exists( ATBDP_LOCATION ) ) {
			return array();
		}
		$f = self::file();
		if ( $f && ! file_exists( $f ) && wp_mkdir_p( dirname( $f ) ) ) {
			copy( DIRNET_PATH . 'data/territories.json', $f );
		}
		$n = 0;
		foreach ( self::names() as $name ) {
			$tid = self::term( $name, 0 );
			if ( $tid && ! get_term_meta( $tid, '_dirnet_seeded', true ) ) {
				update_term_meta( $tid, '_dirnet_seeded', 1 );
				$n++;
			}
		}
		return array( sprintf( /* translators: %d: number of territories */ __( '%d territories (Italy and regions) created from territories.json.', 'directorist-network' ), $n ) );
	}

	/** Find or create a location term and make it usable in every directory type of this plugin. */
	public static function term( $name, $parent ) {
		$found = get_terms( array( 'taxonomy' => ATBDP_LOCATION, 'name' => $name, 'parent' => (int) $parent, 'hide_empty' => false, 'number' => 1, 'fields' => 'ids' ) );
		if ( ! is_wp_error( $found ) && $found ) {
			$tid = (int) $found[0];
		} else {
			$t   = wp_insert_term( $name, ATBDP_LOCATION, $parent ? array( 'parent' => (int) $parent ) : array() );
			$tid = is_wp_error( $t ) ? 0 : (int) $t['term_id'];
		}
		if ( $tid ) {
			Categories::assign( $tid );
		}
		return $tid;
	}

	/**
	 * Creates Italia, the regions, the provinces ("Bologna (BO)") and all municipalities of the GitHub list as location terms,
	 * usable in every directory type. Resumable: it works for $seconds and remembers where it stopped.
	 *
	 * @return array{0:bool,1:int,2:int,3:string} done, created in this run, position, message
	 */
	public static function seed_geo( $seconds = 20, $restart = false, $with_comuni = false ) {
		if ( ! defined( 'ATBDP_LOCATION' ) || ! taxonomy_exists( ATBDP_LOCATION ) ) {
			return array( false, 0, 0, '! Directorist non è pronto.' );
		}
		$geo = Geo::data();
		if ( ! $geo ) {
			return array( false, 0, 0, '! Elenco da GitHub non scaricato: scaricalo prima (Geo::refresh).' );
		}
		if ( $restart ) {
			update_option( 'dirnet_geo_seed_pos', 0, false );
		}
		$pos  = (int) get_option( 'dirnet_geo_seed_pos', 0 );
		$t0   = microtime( true );
		$i    = 0;
		$made = 0;
		self::term( 'Italia', 0 );
		ksort( $geo );
		foreach ( $geo as $reg => $provs ) {
			$rid = 0;
			ksort( $provs );
			foreach ( $provs as $pv => $list ) {
				$pid = 0;
				sort( $list );
				$items = $with_comuni ? array_merge( array( null ), $list ) : array( null ); // null = the province itself; municipalities only when asked
				foreach ( $items as $c ) {
					if ( $i++ < $pos ) {
						continue;
					}
					if ( ( microtime( true ) - $t0 ) > $seconds ) {
						update_option( 'dirnet_geo_seed_pos', $i - 1, false );
						return array( false, $made, $i - 1, sprintf( 'Territori creati finora: %d (posizione %d). Continua.', $made, $i - 1 ) );
					}
					if ( ! $rid ) {
						$rid = self::term( Geo::short( $reg ), 0 );
					}
					if ( ! $rid ) {
						continue;
					}
					if ( ! $pid ) {
						$sg  = Geo::sigla( $reg, $pv );
						$pid = self::term( Geo::short( $pv ) . ( $sg ? ' (' . $sg . ')' : '' ), $rid );
					}
					if ( null !== $c && $pid ) {
						self::term( Geo::short( $c ), $pid );
						$made++;
					}
				}
				$pid = 0;
			}
		}
		update_option( 'dirnet_geo_seed_pos', 0, false );
		update_option( 'dirnet_geo_seeded', 1, false );
		return array( true, $made, $i, sprintf( $with_comuni ? 'Territori caricati da GitHub: regioni, province e comuni (%d comuni in questa tornata).' : 'Territori caricati da GitHub: Italia, regioni e province (i comuni si creano quando servono).', $made ) );
	}
}
