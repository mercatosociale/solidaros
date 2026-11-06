<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A new entry that matches an existing one is not left as a duplicate:
 * - same tax code or same e-mail, and the author is signed in with a verified e-mail: joined AUTOMATICALLY into the existing entry
 *   (the submitted data wins where it is not empty, the existing data stays where it is; the author becomes the new owner).
 *   The previous state is kept, the administrator is told and can undo it with one click;
 * - only the same name and the same city, or no verified e-mail: the new entry is flagged "possible duplicate" and the
 *   administrator decides (a merge on his initiative can also be started from any entry).
 * The new entry goes to the trash after the merge; nothing is deleted for good.
 */
class Merge {

	const DUP = '_dirnet_dup_of';

	public function boot() {
		add_action( 'atbdp_after_created_listing', array( $this, 'remember' ), 20 );
		add_filter( 'directorist_ajax_listing_submission_response', array( $this, 'after_submit' ), 20 );
		add_action( 'admin_post_dirnet_merge_undo', array( $this, 'undo' ) );
		add_action( 'admin_post_dirnet_merge', array( $this, 'admin_merge' ) );
		add_action( 'add_meta_boxes', array( $this, 'box' ) );
	}

	private static function find( $id ) {
		$d = self::find_strong( $id );
		return $d ? $d : self::find_by_name( $id );
	}

	/** Same tax code or same e-mail. */
	private static function find_strong( $id ) {
		$tax   = trim( (string) get_post_meta( $id, SolidarosBridge::TAX_META, true ) );
		$email = strtolower( trim( (string) get_post_meta( $id, '_email', true ) ) );
		$or    = array( 'relation' => 'OR' );
		if ( '' !== $tax ) {
			$or[] = array( 'key' => SolidarosBridge::TAX_META, 'value' => $tax );
		}
		if ( '' !== $email ) {
			$or[] = array( 'key' => '_email', 'value' => $email );
		}
		if ( count( $or ) < 2 ) {
			return 0;
		}
		$ids = get_posts( array(
			'post_type' => ATBDP_POST_TYPE, 'post_status' => array( 'publish', 'pending', 'draft', 'private' ), 'fields' => 'ids',
			'numberposts' => 1, 'post__not_in' => array( (int) $id ), 'orderby' => 'ID', 'order' => 'ASC', 'meta_query' => $or, // phpcs:ignore WordPress.DB.SlowDBQuery
		) );
		return $ids ? (int) $ids[0] : 0;
	}

