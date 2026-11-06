<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Functional profile of an entity (what it does), separate from its legal profile (what it is: association,
 * cooperative, company, informal group...). One entity has one record and any number of functions.
 * CSV column "functions" (also "funzioni"): values separated by ";". Empty = the function of its directory type.
 */
class Functions {

	const TAX = 'dirnet_function';

	public function boot() {
		add_action( 'init', array( $this, 'register' ), 20 );
		add_filter( 'the_content', array( $this, 'single' ), 19 );
		add_action( 'add_meta_boxes', array( $this, 'box' ) );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
		add_action( 'admin_post_dirnet_fn_merge', array( $this, 'merge_handle' ) );
	}


	const DESC = '_dirnet_fn_desc';

	public static function descs( $post_id ) {
		$d = get_post_meta( $post_id, self::DESC, true );
		return is_array( $d ) ? $d : array();
	}

	public static function set_desc( $post_id, $slug, $text ) {
		$d = self::descs( $post_id );
		$text = trim( (string) $text );
		if ( '' === $text ) {
			unset( $d[ $slug ] );
		} else {
			$d[ $slug ] = wp_kses_post( $text );
		}
		update_post_meta( $post_id, self::DESC, $d );
	}

	/** Legal profile + functional profile (each function links to the list of all entities with it) + one description per function. */
	public function single( $content ) {
		static $busy = false;
		if ( $busy || ! defined( 'ATBDP_POST_TYPE' ) || ! is_singular( ATBDP_POST_TYPE ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$busy  = true;
		$id    = get_the_ID();
		$terms = taxonomy_exists( self::TAX ) ? wp_get_object_terms( $id, self::TAX ) : array();
		$legal = '';
		$dir   = Entities::page_url();
		$legal = Entities::legal_label( $id );
		$busy = false;
		if ( ! $terms && '' === $legal ) {
			return $content;
		}
		$h = '<div class="dirnet-profile" style="margin:0 0 16px;padding:10px 12px;border:1px solid #ddd;border-radius:6px">';
		if ( '' !== $legal ) {
			$h .= '<p style="margin:0 0 6px"><b>' . esc_html__( 'Legal profile', 'directorist-network' ) . ':</b> ' . esc_html( $legal ) . '</p>';
		}
		if ( $terms ) {
			$h .= '<p style="margin:0"><b>' . esc_html__( 'Roles', 'directorist-network' ) . ':</b> ';
			foreach ( $terms as $t ) {
				$h .= '<a href="' . esc_url( add_query_arg( 'fn', $t->slug, $dir ) ) . '" style="display:inline-block;margin:2px 4px 2px 0;padding:2px 10px;border-radius:12px;color:#fff;text-decoration:none;background:' . esc_attr( self::color( $t ) ) . '">' . esc_html( $t->name ) . '</a>';
			}
			$h .= '</p>';
		}
		$h .= '</div>';
		$d  = self::descs( $id );
		$s  = '';
		foreach ( $terms as $t ) {
			if ( ! empty( $d[ $t->slug ] ) ) {
				$s .= '<h3>' . esc_html( $t->name ) . '</h3>' . wpautop( $d[ $t->slug ] );
			}
		}
		return $h . $content . $s;
	}

	public function box() {
		if ( defined( 'ATBDP_POST_TYPE' ) ) {
			add_meta_box( 'dirnet_fn_desc', __( 'Description by role', 'directorist-network' ), array( $this, 'box_html' ), ATBDP_POST_TYPE, 'normal' );
		}
	}

	public function box_html( $post ) {
		wp_nonce_field( 'dirnet_fn_desc', 'dirnet_fn_nonce' );
		$d = self::descs( $post->ID );
		echo '<p>' . esc_html__( 'Assign the roles in the Roles box; write here what the entity does in each of them.', 'directorist-network' ) . '</p>';
		$terms = get_terms( array( 'taxonomy' => self::TAX, 'hide_empty' => false ) );
		foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) {
			echo '<p><label><b>' . esc_html( $t->name ) . '</b><br><textarea name="dirnet_fn_desc[' . esc_attr( $t->slug ) . ']" rows="3" style="width:100%">' . esc_textarea( isset( $d[ $t->slug ] ) ? $d[ $t->slug ] : '' ) . '</textarea></label></p>';
		}
	}

	public function save( $post_id, $post ) {
		if ( ! defined( 'ATBDP_POST_TYPE' ) || ATBDP_POST_TYPE !== $post->post_type || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		if ( ! isset( $_POST['dirnet_fn_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dirnet_fn_nonce'] ) ), 'dirnet_fn_desc' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$in = isset( $_POST['dirnet_fn_desc'] ) && is_array( $_POST['dirnet_fn_desc'] ) ? wp_unslash( $_POST['dirnet_fn_desc'] ) : array(); // phpcs:ignore
		$out = array();
		foreach ( $in as $slug => $text ) {
			if ( '' !== trim( $text ) ) {
				$out[ sanitize_title( $slug ) ] = wp_kses_post( $text );
			}
		}
		update_post_meta( $post_id, self::DESC, $out );
	}

	/** slug => [label, colour, aliases]. Flat list (no parents). Created once on an empty site, never again. */
	public static function defaults() {
		return array(
			'produttore' => array( __( 'Producers', 'directorist-network' ), '#2e7d32', array( 'produttore', 'produttori', 'producer', 'producers', 'azienda agricola' ) ),
			'gas'        => array( __( 'GAS', 'directorist-network' ), '#ef6c00', array( 'gas', 'gruppo di acquisto', 'gruppi di acquisto', 'gruppo di acquisto solidale' ) ),
			'botteghe-equosolidali' => array( __( 'Fair trade shops', 'directorist-network' ), '#6a1b9a', array( 'bottega', 'botteghe', 'bottega equosolidale', 'botteghe equosolidali', 'equosolidale', 'equosolidali', 'bottega del mondo', 'botteghe del mondo', 'commercio equo', 'commercio equo e solidale' ) ),
			'food-coop'  => array( __( 'Food coops and community stores', 'directorist-network' ), '#00838f', array( 'food coop', 'foodcoop', 'emporio', 'empori', 'emporio di comunita', 'empori di comunita' ) ),
			'csa'        => array( __( 'CSA', 'directorist-network' ), '#558b2f', array( 'csa', 'agricoltura sostenuta dalla comunita' ) ),
			'cer'        => array( __( 'CER', 'directorist-network' ), '#f9a825', array( 'cer', 'cers', 'ceris', 'comunita energetica', 'comunita energetiche', 'comunita energetica rinnovabile', 'energy community' ) ),
			'fiera'      => array( __( 'Markets and fairs', 'directorist-network' ), '#5d4037', array( 'fiera', 'fiere', 'mercato', 'mercati', 'mercato contadino', 'mercati contadini', 'evento', 'eventi', 'festival', 'fair', 'fairs', 'market', 'markets', 'fiere ed eventi', 'mercati e fiere' ) ),
			'orti-sociali' => array( __( 'Social gardens', 'directorist-network' ), '#689f38', array( 'orti sociali', 'orto sociale', 'orti urbani', 'orto urbano', 'orti comunitari', 'orto comunitario', 'giardino condiviso', 'giardini condivisi', 'community garden', 'community gardens', 'social garden', 'social gardens' ) ),
			'distributori' => array( __( 'Distributors', 'directorist-network' ), '#e65100', array( 'distributore', 'distributori', 'distributor', 'distributors', 'distribuzione' ) ),
			'altre-reti' => array( __( 'Other networks', 'directorist-network' ), '#1565c0', array( 'altre reti', 'rete', 'reti', 'network', 'networks', 'altra rete' ) ),
			'cohousing'  => array( __( 'Cohousing', 'directorist-network' ), '#ad1457', array( 'cohousing', 'co housing', 'abitare condiviso' ) ),
			'des-res'    => array( __( 'DES/RES', 'directorist-network' ), '#0d47a1', array( 'des', 'res', 'des res', 'distretto di economia solidale', 'rete di economia solidale' ) ),
		);
	}

	/** The site's term for a standard role: by slug, else by the name/slug of an alias (the admin may have different slugs). Returns the slug or ''. */
	private static function term_slug( $slug ) {
		$d = self::defaults();
		$t = get_term_by( 'slug', $slug, self::TAX );
		foreach ( isset( $d[ $slug ] ) ? array_merge( array( $d[ $slug ][0] ), $d[ $slug ][2] ) : array() as $a ) {
			if ( $t ) {
				break;
			}
			$t = get_term_by( 'slug', sanitize_title( $a ), self::TAX ) ?: get_term_by( 'name', $a, self::TAX );
		}
		return $t ? $t->slug : '';
	}

	private static function key( $s ) {
		return trim( preg_replace( '/[^a-z0-9]+/', ' ', strtolower( remove_accents( html_entity_decode( (string) $s ) ) ) ) );
	}

	/** Directory type slug of the profile => default function. */
	private static function type_default( $type_slug ) {
		$m = array( 'producer' => 'produttore', 'gas' => 'gas', 'network' => 'altre-reti', 'distributor' => 'distributori' );
		return isset( $m[ $type_slug ] ) ? $m[ $type_slug ] : '';
	}

	public function register() {
		if ( ! defined( 'ATBDP_POST_TYPE' ) ) {
			return;
		}
		register_taxonomy( self::TAX, ATBDP_POST_TYPE, array(
			'labels'            => array( 'name' => __( 'Roles', 'directorist-network' ), 'singular_name' => __( 'Role', 'directorist-network' ) ),
			'hierarchical'      => true, // roles and sub-roles (Shops > Fair trade shops, Food coops...)
			'public'            => false,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'rewrite'           => false,
		) );
	}

	/** Create the standard functions ONCE, only on a site that has none. Afterwards the admin owns the list: nothing is ever recreated. */
	public static function seed() {
		if ( get_option( 'dirnet_fn_seeded' ) || ! taxonomy_exists( self::TAX ) ) {
			return 0;
		}
		update_option( 'dirnet_fn_seeded', 1, false );
		$have = get_terms( array( 'taxonomy' => self::TAX, 'hide_empty' => false, 'number' => 1, 'fields' => 'ids' ) );
		if ( ! is_wp_error( $have ) && $have ) {
			return 0; // the site already has its own roles
		}
		$n = 0;
		foreach ( self::defaults() as $slug => $d ) {
			$r = wp_insert_term( $d[0], self::TAX, array( 'slug' => $slug ) );
			if ( ! is_wp_error( $r ) ) {
				update_term_meta( $r['term_id'], '_dirnet_color', $d[1] );
				$n++;
			}
		}
		return $n;
	}

	public static function color( $term ) {
		// sub-roles take the colour of their main role
		for ( $i = 0; $i < 5 && ! empty( $term->parent ); $i++ ) {
			$par = get_term( (int) $term->parent, self::TAX );
			if ( ! $par || is_wp_error( $par ) ) {
				break;
			}
			$term = $par;
		}
		// main roles: one distinct colour each (fixed for the standard ones, then a palette by order)
		$fixed = array( 'produzione' => '#2e7d32', 'distribuzione' => '#e65100', 'commercio' => '#6a1b9a', 'servizi' => '#ad1457', 'rete' => '#1565c0' );
		if ( isset( $fixed[ $term->slug ] ) ) {
			return $fixed[ $term->slug ];
		}
		$extra = array( '#00838f', '#827717', '#4e342e', '#c62828', '#283593', '#455a64', '#558b2f', '#6d4c41' );
		$tops  = get_terms( array( 'taxonomy' => self::TAX, 'parent' => 0, 'hide_empty' => false, 'orderby' => 'term_id' ) );
		$i     = 0;
		foreach ( is_wp_error( $tops ) ? array() : $tops as $t ) {
			if ( isset( $fixed[ $t->slug ] ) ) {
				continue;
			}
			if ( (int) $t->term_id === (int) $term->term_id ) {
				return $extra[ $i % count( $extra ) ];
			}
			$i++;
		}
		return 'hsl(' . ( abs( crc32( $term->slug ) ) % 360 ) . ',45%,35%)';
	}

	/** Set the functions of a listing from a CSV cell; an empty cell gives the function of its directory type. */
	public static function assign( $post_id, $raw, $type_slug = '' ) {
		if ( ! taxonomy_exists( self::TAX ) ) {
			return;
		}
		self::seed();
		$names = preg_split( '/\s*[;|]\s*/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY );
		$slugs = array();
		foreach ( $names as $nm ) {
			$k = self::key( $nm );
			$hit = '';
			foreach ( self::defaults() as $slug => $d ) {
				if ( $k === self::key( $d[0] ) || $k === $slug || in_array( $k, array_map( array( __CLASS__, 'key' ), $d[2] ), true ) ) {
					$hit = $slug;
					break;
				}
			}
			if ( '' === $hit ) { // a function the admin invents
				$t = term_exists( $nm, self::TAX );
				if ( ! $t ) {
					$t = wp_insert_term( $nm, self::TAX );
				}
				if ( ! is_wp_error( $t ) ) {
					$hit = get_term( is_array( $t ) ? $t['term_id'] : $t, self::TAX )->slug;
				}
			}
			$hit = $hit && isset( self::defaults()[ $hit ] ) ? self::term_slug( $hit ) : $hit; // never creates a standard role that the admin has removed
			if ( $hit && get_term_by( 'slug', $hit, self::TAX ) ) {
				$slugs[] = $hit;
			}
		}
		if ( ! $slugs ) {
			$d = self::type_default( $type_slug );
			$d = $d ? self::term_slug( $d ) : '';
			if ( $d && ! wp_get_object_terms( $post_id, self::TAX, array( 'fields' => 'ids' ) ) ) {
				$slugs[] = $d;
			}
		}
		if ( $slugs ) {
			wp_set_object_terms( $post_id, array_values( array_unique( $slugs ) ), self::TAX );
		}
	}

	/** Move every entry of the function $from to $to (the entries keep their other functions), then delete $from. */
	public static function merge( $from, $to ) {
		$from = (int) $from;
		$to   = (int) $to;
		$a    = get_term( $from, self::TAX );
		$b    = get_term( $to, self::TAX );
		if ( ! $a || is_wp_error( $a ) || ! $b || is_wp_error( $b ) || $from === $to ) {
			return -1;
		}
		$n = 0;
		foreach ( get_objects_in_term( $from, self::TAX ) as $id ) {
			wp_set_object_terms( (int) $id, array( $to ), self::TAX, true ); // add the target, keep the rest
			wp_remove_object_terms( (int) $id, array( $from ), self::TAX );
			$d = self::descs( $id ); // the description written for the old function follows, unless the target already has one
			if ( isset( $d[ $a->slug ] ) ) {
				if ( empty( $d[ $b->slug ] ) ) {
					$d[ $b->slug ] = $d[ $a->slug ];
				}
				unset( $d[ $a->slug ] );
				update_post_meta( $id, self::DESC, $d );
			}
			$n++;
		}
		wp_delete_term( $from, self::TAX );
		return $n;
	}

	/** Data repair page: merge one role into another (move all the entries of one into the other). */
	public static function merge_panel() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( isset( $_GET['dirnet_merged'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( /* translators: %d: number of entries */ __( 'Roles merged: %d entries moved.', 'directorist-network' ), (int) $_GET['dirnet_merged'] ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		$terms = get_terms( array( 'taxonomy' => self::TAX, 'hide_empty' => false ) );
		if ( is_wp_error( $terms ) || count( $terms ) < 2 ) {
			return;
		}
		$old = 0;
		$new = 0;
		foreach ( $terms as $t ) {
			if ( 'bottega' === $t->slug ) {
				$new = $t->term_id;
			} elseif ( in_array( self::key( $t->name ), array( 'botteghe del mondo', 'bottega del mondo', 'world shops' ), true ) ) {
				$old = $t->term_id;
			}
		}
		$opts = static function ( $sel ) use ( $terms ) {
			$h = '';
			foreach ( $terms as $t ) {
				$h .= '<option value="' . (int) $t->term_id . '"' . selected( $sel, $t->term_id, false ) . '>' . esc_html( $t->name . ' (' . (int) $t->count . ')' ) . '</option>';
			}
			return $h;
		};
		echo '<div class="notice notice-info"><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(' . esc_attr( wp_json_encode( __( 'Move all the entries to the other role and delete this one?', 'directorist-network' ) ) ) . ')"><input type="hidden" name="action" value="dirnet_fn_merge" />' . wp_nonce_field( 'dirnet_fn_merge', '_wpnonce', true, false )
			. '<p><strong>' . esc_html__( 'Merge roles', 'directorist-network' ) . '</strong> ' . esc_html__( 'Move all the entries of', 'directorist-network' ) . ' <select name="from">' . $opts( $old ) . '</select> ' . esc_html__( 'into', 'directorist-network' ) . ' <select name="to">' . $opts( $new ) . '</select> <button class="button">' . esc_html__( 'Merge', 'directorist-network' ) . '</button></p></form></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public function merge_handle() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'dirnet_fn_merge' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'directorist-network' ) );
		}
		$n = self::merge( isset( $_POST['from'] ) ? (int) $_POST['from'] : 0, isset( $_POST['to'] ) ? (int) $_POST['to'] : 0 );
		wp_safe_redirect( add_query_arg( 'dirnet_merged', max( 0, $n ), admin_url( 'admin.php?page=dirnet-repairs' ) ) );
		exit;
	}
}
