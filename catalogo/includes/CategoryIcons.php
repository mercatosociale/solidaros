<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Icon inside the map pin. Directorist already draws a standard pin (circle with a point) and puts the icon of the
 * entry's first category inside it, read from the term meta "category_icon". This class gives every category a
 * default icon from data/category-icons.json (by name); subcategories take their parent's icon. Icons already set
 * are never overwritten, and you can change any of them in Directorist > Categories (icon picker).
 */
class CategoryIcons {

	public function boot() {
		if ( ! defined( 'ATBDP_CATEGORY' ) ) {
			return;
		}
		add_action( 'created_' . ATBDP_CATEGORY, array( $this, 'on_created' ) );
		add_action( ATBDP_CATEGORY . '_add_form_fields', array( $this, 'field_add' ) );
		add_action( ATBDP_CATEGORY . '_edit_form_fields', array( $this, 'field_edit' ) );
		add_action( 'created_' . ATBDP_CATEGORY, array( $this, 'save_color' ), 20 );
		add_action( 'edited_' . ATBDP_CATEGORY, array( $this, 'save_color' ), 20 );
		add_action( 'wp_head', array( $this, 'css' ) );
	}

	const COLOR = '_dirnet_color';

	public function field_add() {
		echo '<div class="form-field"><label for="dirnet_color">' . esc_html__( 'Map pin colour', 'directorist-network' ) . '</label><input type="color" id="dirnet_color" name="dirnet_color" value="#3d85c6" /></div>';
	}

	public function field_edit( $term ) {
		$c = (string) get_term_meta( $term->term_id, self::COLOR, true );
		echo '<tr class="form-field"><th scope="row"><label for="dirnet_color">' . esc_html__( 'Map pin colour', 'directorist-network' ) . '</label></th><td><input type="color" id="dirnet_color" name="dirnet_color" value="' . esc_attr( $c ? $c : '#3d85c6' ) . '" /></td></tr>';
	}

	public function save_color( $term_id ) {
		if ( isset( $_POST['dirnet_color'] ) && preg_match( '/^#[0-9a-f]{6}$/i', wp_unslash( $_POST['dirnet_color'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- WordPress verifies the term form
			update_term_meta( $term_id, self::COLOR, wp_unslash( $_POST['dirnet_color'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
	}

	/** The pin takes the colour of its category: Directorist's marker CSS is overridden for pins that contain that category's icon. */
	public function css() {
		$terms = get_terms( array( 'taxonomy' => ATBDP_CATEGORY, 'hide_empty' => false ) );
		$rules = array();
		foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) {
			$icon  = (string) get_term_meta( $t->term_id, 'category_icon', true );
			$color = (string) get_term_meta( $t->term_id, self::COLOR, true );
			if ( '' === $icon || ! preg_match( '/^#[0-9a-f]{6}$/i', $color ) || ! preg_match( '/([a-z0-9-]+)$/i', trim( $icon ), $m ) ) {
				continue;
			}
			$file = preg_replace( '/^(fa|la|uil)-/', '', $m[1] );
			$rules[ $file ] = '.atbd_map_shape:has(i[style*="/' . esc_attr( $file ) . '.svg"]){background:' . $color . ' !important;--directorist-color-marker-shape:' . $color . ';--directorist-color-marker-shape-rgb:' . implode( ',', array_map( 'hexdec', str_split( substr( $color, 1 ), 2 ) ) ) . '}';
		}
		if ( $rules ) {
			echo '<style id="dirnet-pin-colors">' . implode( '', $rules ) . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	private static function map() {
		$d = json_decode( (string) file_get_contents( DIRNET_PATH . 'data/category-icons.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$m = array();
		foreach ( isset( $d['icons'] ) ? (array) $d['icons'] : array() as $name => $icon ) {
			$m[ self::key( $name ) ] = array( $icon, isset( $d['colors'][ $name ] ) ? $d['colors'][ $name ] : '' );
		}
		return $m;
	}

	private static function key( $name ) {
		return strtolower( trim( remove_accents( html_entity_decode( (string) $name ) ) ) );
	}

	/** Set icon and colour of one category if it has none; returns true when something was set. */
	public static function apply( $term_id, $map = null ) {
		$map  = null === $map ? self::map() : $map;
		$term = get_term( $term_id, ATBDP_CATEGORY );
		if ( ! $term || is_wp_error( $term ) ) {
			return false;
		}
		$own  = isset( $map[ self::key( $term->name ) ] ) ? $map[ self::key( $term->name ) ] : array( '', '' );
		$done = false;
		foreach ( array( 'category_icon' => $own[0], self::COLOR => $own[1] ) as $meta => $val ) {
			if ( get_term_meta( $term_id, $meta, true ) ) {
				continue;
			}
			if ( ! $val && $term->parent ) {
				$val = (string) get_term_meta( $term->parent, $meta, true ); // subcategories follow their parent
			}
			if ( $val ) {
				update_term_meta( $term_id, $meta, $val );
				$done = true;
			}
		}
		return $done;
	}

	public function on_created( $term_id ) {
		self::apply( (int) $term_id );
	}

	/** All categories without an icon (top level first, so children can inherit). Returns a log line. */
	public static function apply_all() {
		if ( ! defined( 'ATBDP_CATEGORY' ) || ! taxonomy_exists( ATBDP_CATEGORY ) ) {
			return '';
		}
		$map   = self::map();
		$terms = get_terms( array( 'taxonomy' => ATBDP_CATEGORY, 'hide_empty' => false, 'orderby' => 'parent' ) );
		$n     = 0;
		foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) {
			if ( self::apply( (int) $t->term_id, $map ) ) {
				$n++;
			}
		}
		return sprintf( /* translators: %d: number of categories */ __( 'Map icon set on %d categories (change it in Directorist > Categories).', 'directorist-network' ), $n );
	}
}
