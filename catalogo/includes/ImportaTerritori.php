<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TEMPORARY MODULE (0.88.81) - to be removed in a later release.
 * Aligns the Territories taxonomy (at_biz_dir-location) to Region > Province, from data/territori-it.json.
 * Existing terms are updated in place (name, slug, parent), never deleted and recreated, so listings and
 * municipality children are kept. Provinces written as abbreviations ("BO", "Bologna (BO)") are recognised.
 * Never runs automatically: only from Catalogue > Importa territori, with a dry run first.
 */
class ImportaTerritori {

	const SLUG = 'dirnet-importa-territori';

	/** Names that Geo / comuni-json write differently from the official list. */
	private static $alias = array(
		'reggio nell emilia' => 'reggio emilia',
		'bolzano bozen'      => 'bolzano',
		'aosta aoste'        => 'aosta',
		'massa carrara'      => 'massa carrara',
		'pesaro e urbino'    => 'pesaro urbino',
		'forli cesena'       => 'forli cesena',
		'monza e della brianza' => 'monza brianza',
		'monza e brianza'    => 'monza brianza',
		'reggio di calabria' => 'reggio calabria',
		'roma capitale'      => 'roma',
		'citta metropolitana di roma capitale' => 'roma',
	);

	public function boot() {
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_post_dirnet_importa_territori', array( $this, 'run' ) );
	}

	public function menu() {
		add_submenu_page( 'edit.php?post_type=at_biz_dir', 'Importa territori', 'Importa territori', 'manage_options', self::SLUG, array( $this, 'page' ) );
	}

	/** Region key: "Trentino-Alto Adige/Südtirol" and "Valle d'Aosta/Vallée d'Aoste" match the short names. */
	private static function rnorm( $s ) {
		$p = explode( '/', (string) $s );
		return self::norm( $p[0] );
	}

	/** Province key from the ISTAT name: Italian part only ("Bolzano/Bozen" -> bolzano; Valle d'Aosta -> aosta). */
	private static function pnorm( $s ) {
		$p = explode( '/', (string) $s );
		$n = self::norm( $p[0] );
		return 'valle d aosta' === $n ? 'aosta' : $n;
	}

	private static function norm( $s ) {
		$s = remove_accents( mb_strtolower( (string) $s ) );
		$s = preg_replace( '/\s*\([a-z]{2}\)\s*$/', '', $s );
		$s = preg_replace( '/[^a-z0-9]+/', ' ', $s );
		$s = trim( $s );
		return isset( self::$alias[ $s ] ) ? self::$alias[ $s ] : $s;
	}

	/** True if $name is a municipality of $region according to Geo's list (empty list: false). */
	private static function is_comune( $region, $name ) {
		static $set = null;
		if ( null === $set ) {
			$set = array();
			foreach ( Geo::data() as $reg => $provs ) {
				foreach ( $provs as $list ) {
					foreach ( $list as $c ) {
						$set[ self::rnorm( $reg ) ][ self::norm( $c ) ] = 1;
					}
				}
			}
		}
		return isset( $set[ self::rnorm( $region ) ][ self::norm( $name ) ] );
	}

