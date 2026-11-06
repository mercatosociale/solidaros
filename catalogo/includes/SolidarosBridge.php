<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connection with Solidaros through public WordPress hooks only: nothing here names a Solidaros class,
 * and nothing happens if Solidaros is not installed.
 *
 * - Solidaros asks which company the logged-in user belongs to (filter solidaros_user_company_tax_id):
 *   we answer with the key of the entity that has the user's e-mail.
 * - The social report published by Solidaros is read through its public filter solicat_bilancio_sociale.
 */
class SolidarosBridge {

	const TAX_META = '_tax_code';

	private static $cache  = array();
	private static $sheets = array();

	public function boot() {
		add_filter( 'solidaros_user_company_tax_id', array( $this, 'company_of_user' ), 10, 2 );
		add_filter( 'dirnet_company_sheet_url', array( $this, 'company_sheet' ), 10, 2 );
		add_filter( 'dirnet_product_sheets', array( $this, 'product_sheets' ), 10, 2 );
		add_filter( 'dirnet_sourceprice_sheet_url', array( $this, 'sourceprice_sheet' ), 10, 2 );
		add_filter( 'dirnet_pgs_badge', array( $this, 'pgs_badge' ), 10, 3 );
		add_filter( 'solidaros_pages_registry', array( $this, 'registry' ) );
		add_filter( 'solidaros_page_url', array( $this, 'page_url' ), 10, 2 );
		add_filter( 'solidaros_producer_email', array( $this, 'email_of_company' ), 10, 2 );
	}

	/** Completed questionnaires with a published booklet, newest first (rows: section, year, label, booklet_url). */
	private static function booklets( $post_id ) {
		$post_id = (int) $post_id;
		if ( ! isset( self::$sheets[ $post_id ] ) ) {
			$rows = has_filter( 'solicat_questionari_soggetto' ) ? apply_filters( 'solicat_questionari_soggetto', array(), self::company_id( $post_id ) ) : array();
			$out  = array();
			foreach ( (array) $rows as $r ) {
				if ( is_array( $r ) && ! empty( $r['booklet_url'] ) && ! empty( $r['section'] ) ) {
					$out[] = $r;
				}
			}
			usort( $out, static function ( $a, $b ) {
				return (int) ( isset( $b['year'] ) ? $b['year'] : 0 ) <=> (int) ( isset( $a['year'] ) ? $a['year'] : 0 );
			} );
			self::$sheets[ $post_id ] = $out;
		}
		return self::$sheets[ $post_id ];
	}

	/** Earlier questionnaires of this entity with a published booklet, grouped by kind: [ title => [ [year, url], ... ] ]. */
	public static function history( $post_id ) {
		$seen = array();
		$out  = array();
		foreach ( self::booklets( $post_id ) as $r ) { // newest first
			$sec = (string) $r['section'];
			if ( ! isset( $seen[ $sec ] ) ) {
				$seen[ $sec ] = true; // the latest of each kind is the main link
				continue;
			}
			if ( 'xes' === $sec ) {
				$title = __( 'Social report', 'directorist-network' );
			} elseif ( 'entity' === $sec || 'places' === $sec ) { // 'places' = legacy entity profile
				$title = __( 'Entity profile', 'directorist-network' );
			} else {
				$name  = isset( $r['label'] ) && '' !== $r['label'] ? (string) $r['label'] : ( 0 === strpos( $sec, 'products/' ) ? substr( $sec, 9 ) : $sec );
				$title = sprintf( /* translators: %s: product type */ __( 'Product profile: %s', 'directorist-network' ), $name );
			}
			$out[ $title ][] = array( isset( $r['year'] ) ? (int) $r['year'] : 0, (string) $r['booklet_url'] );
		}
		return $out;
	}

	/**
	 * The self-certification of an entity as links: Bilancio sociale, then the questionnaires of the
	 * participatory self-certification (Scheda Azienda, Scheda Prodotti, Scheda Prezzo sorgente), and the
	 * evaluated version when one exists. Empty array = no self-certification.
	 */
	public static function selfcert( $post_id ) {
		$out  = array( 'balance' => null, 'entity' => null, 'products' => array(), 'source' => null, 'eval' => null );
		$seen = array();
		foreach ( self::booklets( $post_id ) as $r ) { // newest first
			$sec = (string) $r['section'];
			if ( isset( $seen[ $sec ] ) ) {
				continue;
			}
			$seen[ $sec ] = true;
			$row          = array( isset( $r['year'] ) ? (int) $r['year'] : 0, (string) $r['booklet_url'] );
			if ( 'xes' === $sec ) {
				$out['balance'] = $row;
			} elseif ( 'entity' === $sec || 'places' === $sec ) {
				$out['entity'] = $out['entity'] ? $out['entity'] : $row;
			} elseif ( 'sourceprice' === $sec ) {
				$out['source'] = $row;
			} elseif ( 0 === strpos( $sec, 'products/' ) ) {
				$out['products'][] = array( isset( $r['label'] ) && '' !== $r['label'] ? (string) $r['label'] : substr( $sec, 9 ), $row[1], $row[0] );
			}
		}
		if ( ! $out['balance'] && ! $out['entity'] && ! $out['source'] && ! $out['products'] ) {
			return array();
		}
		$st = self::pgs_status( $post_id, 'entity' );
		if ( $st && ! empty( $st['pdf_url'] ) ) {
			$out['eval'] = array( isset( $st['year'] ) ? (int) $st['year'] : 0, (string) $st['pdf_url'] );
		} elseif ( is_array( $st ) && isset( $st[3] ) && '' !== (string) $st[3] ) {
			$out['eval'] = array( isset( $st[1] ) ? (int) $st[1] : 0, (string) $st[3] );
		}
		return $out;
	}

