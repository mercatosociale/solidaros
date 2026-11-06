<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Who owns an entity. The e-mail of the entity is the key:
 * - the CSV import links each entity to a WordPress user (subscriber) with that e-mail, creating it silently;
 * - invitations (a link to choose the password) are sent later, one by one or in bulk, from the Users screen;
 * - the "claim" form on the registration page lets an entity already in the list take possession of it,
 *   proving the e-mail with a confirmation link;
 * - an entity edited by its owner is flagged, so a new CSV import does not overwrite it.
 */
class Owners {

	const INVITE = '_dirnet_invite'; // user meta: pending | sent
	const EDITED = '_dirnet_edited'; // post meta: time of the last edit by the owner

	/** True while the importer runs: its writes are not edits by the owner. */
	public static $importing = false;

	public function boot() {
		add_action( 'post_updated', array( $this, 'flag_edit' ), 10, 3 );
		add_filter( 'password_reset_expiration', array( $this, 'expiration' ) );

		add_filter( 'user_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_filter( 'bulk_actions-users', array( $this, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-users', array( $this, 'handle_bulk' ), 10, 3 );
		add_filter( 'views_users', array( $this, 'views' ) );
		add_action( 'pre_get_users', array( $this, 'filter_users' ) );
		add_action( 'admin_post_dirnet_invite', array( $this, 'invite_one' ) );
		add_action( 'admin_post_dirnet_owners_sync', array( $this, 'sync_existing' ) );
		add_action( 'admin_post_dirnet_purge_subs', array( $this, 'purge_subscribers' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );

		// no "claim" step: an entity is taken over by sending its data again (see Merge)
	}

	/* ---------- ownership ---------- */

	const CONTACT = '_dirnet_contact_uid'; // post meta: the user (from the entry e-mail) to invite; not the owner

	/** Administrator who holds the entries: mercatosocialeorg@gmail.com when it is an administrator, else the first one. */
	public static function heir() {
		$heir = get_user_by( 'email', 'mercatosocialeorg@gmail.com' );
		if ( ! $heir || ! user_can( $heir, 'manage_options' ) ) {
			$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'fields' => 'ID' ) );
			$heir   = $admins ? get_user_by( 'id', $admins[0] ) : null;
		}
		return $heir ? $heir : null;
	}

	/**
	 * The entry stays with the administrators: no user is created for its e-mail (invitations are sent from Directorist > Invitations,
	 * and the account is created when the owner opens the link). Returns 0.
	 */
	public static function attach( $post_id, $email ) {
		$cur   = (int) get_post_field( 'post_author', $post_id );
		$owner = $cur ? get_userdata( $cur ) : null;
		$heir  = self::heir();
		$held  = $owner && ! user_can( $owner, 'edit_others_posts' ) && get_user_meta( $owner->ID, Member::VERIFIED, true ); // the owner already took it over (signed in): leave it
		if ( $heir && ! $held && $cur !== (int) $heir->ID && ( ! $owner || ! user_can( $owner, 'edit_others_posts' ) ) ) {
			$was = self::$importing;
			self::$importing = true;
			wp_update_post( array( 'ID' => $post_id, 'post_author' => $heir->ID ) );
			self::$importing = $was;
		}
		return 0;
	}

	/** An edit by the owner (not by an administrator, not by the importer) protects the entity from the next import. */
	public function flag_edit( $post_id, $after, $before ) {
		if ( self::$importing || ATBDP_POST_TYPE !== $after->post_type || ! is_user_logged_in() ) {
			return;
		}
		if ( current_user_can( 'manage_options' ) || (int) get_current_user_id() !== (int) $after->post_author ) {
			return;
		}
		update_post_meta( $post_id, self::EDITED, time() );
	}

	/* ---------- invitations ---------- */

	/** Links to choose the password stay valid longer, as invitations are sent in advance. */
	public function expiration( $secs ) {
		return max( (int) $secs, 7 * DAY_IN_SECONDS );
	}

	/** Send the "choose your password" mail. Returns true when sent. */
	public static function invite( $user_id ) {
		$user = get_user_by( 'id', (int) $user_id );
		if ( ! $user ) {
			return false;
		}
		$url = Member::magic_link( $user->user_email );
		$names = array();
		$posts = get_posts( array( 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'any', 'numberposts' => 5, 'meta_key' => self::CONTACT, 'meta_value' => (int) $user->ID ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		foreach ( $posts as $p ) {
			$names[] = $p->post_title;
		}
		$body = implode(
			"\n\n",
			array(
				sprintf( /* translators: 1: site name, 2: entity names */ __( '%1$s has your entity in its catalogue (%2$s).', 'directorist-network' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), implode( ', ', $names ) ),
				__( 'Open this link to enter, check your data and take your entry over:', 'directorist-network' ),
				$url,
				__( 'The link works once and for one hour; you can ask for another from the Access page.', 'directorist-network' ),
			)
		);
		$ok = wp_mail( $user->user_email, sprintf( /* translators: %s: site name */ __( '[%s] Access to your entity', 'directorist-network' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ), $body );
		if ( $ok ) {
			update_user_meta( $user->ID, self::INVITE, 'sent' );
		}
		return (bool) $ok;
	}

	private static function reset_url( $user ) {
		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			return '';
		}
		return network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $user->user_login ), 'login' );
	}

	public function row_action( $actions, $user ) {
		if ( current_user_can( 'edit_users' ) && get_user_meta( $user->ID, self::INVITE, true ) ) {
			$label = 'sent' === get_user_meta( $user->ID, self::INVITE, true ) ? __( 'Send invitation again', 'directorist-network' ) : __( 'Send invitation', 'directorist-network' );
			$actions['dirnet_invite'] = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dirnet_invite&user=' . (int) $user->ID ), 'dirnet_invite_' . $user->ID ) ) . '">' . esc_html( $label ) . '</a>';
		}
		return $actions;
	}

	public function invite_one() {
		$uid = isset( $_GET['user'] ) ? (int) $_GET['user'] : 0;
		if ( ! current_user_can( 'edit_users' ) || ! check_admin_referer( 'dirnet_invite_' . $uid ) ) {
			wp_die( esc_html__( 'Permission denied.', 'directorist-network' ) );
		}
		$ok = self::invite( $uid ) ? 1 : 0;
		wp_safe_redirect( add_query_arg( array( 'dirnet_sent' => $ok, 'dirnet_failed' => 1 - $ok ), admin_url( 'users.php' ) ) );
		exit;
	}

	public function bulk_actions( $a ) {
		$a['dirnet_invite'] = __( 'Send access invitation', 'directorist-network' );
		return $a;
	}

	public function handle_bulk( $redirect, $action, $ids ) {
		if ( 'dirnet_invite' !== $action || ! current_user_can( 'edit_users' ) ) {
			return $redirect;
		}
		$sent = 0;
		$fail = 0;
		foreach ( (array) $ids as $uid ) {
			if ( get_user_meta( (int) $uid, self::INVITE, true ) ) { // only users created or linked by the import
				self::invite( (int) $uid ) ? $sent++ : $fail++;
			}
		}
		return add_query_arg( array( 'dirnet_sent' => $sent, 'dirnet_failed' => $fail ), $redirect );
	}

	/** Users screen filters: "To invite" and "Invited". */
	public function views( $views ) {
		foreach ( array( 'pending' => __( 'To invite', 'directorist-network' ), 'sent' => __( 'Invited', 'directorist-network' ) ) as $k => $label ) {
			$q = new \WP_User_Query( array( 'meta_key' => self::INVITE, 'meta_value' => $k, 'fields' => 'ID', 'count_total' => true, 'number' => 1 ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
			$cur = isset( $_GET['dirnet_invite_filter'] ) && $k === $_GET['dirnet_invite_filter']; // phpcs:ignore WordPress.Security.NonceVerification
			$views[ 'dirnet_' . $k ] = '<a href="' . esc_url( add_query_arg( 'dirnet_invite_filter', $k, admin_url( 'users.php' ) ) ) . '"' . ( $cur ? ' class="current"' : '' ) . '>' . esc_html( $label ) . ' <span class="count">(' . (int) $q->get_total() . ')</span></a>';
		}
		return $views;
	}

	public function filter_users( $q ) {
		if ( ! is_admin() || empty( $_GET['dirnet_invite_filter'] ) || ! in_array( $_GET['dirnet_invite_filter'], array( 'pending', 'sent' ), true ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$q->set( 'meta_key', self::INVITE ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$q->set( 'meta_value', sanitize_key( $_GET['dirnet_invite_filter'] ) ); // phpcs:ignore WordPress.DB.SlowDBQuery,WordPress.Security.NonceVerification
	}

	/**
	 * Entities already on the site: one subscriber for each e-mail found in the entities (no e-mail is sent),
	 * the entity is assigned to that user. Administrators and other staff are never touched or assigned.
	 */
	/** Deletes every subscriber; their content goes to the heir administrator. */
	public function purge_subscribers() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'dirnet_purge_subs' ) ) {
			wp_die( 'Forbidden' );
		}
		$back = wp_get_referer() ? remove_query_arg( array( 'dirnet_purged', 'dirnet_purge_err' ), wp_get_referer() ) : admin_url();
		$heir = self::heir();
		if ( empty( $_POST['confirm'] ) || ! $heir ) {
			wp_safe_redirect( add_query_arg( 'dirnet_purge_err', 1, $back ) );
			exit;
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore
		}
		$n = 0;
		foreach ( get_users( array( 'role' => 'subscriber', 'fields' => 'ID' ) ) as $uid ) {
			$u = get_userdata( $uid );
			if ( ! $u || array_diff( (array) $u->roles, array( 'subscriber' ) ) || (int) $uid === (int) $heir->ID || (int) $uid === get_current_user_id() ) {
				continue; // has another role: not a plain subscriber
			}
			if ( wp_delete_user( (int) $uid, (int) $heir->ID ) ) {
				$n++;
			}
		}
		wp_safe_redirect( add_query_arg( 'dirnet_purged', $n, $back ) );
		exit;
	}

	public function sync_existing() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'dirnet_owners_sync' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'directorist-network' ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions
		}
		global $wpdb;
		$ids  = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ( 'trash', 'auto-draft' )", ATBDP_POST_TYPE ) ); // phpcs:ignore WordPress.DB
		$heir = self::heir();
		$moved = 0; $skip = 0; $none = 0;
		foreach ( $ids as $id ) {
			if ( ! is_email( sanitize_email( (string) get_post_meta( $id, '_email', true ) ) ) ) {
				$none++;
			}
			$cur = (int) get_post_field( 'post_author', $id );
			$o   = $cur ? get_userdata( $cur ) : null;
			if ( $o && user_can( $o, 'edit_others_posts' ) ) {
				$skip++; // already with the administrators
				continue;
			}
			if ( $o && get_user_meta( $o->ID, Member::VERIFIED, true ) ) {
				$skip++; // the owner signed in and took it over: it stays his
				continue;
			}
			if ( $heir ) {
				$was = self::$importing;
				self::$importing = true;
				wp_update_post( array( 'ID' => $id, 'post_author' => $heir->ID ) );
				self::$importing = $was;
				$moved++;
			}
		}
		// the silent accounts made by the import (never signed in, only subscribers, no entries left) are removed
		$gone = 0;
		if ( $heir ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			foreach ( get_users( array( 'role' => 'subscriber', 'meta_key' => self::INVITE, 'fields' => 'ID', 'number' => -1 ) ) as $uid ) { // phpcs:ignore WordPress.DB.SlowDBQuery
				$u = get_userdata( $uid );
				if ( ! $u || array( 'subscriber' ) !== array_values( $u->roles ) || get_user_meta( $uid, Member::VERIFIED, true ) ) {
					continue;
				}
				if ( get_posts( array( 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'any', 'author' => $uid, 'fields' => 'ids', 'numberposts' => 1 ) ) ) {
					continue;
				}
				wp_delete_user( $uid, $heir->ID );
				$gone++;
			}
		}
		wp_safe_redirect( add_query_arg( array( 'dirnet_owners' => $moved, 'dirnet_owners_new' => 0, 'dirnet_owners_skip' => $skip, 'dirnet_owners_none' => $none, 'dirnet_owners_gone' => $gone ), wp_get_referer() ? wp_get_referer() : admin_url() ) );
		exit;
	}

	public function notice() {
		if ( isset( $_GET['dirnet_purged'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( 'Subscribers deleted: %d. Their entities now belong to the administrator.', 'directorist-network' ), (int) $_GET['dirnet_purged'] ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		if ( isset( $_GET['dirnet_purge_err'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Nothing deleted: tick the confirmation box.', 'directorist-network' ) . '</p></div>';
		}
		if ( isset( $_GET['dirnet_owners'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( /* translators: 1: entities linked, 2: users created, 3: skipped (staff), 4: without e-mail */ __( 'Entities linked to an owner: %1$d. New subscriber users: %2$d. Skipped (e-mail of an administrator or editor): %3$d. Without a valid e-mail: %4$d. Previous owner users removed: %5$d.', 'directorist-network' ), (int) $_GET['dirnet_owners'], (int) ( $_GET['dirnet_owners_new'] ?? 0 ), (int) ( $_GET['dirnet_owners_skip'] ?? 0 ), (int) ( $_GET['dirnet_owners_none'] ?? 0 ), (int) ( $_GET['dirnet_owners_gone'] ?? 0 ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		if ( ! isset( $_GET['dirnet_sent'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$s = (int) $_GET['dirnet_sent']; // phpcs:ignore WordPress.Security.NonceVerification
		$f = isset( $_GET['dirnet_failed'] ) ? (int) $_GET['dirnet_failed'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="notice notice-' . ( $f ? 'warning' : 'success' ) . ' is-dismissible"><p>' . esc_html( sprintf( /* translators: 1: sent, 2: failed */ __( 'Invitations sent: %1$d. Not sent: %2$d.', 'directorist-network' ), $s, $f ) ) . '</p></div>';
	}

	/* ---------- claim ---------- */

	/** The form shown on the registration page, above the "register" selector. */
	public static function claim_form( $title = true ) {
		$msg = isset( $_GET['dirnet_claim_sent'] ) ? '<p><strong>' . esc_html__( 'If this e-mail is in the catalogue, we have sent a confirmation link to it.', 'directorist-network' ) . '</strong></p>' : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return '<form class="dirnet-claim" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . ( $title ? '<h3>' . esc_html__( 'Is your entity already in the list?', 'directorist-network' ) . '</h3>' : '' ) . $msg
			. '<p>' . esc_html__( 'Enter the e-mail of the entity: we send a link to it to take over the entry and update it.', 'directorist-network' ) . '</p>'
			. '<input type="hidden" name="action" value="dirnet_claim" />' . wp_nonce_field( 'dirnet_claim', '_wpnonce', true, false )
			. '<input type="hidden" name="back" value="' . esc_url( get_permalink() ) . '" />'
			. '<p style="position:absolute;left:-9999px" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off" /></p>'
			. '<p><input type="email" name="email" required placeholder="' . esc_attr__( 'Entity e-mail', 'directorist-network' ) . '" /> <button type="submit" class="button">' . esc_html__( 'Send link', 'directorist-network' ) . '</button></p></form>';
	}

	public function claim_request() {
		$back = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : home_url( '/' );
		$back = wp_validate_redirect( $back, home_url( '/' ) );
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'dirnet_claim' ) || ! empty( $_POST['website'] ) ) {
			wp_safe_redirect( $back );
			exit;
		}
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$lock  = 'dirnet_claim_lock_' . md5( strtolower( $email ) );
		if ( is_email( $email ) && ! get_transient( $lock ) ) {
			set_transient( $lock, 1, 10 * MINUTE_IN_SECONDS );
			$ids = get_posts( array(
				'post_type' => ATBDP_POST_TYPE, 'post_status' => 'any', 'fields' => 'ids', 'numberposts' => 20,
				'meta_query' => array( array( 'key' => '_email', 'value' => $email ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
			) );
			if ( $ids ) {
				$token = wp_generate_password( 32, false );
				set_transient( 'dirnet_claim_' . $token, array( 'email' => $email, 'ids' => $ids ), 2 * DAY_IN_SECONDS );
				$link = add_query_arg( 'dirnet_claim', $token, home_url( '/' ) );
				wp_mail( $email, sprintf( /* translators: %s: site name */ __( '[%s] Confirm your entity', 'directorist-network' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
					__( 'Open this link to take over your entry in the catalogue and choose your password:', 'directorist-network' ) . "\n\n" . $link . "\n\n" . __( 'If you did not ask for it, ignore this message.', 'directorist-network' ) );
			}
		}
		// Same answer whether or not the e-mail exists, so the list cannot be probed.
		wp_safe_redirect( add_query_arg( 'dirnet_claim_sent', 1, $back ) );
		exit;
	}

	public function claim_confirm() {
		if ( empty( $_GET['dirnet_claim'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$key  = 'dirnet_claim_' . sanitize_key( wp_unslash( $_GET['dirnet_claim'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$data = get_transient( $key );
		if ( ! is_array( $data ) ) {
			wp_die( esc_html__( 'This link is not valid or has expired. Ask for a new one.', 'directorist-network' ), '', array( 'response' => 410 ) );
		}
		delete_transient( $key ); // single use
		$uid = 0;
		foreach ( $data['ids'] as $id ) {
			$got = self::attach( (int) $id, $data['email'] );
			$uid = $got ? $got : $uid;
		}
		$user = $uid ? get_user_by( 'id', $uid ) : null;
		if ( ! $user ) {
			wp_die( esc_html__( 'Could not link the entry. Contact the site administrator.', 'directorist-network' ) );
		}
		update_user_meta( $user->ID, self::INVITE, 'sent' );
		$url = self::reset_url( $user );
		wp_safe_redirect( '' !== $url ? $url : wp_login_url() );
		exit;
	}
}
