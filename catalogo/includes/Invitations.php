<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Invitations area (Directorist > Invitations): the entries that carry an e-mail, with the date of the invitation.
 * Sending the invitation creates the user (name of the entry, e-mail of the entry, no role) and gives him the entries with that
 * e-mail, so entry and user are aligned from the start. The invitation holds a one-hour sign-in link for that e-mail: only
 * whoever reads the mailbox can enter. Staff accounts are never touched.
 */
class Invitations {

	const SLUG = 'dirnet-invitations';
	const SENT = '_dirnet_invited_at'; // post meta: time of the last invitation

	public function boot() {
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_post_dirnet_invitations', array( $this, 'send' ) );
		add_action( 'updated_post_meta', array( $this, 'email_changed' ), 10, 4 );
		add_action( 'added_post_meta', array( $this, 'email_changed' ), 10, 4 );
	}

	public function menu() {
		add_submenu_page( 'edit.php?post_type=at_biz_dir', __( 'Invitations', 'directorist-network' ), __( 'Invitations', 'directorist-network' ), 'manage_options', self::SLUG, array( $this, 'page' ) );
	}

	/** id => e-mail of the entries that have one and are not trashed. */
	private static function rows( $filter, $search ) {
		global $wpdb;
		$where = "p.post_type = %s AND p.post_status NOT IN ('trash','auto-draft') AND m.meta_value LIKE '%%@%%'";
		$args  = array( ATBDP_POST_TYPE );
		if ( '' !== $search ) {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$where .= ' AND ( p.post_title LIKE %s OR m.meta_value LIKE %s )';
			$args[] = $like;
			$args[] = $like;
		}
		$join = '';
		if ( 'todo' === $filter ) {
			$join   = " LEFT JOIN {$wpdb->postmeta} i ON i.post_id = p.ID AND i.meta_key = '" . self::SENT . "'";
			$where .= ' AND i.meta_id IS NULL';
		} elseif ( 'assign' === $filter ) { // the owner of the entry is not the user of its e-mail (new or changed e-mail, entry still with the administrators)
			$join   = " JOIN {$wpdb->users} u ON u.ID = p.post_author";
			$where .= ' AND LOWER(u.user_email) <> LOWER(m.meta_value)';
		} elseif ( 'sent' === $filter ) {
			$join   = " JOIN {$wpdb->postmeta} i ON i.post_id = p.ID AND i.meta_key = '" . self::SENT . "'";
		}
		$sql = "SELECT p.ID, p.post_title, p.post_author, m.meta_value AS email FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_email'{$join} WHERE {$where} ORDER BY p.post_title LIMIT 500";
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB
	}