	/** Entity profile = the latest places questionnaire. The published booklet wins; a link entered by hand is the fallback. */
	public function company_sheet( $url, $post_id ) {
		foreach ( array( 'entity', 'places' ) as $want ) { // Solidaros 3: 'entity'; 'places' only for booklets made before
			foreach ( self::booklets( $post_id ) as $r ) {
				if ( $want === $r['section'] ) {
					return (string) $r['booklet_url'];
				}
			}
		}
		return $url;
	}

	/** Product profile = the latest questionnaire of each product type. Links entered by hand come first. */
	public function product_sheets( $items, $post_id ) {
		$items = (array) $items;
		$seen  = array();
		foreach ( self::booklets( $post_id ) as $r ) {
			if ( 0 === strpos( $r['section'], 'products/' ) && ! isset( $seen[ $r['section'] ] ) ) {
				$seen[ $r['section'] ] = true;
				$st      = self::pgs_status( $post_id, (string) $r['section'] );
				$items[] = array( isset( $r['label'] ) && '' !== $r['label'] ? (string) $r['label'] : substr( $r['section'], 9 ), (string) $r['booklet_url'], ! empty( $st['badge'] ) );
			}
		}
		return $items;
	}

	/** Source price sheet = the latest 'sourceprice' booklet (declared by the producer). */
	public function sourceprice_sheet( $url, $post_id ) {
		foreach ( self::booklets( $post_id ) as $r ) {
			if ( 'sourceprice' === $r['section'] ) {
				return (string) $r['booklet_url'];
			}
		}
		return $url;
	}

	/** PGS badge of an entity sheet or product sheet: only "Garantito" has it. Returns [ true, evaluation PDF url ] or null. */
	public function pgs_badge( $badge, $post_id, $section ) {
		$st = self::pgs_status( $post_id, (string) $section );
		return ( $st && ! empty( $st['badge'] ) ) ? array( true, (string) ( $st['pdf_url'] ?? '' ) ) : $badge;
	}

	/** Our pages host sign-in and the questionnaires; Solidaros keeps only the pages we do not have. */
	/** Two modes: with WordPress users this plugin hosts sign-in and questionnaires; standalone, Solidaros runs its own pages. */
	/** The Autocertificazione module is on (the Solidaros classes are always loadable, so the module switch decides). */
	public static function active() {
		return class_exists( '\\Solidaros\\Core\\Modules' ) && \Solidaros\Core\Modules::enabled( \Solidaros\Core\Modules::SELFCERT );
	}

	public static function delegated() {
		return 'token' !== get_option( 'solidaros_auth_mode', 'wp' );
	}

	public function registry( $reg ) {
		if ( ! self::delegated() ) {
			return $reg;
		}
		foreach ( array_keys( (array) $reg ) as $key ) {
			unset( $reg[ $key ] ); // Solidaros keeps no page of its own here
		}
		return $reg;
	}

