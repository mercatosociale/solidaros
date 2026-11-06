<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Relations between listings, declared in profile.json ("relations").
 *
 * Storage (post meta):
 *   _dirnet_rel_<key>      on the source listing: array of target listing IDs
 *   _dirnet_by_<key>       on the target listing: array of source listing IDs (reverse side)
 *   _dirnet_pending_<key>  on the source listing: CSV tokens not resolved yet (target not imported yet)
 *
 * Directorist has no listing-to-listing relation of its own, so this is ours.
 */
class Relations {

	public function boot() {
		add_action( 'add_meta_boxes', array( $this, 'metabox' ) );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
		add_filter( 'directorist_field_template', array( $this, 'field_template' ), 20, 2 );
		add_filter( 'directorist_template_file_path', array( $this, 'field_file' ), 20, 2 );
		add_shortcode( 'dirnet_relations', array( $this, 'shortcode' ) );
		add_filter( 'the_content', array( $this, 'append' ), 20 );
		add_filter( 'directorist_the_content', array( $this, 'append_directorist' ), 20 ); // Directorist's own single page does not run the_content
	}

	/** @return array<int,array{0:string,1:string}> directory term id => [profile id, type slug] */
	public static function registry() {
		$out = array();
		foreach ( Generator::applied() as $pid => $types ) {
			foreach ( (array) $types as $slug => $term_id ) {
				$out[ (int) $term_id ] = array( $pid, $slug );
			}
		}
		return $out;
	}

	/** @return array{0:Profile,1:string}|null profile and type slug of a listing */
	public static function type_of( $post_id ) {
		$reg = self::registry();
		$dir = (int) get_post_meta( $post_id, '_directory_type', true );
		if ( ! $dir || empty( $reg[ $dir ] ) ) {
			return null;
		}
		$p = Profile::get( $reg[ $dir ][0] );
		return $p ? array( $p, $reg[ $dir ][1] ) : null;
	}

	/** Normalised relation definitions of a profile. */
	public static function defs( Profile $p ) {
		$out = array();
		foreach ( isset( $p->data['relations'] ) ? (array) $p->data['relations'] : array() as $r ) {
			if ( empty( $r['key'] ) || empty( $r['from'] ) || empty( $r['to'] ) ) {
				continue;
			}
			$out[ $r['key'] ] = array(
				'key'     => $r['key'],
				'from'    => (array) $r['from'],
				'to'      => $r['to'],
				'label'   => $p->t( isset( $r['label'] ) ? $r['label'] : $r['key'] ),
				'reverse' => $p->t( isset( $r['reverse_label'] ) ? $r['reverse_label'] : ( isset( $r['label'] ) ? $r['label'] : $r['key'] ) ),
			);
		}
		return $out;
	}

	public static function dir_id( Profile $p, $slug ) {
		$a = Generator::applied();
		return isset( $a[ $p->id ][ $slug ] ) ? (int) $a[ $p->id ][ $slug ] : 0;
	}

	/* ------------------------------------------------------------ storage */

	public static function set( $source_id, $key, array $target_ids ) {
		$target_ids = array_values( array_unique( array_filter( array_map( 'intval', $target_ids ) ) ) );
		$old        = (array) get_post_meta( $source_id, '_dirnet_rel_' . $key, true );
		foreach ( array_diff( $old, $target_ids ) as $t ) {
			$by = array_diff( (array) get_post_meta( $t, '_dirnet_by_' . $key, true ), array( $source_id ) );
			update_post_meta( $t, '_dirnet_by_' . $key, array_values( $by ) );
		}
		foreach ( $target_ids as $t ) {
			$by   = (array) get_post_meta( $t, '_dirnet_by_' . $key, true );
			$by[] = $source_id;
			update_post_meta( $t, '_dirnet_by_' . $key, array_values( array_unique( array_filter( array_map( 'intval', $by ) ) ) ) );
		}
		update_post_meta( $source_id, '_dirnet_rel_' . $key, $target_ids );
	}