	/** Regions > provinces from the GitHub list (Geo); the bundled copy only until the first download. */
	private static function data() {
		$g = Geo::provinces();
		if ( $g ) {
			return $g;
		}
		$d = json_decode( (string) file_get_contents( DIRNET_PATH . 'data/territori-it.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return isset( $d['regioni'] ) ? $d['regioni'] : array();
	}

	/** Merge territory terms with the same name under the same parent (the one with most entries wins). */
	public static function doppie( $apply ) {
		$log    = array();
		$merged = 0;
		for ( $pass = 0; $pass < 6; $pass++ ) {
			$terms = get_terms( array( 'taxonomy' => ATBDP_LOCATION, 'hide_empty' => false ) );
			if ( is_wp_error( $terms ) ) {
				break;
			}
			$groups = array();
			foreach ( $terms as $t ) {
				// "Bologna (BO)" (province) and "Bologna" (municipality) are different territories even when they share the name and the parent
				$has_sg = preg_match( '/\(\s*[A-Za-z]{2}\s*\)\s*$/', $t->name ) ? 'p' : 'c';
				$groups[ (int) $t->parent . '|' . $has_sg . '|' . self::norm( $t->name ) ][] = $t;
			}
			$did = false;
			foreach ( $groups as $list ) {
				if ( count( $list ) < 2 ) {
					continue;
				}
				usort( $list, function ( $a, $b ) {
					$sa = preg_match( '/\(\s*[A-Za-z]{2}\s*\)\s*$/', $a->name ) ? 1 : 0;
					$sb = preg_match( '/\(\s*[A-Za-z]{2}\s*\)\s*$/', $b->name ) ? 1 : 0;
					return ( $sb <=> $sa ) ?: ( (int) $b->count <=> (int) $a->count ) ?: ( (int) $a->term_id <=> (int) $b->term_id );
				} );
				$win = array_shift( $list );
				foreach ( $list as $dup ) {
					$merged++;
					$log[] = sprintf( '%s (id %d, annunci %d) unito a id %d', $dup->name, $dup->term_id, $dup->count, $win->term_id );
					$did   = true;
					if ( ! $apply ) {
						continue;
					}
					foreach ( (array) get_terms( array( 'taxonomy' => ATBDP_LOCATION, 'parent' => $dup->term_id, 'hide_empty' => false ) ) as $ch ) {
						if ( is_object( $ch ) ) {
							wp_update_term( $ch->term_id, ATBDP_LOCATION, array( 'parent' => (int) $win->term_id ) );
						}
					}
					foreach ( (array) get_objects_in_term( $dup->term_id, ATBDP_LOCATION ) as $oid ) {
						wp_set_object_terms( (int) $oid, (int) $win->term_id, ATBDP_LOCATION, true );
					}
					wp_delete_term( $dup->term_id, ATBDP_LOCATION );
				}
			}
			if ( ! $did || ! $apply ) {
				break; // a preview shows the first level only; applying repeats until nothing is left
			}
		}
		array_unshift( $log, sprintf( '%d territori doppi %s.', $merged, $apply ? 'uniti' : 'da unire' ) );
		return $log;
	}

	/** Merge the territory terms named only by a province code ("VT") into the existing "Viterbo (VT)". */
	public static function sigle( $apply ) {
		$log   = array();
		$terms = get_terms( array( 'taxonomy' => ATBDP_LOCATION, 'hide_empty' => false ) );
		if ( is_wp_error( $terms ) ) {
			return array( 'Territori non disponibili.' );
		}
		$target = array();
		foreach ( $terms as $t ) {
			if ( preg_match( '/\(([A-Za-z]{2})\)\s*$/', $t->name, $m ) ) {
				$k = strtoupper( $m[1] );
				if ( ! isset( $target[ $k ] ) || count( get_term_children( $t->term_id, ATBDP_LOCATION ) ) > count( get_term_children( $target[ $k ]->term_id, ATBDP_LOCATION ) ) ) {
					$target[ $k ] = $t;
				}
			}
		}
		$n = 0;
		foreach ( $terms as $t ) {
			if ( ! preg_match( '/^\s*([A-Z]{2})\s*$/', $t->name, $m ) ) {
				continue;
			}
			$k = $m[1];
			if ( empty( $target[ $k ] ) || (int) $target[ $k ]->term_id === (int) $t->term_id ) {
				$log[] = sprintf( '%s: nessuna provincia "(%s)" esistente, non toccata.', $t->name, $k );
				continue;
			}
			$dest = $target[ $k ];
			$objs = (array) get_objects_in_term( $t->term_id, ATBDP_LOCATION );
			$kids = (array) get_terms( array( 'taxonomy' => ATBDP_LOCATION, 'parent' => $t->term_id, 'hide_empty' => false ) );
			$log[] = sprintf( '%s -> %s: %d soggetti, %d sotto-territori', $t->name, $dest->name, count( $objs ), count( $kids ) );
			$n++;
			if ( ! $apply ) {
				continue;
			}
			foreach ( $kids as $kid ) {
				if ( is_object( $kid ) ) {
					wp_update_term( $kid->term_id, ATBDP_LOCATION, array( 'parent' => (int) $dest->term_id ) );
				}
			}
			foreach ( $objs as $oid ) {
				wp_set_object_terms( (int) $oid, (int) $dest->term_id, ATBDP_LOCATION, true );
			}
			wp_delete_term( $t->term_id, ATBDP_LOCATION );
		}
		array_unshift( $log, sprintf( '%d sigle %s.', $n, $apply ? 'unite' : 'da unire' ) );
		if ( $apply ) {
			$log = array_merge( $log, self::doppie( true ) );
		}
		return $log;
	}

	private static function match( $term, $name, $sigla ) {
		$n = self::norm( $term->name );
		return $n === self::norm( $name ) || $n === strtolower( $sigla ) || strtolower( $term->slug ) === strtolower( $sigla );
	}

	/** Run the alignment. $apply=false: only describe. Returns log lines. */
	public static function align( $apply, $prune ) {
		$log    = array();
		$keep   = array(); // region and province term ids
		$prov   = array(); // region norm => [ province norm => term id (0: to be created) ]
		$labels = array();
		$rids   = array();
		foreach ( self::data() as $region => $provs ) {
			$rid = 0;
			foreach ( get_terms( array( 'taxonomy' => ATBDP_LOCATION, 'parent' => 0, 'hide_empty' => false ) ) as $t ) {
				if ( self::norm( $t->name ) === self::norm( $region ) ) {
					$rid = (int) $t->term_id;
					break;
				}
			}
			if ( ! $rid ) {
				$log[] = "+ regione: $region";
				$rid   = $apply ? Territories::term( $region, 0 ) : 0;
			}
			$keep[]  = $rid;
			$rids[ $region ] = $rid;
			$rn      = self::rnorm( $region );
			$kids    = $rid ? get_terms( array( 'taxonomy' => ATBDP_LOCATION, 'parent' => $rid, 'hide_empty' => false ) ) : array();
			$free    = get_terms( array( 'taxonomy' => ATBDP_LOCATION, 'parent' => 0, 'hide_empty' => false ) ); // misplaced at top level
			foreach ( $provs as $p ) {
				list( $name, $sigla ) = $p;
				if ( '' === trim( (string) $sigla ) ) {
					$log[] = "! sigla mancante per $region > $name: provincia saltata (non rinomino in \"$name ()\")";
					continue;
				}
				$label = $name . ' (' . $sigla . ')'; // "Bologna (BO)", slug bologna-bo: tells provinces from municipalities
				$slug  = sanitize_title( $name . ' ' . $sigla );
				$hit   = null;
				// First the terms written with the abbreviation; the plain name only as a fallback, and never a bare
				// municipality (a leaf that is a municipality of the region), which can share the province's name.
				foreach ( array( true, false ) as $strict ) {
					foreach ( array_merge( (array) $kids, (array) $free ) as $t ) {
						if ( (int) $t->term_id === (int) $rid || in_array( (int) $t->term_id, $keep, true ) ) {
							continue;
						}
						$sg  = strtolower( $sigla );
						$isg = ( self::norm( $t->name ) === $sg || strtolower( $t->slug ) === $sg || preg_match( '/\(' . $sg . '\)\s*$/i', $t->name ) || strtolower( $t->slug ) === $slug );
						if ( $strict ) {
							$ok = $isg;
						} else {
							$ok = self::match( $t, $name, $sigla ) && ( get_term_children( $t->term_id, ATBDP_LOCATION ) || ! self::is_comune( $region, $t->name ) );
						}
						if ( $ok && ( ! $hit || (int) $t->count > (int) $hit->count ) ) {
							$hit = $t; // several candidates: keep the one with more listings
						}
					}
					if ( $hit ) {
						break;
					}
				}
				if ( ! $hit ) {
					$log[] = "+ $region > $label";
					$tid   = ( $apply && $rid ) ? (int) Territories::term( $label, $rid ) : 0;
					if ( $tid ) {
						$keep[] = $tid;
					}
					$prov[ $rn ][ self::norm( $name ) ] = $tid;
					$labels[ $tid ? $tid : $rn . '|' . self::norm( $name ) ] = $label;
					continue;
				}
				$keep[] = (int) $hit->term_id;
				$prov[ $rn ][ self::norm( $name ) ] = (int) $hit->term_id;
				$args = array();
				if ( $hit->name !== $label ) {
					$args['name'] = $label;
				}
				if ( $slug !== $hit->slug ) {
					$args['slug'] = $slug;
				}
				if ( $rid && (int) $hit->parent !== $rid ) {
					$args['parent'] = $rid;
				}
				if ( $args ) {
					$log[] = "~ {$hit->name} (id {$hit->term_id}) -> " . wp_json_encode( $args, JSON_UNESCAPED_UNICODE );
					if ( $apply ) {
						$r = wp_update_term( $hit->term_id, ATBDP_LOCATION, $args );
						if ( is_wp_error( $r ) && isset( $args['slug'] ) ) {
							unset( $args['slug'] );
							$r = wp_update_term( $hit->term_id, ATBDP_LOCATION, $args );
						}
						if ( is_wp_error( $r ) ) {
							$log[] = '! errore: ' . $r->get_error_message();
						} else {
							Categories::assign( $hit->term_id );
						}
					}
				}
			}
		}
		$handled = array();
		$log     = array_merge( $log, self::comuni( $apply, $prov, $keep, $handled ) );
		// What is still directly under a region and is neither a province nor a recognised municipality.
		foreach ( $rids as $region => $rid ) {
			if ( ! $rid ) {
				continue;
			}
			foreach ( (array) get_terms( array( 'taxonomy' => ATBDP_LOCATION, 'parent' => $rid, 'hide_empty' => false ) ) as $t ) {
				if ( in_array( (int) $t->term_id, $keep, true ) || in_array( (int) $t->term_id, $handled, true ) ) {
					continue;
				}
				$pk = $prov[ self::rnorm( $region ) ][ self::norm( $t->name ) ] ?? 0;
				if ( $pk && (int) $pk !== (int) $t->term_id ) { // a spelling variant of a province: merge into it
					$log[] = "= variante di provincia {$t->name} (id {$t->term_id}, annunci {$t->count}) unita a id $pk";
					if ( $apply ) {
						foreach ( (array) get_terms( array( 'taxonomy' => ATBDP_LOCATION, 'parent' => $t->term_id, 'hide_empty' => false ) ) as $ch ) {
							wp_update_term( $ch->term_id, ATBDP_LOCATION, array( 'parent' => (int) $pk ) );
						}
						wp_delete_term( $t->term_id, ATBDP_LOCATION, array( 'default' => (int) $pk, 'force_default' => true ) );
					}
					continue;
				}
				$leaf = ! get_term_children( $t->term_id, ATBDP_LOCATION );
				if ( $prune && $leaf && 0 === (int) $t->count ) {
					$log[] = "- elimino {$t->name} (vuota)";
					if ( $apply ) {
						wp_delete_term( $t->term_id, ATBDP_LOCATION );
					}
				} else {
					$log[] = "? fuori elenco, lasciata: $region > {$t->name} (annunci {$t->count})";
				}
			}
		}
		return $log ? $log : array( 'Niente da fare: i territori sono già allineati.' );
	}

	/** Every location term must be usable in every directory type of this plugin, or Directorist does not offer it in the forms and filters. */
	public static function directory( $apply ) {
		if ( ! function_exists( 'directorist_update_term_directory' ) ) {
			return array( '! Directorist non espone directorist_update_term_directory(): aggiorna Directorist.' );
		}
		$types = array_map( 'intval', array_keys( Relations::registry() ) );
		if ( ! $types ) {
			return array( '! Nessun tipo di catalogo generato dal plugin: genera prima i tipi (Impostazioni).' );
		}
		$ids = get_terms( array( 'taxonomy' => ATBDP_LOCATION, 'hide_empty' => false, 'fields' => 'ids' ) );
		if ( is_wp_error( $ids ) ) {
			return array( 'Territori non disponibili.' );
		}
		$n = 0;
		if ( $apply ) {
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
			foreach ( $ids as $tid ) {
				directorist_update_term_directory( (int) $tid, $types, true );
				$n++;
			}
		}
		return array( sprintf( '%d territori %s per %d tipi di catalogo.', $apply ? $n : count( $ids ), $apply ? 'resi disponibili' : 'da rendere disponibili', count( $types ) ) );
	}

	/** Every municipality term goes under its province; duplicates of the same municipality are merged. */
	private static function comuni( $apply, $prov, $keep, &$handled ) {
		$log = array();
		$geo = Geo::data();
		if ( ! $geo && $apply ) {
			Geo::refresh();
			$geo = Geo::data();
		}
		if ( ! $geo ) {
			return array( '! elenco dei comuni non disponibile (geo.json non ancora scaricato): i comuni non sono stati assegnati. Applica per scaricarlo, poi ripeti.' );
		}
		$map = array(); // comune norm => region norm => [province norm]
		foreach ( $geo as $reg => $provs ) {
			foreach ( $provs as $pv => $list ) {
				foreach ( $list as $c ) {
					$map[ self::norm( $c ) ][ self::rnorm( $reg ) ][ self::pnorm( $pv ) ] = $pv;
				}
			}
		}
		$provset = array();
		foreach ( $prov as $r ) {
			foreach ( $r as $id ) {
				if ( $id ) {
					$provset[] = (int) $id;
				}
			}
		}
		$by = array();
		foreach ( get_terms( array( 'taxonomy' => ATBDP_LOCATION, 'hide_empty' => false ) ) as $t ) {
			$by[ (int) $t->term_id ] = $t;
		}
		$groups = array();
		$regions_n = array();
		foreach ( $geo as $reg => $unused ) {
			$regions_n[ self::rnorm( $reg ) ] = 1;
		}
		foreach ( $by as $id => $t ) {
			if ( in_array( $id, $provset, true ) || in_array( $id, $keep, true ) ) {
				continue;
			}
			$top = $t;
			$i   = 0;
			while ( $top->parent && isset( $by[ (int) $top->parent ] ) && $i++ < 6 ) {
				$top = $by[ (int) $top->parent ];
			}
			$rn = self::rnorm( $top->name );
			$cn = self::norm( $t->name );
			if ( ! $t->parent ) {
				// Top level: a region, Italia or Estero stays; anything else is a loose municipality, placed by its name when that is unique in Italy.
				if ( isset( $regions_n[ $rn ] ) || in_array( $rn, array( 'italia', 'italy', 'estero' ), true ) || ! isset( $map[ $cn ] ) ) {
					continue;
				}
				if ( count( $map[ $cn ] ) > 1 ) {
					$log[] = "? comune senza regione e omonimo in più regioni, assegnalo a mano: {$t->name} (id $id)";
					continue;
				}
				$rn = (string) array_key_first( $map[ $cn ] );
			} elseif ( ! isset( $map[ $cn ][ $rn ] ) && isset( $map[ $cn ] ) && count( $map[ $cn ] ) === 1 && ( 'italia' === $rn || 'italy' === $rn || ! isset( $regions_n[ $rn ] ) ) ) {
				$rn = (string) array_key_first( $map[ $cn ] ); // under Italia or a stray parent: the unique region of that municipality
			}
			if ( ! isset( $map[ $cn ][ $rn ] ) ) {
				continue;
			}
			$cands = array_keys( $map[ $cn ][ $rn ] );
			$handled[] = $id;
			if ( count( $cands ) > 1 ) {
				// Homonymous municipality inside the region: the entries that carry it may also carry one of the provinces.
				$pick = array();
				foreach ( (array) get_objects_in_term( $id, ATBDP_LOCATION ) as $oid ) {
					foreach ( (array) wp_get_object_terms( (int) $oid, ATBDP_LOCATION, array( 'fields' => 'ids' ) ) as $tid2 ) {
						foreach ( $cands as $cp ) {
							if ( isset( $prov[ $rn ][ $cp ] ) && (int) $prov[ $rn ][ $cp ] === (int) $tid2 ) {
								$pick[ $cp ] = 1;
							}
						}
					}
				}
				if ( 1 !== count( $pick ) ) {
					$log[] = "? comune omonimo, assegnalo a mano: {$t->name} (id $id) in " . implode( ' / ', array_values( $map[ $cn ][ $rn ] ) );
					continue;
				}
				$cands = array( (string) array_key_first( $pick ) );
			}
			if ( ! isset( $prov[ $rn ][ $cands[0] ] ) ) {
				$log[] = "? provincia {$map[$cn][$rn][$cands[0]]} non è nella lista: comune {$t->name} (id $id) lasciato dov'è";
				continue;
			}
			$groups[ $rn . '|' . $cands[0] . '|' . $cn ][] = $t;
		}
		$moved = 0;
		$merged = 0;
		foreach ( $groups as $key => $list ) {
			list( $rn, $pn ) = explode( '|', $key );
			$target = (int) $prov[ $rn ][ $pn ];
			usort( $list, function ( $a, $b ) {
				return ( (int) $b->count <=> (int) $a->count ) ?: ( (int) $a->term_id <=> (int) $b->term_id );
			} );
			$win = array_shift( $list );
			if ( (int) $win->parent !== $target || ! $target ) {
				if ( (int) $win->parent !== $target ) {
					$log[] = "~ comune {$win->name} (id {$win->term_id}) -> sotto la provincia " . ( $target ? "(id $target)" : '(nuova)' ) . ' ' . $pn;
					$moved++;
					if ( $apply && $target ) {
						wp_update_term( $win->term_id, ATBDP_LOCATION, array( 'parent' => $target ) );
					}
				}
			}
			foreach ( $list as $dup ) {
				$log[] = "= comune doppio {$dup->name} (id {$dup->term_id}, annunci {$dup->count}) unito a id {$win->term_id}";
				$merged++;
				if ( $apply ) {
					foreach ( (array) get_terms( array( 'taxonomy' => ATBDP_LOCATION, 'parent' => $dup->term_id, 'hide_empty' => false ) ) as $ch ) {
						wp_update_term( $ch->term_id, ATBDP_LOCATION, array( 'parent' => (int) $win->term_id ) );
					}
					wp_delete_term( $dup->term_id, ATBDP_LOCATION, array( 'default' => (int) $win->term_id, 'force_default' => true ) );
				}
			}
		}
		$log[] = "Comuni spostati sotto la provincia: $moved; doppioni uniti: $merged";
		return $log;
	}

	/** Every listing gets, besides its own location and category terms, all their ancestors. */
	public static function antenati( $apply ) {
		$ids = get_posts( array( 'post_type' => ATBDP_POST_TYPE, 'post_status' => array( 'publish', 'pending', 'draft', 'private', 'future' ), 'numberposts' => -1, 'fields' => 'ids', 'suppress_filters' => true ) );
		$log = array();
		$n   = 0;
		foreach ( array( ATBDP_LOCATION => 'territori', ATBDP_CATEGORY => 'categorie', Functions::TAX => 'funzioni' ) as $tax => $lab ) {
			$m = 0;
			foreach ( $ids as $id ) {
				$have = wp_get_object_terms( $id, $tax, array( 'fields' => 'ids' ) );
				if ( is_wp_error( $have ) || ! $have ) {
					continue;
				}
				$want = array();
				foreach ( $have as $tid ) {
					foreach ( get_ancestors( (int) $tid, $tax, 'taxonomy' ) as $a ) {
						$want[ (int) $a ] = 1;
					}
				}
				$missing = array_values( array_diff( array_keys( $want ), array_map( 'intval', $have ) ) );
				if ( ! $missing ) {
					continue;
				}
				$m++;
				if ( count( $log ) < 300 ) {
					$names = array();
					foreach ( $missing as $x ) {
						$t       = get_term( $x, $tax );
						$names[] = $t && ! is_wp_error( $t ) ? $t->name : $x;
					}
					$log[] = "+ [$lab] " . get_the_title( $id ) . " (id $id): " . implode( ', ', $names );
				}
				if ( $apply ) {
					wp_set_object_terms( $id, $missing, $tax, true );
				}
			}
			$log[] = "Annunci aggiornati ($lab): $m su " . count( $ids );
			$n += $m;
		}
		return $log;
	}

	/** Read-only list of entries lacking region, province, municipality or category. */
	private static function manca_table() {
		$f    = isset( $_GET['mancano'] ) ? sanitize_key( wp_unslash( $_GET['mancano'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$base = admin_url( 'admin.php?page=dirnet-repairs' );
		$labs = array( 'regione' => 'senza regione', 'provincia' => 'senza provincia', 'comune' => 'senza comune', 'categoria' => 'senza categoria', 'qualsiasi' => 'con almeno un dato mancante' );
		echo '<hr><h2>Soggetti con dati mancanti</h2><p>';
		foreach ( $labs as $k => $l ) {
			echo '<a class="button' . ( $f === $k ? ' button-primary' : '' ) . '" href="' . esc_url( add_query_arg( 'mancano', $k, $base ) ) . '">' . esc_html( ucfirst( $l ) ) . '</a> ';
		}
		echo '</p>';
		if ( ! isset( $labs[ $f ] ) ) {
			return;
		}
		$ids = get_posts( array( 'post_type' => ATBDP_POST_TYPE, 'post_status' => array( 'publish', 'pending', 'draft', 'private', 'future' ), 'numberposts' => -1, 'fields' => 'ids', 'suppress_filters' => true ) );
		$reg = Relations::registry();
		$ids = array_values( array_filter( $ids, function ( $i ) use ( $reg ) { // campaigns are not entities
			$t = (int) get_post_meta( $i, '_directory_type', true );
			return ! ( isset( $reg[ $t ] ) && 'campaign' === $reg[ $t ][1] );
		} ) );
		update_object_term_cache( $ids, ATBDP_POST_TYPE );
		$rows = array();
		foreach ( $ids as $id ) {
			$depth = array();
			foreach ( (array) get_the_terms( $id, ATBDP_LOCATION ) as $t ) {
				if ( $t && ! is_wp_error( $t ) ) {
					$depth[ count( get_ancestors( $t->term_id, ATBDP_LOCATION, 'taxonomy' ) ) ] = 1;
				}
			}
			$cats = get_the_terms( $id, ATBDP_CATEGORY );
			$miss = array();
			if ( empty( $depth[0] ) ) {
				$miss['regione'] = 1;
			}
			if ( empty( $depth[1] ) ) {
				$miss['provincia'] = 1;
			}
			if ( empty( $depth[2] ) ) {
				$miss['comune'] = 1;
			}
			if ( ! $cats || is_wp_error( $cats ) ) {
				$miss['categoria'] = 1;
			}
			if ( 'qualsiasi' === $f ? $miss : isset( $miss[ $f ] ) ) {
				$rows[ $id ] = array_keys( $miss );
			}
		}
		echo '<p><b>' . count( $rows ) . '</b> soggetti ' . esc_html( $labs[ $f ] ) . ' su ' . count( $ids ) . '.</p>';
		if ( $rows ) {
			echo '<table class="widefat striped"><thead><tr><th>Soggetto</th><th>Manca</th><th>Stato</th></tr></thead><tbody>';
			foreach ( array_slice( $rows, 0, 500, true ) as $id => $m ) {
				echo '<tr><td><a href="' . esc_url( (string) get_edit_post_link( $id, 'url' ) ) . '">' . esc_html( get_the_title( $id ) ?: "#$id" ) . '</a></td><td>' . esc_html( implode( ', ', $m ) ) . '</td><td>' . esc_html( get_post_status( $id ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
			if ( count( $rows ) > 500 ) {
				echo '<p>Elenco troncato ai primi 500.</p>';
			}
		}
	}

	/**
	 * One pass over regione > provincia > comune against the GitHub list (Geo): joins plate codes into provinces,
	 * puts regions/provinces/municipalities in the right place, merges duplicates, adds missing parents to the entries.
	 * In "prova" the steps are simulated one by one, so later lines may differ a little from the real run.
	 */
	public static function normalizza( $apply, $prune ) {
		$log = array();
		if ( ! Geo::data() ) {
			if ( $apply ) {
				$log[] = Geo::refresh();
			}
			if ( ! Geo::data() ) {
				return array( '! Elenco regioni/province/comuni da GitHub non ancora scaricato: premi Applica per scaricarlo (se GitHub non risponde riprova più tardi).' );
			}
		}
		$steps = array(
			'1. Sigle di provincia unite alla provincia'   => function () use ( $apply ) {
				return self::sigle( $apply );
			},
			'2. Regioni, province e comuni allineati a GitHub' => function () use ( $apply, $prune ) {
				return self::align( $apply, $prune );
			},
			'3. Doppioni uniti'                             => function () use ( $apply ) {
				return self::doppie( $apply );
			},
			'4. Annunci: regione e provincia mancanti aggiunte' => function () use ( $apply ) {
				return self::antenati( $apply );
			},
			'5. Territori disponibili nelle directory di Directorist' => function () use ( $apply ) {
				return self::directory( $apply );
			},
		);
		foreach ( $steps as $title => $fn ) {
			$log[] = '== ' . $title . ' ==';
			foreach ( (array) $fn() as $line ) {
				$log[] = $line;
			}
		}
		$log[] = '';
		$log[] = 'Le righe che iniziano con "?" sono voci fuori elenco: non sono state toccate (vanno sistemate a mano o con "Elimina anche le province fuori elenco").';
		return $log;
	}

	/** Path of the saved snapshots of the entries' locations. */
	private static function snap_dir() {
		$up = wp_upload_dir( null, false );
		return empty( $up['basedir'] ) ? '' : trailingslashit( $up['basedir'] ) . 'directorist-network/';
	}

	/** Saves where every entry is (region > province > municipality as names) before any change; returns the file name. */
	public static function snapshot() {
		$dir = self::snap_dir();
		if ( '' === $dir || ! wp_mkdir_p( $dir ) || ! defined( 'ATBDP_POST_TYPE' ) ) {
			return '';
		}
		$ids = get_posts( array( 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'suppress_filters' => true ) );
		$out = array();
		foreach ( $ids as $id ) {
			$paths = array();
			foreach ( (array) wp_get_object_terms( $id, ATBDP_LOCATION ) as $t ) {
				if ( ! is_object( $t ) ) {
					continue;
				}
				$chain = array( $t->name );
				$cur   = $t;
				$i     = 0;
				while ( $cur->parent && $i++ < 6 ) {
					$cur = get_term( $cur->parent, ATBDP_LOCATION );
					if ( ! $cur || is_wp_error( $cur ) ) {
						break;
					}
					array_unshift( $chain, $cur->name );
				}
				$paths[] = $chain;
			}
			if ( $paths ) {
				$out[ $id ] = $paths;
			}
		}
		$f = 'loc-snapshot-' . gmdate( 'Ymd-His' ) . '.json';
		file_put_contents( $dir . $f, wp_json_encode( $out ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		update_option( 'dirnet_loc_snapshot', $f, false );
		return $f;
	}

	/** Puts every entry back where the last snapshot says (terms are recreated by name when they no longer exist). */
	public static function restore() {
		$f   = (string) get_option( 'dirnet_loc_snapshot', '' );
		$p   = self::snap_dir() . $f;
		if ( '' === $f || ! is_readable( $p ) ) {
			return array( '! Nessuno snapshot disponibile: le località non si possono ripristinare da qui (serve un backup del database).' );
		}
		$data = json_decode( (string) file_get_contents( $p ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$n    = 0;
		foreach ( is_array( $data ) ? $data : array() as $id => $paths ) {
			$tids = array();
			foreach ( $paths as $chain ) {
				$parent = 0;
				foreach ( $chain as $name ) {
					$parent = (int) Territories::term( (string) $name, $parent );
					if ( ! $parent ) {
						break;
					}
				}
				if ( $parent ) {
					$tids[] = $parent;
				}
			}
			if ( $tids ) {
				wp_set_object_terms( (int) $id, $tids, ATBDP_LOCATION, false );
				$n++;
			}
		}
		return array( sprintf( 'Località ripristinate da %s: %d schede.', $f, $n ) );
	}

	public function run() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No.' );
		}
		check_admin_referer( 'dirnet_importa_territori' );
		if ( ! empty( $_POST['mail_prova'] ) || ! empty( $_POST['mail_applica'] ) ) {
			$f = isset( $_FILES['mail_csv']['tmp_name'] ) ? (string) $_FILES['mail_csv']['tmp_name'] : '';
			$log = $f && is_uploaded_file( $f ) ? self::ripristina_email( $f, ! empty( $_POST['mail_applica'] ) ) : array( 'Nessun file CSV caricato.' );
			set_transient( 'dirnet_it_log', array( ! empty( $_POST['mail_applica'] ), $log ), 600 );
			wp_safe_redirect( admin_url( 'admin.php?page=dirnet-repairs' ) );
			exit;
		}
		if ( ! empty( $_POST['coord_run'] ) ) {
			if ( ! empty( $_POST['coord_reset'] ) ) {
				delete_post_meta_by_key( '_dirnet_coord_tried' );
			}
			$r = self::trova_coordinate( 10 );
			set_transient( 'dirnet_it_log', array( true, array( $r[1] ) ), 600 );
			wp_safe_redirect( admin_url( 'admin.php?page=dirnet-repairs' . ( $r[0] ? '&dirnet_coord_continue=1' : '' ) ) );
			exit;
		}
		if ( ! empty( $_POST['geo_refresh'] ) ) {
			$m = Geo::refresh();
			set_transient( 'dirnet_it_log', array( '!' !== substr( $m, 0, 1 ), array( ltrim( $m, '!' ) ) ), 600 );
			wp_safe_redirect( admin_url( 'admin.php?page=dirnet-repairs' ) );
			exit;
		}
		if ( ! empty( $_POST['geo_seed'] ) ) {
			if ( ! Geo::data() ) {
				Geo::refresh();
			}
			$r = Territories::seed_geo( 20, ! empty( $_POST['geo_restart'] ), true );
			set_transient( 'dirnet_it_log', array( true, array( $r[3] ) ), 600 );
			wp_safe_redirect( admin_url( 'admin.php?page=dirnet-repairs' . ( $r[0] ? '' : '&dirnet_geo_continue=1' ) ) );
			exit;
		}
		if ( ! empty( $_POST['ripristina_localita'] ) ) {
			set_transient( 'dirnet_it_log', array( true, self::restore() ), 600 );
			wp_safe_redirect( admin_url( 'admin.php?page=dirnet-repairs' ) );
			exit;
		}
		foreach ( array( 'applica', 'normalizza_applica', 'doppie_applica', 'sigle_applica', 'antenati_applica' ) as $btn ) {
			if ( ! empty( $_POST[ $btn ] ) ) {
				self::snapshot(); // before anything is changed
				break;
			}
		}
		if ( ! empty( $_POST['sol_allinea'] ) ) {
			set_transient( 'dirnet_it_log', array( true, array( 'Schede allineate ai dati Solidaros: ' . Member::sync_all() ) ), 600 );
			wp_safe_redirect( admin_url( 'admin.php?page=dirnet-repairs' ) );
			exit;
		}
		if ( ! empty( $_POST['normalizza_prova'] ) || ! empty( $_POST['normalizza_applica'] ) ) {
			$apply = ! empty( $_POST['normalizza_applica'] );
			set_transient( 'dirnet_it_log', array( $apply, self::normalizza( $apply, ! empty( $_POST['prune'] ) ) ), 600 );
			wp_safe_redirect( admin_url( 'admin.php?page=dirnet-repairs' ) );
			exit;
		}
		if ( ! empty( $_POST['doppie_prova'] ) || ! empty( $_POST['doppie_applica'] ) ) {
			$apply = ! empty( $_POST['doppie_applica'] );
			set_transient( 'dirnet_it_log', array( $apply, self::doppie( $apply ) ), 600 );
			wp_safe_redirect( admin_url( 'admin.php?page=dirnet-repairs' ) );
			exit;
		}
		if ( ! empty( $_POST['sigle_prova'] ) || ! empty( $_POST['sigle_applica'] ) ) {
			$apply = ! empty( $_POST['sigle_applica'] );
			set_transient( 'dirnet_it_log', array( $apply, self::sigle( $apply ) ), 600 );
			wp_safe_redirect( admin_url( 'admin.php?page=dirnet-repairs' ) );
			exit;
		}
		if ( ! empty( $_POST['antenati_prova'] ) || ! empty( $_POST['antenati_applica'] ) ) {
			$apply = ! empty( $_POST['antenati_applica'] );
			set_transient( 'dirnet_it_log', array( $apply, self::antenati( $apply ) ), 600 );
			wp_safe_redirect( admin_url( 'admin.php?page=dirnet-repairs' ) );
			exit;
		}
		$apply = ! empty( $_POST['applica'] );
		$prune = ! empty( $_POST['prune'] );
		set_transient( 'dirnet_it_log', array( $apply, self::align( $apply, $prune ) ), 600 );
		wp_safe_redirect( admin_url( 'admin.php?page=dirnet-repairs' ) );
		exit;
	}

	public function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>Importa territori (modulo temporaneo)</h1>';
		$this->body( false );
		echo '</div>';
	}

	/** The content of the page; $nested = shown inside another page (subtitles one level down). */
	public function body( $nested = true ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		ob_start();
		echo '<p>Allinea Territori a <b>regione &gt; provincia</b> (20 regioni, 110 province). I comuni sotto le province restano. I comuni già presenti vengono spostati sotto la propria provincia (elenco ISTAT di Geo). Le province scritte con la sigla vengono rinominate nella forma “Bologna (BO)” con slug bologna-bo; i termini esistenti sono aggiornati sul posto, non cancellati. <b>Fai prima un backup del database e lancia la prova.</b> “Italia” ed “Estero” non vengono toccati.</p>';
		$l = get_transient( 'dirnet_it_log' );
		if ( $l ) {
			delete_transient( 'dirnet_it_log' );
			echo '<div class="notice notice-info"><p><b>' . ( $l[0] ? 'Applicato' : 'Prova (nulla è stato modificato)' ) . '</b></p><pre style="max-height:420px;overflow:auto">' . esc_html( implode( "\n", $l[1] ) ) . '</pre></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dirnet_importa_territori">';
		wp_nonce_field( 'dirnet_importa_territori' );
		echo '<h2>Allinea le schede ai dati Solidaros</h2><p>Copia nelle schede Directorist tutti i dati di registrazione presenti in Solidaros (anagrafica, titolare, social, immagine).</p><p><button class="button" name="sol_allinea" value="1">Allinea schede</button></p><hr>';
		echo '</form><form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dirnet_importa_territori">';
		wp_nonce_field( 'dirnet_importa_territori' );
		echo '<h2>Ripristina le email dal CSV</h2><p>Per le schede che hanno l\'email di un amministratore: rimette l\'email del CSV di importazione (abbinamento per partita IVA, poi per nome). Le altre schede non vengono toccate. Fai prima la Prova.</p><p><input type="file" name="mail_csv" accept=".csv"> <button class="button" name="mail_prova" value="1">Prova</button> <button class="button button-primary" name="mail_applica" value="1" onclick="return confirm(\'Ripristinare le email?\')">Applica</button></p></form><hr>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dirnet_importa_territori">';
		wp_nonce_field( 'dirnet_importa_territori' );
		echo '<h2>Coordinate mancanti</h2><p>L\'elenco di GitHub contiene solo i nomi dei comuni, non le coordinate: la mappa mostra una scheda solo se ha latitudine e longitudine. Questo tasto le cerca su OpenStreetMap (indirizzo + comune; se l\'indirizzo non si trova, il centro del comune). Circa 10 schede alla volta, prosegue da solo.</p><p><button class="button button-primary" id="dirnet-coord" name="coord_run" value="1">Trova le coordinate</button> <label><input type="checkbox" name="coord_reset" value="1"> riprova anche le schede già tentate</label></p><hr>';
		if ( ! empty( $_GET['dirnet_coord_continue'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<p><em>Ricerca coordinate in corso, la pagina prosegue da sola…</em></p><script>setTimeout(function(){var b=document.getElementById("dirnet-coord");if(b){b.click();}},1500);</script>';
		}
		echo '<h2>Elenco dei comuni da GitHub</h2><p>Scarica (o riscarica) l\'elenco dei comuni italiani usato dai moduli e per completare l\'indirizzo delle schede. Si aggiorna da solo ogni mese: questo tasto serve per forzarlo. Data ultimo scaricamento: ' . esc_html( Geo::data() ? 'elenco presente' : 'elenco non ancora scaricato' ) . '.</p><p><button class="button button-primary" name="geo_refresh" value="1">Scarica elenco da GitHub</button></p><hr>';
		echo '<h2>Carica territori da GitHub</h2><p>Facoltativo: crea in anticipo tutti i comuni (Italia, regioni e province sono già creati dal setup) dell\'elenco di GitHub, utilizzabili in tutte le directory. Si può ripetere: i territori esistenti non vengono duplicati. Se è lungo, riprende da solo.</p>';
		echo '<p><button class="button button-primary" id="dirnet-geo-seed" name="geo_seed" value="1">Carica territori</button> <label><input type="checkbox" name="geo_restart" value="1"> riparti dall\'inizio</label></p>';
		if ( ! empty( $_GET['dirnet_geo_continue'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<p><em>Caricamento in corso, la pagina prosegue da sola…</em></p><script>setTimeout(function(){var b=document.getElementById("dirnet-geo-seed");if(b){b.click();}},1500);</script>';
		}
		echo '<h2>Ripristina le località delle schede</h2><p>Prima di ogni «Applica» viene salvata la posizione di tutte le schede. Qui le rimetti dove erano all\'ultimo salvataggio (' . esc_html( (string) get_option( 'dirnet_loc_snapshot', 'nessuno snapshot' ) ) . ').</p><p><button class="button" name="ripristina_localita" value="1" onclick="return confirm(\'Ripristinare le località delle schede dall\'ultimo snapshot?\')">Ripristina località</button></p>';
		echo '<h2>Normalizza regione, provincia e comune (GitHub)</h2><p>Un solo passaggio: unisce le sigle alle province, mette regioni, province e comuni al posto giusto secondo l\'elenco ISTAT scaricato da GitHub (nomi in forma italiana), unisce i doppioni e aggiunge agli annunci le località superiori mancanti. <b>Fai prima la Prova</b>: il resoconto elenca ogni spostamento e unione.</p>';
		echo '<p><label><input type="checkbox" name="prune" value="1"> Elimina anche le province fuori elenco, se vuote e senza comuni</label></p>';
		echo '<p><button class="button" name="normalizza_prova" value="1">Prova</button> <button class="button button-primary" name="normalizza_applica" value="1" onclick="return confirm(\'Applicare la normalizzazione? Gli annunci vengono spostati e i doppioni uniti.\')">Applica</button></p></form><hr>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dirnet_importa_territori">';
		wp_nonce_field( 'dirnet_importa_territori' );
		echo '<h2>1. Sigle di provincia da unire</h2><p>Unisce i territori chiamati solo con la sigla (per esempio <b>VT</b>) alla provincia esistente (<b>Viterbo (VT)</b>): sposta i soggetti e i sotto-territori, poi elimina la sigla. Una sigla senza provincia corrispondente non viene toccata.</p><p><button class="button" name="sigle_prova" value="1">Prova</button> <button class="button button-primary" name="sigle_applica" value="1" onclick="return confirm(\'Unire le sigle alle province?\')">Unisci</button></p></form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dirnet_importa_territori">';
		wp_nonce_field( 'dirnet_importa_territori' );
		echo '<hr><h2>Città doppie da unire</h2><p>Se la stessa città compare due volte sotto la stessa provincia (per esempio dopo aver unito le sigle), le unisce: i soggetti e i sotto-territori vanno sulla voce che ne ha di più, l\'altra viene eliminata. Vale per qualunque territorio con lo stesso nome sotto lo stesso genitore.</p><p><button class="button" name="doppie_prova" value="1">Prova</button> <button class="button button-primary" name="doppie_applica" value="1" onclick="return confirm(\'Unire i territori doppi?\')">Unisci</button></p></form>';
		echo '<hr><h2>2. Allinea regioni e province</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dirnet_importa_territori">';
		wp_nonce_field( 'dirnet_importa_territori' );
		echo '<p><label><input type="checkbox" name="prune" value="1"> Elimina anche le province fuori elenco, se vuote e senza comuni</label></p>';
		echo '<p><button class="button" name="prova" value="1">Prova</button> <button class="button button-primary" name="applica" value="1" onclick="return confirm(\'Applicare le modifiche?\')">Applica</button></p>';
		echo '<hr><h2>Annunci: regione, provincia e categoria</h2><p>Aggiunge a ogni annuncio le località e le categorie superiori a quelle che ha già (un annuncio in una sottocategoria viene assegnato anche alla categoria): un annuncio in un comune viene assegnato anche alla sua provincia e alla sua regione, uno in una provincia anche alla regione. Non toglie nulla. Da lanciare dopo l\'allineamento qui sopra.</p><p><button class="button" name="antenati_prova" value="1">Prova</button> <button class="button button-primary" name="antenati_applica" value="1" onclick="return confirm(\'Applicare agli annunci?\')">Applica agli annunci</button></p></form>';
		self::manca_table();
		$html = ob_get_clean();
		echo $nested ? str_replace( array( '<h2>', '</h2>' ), array( '<h3>', '</h3>' ), $html ) : $html; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** Puts back the e-mail of the CSV on the entries that hold an administrator's e-mail. Match: tax code, then title. */
	private static function ripristina_email( $file, $apply ) {
		$adm = array( strtolower( (string) get_option( 'admin_email' ) ) );
		foreach ( get_users( array( 'capability' => 'manage_options', 'fields' => array( 'user_email' ) ) ) as $u ) {
			$adm[] = strtolower( $u->user_email );
		}
		$raw = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$raw = preg_replace( '/^\xEF\xBB\xBF/', '', $raw );
		$fh  = fopen( 'php://memory', 'r+' );
		fwrite( $fh, $raw );
		rewind( $fh );
		$first = (string) strtok( $raw, "\n" );
		$sep   = substr_count( $first, ';' ) >= substr_count( $first, ',' ) ? ';' : ',';
		$head  = fgetcsv( $fh, 0, $sep );
		$ix    = is_array( $head ) ? array_flip( array_map( 'trim', $head ) ) : array();
		if ( ! isset( $ix['email'], $ix['title'] ) ) {
			return array( 'Il CSV deve avere le colonne title ed email.' );
		}
		$norm = function ( $t ) { return preg_replace( '/[^a-z0-9]+/', '', strtolower( remove_accents( html_entity_decode( (string) $t, ENT_QUOTES, 'UTF-8' ) ) ) ); };
		$by_tax = array();
		$by_ttl = array();
		while ( ( $r = fgetcsv( $fh, 0, $sep ) ) ) {
			$e = sanitize_email( trim( (string) ( $r[ $ix['email'] ] ?? '' ) ) );
			if ( ! is_email( $e ) ) {
				continue;
			}
			$t = isset( $ix['tax_code'] ) ? strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $r[ $ix['tax_code'] ] ?? '' ) ) ) : '';
			if ( strlen( $t ) >= 8 ) {
				$by_tax[ $t ] = $e;
			}
			$by_ttl[ $norm( $r[ $ix['title'] ] ?? '' ) ] = $e;
		}
		fclose( $fh );
		$ids = get_posts( array( 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_query' => array( array( 'key' => '_email', 'value' => $adm, 'compare' => 'IN' ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$ok = 0;
		$no = array();
		foreach ( $ids as $id ) {
			$t = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) get_post_meta( $id, '_tax_code', true ) ) );
			$e = ( strlen( $t ) >= 8 && isset( $by_tax[ $t ] ) ) ? $by_tax[ $t ] : ( $by_ttl[ $norm( get_the_title( $id ) ) ] ?? '' );
			if ( '' === $e ) {
				$no[] = '#' . $id . ' ' . get_the_title( $id ) . ': non trovata nel CSV (email lasciata com\'è)';
				continue;
			}
			if ( $apply ) {
				update_post_meta( $id, '_email', $e );
			}
			++$ok;
		}
		return array_merge( array( ( $apply ? 'Ripristinate ' : 'Da ripristinare: ' ) . $ok . ' schede su ' . count( $ids ) . ' con email di amministratore.' ), $no );
	}

	/** Finds the coordinates (OpenStreetMap Nominatim, 1 request per second) of the entries that have none. Returns [more, log]. */
	private static function trova_coordinate( $max ) {
		$ids = get_posts( array(
			'post_type' => ATBDP_POST_TYPE, 'post_status' => 'any', 'numberposts' => $max, 'fields' => 'ids',
			'meta_query' => array( 'relation' => 'AND',
				array( 'relation' => 'OR', array( 'key' => '_manual_lat', 'compare' => 'NOT EXISTS' ), array( 'key' => '_manual_lat', 'value' => '' ) ),
				array( 'key' => '_dirnet_coord_tried', 'compare' => 'NOT EXISTS' ),
			),
		) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$ok = 0;
		foreach ( $ids as $id ) {
			update_post_meta( $id, '_dirnet_coord_tried', time() );
			$loc = array();
			$terms = wp_get_object_terms( $id, ATBDP_LOCATION );
			$deep  = null;
			$dd    = -1;
			foreach ( is_array( $terms ) ? $terms : array() as $t ) {
				$d = count( get_ancestors( $t->term_id, ATBDP_LOCATION, 'taxonomy' ) );
				if ( $d > $dd ) {
					$dd   = $d;
					$deep = $t;
				}
			}
			if ( $deep ) {
				$loc[] = $deep->name;
				foreach ( get_ancestors( $deep->term_id, ATBDP_LOCATION, 'taxonomy' ) as $a ) {
					$tt = get_term( $a, ATBDP_LOCATION );
					if ( $tt && ! is_wp_error( $tt ) ) {
						$loc[] = $tt->name;
					}
				}
			}
			$addr = trim( (string) get_post_meta( $id, '_address', true ) );
			$zip  = trim( (string) get_post_meta( $id, '_zip', true ) );
			$tries = array();
			if ( $addr && $loc ) {
				$tries[] = implode( ', ', array_filter( array( $addr, $zip, implode( ', ', $loc ) ) ) );
			}
			if ( $loc ) {
				$tries[] = implode( ', ', $loc ); // the town alone: the marker goes on the town
			}
			foreach ( $tries as $q ) {
				usleep( 1100000 );
				$res = wp_remote_get( add_query_arg( array( 'format' => 'json', 'limit' => 1, 'countrycodes' => 'it', 'q' => $q ), 'https://nominatim.openstreetmap.org/search' ), array( 'timeout' => 10, 'user-agent' => 'DirectoristNetwork/' . DIRNET_VERSION . ' (WordPress)' ) );
				if ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) ) {
					$j = json_decode( wp_remote_retrieve_body( $res ), true );
					if ( ! empty( $j[0]['lat'] ) ) {
						update_post_meta( $id, '_manual_lat', (string) $j[0]['lat'] );
						update_post_meta( $id, '_manual_lng', (string) $j[0]['lon'] );
						++$ok;
						break;
					}
				}
			}
		}
		$left = count( get_posts( array( 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'meta_query' => array( 'relation' => 'AND', array( 'relation' => 'OR', array( 'key' => '_manual_lat', 'compare' => 'NOT EXISTS' ), array( 'key' => '_manual_lat', 'value' => '' ) ), array( 'key' => '_dirnet_coord_tried', 'compare' => 'NOT EXISTS' ) ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		return array( (bool) $left, 'Coordinate trovate: ' . $ok . ' su ' . count( $ids ) . ' schede elaborate' . ( $left ? ' (si prosegue da solo)' : ' (finito: quelle senza coordinate restano fuori mappa, sono senza località)' ) . '.' );
	}
}