	/** Solidaros links (sign-in, questionnaires) point to our pages. */
	public function page_url( $url, $key ) {
		if ( ! self::delegated() ) {
			// Standalone: Solidaros keeps its own pages; where it has none (the link would be the home page) use ours.
			$own_id = preg_match( '/[?&]page_id=(\d+)/', (string) $url, $pm ) ? (int) $pm[1] : 0; // plain-permalink address of an unpublished page
			$stale  = $own_id && 'publish' !== get_post_status( $own_id ); // the Solidaros page was deleted, trashed or unpublished: the link would give "page not found"
			if ( ! $stale && untrailingslashit( (string) $url ) !== untrailingslashit( home_url( '/' ) ) ) {
				return $url;
			}
		}
		if ( in_array( $key, array( 'mydocs', 'wizard', 'evalwizard', 'evaluator' ), true ) ) { // pages chosen in the system pages
			$own = Pages::id( 'mydocs' === $key ? 'area' : $key );
			if ( $own && 'publish' === get_post_status( $own ) ) {
				return (string) get_permalink( $own );
			}
		}
		if ( 'mydocs' === $key ) { // Solidaros no longer makes pages: "My documents" lives in the dashboard (Questionnaires)
			$d = (int) get_option( 'dirnet_myspace_page' );
			if ( $d && 'publish' === get_post_status( $d ) ) {
				return (string) get_permalink( $d ) . '#dirnet_questionnaires'; // Directorist opens the tab named in the address
			}
		}
		if ( 'login' === $key ) {
			$enter = (int) get_option( 'dirnet_enter_page' );
			if ( $enter && 'publish' === get_post_status( $enter ) ) {
				return (string) get_permalink( $enter );
			}
		}
		$map = array( 'register' => 'login', 'sourceprice' => 'sourceprice', 'products' => 'product', 'pgs' => 'pgs', 'login' => 'login', 'xes' => 'report', 'entity' => 'entity', 'places' => 'entity' );
		$own = isset( $map[ $key ] ) ? $map[ $key ] : ( 0 === strpos( (string) $key, 'products_' ) ? 'product' : '' );
		$id  = '' !== $own ? Pages::id( $own ) : 0;
		return $id ? (string) get_permalink( $id ) : $url;
	}

	/** PGS status of an entity sheet ('entity') or product sheet ('products/<type>') from Solidaros 3: [status, year, valid_until, pdf_url, badge] or null. Only "Garantito" has badge = true. */
	public static function pgs_status( $post_id, $section = 'entity' ) {
		if ( ! has_filter( 'solidaros_pgs_status' ) ) {
			return null;
		}
		$r = apply_filters( 'solidaros_pgs_status', null, self::company_id( $post_id ), (string) $section );
		return is_array( $r ) ? $r : null;
	}

	/** Company key used with Solidaros: the ID of the entity (one key everywhere). */
	public static function company_id( $post_id ) {
		return (string) (int) $post_id;
	}

	/** The entity behind a company key: its ID, "DN-<id>" or (legacy) its tax code. */
	private static function entity_of( $company ) {
		$company = trim( (string) $company );
		if ( '' === $company ) {
			return 0;
		}
		if ( ctype_digit( $company ) ) {
			return ATBDP_POST_TYPE === get_post_type( (int) $company ) ? (int) $company : 0;
		}
		if ( 0 === strpos( $company, 'DN-' ) ) {
			$id = (int) substr( $company, 3 );
			return ( $id && ATBDP_POST_TYPE === get_post_type( $id ) ) ? $id : 0;
		}
		$ids = get_posts( array( 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'any', 'fields' => 'ids', 'numberposts' => 1, 'meta_query' => array( array( 'key' => self::TAX_META, 'value' => $company ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		return $ids ? (int) $ids[0] : 0;
	}

	/** The e-mail is the key: the user's entity is the one with the user's e-mail (or owned by the user). */
	public function company_of_user( $tax_id, $user_id ) {
		$user = get_user_by( 'id', (int) $user_id );
		if ( ! $user ) {
			return $tax_id;
		}
		$ids = get_posts( array( 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'any', 'fields' => 'ids', 'numberposts' => 1, 'meta_query' => array( array( 'key' => '_email', 'value' => $user->user_email ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( ! $ids ) {
			$ids = get_posts( array( 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'any', 'fields' => 'ids', 'numberposts' => 1, 'author' => (int) $user_id ) );
		}
		if ( $ids ) {
			if ( (string) get_user_meta( (int) $user_id, 'sm_entity_id', true ) !== (string) $ids[0] ) {
				update_user_meta( (int) $user_id, 'sm_entity_id', (string) $ids[0] ); // the key Solidaros uses, kept in step with the entry
			}
			return self::company_id( $ids[0] );
		}
		return $tax_id;
	}

	/** Solidaros writes to the producer: answer with the e-mail of the entity. */
	public function email_of_company( $email, $company ) {
		if ( is_email( $email ) ) {
			return $email;
		}
		$id = self::entity_of( $company );
		return $id ? (string) get_post_meta( $id, '_email', true ) : $email;
	}

	/** URL of the latest social report booklet published by Solidaros for this entity, or ''. */
	public static function report_url( $post_id ) {
		$post_id = (int) $post_id;
		if ( isset( self::$cache[ $post_id ] ) ) {
			return self::$cache[ $post_id ];
		}
		$url = '';
		if ( has_filter( 'solicat_bilancio_sociale' ) ) {
			$r = apply_filters( 'solicat_bilancio_sociale', null, self::company_id( $post_id ) );
			if ( is_array( $r ) && ! empty( $r['url'] ) ) {
				$url = (string) $r['url'];
			}
		}
		self::$cache[ $post_id ] = $url;
		return $url;
	}
}