	public function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Invitations', 'directorist-network' ) . '</h1>';
		$this->body( false );
		echo '</div>';
	}

	/** The content of the page, shown inside Community. */
	public function body( $nested = true ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$filter = isset( $_GET['f'] ) ? sanitize_key( wp_unslash( $_GET['f'] ) ) : 'todo'; // phpcs:ignore WordPress.Security.NonceVerification
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$rows   = self::rows( $filter, $search );
		$base   = admin_url( 'edit.php?post_type=at_biz_dir&page=' . self::SLUG );
		ob_start();
		if ( isset( $_GET['created'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( /* translators: %d: number */ __( 'Entries assigned to their users: %d.', 'directorist-network' ), (int) $_GET['created'] ) ) . ( ! empty( $_GET['skipped'] ) ? ' ' . esc_html( sprintf( /* translators: %d: number */ __( 'Skipped (staff accounts): %d.', 'directorist-network' ), (int) $_GET['skipped'] ) ) : '' ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		if ( isset( $_GET['sent'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( /* translators: %d: number */ __( 'Invitations sent: %d.', 'directorist-network' ), (int) $_GET['sent'] ) ) . ( ! empty( $_GET['skipped'] ) ? ' ' . esc_html( sprintf( /* translators: %d: number */ __( 'Skipped (staff accounts): %d.', 'directorist-network' ), (int) $_GET['skipped'] ) ) : '' ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		echo '<p class="description">' . esc_html__( '"Create users" makes the user (name and e-mail of the entry, no role) and gives him the entries with that e-mail, without writing to anyone. "Send the invitation" does the same if needed and then sends the e-mail, which holds a sign-in link (valid one hour, once): only whoever reads that mailbox can enter. Staff accounts are skipped.', 'directorist-network' ) . '</p>';
		echo '<ul class="subsubsub">';
		foreach ( array( 'todo' => __( 'Not invited', 'directorist-network' ), 'sent' => __( 'Invited', 'directorist-network' ), 'assign' => __( 'To assign or update', 'directorist-network' ), 'all' => __( 'All', 'directorist-network' ) ) as $k => $l ) {
			echo '<li><a href="' . esc_url( add_query_arg( 'f', $k, $base ) ) . '"' . ( $k === $filter ? ' class="current"' : '' ) . '>' . esc_html( $l ) . '</a>' . ( 'all' === $k ? '' : ' |' ) . ' </li>';
		}
		echo '</ul><form method="get" style="float:right"><input type="hidden" name="post_type" value="at_biz_dir" /><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" /><input type="hidden" name="f" value="' . esc_attr( $filter ) . '" /><input type="search" name="s" value="' . esc_attr( $search ) . '" /> <button class="button">' . esc_html__( 'Search', 'directorist-network' ) . '</button></form><div style="clear:both"></div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dirnet_invitations" />' . wp_nonce_field( 'dirnet_invitations', '_wpnonce', true, false ) . '<input type="hidden" name="back" value="' . esc_attr( add_query_arg( array( 'f' => $filter, 's' => $search ), $base ) ) . '" />';
		echo '<table class="widefat striped"><thead><tr><td class="check-column"><input type="checkbox" onclick="document.querySelectorAll(\'.dirnet-inv\').forEach(function(c){c.checked=this.checked}.bind(this))" /></td><th>' . esc_html__( 'Entry', 'directorist-network' ) . '</th><th>' . esc_html__( 'E-mail', 'directorist-network' ) . '</th><th>' . esc_html__( 'Owner', 'directorist-network' ) . '</th><th>' . esc_html__( 'Invited', 'directorist-network' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$at    = (int) get_post_meta( $r->ID, self::SENT, true );
			$owner = get_userdata( (int) $r->post_author );
			echo '<tr><th class="check-column"><input type="checkbox" class="dirnet-inv" name="ids[]" value="' . (int) $r->ID . '" /></th><td><a href="' . esc_url( (string) get_edit_post_link( $r->ID ) ) . '">' . esc_html( $r->post_title ) . '</a></td><td>' . esc_html( $r->email ) . '</td><td>' . esc_html( $owner ? ( user_can( $owner, 'edit_others_posts' ) ? __( 'Administrators', 'directorist-network' ) : $owner->display_name ) : '—' ) . '</td><td>' . ( $at ? esc_html( wp_date( 'd/m/Y H:i', $at ) ) : '—' ) . '</td></tr>';
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No entries.', 'directorist-network' ) . '</td></tr>';
		}
		echo '</tbody></table><p><button class="button" name="op" value="create">' . esc_html__( 'Create users and assign the selected entries', 'directorist-network' ) . '</button> <button class="button button-primary" name="op" value="invite">' . esc_html__( 'Send the invitation to the selected entries', 'directorist-network' ) . '</button> <small>' . esc_html__( 'At most 500 entries are shown.', 'directorist-network' ) . '</small></p></form>';
		$html = ob_get_clean();
		echo $nested ? str_replace( array( '<h2>', '</h2>', '<h2 ' ), array( '<h3>', '</h3>', '<h3 ' ), $html ) : $html; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * The e-mail of an entry changed. If the entry is still with a user created by an invitation who never signed in, it
	 * follows the new e-mail (so a typo does not leave it with the wrong person). Entries of real users or of the staff are
	 * not moved: they appear in "To assign or update".
	 */
	public function email_changed( $meta_id, $post_id, $key, $value ) {
		if ( '_email' !== $key || ! defined( 'ATBDP_POST_TYPE' ) || ATBDP_POST_TYPE !== get_post_type( $post_id ) || ! is_email( $value ) ) {
			return;
		}
		$owner = (int) get_post_field( 'post_author', $post_id );
		if ( ! $owner || ! get_user_meta( $owner, '_dirnet_invited', true ) || get_user_meta( $owner, Member::VERIFIED, true ) ) {
			return;
		}
		$u = get_userdata( $owner );
		if ( ! $u || strtolower( $u->user_email ) === strtolower( $value ) ) {
			return;
		}
		$new = self::ensure_user( sanitize_email( $value ), $post_id );
		if ( $new && $new !== $owner ) {
			Owners::$importing = true;
			wp_update_post( array( 'ID' => $post_id, 'post_author' => $new ) );
			Owners::$importing = false;
		}
	}

	/** The user of an e-mail: created with the name of the entry when missing. 0 for staff accounts (never touched). */
	private static function ensure_user( $email, $post_id ) {
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			return user_can( $user, 'edit_others_posts' ) ? 0 : (int) $user->ID;
		}
		$base  = sanitize_user( strstr( $email, '@', true ), true );
		$base  = '' !== $base ? $base : 'socio';
		$login = $base;
		$i     = 1;
		while ( username_exists( $login ) ) {
			$login = $base . ++$i;
		}
		$name = get_the_title( $post_id );
		$uid  = wp_insert_user( array( 'user_login' => $login, 'user_email' => $email, 'user_pass' => wp_generate_password( 24 ), 'role' => 'subscriber', 'display_name' => $name, 'nickname' => $name ) );
		if ( is_wp_error( $uid ) ) {
			return 0;
		}
		update_user_meta( $uid, Member::NAME, $name );
		update_user_meta( $uid, Member::KIND, 'legal' );
		update_user_meta( $uid, '_dirnet_invited', time() ); // created by the invitation: not verified until the first sign-in
		return (int) $uid;
	}

	public function send() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'dirnet_invitations' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'directorist-network' ) );
		}
		$ids  = isset( $_POST['ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['ids'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$op   = ( isset( $_POST['op'] ) && 'create' === $_POST['op'] ) ? 'create' : 'invite';
		$sent = array();
		$made = array();
		$skipped = 0;
		$n    = 0;
		foreach ( $ids as $id ) {
			if ( ATBDP_POST_TYPE !== get_post_type( $id ) ) {
				continue;
			}
			$email = sanitize_email( (string) get_post_meta( $id, '_email', true ) );
			if ( ! is_email( $email ) ) {
				continue;
			}
			if ( ! isset( $made[ $email ] ) ) { // the user of this e-mail exists before the invitation; the entries are his
				$made[ $email ] = self::ensure_user( $email, $id );
			}
			if ( ! $made[ $email ] ) {
				$skipped++;
				continue;
			}
			if ( (int) get_post_field( 'post_author', $id ) !== (int) $made[ $email ] ) {
				Owners::$importing = true;
				wp_update_post( array( 'ID' => $id, 'post_author' => (int) $made[ $email ] ) );
				Owners::$importing = false;
			}
			if ( 'create' === $op ) { // only the users and the assignment: no e-mail
				$n++;
				continue;
			}
			if ( ! isset( $sent[ $email ] ) ) { // one e-mail per address, listing all its entries
				$names = array();
				foreach ( $ids as $other ) {
					if ( sanitize_email( (string) get_post_meta( $other, '_email', true ) ) === $email ) {
						$names[] = get_the_title( $other );
					}
				}
				$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
				$link = Member::magic_link( $email, 14 * DAY_IN_SECONDS );
				$body = '<p>' . esc_html__( 'Ciao,', 'directorist-network' ) . '</p><p>' . sprintf(
					/* translators: 1: site name, 2: entry name(s), 3: e-mail, 4: opening link tag, 5: closing link tag */
					esc_html__( 'sul sito %1$s c’è una scheda intestata a %2$s associata alla email %3$s. Se sei interessata/o a gestire questa scheda, %4$sclicca qui%5$s. Verrai inviato sul sito dove potrai gestire la scheda della tua organizzazione.', 'directorist-network' ),
					'<strong>' . esc_html( $site ) . '</strong>',
					'<strong>' . esc_html( implode( ', ', array_unique( $names ) ) ) . '</strong>',
					'<strong>' . esc_html( $email ) . '</strong>',
					'<a href="' . esc_url( $link ) . '">',
					'</a>'
				) . '</p>';
				$sent[ $email ] = wp_mail(
					$email,
					sprintf( /* translators: %s: site */ __( '[%s] La scheda della tua organizzazione', 'directorist-network' ), $site ),
					$body,
					array( 'Content-Type: text/html; charset=UTF-8' )
				);
			}
			if ( $sent[ $email ] ) {
				update_post_meta( $id, self::SENT, time() );
				$n++;
			}
		}
		$back = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : admin_url( 'edit.php?post_type=at_biz_dir&page=' . self::SLUG );
		wp_safe_redirect( add_query_arg( array( ( 'create' === $op ? 'created' : 'sent' ) => $n, 'skipped' => $skipped ), $back ) );
		exit;
	}
}