	public static function add_pending( $source_id, $key, array $tokens ) {
		$tokens = array_values( array_filter( array_map( 'trim', $tokens ), 'strlen' ) );
		if ( $tokens ) {
			update_post_meta( $source_id, '_dirnet_pending_' . $key, $tokens );
		} else {
			delete_post_meta( $source_id, '_dirnet_pending_' . $key );
		}
	}

	/**
	 * Resolve every pending token of a profile against the target type
	 * (matched on the target's key field, then on its title).
	 *
	 * @return array{resolved:int,unresolved:string[]}
	 */
	public static function resolve_all( Profile $p ) {
		global $wpdb;
		$res = array( 'resolved' => 0, 'unresolved' => array() );
		foreach ( self::defs( $p ) as $key => $d ) {
			$target_dir = self::dir_id( $p, $d['to'] );
			if ( ! $target_dir ) {
				continue;
			}
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", '_dirnet_pending_' . $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			foreach ( (array) $rows as $row ) {
				$tokens = maybe_unserialize( $row->meta_value );
				$ids    = (array) get_post_meta( $row->post_id, '_dirnet_rel_' . $key, true );
				$left   = array();
				foreach ( (array) $tokens as $tok ) {
					$id = Importer::lookup( $p, $d['to'], $target_dir, $tok );
					if ( $id ) {
						$ids[] = $id;
						$res['resolved']++;
					} else {
						$left[] = $tok;
						$res['unresolved'][] = get_the_title( $row->post_id ) . ' → ' . $key . ': ' . $tok;
					}
				}
				self::set( (int) $row->post_id, $key, $ids );
				self::add_pending( (int) $row->post_id, $key, $left );
			}
		}
		return $res;
	}

	/* ------------------------------------------------------------ metabox */

	/** Key of the relation a form field stands for ('' = not a relation field). */
	public static function field_relation( $data ) {
		$k = is_array( $data ) && ! empty( $data['field_key'] ) ? (string) $data['field_key'] : '';
		return 0 === strpos( $k, 'custom-rel-' ) ? substr( $k, 11 ) : '';
	}

	public function field_template( $template, $data ) {
		return '' !== self::field_relation( $data ) ? 'dirnet-relation-field' : $template;
	}

	public function field_file( $file, $name ) {
		return 'dirnet-relation-field' === $name ? DIRNET_PATH . 'templates/relation-field.php' : $file;
	}

	/** Does the Directorist form of this listing's directory hold the relation fields? */
	public static function form_has_field( $post_id ) {
		$dir = (int) get_post_meta( $post_id, '_directory_type', true );
		if ( ! $dir ) {
			return false;
		}
		$f = get_term_meta( $dir, 'submission_form_fields', true );
		return false !== strpos( wp_json_encode( $f ), 'custom-rel-' );
	}

	/** Html of one relation selector (multi-select of the entities of the target type); same output in backend and frontend. */
	public static function field_html( $post_id, $key ) {
		$post_id = (int) $post_id;
		$dir     = (int) ( $post_id ? get_post_meta( $post_id, '_directory_type', true ) : ( isset( $_REQUEST['directory_type'] ) ? absint( $_REQUEST['directory_type'] ) : 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$reg     = self::registry();
		if ( ! $dir || empty( $reg[ $dir ] ) ) {
			return '';
		}
		$p = Profile::get( $reg[ $dir ][0] );
		$d = $p ? self::defs( $p ) : array();
		if ( empty( $d[ $key ] ) || ! in_array( $reg[ $dir ][1], $d[ $key ]['from'], true ) ) {
			return '';
		}
		$d       = $d[ $key ];
		$current = $post_id ? array_map( 'intval', (array) get_post_meta( $post_id, '_dirnet_rel_' . $key, true ) ) : array();
		$targets = get_posts( array(
			'post_type'              => ATBDP_POST_TYPE,
			'post_status'            => 'publish',
			'posts_per_page'         => 1000,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'meta_key'               => '_directory_type', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'             => self::dir_id( $p, $d['to'] ), // phpcs:ignore WordPress.DB.SlowDBQuery
			'post__not_in'           => $post_id ? array( $post_id ) : array(),
		) );
		static $nonce = false;
		$out = '';
		if ( ! $nonce ) {
			$nonce = true;
			$out  .= wp_nonce_field( 'dirnet_rel', 'dirnet_rel_nonce', true, false );
		}
		$out .= '<select multiple size="8" style="width:100%" name="dirnet_rel[' . esc_attr( $key ) . '][]">';
		foreach ( $targets as $tp ) {
			$out .= '<option value="' . (int) $tp->ID . '"' . selected( in_array( $tp->ID, $current, true ), true, false ) . '>' . esc_html( $tp->post_title ) . '</option>';
		}
		$out .= '</select>';
		$pend = $post_id ? array_filter( (array) get_post_meta( $post_id, '_dirnet_pending_' . $key, true ) ) : array();
		if ( $pend ) {
			$out .= '<p class="description">' . esc_html( sprintf( /* translators: %s: list of names */ __( 'Not found yet: %s', 'directorist-network' ), implode( '; ', $pend ) ) ) . '</p>';
		}
		return $out;
	}

	public function metabox() {
		if ( ! defined( 'ATBDP_POST_TYPE' ) ) {
			return;
		}
		add_meta_box( 'dirnet_relations', __( 'Network relations', 'directorist-network' ), array( $this, 'render_box' ), ATBDP_POST_TYPE, 'normal', 'default' );
	}

	public function render_box( $post ) {
		$t = self::type_of( $post->ID );
		if ( ! $t ) {
			echo '<p>' . esc_html__( 'Relations are available for listings created from a network profile.', 'directorist-network' ) . '</p>';
			return;
		}
		list( $p, $slug ) = $t;
		wp_nonce_field( 'dirnet_rel', 'dirnet_rel_nonce' );
		$shown = false;
		$in_form = self::form_has_field( $post->ID ); // the outgoing field is already in the Directorist form (backend and frontend)
		foreach ( self::defs( $p ) as $key => $d ) {
			if ( $slug === $d['to'] ) { // incoming side: the entities that adhere to this one
				$shown = true;
				$this->incoming_field( $post->ID, $p, $key, $d );
			}
			if ( $in_form || ! in_array( $slug, $d['from'], true ) ) {
				continue;
			}
			$shown   = true;
			$current = array_map( 'intval', (array) get_post_meta( $post->ID, '_dirnet_rel_' . $key, true ) );
			$targets = get_posts( array(
				'post_type'      => ATBDP_POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'posts_per_page' => 1000,
				'no_found_rows'  => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'meta_key'       => '_directory_type', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => self::dir_id( $p, $d['to'] ), // phpcs:ignore WordPress.DB.SlowDBQuery
			) );
			echo '<p><label><strong>' . esc_html( $d['label'] ) . '</strong></label><br />';
			echo '<select multiple size="8" style="width:100%;max-width:520px" name="dirnet_rel[' . esc_attr( $key ) . '][]">';
			foreach ( $targets as $tp ) {
				echo '<option value="' . (int) $tp->ID . '"' . selected( in_array( $tp->ID, $current, true ), true, false ) . '>' . esc_html( $tp->post_title ) . '</option>';
			}
			echo '</select></p>';
			$pend = (array) get_post_meta( $post->ID, '_dirnet_pending_' . $key, true );
			if ( array_filter( $pend ) ) {
				echo '<p class="description">' . esc_html( sprintf( /* translators: %s: list of names */ __( 'Not found yet: %s', 'directorist-network' ), implode( '; ', $pend ) ) ) . '</p>';
			}
		}
		if ( ! $shown ) {
			echo '<p>' . esc_html__( 'This type has no outgoing relations. Incoming ones are shown on the listing page.', 'directorist-network' ) . '</p>';
		}
	}

	/** Dropdown with the entities that adhere to this one (e.g. members of a network); editable. */
	private function incoming_field( $post_id, Profile $p, $key, array $d ) {
		global $wpdb;
		$dirs = array_filter( array_map( function ( $sl ) use ( $p ) {
			return self::dir_id( $p, $sl );
		}, $d['from'] ) );
		$cur  = array_map( 'intval', (array) get_post_meta( $post_id, '_dirnet_by_' . $key, true ) );
		$rows = array();
		if ( $dirs ) {
			$in   = implode( ',', array_map( 'intval', $dirs ) );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT p.ID, p.post_title FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_directory_type' WHERE p.post_type = %s AND p.post_status IN ('publish','draft','pending') AND m.meta_value IN ({$in}) AND p.ID <> %d ORDER BY p.post_title ASC LIMIT 5000", ATBDP_POST_TYPE, $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		echo '<p><label><strong>' . esc_html( $d['reverse'] ) . '</strong> <span class="description">(' . (int) count( $cur ) . ')</span></label><br />';
		echo '<input type="hidden" name="dirnet_in_present[' . esc_attr( $key ) . ']" value="1" />';
		echo '<select multiple size="10" style="width:100%;max-width:520px" name="dirnet_in[' . esc_attr( $key ) . '][]">';
		foreach ( (array) $rows as $r ) {
			echo '<option value="' . (int) $r->ID . '"' . selected( in_array( (int) $r->ID, $cur, true ), true, false ) . '>' . esc_html( $r->post_title ) . '</option>';
		}
		echo '</select><br /><span class="description">' . esc_html__( 'Selected entities adhere to this one. Hold Ctrl or Cmd to select several; deselect to remove.', 'directorist-network' ) . '</span></p>';
	}

	public function save( $post_id, $post ) {
		if ( ! defined( 'ATBDP_POST_TYPE' ) || ATBDP_POST_TYPE !== $post->post_type || ! isset( $_POST['dirnet_rel_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dirnet_rel_nonce'] ) ), 'dirnet_rel' )
			|| ! current_user_can( 'edit_post', $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$t = self::type_of( $post_id );
		if ( ! $t ) {
			return;
		}
		list( $p, $slug ) = $t;
		$posted = isset( $_POST['dirnet_rel'] ) && is_array( $_POST['dirnet_rel'] ) ? wp_unslash( $_POST['dirnet_rel'] ) : array(); // phpcs:ignore WordPress.Security
		$in_posted = isset( $_POST['dirnet_in'] ) && is_array( $_POST['dirnet_in'] ) ? wp_unslash( $_POST['dirnet_in'] ) : array(); // phpcs:ignore WordPress.Security
		$in_flags  = isset( $_POST['dirnet_in_present'] ) && is_array( $_POST['dirnet_in_present'] ) ? wp_unslash( $_POST['dirnet_in_present'] ) : array(); // phpcs:ignore WordPress.Security
		foreach ( self::defs( $p ) as $key => $d ) {
			if ( $slug === $d['to'] && isset( $in_flags[ $key ] ) ) {
				$new = array_values( array_unique( array_filter( array_map( 'absint', isset( $in_posted[ $key ] ) ? (array) $in_posted[ $key ] : array() ) ) ) );
				$old = array_map( 'intval', (array) get_post_meta( $post_id, '_dirnet_by_' . $key, true ) );
				foreach ( array_diff( $new, $old ) as $src ) {
					self::set( $src, $key, array_merge( array_map( 'intval', (array) get_post_meta( $src, '_dirnet_rel_' . $key, true ) ), array( $post_id ) ) );
				}
				foreach ( array_diff( $old, $new ) as $src ) {
					self::set( $src, $key, array_diff( array_map( 'intval', (array) get_post_meta( $src, '_dirnet_rel_' . $key, true ) ), array( $post_id ) ) );
				}
			}
			if ( ! in_array( $slug, $d['from'], true ) ) {
				continue;
			}
			if ( self::form_has_field( $post_id ) ) {
				continue; // the outgoing field is in the Directorist form, which saves it
			}
			$ids = isset( $posted[ $key ] ) ? array_map( 'absint', (array) $posted[ $key ] ) : array();
			self::set( $post_id, $key, $ids );
		}
	}

	/* ------------------------------------------------------------ display */

	/** Widget version: small labels like the other widgets instead of the page headings. */
	public $compact = false;

	public function html( $post_id ) {
		$t = self::type_of( $post_id );
		if ( ! $t ) {
			return '';
		}
		list( $p, $slug ) = $t;
		$out = '';
		foreach ( self::defs( $p ) as $key => $d ) {
			if ( in_array( $slug, $d['from'], true ) ) {
				$out .= $this->list_html( $d['label'], (array) get_post_meta( $post_id, '_dirnet_rel_' . $key, true ) );
			}
			if ( $slug === $d['to'] ) {
				$out .= $this->list_html( $d['reverse'], (array) get_post_meta( $post_id, '_dirnet_by_' . $key, true ) );
			}
		}
		return $out ? '<div class="dirnet-relations">' . $out . '</div>' : '';
	}

	private function list_html( $label, array $ids ) {
		$items = '';
		foreach ( array_unique( array_filter( array_map( 'intval', $ids ) ) ) as $id ) {
			if ( 'publish' !== get_post_status( $id ) ) {
				continue;
			}
			$items .= '<li><a href="' . esc_url( get_permalink( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a></li>';
		}
		if ( '' === $items ) {
			return '';
		}
		if ( $this->compact ) {
			return '<strong style="display:block;font-weight:700;margin-top:8px">' . esc_html( $label ) . '</strong><ul style="list-style:none;margin:0;padding:0">' . $items . '</ul>';
		}
		return '<h3>' . esc_html( $label ) . '</h3><ul>' . $items . '</ul>';
	}

	public function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'dirnet_relations' );
		$id   = (int) $atts['id'] ? (int) $atts['id'] : get_the_ID();
		return $id ? $this->html( $id ) : '';
	}

	/** @var array<int,bool> entities whose relations were already printed in this request */
	private static $shown = array();

	/** Same block on the page Directorist builds itself (no the_content there). Once per entity per request. */
	public function append_directorist( $content ) {
		$id = (int) get_the_ID();
		if ( ! $id || isset( self::$shown[ $id ] ) || Widgets::placed( 'dirnet_relations_w' ) || ! defined( 'ATBDP_POST_TYPE' ) || ! is_singular( ATBDP_POST_TYPE ) || has_shortcode( (string) $content, 'dirnet_relations' ) ) {
			return $content;
		}
		$html = $this->html( $id );
		if ( '' === $html ) {
			return $content;
		}
		self::$shown[ $id ] = true;
		return $content . $html . Entities::relations_map( $id );
	}

	/** Automatically list relations under the listing description (single listing pages). */
	public function append( $content ) {
		static $busy = false;
		if ( $busy || Widgets::placed( 'dirnet_relations_w' ) || ! defined( 'ATBDP_POST_TYPE' ) || ! is_singular( ATBDP_POST_TYPE ) || ! in_the_loop() || ! is_main_query() || has_shortcode( $content, 'dirnet_relations' ) ) {
			return $content;
		}
		if ( isset( self::$shown[ (int) get_the_ID() ] ) ) {
			return $content;
		}
		$busy = true;
		$html = $this->html( get_the_ID() );
		if ( '' !== $html ) {
			$html .= Entities::relations_map( get_the_ID() );
			self::$shown[ (int) get_the_ID() ] = true;
		}
		$busy = false;
		return $content . $html;
	}
}