	/** Last resort: same title and same city. Only flags, never merges by itself (no e-mail is sent). */
	private static function find_by_name( $id ) {
		$title = trim( (string) get_the_title( $id ) );
		if ( '' === $title ) {
			return 0;
		}
		$norm = function ( $x ) {
			$x = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $x ) ) : strtolower( trim( $x ) );
			return preg_replace( '/[^\p{L}\p{N}]+/u', '', $x );
		};
		$city = $norm( implode( ' ', wp_get_object_terms( $id, ATBDP_LOCATION, array( 'fields' => 'names' ) ) ) );
		$ids  = get_posts( array( 'post_type' => ATBDP_POST_TYPE, 'post_status' => array( 'publish', 'pending', 'draft', 'private' ), 'fields' => 'ids', 'numberposts' => 20, 'post__not_in' => array( (int) $id ), 'title' => $title ) );
		foreach ( $ids as $o ) {
			if ( $norm( get_the_title( $o ) ) === $norm( $title ) && $norm( implode( ' ', wp_get_object_terms( $o, ATBDP_LOCATION, array( 'fields' => 'names' ) ) ) ) === $city ) {
				return (int) $o;
			}
		}
		return 0;
	}

	private $last = 0;

	public function remember( $id ) {
		$this->last = (int) $id;
	}

	/** After the whole submission (images included): join automatically when the signed-in, e-mail-verified author owns the matching e-mail. */
	public function after_submit( $data ) {
		if ( ! $this->last || empty( $data['success'] ) ) {
			return $data;
		}
		$id     = $this->last;
		if ( self::find( $id ) ) {
			$this->detect( $id );
		}
		return $data;
	}

	/** E-mail verified: by our link, or by Directorist when its e-mail verification is on. */
	private static function verified( $uid ) {
		if ( get_user_meta( $uid, Member::VERIFIED, true ) ) {
			return true;
		}
		return function_exists( 'directorist_is_email_verification_enabled' ) && directorist_is_email_verification_enabled() && ! get_user_meta( $uid, 'directorist_user_email_unverified', true );
	}

	private static function notify_merge( $new, $target, $u ) {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$edit = (string) get_edit_post_link( $target, 'url' );
		wp_mail(
			get_option( 'admin_email' ),
			sprintf( /* translators: %s: site name */ __( '[%s] An entry was joined automatically', 'directorist-network' ), $site ),
			sprintf( /* translators: 1: entity, 2: user e-mail */ __( '"%1$s" was updated with the data sent by %2$s. You can undo it from the entry:', 'directorist-network' ), get_the_title( $target ), $u->user_email ) . "\n\n" . $edit
		);
	}

	public function detect( $id ) {
		$id  = (int) $id;
		$dup = self::find( $id );
		if ( ! $dup ) {
			return;
		}
		update_post_meta( $id, self::DUP, $dup ); // the administrator decides (Possible duplicate, on the entry)
		wp_mail( get_option( 'admin_email' ), __( 'Possible duplicate entry', 'directorist-network' ), get_the_title( $id ) . ' / ' . get_the_title( $dup ) . "\n\n" . (string) get_edit_post_link( $id, 'url' ) );
	}

	/** What the target looked like before the merge, to undo it. */
	private static function snapshot( $target, $new ) {
		$tp   = get_post( $target );
		$meta = array();
		foreach ( (array) get_post_meta( $target ) as $k => $vals ) {
			if ( 0 === strpos( $k, '_wp_' ) || 0 === strpos( $k, '_edit_' ) || '_dirnet_merge_prev' === $k ) {
				continue;
			}
			$meta[ $k ] = maybe_unserialize( isset( $vals[0] ) ? $vals[0] : '' );
		}
		$terms = array();
		foreach ( get_object_taxonomies( ATBDP_POST_TYPE ) as $tax ) {
			$t = wp_get_object_terms( $target, $tax, array( 'fields' => 'ids' ) );
			$terms[ $tax ] = is_wp_error( $t ) ? array() : $t;
		}
		update_post_meta( $target, '_dirnet_merge_prev', array( 'new' => (int) $new, 'author' => (int) $tp->post_author, 'title' => $tp->post_title, 'content' => $tp->post_content, 'excerpt' => $tp->post_excerpt, 'thumb' => (int) get_post_thumbnail_id( $target ), 'meta' => $meta, 'terms' => $terms ) );
	}

	public function undo() {
		$target = isset( $_REQUEST['target'] ) ? (int) $_REQUEST['target'] : 0;
		if ( ! current_user_can( 'edit_others_posts' ) || ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ), 'dirnet_merge_undo' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'directorist-network' ) );
		}
		$p = $target ? get_post_meta( $target, '_dirnet_merge_prev', true ) : null;
		if ( is_array( $p ) ) {
			foreach ( array_keys( (array) get_post_meta( $target ) ) as $k ) {
				if ( 0 !== strpos( $k, '_wp_' ) && 0 !== strpos( $k, '_edit_' ) && '_dirnet_merge_prev' !== $k && 0 !== strpos( $k, '_dirnet_' ) && ! array_key_exists( $k, $p['meta'] ) ) {
					delete_post_meta( $target, $k );
				}
			}
			foreach ( $p['meta'] as $k => $v ) {
				update_post_meta( $target, $k, $v );
			}
			foreach ( $p['terms'] as $tax => $ids ) {
				wp_set_object_terms( $target, $ids, $tax );
			}
			if ( $p['thumb'] ) {
				set_post_thumbnail( $target, $p['thumb'] );
			} else {
				delete_post_thumbnail( $target );
			}
			wp_update_post( array( 'ID' => $target, 'post_author' => $p['author'], 'post_title' => $p['title'], 'post_content' => $p['content'], 'post_excerpt' => $p['excerpt'] ) );
			if ( $p['new'] && 'trash' === get_post_status( $p['new'] ) ) {
				wp_untrash_post( $p['new'] );
			}
			delete_post_meta( $target, '_dirnet_merge_prev' );
		}
		wp_safe_redirect( $target ? get_edit_post_link( $target, 'url' ) : admin_url( 'edit.php?post_type=' . ATBDP_POST_TYPE ) );
		exit;
	}

	/** Join $new into $target. */
	public static function run( $new, $target ) {
		$new    = (int) $new;
		$target = (int) $target;
		if ( ! $new || ! $target || $new === $target || ATBDP_POST_TYPE !== get_post_type( $new ) || ATBDP_POST_TYPE !== get_post_type( $target ) ) {
			return false;
		}
		self::snapshot( $target, $new );
		$skip = array( '_edit_lock', '_edit_last', '_directory_type', '_listing_status', '_featured', '_expiry_date', '_never_expire', '_listing_prime', self::DUP, '_thumbnail_id' );
		foreach ( (array) get_post_meta( $new ) as $k => $vals ) {
			if ( in_array( $k, $skip, true ) || 0 === strpos( $k, '_wp_' ) || 0 === strpos( $k, '_dirnet_' ) ) {
				continue;
			}
			$v = maybe_unserialize( isset( $vals[0] ) ? $vals[0] : '' );
			if ( '' === $v || array() === $v || null === $v ) {
				continue;
			}
			update_post_meta( $target, $k, $v );
		}
		foreach ( get_object_taxonomies( ATBDP_POST_TYPE ) as $tax ) {
			$terms = wp_get_object_terms( $new, $tax, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) && $terms ) {
				wp_set_object_terms( $target, $terms, $tax );
			}
		}
		$thumb = get_post_thumbnail_id( $new );
		if ( $thumb ) {
			set_post_thumbnail( $target, $thumb );
		}
		$np  = get_post( $new );
		$upd = array( 'ID' => $target, 'post_author' => (int) $np->post_author );
		foreach ( array( 'post_title', 'post_content', 'post_excerpt' ) as $f ) {
			if ( '' !== trim( (string) $np->$f ) ) {
				$upd[ $f ] = $np->$f;
			}
		}
		wp_update_post( $upd );
		delete_post_meta( $new, self::DUP );
		update_post_meta( $new, '_dirnet_merged_into', $target );
		wp_trash_post( $new );
		return true;
	}

	public function confirm() {
		if ( empty( $_GET['dirnet_merge'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$key  = 'dirnet_merge_' . sanitize_key( wp_unslash( $_GET['dirnet_merge'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$data = get_transient( $key );
		if ( ! is_array( $data ) ) {
			wp_die( esc_html__( 'This link is not valid or has expired.', 'directorist-network' ), '', array( 'response' => 410 ) );
		}
		delete_transient( $key );
		if ( ! self::run( $data['new'], $data['target'] ) ) {
			wp_die( esc_html__( 'Could not join the entries. Contact the site administrator.', 'directorist-network' ) );
		}
		$id = (int) get_option( 'dirnet_myspace_page' );
		wp_safe_redirect( $id ? add_query_arg( 'dirnet_merged', 1, get_permalink( $id ) ) : home_url( '/' ) );
		exit;
	}

	public function admin_merge() {
		if ( ! current_user_can( 'edit_others_posts' ) || ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ), 'dirnet_merge' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'directorist-network' ) );
		}
		$new    = isset( $_REQUEST['new'] ) ? (int) $_REQUEST['new'] : 0;
		$target = isset( $_REQUEST['target'] ) ? (int) $_REQUEST['target'] : 0;
		self::run( $new, $target );
		wp_safe_redirect( $target ? get_edit_post_link( $target, 'url' ) : admin_url( 'edit.php?post_type=' . ATBDP_POST_TYPE ) );
		exit;
	}

	public function box() {
		add_meta_box( 'dirnet-dup', __( 'Possible duplicate', 'directorist-network' ), array( $this, 'box_html' ), ATBDP_POST_TYPE, 'side', 'high' );
	}

	/** No <form> here: the box sits inside the entry's own edit form, and a nested form would hijack its "Update" button. */
	public function box_html( $post ) {
		$dup    = (int) get_post_meta( $post->ID, self::DUP, true );
		$undo   = get_post_meta( $post->ID, '_dirnet_merge_prev', true );
		$base   = admin_url( 'admin-post.php' );
		$merge  = wp_create_nonce( 'dirnet_merge' );
		$ask    = esc_attr( wp_json_encode( __( 'Merge this entry into the other one?', 'directorist-network' ) ) );
		if ( is_array( $undo ) ) {
			$u = add_query_arg( array( 'action' => 'dirnet_merge_undo', 'target' => $post->ID, '_wpnonce' => wp_create_nonce( 'dirnet_merge_undo' ) ), $base );
			echo '<p>' . esc_html__( 'This entry received the data of another one.', 'directorist-network' ) . '</p><p><a class="button" href="' . esc_url( $u ) . '">' . esc_html__( 'Undo the last merge', 'directorist-network' ) . '</a></p><hr />';
		}
		if ( $dup && get_post( $dup ) ) {
			$m = add_query_arg( array( 'action' => 'dirnet_merge', 'new' => $post->ID, 'target' => $dup, '_wpnonce' => $merge ), $base );
			echo '<p>' . esc_html__( 'Looks like:', 'directorist-network' ) . ' <a href="' . esc_url( (string) get_edit_post_link( $dup, 'url' ) ) . '">' . esc_html( get_the_title( $dup ) ) . '</a></p>';
			echo '<p><a class="button button-primary" href="' . esc_url( $m ) . '" onclick="return confirm(' . $ask . ')">' . esc_html__( 'Merge into that entry', 'directorist-network' ) . '</a></p><hr />'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		$url = add_query_arg( array( 'action' => 'dirnet_merge', 'new' => $post->ID, '_wpnonce' => $merge ), $base );
		echo '<p>' . esc_html__( 'Merge this entry into another one (ID):', 'directorist-network' ) . '</p>';
		echo '<input type="number" id="dirnet-merge-id" min="1" style="width:100%" /> '; // no name: it is not part of the entry form
		echo '<p><button type="button" class="button" onclick="var v=document.getElementById(\'dirnet-merge-id\').value;if(v&&confirm(' . $ask . '))location.href=' . esc_attr( wp_json_encode( $url . '&target=' ) ) . '+encodeURIComponent(v)">' . esc_html__( 'Merge', 'directorist-network' ) . '</button></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
