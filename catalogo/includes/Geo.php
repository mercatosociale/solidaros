<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Region / province / municipality lists for the Membership form, taken from a public JSON on GitHub
 * (default: matteocontrini/comuni-json, ISTAT data, CC-BY). The file is downloaded in the background
 * (setup, then monthly), reduced to region > province > municipalities and kept in
 * uploads/directorist-network/geo.json. Visitors never wait for GitHub, and the form only asks the server for
 * the provinces of the chosen region and the municipalities of the chosen province. A territory term is created
 * only when an entry actually uses it.
 */
class Geo {

	const URL_OPTION = 'dirnet_geo_url';
	const DEFAULT_URL = 'https://raw.githubusercontent.com/matteocontrini/comuni-json/master/comuni.json';
	const CRON        = 'dirnet_geo_refresh';
	const TO_COMPLETE  = '_dirnet_to_complete';
	const AUTO_ADDRESS = '_dirnet_auto_address';

	public function boot() {
		// The region > province > municipality choice is Directorist's own Location field (the terms come from the GitHub list): no extra selects.
		add_action( 'atbdp_listing_inserted', array( $this, 'save' ), 20 );
		add_action( 'atbdp_listing_updated', array( $this, 'save' ), 20 );
		add_action( 'save_post', array( $this, 'save_post' ), 99, 2 );
		add_action( 'save_post', function ( $id ) { self::check_completed( $id ); self::fill_address( $id ); }, 40 );
		add_filter( 'display_post_states', function ( $states, $post ) {
			if ( defined( 'ATBDP_POST_TYPE' ) && ATBDP_POST_TYPE === $post->post_type && self::to_complete( $post->ID ) ) {
				$states['dirnet_to_complete'] = __( 'To complete', 'directorist-network' );
			}
			return $states;
		}, 10, 2 );
		add_action( 'set_object_terms', function ( $id, $terms, $tt, $tax ) {
			if ( defined( 'ATBDP_LOCATION' ) && ATBDP_LOCATION === $tax ) {
				self::fill_address( $id );
			}
		}, 40, 4 );
		add_action( 'wp_ajax_dirnet_geo', array( $this, 'ajax' ) );
		add_action( 'wp_ajax_nopriv_dirnet_geo', array( $this, 'ajax' ) );
		add_action( self::CRON, array( __CLASS__, 'refresh' ) );
		add_filter( 'cron_schedules', function ( $s ) {
			$s['monthly_dirnet'] = array( 'interval' => 30 * DAY_IN_SECONDS, 'display' => 'Monthly' );
			return $s;
		} );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'monthly_dirnet', self::CRON );
		}
	}

	private static function file() {
		$up = wp_upload_dir( null, false );
		return empty( $up['basedir'] ) ? '' : trailingslashit( $up['basedir'] ) . 'directorist-network/geo.json';
	}

	public static function url() {
		$u = trim( (string) get_option( self::URL_OPTION, '' ) );
		return '' !== $u ? $u : self::DEFAULT_URL;
	}

	/** Download and reduce the list. Returns a log line. */
	public static function refresh() {
		$r = wp_remote_get( self::url(), array( 'timeout' => 40 ) );
		if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
			return '!' . __( 'The list of Italian municipalities could not be downloaded; the form falls back to the region only. It will retry on the next monthly run or the next setup.', 'directorist-network' );
		}
		$rows = json_decode( (string) wp_remote_retrieve_body( $r ), true );
		$geo  = array();
		$sig  = array(); // province plate code ("RM"), kept beside the list: "<region>|<province>" => sigla
		foreach ( is_array( $rows ) ? $rows : array() as $c ) {
			if ( empty( $c['nome'] ) || empty( $c['provincia']['nome'] ) || empty( $c['regione']['nome'] ) ) {
				continue;
			}
			$geo[ $c['regione']['nome'] ][ $c['provincia']['nome'] ][] = $c['nome'];
			if ( ! empty( $c['sigla'] ) ) {
				$sig[ $c['regione']['nome'] . '|' . $c['provincia']['nome'] ] = strtoupper( (string) $c['sigla'] );
			}
		}
		$f = self::file();
		if ( count( $geo ) < 15 || ! $f || ! wp_mkdir_p( dirname( $f ) ) ) {
			return '!' . __( 'The downloaded list of municipalities is not in the expected format.', 'directorist-network' );
		}
		ksort( $geo );
		foreach ( $geo as &$provs ) {
			ksort( $provs );
			foreach ( $provs as &$m ) {
				sort( $m );
			}
		}
		unset( $provs, $m );
		file_put_contents( $f, wp_json_encode( $geo ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		file_put_contents( dirname( $f ) . '/geo-sigle.json', wp_json_encode( $sig ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return sprintf( /* translators: %d: number of regions */ __( 'List of regions, provinces and municipalities downloaded (%d regions).', 'directorist-network' ), count( $geo ) );
	}

	public static function data() {
		$f = self::file();
		if ( $f && is_readable( $f ) ) {
			$d = json_decode( (string) file_get_contents( $f ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return is_array( $d ) ? $d : array();
		}
		return array();
	}

	/** Italian part of an ISTAT name ("Bolzano/Bozen" -> "Bolzano"). */
	public static function short( $name ) {
		return trim( (string) strtok( (string) $name, '/' ) );
	}

	private static function plain( $x ) {
		$x = remove_accents( strtolower( trim( (string) strtok( (string) $x, '/' ) ) ) );
		$x = preg_replace( '/\s*\([a-z]{2}\)\s*$/', '', $x );
		return trim( preg_replace( '/[^a-z0-9]+/', ' ', $x ) );
	}

	/**
	 * Exact place of an entry from the words written in a file or form (any of region, province name or plate code, municipality,
	 * in the order given): resolved against the GitHub list, then the term of the deepest known level (region > "Bologna (BO)" > municipality)
	 * is found or created. Returns the term id, 0 when nothing in the list is recognised or the municipality is ambiguous.
	 */
	public static function locate( array $segments ) {
		$geo = self::data();
		if ( ! $geo || ! defined( 'ATBDP_LOCATION' ) ) {
			return 0;
		}
		$seg = array();
		foreach ( $segments as $x ) {
			$x = trim( (string) $x );
			if ( '' !== $x && ! in_array( self::plain( $x ), array( 'italia', 'italy' ), true ) ) {
				$seg[] = $x;
			}
		}
		if ( ! $seg ) {
			return 0;
		}
		$reg = '';
		$pro = '';
		$com = '';
		$cand = array(); // all (region, province) pairs of each municipality name
		foreach ( $seg as $x ) {
			$px = self::plain( $x );
			foreach ( $geo as $r => $provs ) {
				if ( '' === $reg && self::plain( $r ) === $px ) {
					$reg = $r;
					continue 2;
				}
			}
			foreach ( $geo as $r => $provs ) {
				foreach ( $provs as $pv => $list ) {
					$sg = self::sigla( $r, $pv );
					if ( '' === $pro && ( self::plain( $pv ) === $px || ( '' !== $sg && strtoupper( $x ) === $sg ) ) && ( '' === $reg || $reg === $r ) ) {
						$pro = $pv;
						if ( '' === $reg ) {
							$reg = $r;
						}
						continue 3;
					}
				}
			}
		}
		foreach ( array_reverse( $seg ) as $x ) { // the municipality is the last word that is not the region/province
			$px = self::plain( $x );
			if ( ( '' !== $reg && self::plain( $reg ) === $px ) || ( '' !== $pro && self::plain( $pro ) === $px && ! self::is_comune_of( $geo, $reg, $pro, $px ) ) ) {
				continue;
			}
			foreach ( $geo as $r => $provs ) {
				if ( '' !== $reg && $r !== $reg ) {
					continue;
				}
				foreach ( $provs as $pv => $list ) {
					if ( '' !== $pro && $pv !== $pro ) {
						continue;
					}
					foreach ( $list as $c ) {
						if ( self::plain( $c ) === $px ) {
							$cand[] = array( $r, $pv, $c );
						}
					}
				}
			}
			if ( $cand ) {
				break;
			}
		}
		if ( count( $cand ) > 1 ) {
			return 0; // homonymous municipality that the words do not settle: left as it is
		}
		if ( 1 === count( $cand ) ) {
			list( $reg, $pro, $com ) = $cand[0];
		}
		if ( '' === $reg ) {
			return 0;
		}
		$tid = Territories::term( self::short( $reg ), 0 );
		if ( $tid && '' !== $pro ) {
			$sg  = self::sigla( $reg, $pro );
			$tid = Territories::term( self::short( $pro ) . ( $sg ? ' (' . $sg . ')' : '' ), $tid ) ?: $tid;
			if ( '' !== $com ) {
				$tid = Territories::term( self::short( $com ), $tid ) ?: $tid;
			}
		}
		return (int) $tid;
	}

	private static function is_comune_of( $geo, $reg, $pro, $px ) {
		foreach ( isset( $geo[ $reg ][ $pro ] ) ? $geo[ $reg ][ $pro ] : array() as $c ) {
			if ( self::plain( $c ) === $px ) {
				return true;
			}
		}
		return false;
	}

	/** "Bologna (BO)" for a province name written in any form under the given region ("Bologna", "bologna (bo)", "BO"); '' if it is not a province of that region. */
	public static function province_label( $region_name, $name ) {
		$plain = static function ( $x ) {
			$x = remove_accents( strtolower( trim( (string) strtok( (string) $x, '/' ) ) ) );
			$x = preg_replace( '/\s*\([a-z]{2}\)\s*$/', '', $x );
			return trim( preg_replace( '/[^a-z0-9]+/', ' ', $x ) );
		};
		$all = self::provinces();
		$rk  = $plain( $region_name );
		foreach ( $all as $reg => $provs ) {
			if ( $plain( $reg ) !== $rk ) {
				continue;
			}
			foreach ( $provs as $p ) {
				if ( '' !== $p[1] && ( $plain( $p[0] ) === $plain( $name ) || strtoupper( trim( (string) $name ) ) === $p[1] ) ) {
					return $p[0] . ' (' . $p[1] . ')';
				}
			}
		}
		return '';
	}

	/** Territory name without the plate code: "Bologna (BO)" -> "Bologna". */
	private static function bare( $name ) {
		return strtolower( self::short( preg_replace( '/\s*\([A-Za-z]{2}\)\s*$/', '', (string) $name ) ) );
	}

	/** Plate codes of the bundled list: "region|province" (short names, lower case) => sigla. Used when the downloaded list has none. */
	private static function bundled_sigle() {
		static $map = null;
		if ( null === $map ) {
			$map = array();
			$f   = DIRNET_PATH . 'data/territori-it.json';
			$d   = is_readable( $f ) ? json_decode( (string) file_get_contents( $f ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions
			foreach ( isset( $d['regioni'] ) ? (array) $d['regioni'] : array() as $reg => $provs ) {
				foreach ( (array) $provs as $p ) {
					if ( is_array( $p ) && ! empty( $p[0] ) && ! empty( $p[1] ) ) {
						$map[ strtolower( self::short( $reg ) ) . '|' . strtolower( self::short( $p[0] ) ) ] = strtoupper( (string) $p[1] );
					}
				}
			}
		}
		return $map;
	}

	/** Plate code of a province as listed in the downloaded file, else in the bundled list ('' if unknown). */
	public static function sigla( $region, $province ) {
		$f   = self::file();
		$sig = $f && is_readable( dirname( $f ) . '/geo-sigle.json' ) ? json_decode( (string) file_get_contents( dirname( $f ) . '/geo-sigle.json' ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( isset( $sig[ $region . '|' . $province ] ) && '' !== (string) $sig[ $region . '|' . $province ] ) {
			return (string) $sig[ $region . '|' . $province ];
		}
		$b = self::bundled_sigle();
		$k = strtolower( self::short( $region ) ) . '|' . strtolower( self::short( $province ) );
		return isset( $b[ $k ] ) ? $b[ $k ] : '';
	}

	/** Region names (short form) from the downloaded list; empty until the first download. */
	public static function regions() {
		$out = array();
		foreach ( array_keys( self::data() ) as $r ) {
			$out[] = self::short( $r );
		}
		sort( $out );
		return $out;
	}

	/** Region (short) => [ [province (short), sigla], … ] from the downloaded list; empty until the first download. */
	public static function provinces() {
		$geo = self::data();
		$f   = self::file();
		$sig = $f && is_readable( dirname( $f ) . '/geo-sigle.json' ) ? json_decode( (string) file_get_contents( dirname( $f ) . '/geo-sigle.json' ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$out = array();
		foreach ( $geo as $reg => $provs ) {
			foreach ( array_keys( $provs ) as $pv ) {
				$out[ self::short( $reg ) ][] = array( self::short( $pv ), self::sigla( $reg, $pv ) );
			}
			usort( $out[ self::short( $reg ) ], function ( $a, $b ) {
				return strcmp( $a[0], $b[0] );
			} );
		}
		ksort( $out );
		return $out;
	}

	/** Public answer for the form: provinces of a region, or municipalities of a province. */
	public function ajax() {
		$geo = self::data();
		$reg = isset( $_GET['region'] ) ? sanitize_text_field( wp_unslash( $_GET['region'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- read-only public lists
		$pro = isset( $_GET['province'] ) ? sanitize_text_field( wp_unslash( $_GET['province'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $reg && $pro && isset( $geo[ $reg ][ $pro ] ) ) {
			wp_send_json( $geo[ $reg ][ $pro ] );
		}
		wp_send_json( $reg && isset( $geo[ $reg ] ) ? array_keys( $geo[ $reg ] ) : array() );
	}

	/** Region, province and municipality of a listing, as keys of the downloaded list ('' where unknown). */
	public static function current( $listing_id ) {
		$out = array( 'region' => '', 'province' => '', 'municipality' => '' );
		$geo = self::data();
		if ( ! $listing_id || ! $geo || ! defined( 'ATBDP_LOCATION' ) ) {
			return $out;
		}
		$terms = wp_get_object_terms( (int) $listing_id, ATBDP_LOCATION );
		if ( is_wp_error( $terms ) || ! $terms ) {
			return $out;
		}
		$chain = array(); // deepest term first
		$t     = $terms[0];
		foreach ( $terms as $x ) {
			if ( count( get_ancestors( $x->term_id, ATBDP_LOCATION, 'taxonomy' ) ) > count( get_ancestors( $t->term_id, ATBDP_LOCATION, 'taxonomy' ) ) ) {
				$t = $x;
			}
		}
		while ( $t && ! is_wp_error( $t ) ) {
			array_unshift( $chain, $t->name );
			$t = $t->parent ? get_term( $t->parent, ATBDP_LOCATION ) : null;
		}
		// chain: [Italia?], region, province, municipality
		foreach ( $chain as $i => $name ) {
			foreach ( $geo as $reg => $provs ) {
				if ( '' === $out['region'] && strtolower( self::short( $reg ) ) === strtolower( self::short( $name ) ) ) {
					$out['region'] = $reg;
					$next          = isset( $chain[ $i + 1 ] ) ? $chain[ $i + 1 ] : '';
					$next2         = isset( $chain[ $i + 2 ] ) ? $chain[ $i + 2 ] : '';
					foreach ( $provs as $pv => $list ) {
						if ( '' !== $next && strtolower( self::short( $pv ) ) === self::bare( $next ) ) {
							$out['province'] = $pv;
							foreach ( $list as $m ) {
								if ( '' !== $next2 && strtolower( self::short( $m ) ) === strtolower( self::short( $next2 ) ) ) {
									$out['municipality'] = $m;
								}
							}
						}
					}
				}
			}
		}
		return $out;
	}

	/** The three selects (region > province > municipality), filled from the downloaded list, with the listing's current choice. */
	public static function form( $listing_id = 0 ) {
		$geo = self::data();
		if ( ! $geo ) {
			return '';
		}
		$cur  = self::current( $listing_id );
		$ph   = '<option value="">' . esc_html__( '— Select —', 'directorist-network' ) . '</option>';
		$ropt = $ph;
		foreach ( array_keys( $geo ) as $r ) {
			$ropt .= '<option value="' . esc_attr( $r ) . '"' . selected( $cur['region'], $r, false ) . '>' . esc_html( self::short( $r ) ) . '</option>';
		}
		$popt = $ph;
		if ( $cur['region'] ) {
			foreach ( array_keys( $geo[ $cur['region'] ] ) as $pv ) {
				$popt .= '<option value="' . esc_attr( $pv ) . '"' . selected( $cur['province'], $pv, false ) . '>' . esc_html( self::short( $pv ) ) . '</option>';
			}
		}
		$mopt = $ph;
		if ( $cur['region'] && $cur['province'] ) {
			foreach ( $geo[ $cur['region'] ][ $cur['province'] ] as $m ) {
				$mopt .= '<option value="' . esc_attr( $m ) . '"' . selected( $cur['municipality'], $m, false ) . '>' . esc_html( $m ) . '</option>';
			}
		}
		$ajax = esc_url( admin_url( 'admin-ajax.php' ) );
		return wp_nonce_field( 'dirnet_geo', 'dirnet_geo_nonce', true, false )
			. '<div class="directorist-form-group dirnet-geo" style="margin-bottom:10px;display:flex;flex-wrap:wrap;gap:8px 14px;align-items:center">'
			. '<label for="dirnet_region">' . esc_html__( 'Region', 'directorist-network' ) . '</label> <select id="dirnet_region" name="dirnet_region">' . $ropt . '</select> '
			. '<label for="dirnet_province">' . esc_html__( 'Province', 'directorist-network' ) . '</label> <select id="dirnet_province" name="dirnet_province"' . ( $cur['region'] ? '' : ' disabled' ) . '>' . $popt . '</select> '
			. '<label for="dirnet_municipality">' . esc_html__( 'Municipality', 'directorist-network' ) . '</label> <select id="dirnet_municipality" name="dirnet_municipality"' . ( $cur['province'] ? '' : ' disabled' ) . '>' . $mopt . '</select></div>'
			. '<script>(function(){var A="' . $ajax . '",r=document.getElementById("dirnet_region"),p=document.getElementById("dirnet_province"),m=document.getElementById("dirnet_municipality");if(!r||r.getAttribute("data-ok"))return;r.setAttribute("data-ok","1");'
			. 'function fill(s,a){s.innerHTML="<option value=\"\">—</option>";a.forEach(function(x){var o=document.createElement("option");o.value=x;o.textContent=x.split("/")[0];s.appendChild(o)});s.disabled=!a.length}'
			. 'function get(q,cb){fetch(A+"?action=dirnet_geo&"+q).then(function(x){return x.json()}).then(cb)}'
			. 'r.addEventListener("change",function(){fill(m,[]);if(!r.value){fill(p,[]);return}get("region="+encodeURIComponent(r.value),function(a){fill(p,a)})});'
			. 'p.addEventListener("change",function(){if(!p.value){fill(m,[]);return}get("region="+encodeURIComponent(r.value)+"&province="+encodeURIComponent(p.value),function(a){fill(m,a)})});})();</script>';
	}

	/** Frontend add/edit form: before the terms and conditions. */
	public function fields() {
		echo self::form( (int) get_query_var( 'atbdp_listing_id', 0 ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in form()
	}

	/** Backend: the same selects on the listing edit screen. */
	public function metabox() {
		if ( defined( 'ATBDP_POST_TYPE' ) && self::data() ) {
			add_meta_box( 'dirnet_geo', __( 'Location (from the list of Italian municipalities)', 'directorist-network' ), function ( $post ) {
				echo self::form( (int) $post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput
			}, ATBDP_POST_TYPE, 'normal', 'high' );
		}
	}

	/** Backend save: runs after WordPress and Directorist have saved their own location field. */
	public function save_post( $post_id, $post ) {
		if ( ! defined( 'ATBDP_POST_TYPE' ) || ATBDP_POST_TYPE !== $post->post_type || wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( isset( $_POST['dirnet_geo_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dirnet_geo_nonce'] ) ), 'dirnet_geo' ) ) {
			$this->save( $post_id );
		}
	}

	/** Chosen region/province/municipality become the entry's location (terms created under the region as needed). */
	public function save( $listing_id ) {
		$reg = isset( $_POST['dirnet_region'] ) ? sanitize_text_field( wp_unslash( $_POST['dirnet_region'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- Directorist verifies the submission; backend: save_post()
		$pro = isset( $_POST['dirnet_province'] ) ? sanitize_text_field( wp_unslash( $_POST['dirnet_province'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$mun = isset( $_POST['dirnet_municipality'] ) ? sanitize_text_field( wp_unslash( $_POST['dirnet_municipality'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		self::apply( $listing_id, $reg, $pro, $mun );
	}

	/**
	 * A new entry without an address on the map gets one from its location: municipality, province, region
	 * (the names of the list downloaded from GitHub). Runs when the entry is saved and when its location terms are set,
	 * so it works for the registration, the public form, the administration and the import.
	 */
	public static function fill_address( $listing_id ) {
		$listing_id = (int) $listing_id;
		if ( ! $listing_id || ! defined( 'ATBDP_POST_TYPE' ) || ! defined( 'ATBDP_LOCATION' ) || ATBDP_POST_TYPE !== get_post_type( $listing_id ) || '' !== trim( (string) get_post_meta( $listing_id, '_address', true ) ) ) {
			return;
		}
		$terms = wp_get_object_terms( $listing_id, ATBDP_LOCATION );
		if ( is_wp_error( $terms ) || ! $terms ) {
			return;
		}
		$best = array(); // the deepest chain: municipality, province, region
		foreach ( $terms as $t ) {
			$chain = array( $t->name );
			foreach ( get_ancestors( $t->term_id, ATBDP_LOCATION, 'taxonomy' ) as $aid ) {
				$a = get_term( $aid, ATBDP_LOCATION );
				if ( $a && ! is_wp_error( $a ) ) {
					$chain[] = $a->name;
				}
			}
			if ( count( $chain ) > count( $best ) ) {
				$best = $chain;
			}
		}
		if ( $best ) {
			$addr = implode( ', ', $best );
			update_post_meta( $listing_id, '_address', $addr );
			update_post_meta( $listing_id, self::AUTO_ADDRESS, $addr );
			update_post_meta( $listing_id, self::TO_COMPLETE, 1 ); // municipality but no street: the entry is "to complete"
		}
	}

	/** Entry still "to complete" (no real address)? */
	public static function to_complete( $listing_id ) {
		return (bool) get_post_meta( (int) $listing_id, self::TO_COMPLETE, true );
	}

	/** A real address typed later (different from the one built from the list) clears the state. */
	public static function check_completed( $listing_id ) {
		$listing_id = (int) $listing_id;
		if ( ! self::to_complete( $listing_id ) ) {
			return;
		}
		$addr = trim( (string) get_post_meta( $listing_id, '_address', true ) );
		if ( '' !== $addr && $addr !== (string) get_post_meta( $listing_id, self::AUTO_ADDRESS, true ) ) {
			delete_post_meta( $listing_id, self::TO_COMPLETE );
			delete_post_meta( $listing_id, self::AUTO_ADDRESS );
		}
	}

	/** Puts the entry in region > province ("Bologna (BO)") > municipality; keys are those of the downloaded list. */
	public static function apply( $listing_id, $reg, $pro = '', $mun = '' ) {
		$geo = self::data();
		if ( ! $geo || ! defined( 'ATBDP_LOCATION' ) || ! isset( $geo[ $reg ] ) ) {
			return false; // the region picked in Directorist's own field stays
		}
		$leaf = self::region_term( self::short( $reg ) );
		if ( $leaf && $pro && isset( $geo[ $reg ][ $pro ] ) ) {
			$sg   = self::sigla( $reg, $pro );
			$leaf = Territories::term( self::short( $pro ) . ( $sg ? ' (' . $sg . ')' : '' ), $leaf ) ?: $leaf; // "Bologna (BO)": same naming as Import territories
			if ( $mun && in_array( $mun, $geo[ $reg ][ $pro ], true ) ) {
				$leaf = Territories::term( self::short( $mun ), $leaf ) ?: $leaf;
			}
		}
		if ( $leaf ) {
			wp_set_object_terms( (int) $listing_id, array( $leaf ), ATBDP_LOCATION, false );
			return true;
		}
		return false;
	}

	/** Same, from the plate code ("RM") and the municipality name as given in the registration data. */
	public static function apply_by_sigla( $listing_id, $sigla, $municipality = '' ) {
		$sigla = strtoupper( trim( (string) $sigla ) );
		foreach ( self::data() as $reg => $provs ) {
			foreach ( $provs as $pv => $list ) {
				if ( self::sigla( $reg, $pv ) !== $sigla ) {
					continue;
				}
				$mun = '';
				foreach ( $list as $m ) {
					if ( '' !== $municipality && strtolower( self::short( $m ) ) === strtolower( self::short( $municipality ) ) ) {
						$mun = $m;
					}
				}
				return self::apply( $listing_id, $reg, $pv, $mun );
			}
		}
		return false;
	}

	/** Region term whose name matches ("Valle d'Aosta/Vallée d'Aoste" matches "Valle d'Aosta"). */
	private static function region_term( $name ) {
		$short = trim( strtolower( strtok( $name, '/' ) ) );
		$tops  = get_terms( array( 'taxonomy' => ATBDP_LOCATION, 'parent' => 0, 'hide_empty' => false ) );
		foreach ( is_wp_error( $tops ) ? array() : $tops as $t ) {
			if ( trim( strtolower( strtok( $t->name, '/' ) ) ) === $short ) {
				return (int) $t->term_id;
			}
		}
		return Territories::term( trim( strtok( $name, '/' ) ), 0 );
	}
}
