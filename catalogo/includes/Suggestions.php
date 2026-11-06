<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Suggest an edit" on every entity page.
 *
 * - A collapsible form at the end of the entity page (no JavaScript needed): what to change, the proposal,
 *   an optional source link and an optional name/e-mail for follow-up (only stored when given, with consent).
 * - The suggestion is saved as a private record (Catalogue > Suggested edits) and mailed to the entity's owner
 *   when the entity has been claimed, otherwise to the site administrator. Nothing is changed on the entity
 *   until an editor does it by hand, then marks the suggestion applied or dismissed.
 * - Spam: hidden honeypot field, a signed time token (works on cached pages, unlike a nonce), a limit of
 *   5 suggestions per hour per visitor (a hash is kept for an hour, never the IP itself).
 * - Privacy: applied or dismissed suggestions are deleted after 180 days.
 */
class Suggestions {

	const CPT      = 'dirnet_suggestion';
	const STATUS   = '_dirnet_sg_status'; // new | applied | dismissed
	const CRON     = 'dirnet_suggestions_purge';
	const KEEP     = 180; // days
	const PER_HOUR = 5;

	public function boot() {
		add_action( 'init', array( $this, 'register' ) );
		add_filter( 'the_content', array( $this, 'append_button' ), 21 );
		add_filter( 'directorist_the_content', array( $this, 'append_button_directorist' ), 21 ); // Directorist's own single page does not run the_content

		add_action( 'admin_post_nopriv_dirnet_suggest', array( $this, 'receive' ) );
		add_action( 'admin_post_dirnet_suggest', array( $this, 'receive' ) );
		add_action( 'add_meta_boxes_' . self::CPT, array( $this, 'box' ) );
		add_action( 'save_post_' . self::CPT, array( $this, 'save' ), 10, 2 );
		add_filter( 'manage_' . self::CPT . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', array( $this, 'column' ), 10, 2 );
		add_action( self::CRON, array( $this, 'purge' ) );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/* ------------------------------------------------------------ the suggestion record */

	public function register() {
		$cap = 'manage_options'; // suggestions may hold e-mail addresses: administrators only.
		register_post_type(
			self::CPT,
			array(
				'labels'       => array(
					'name'          => __( 'Suggested edits', 'directorist-network' ),
					'singular_name' => __( 'Suggested edit', 'directorist-network' ),
					'menu_name'     => __( 'Suggested edits', 'directorist-network' ),
					'all_items'     => __( 'Suggested edits', 'directorist-network' ),
					'edit_item'     => __( 'Suggested edit', 'directorist-network' ),
					'search_items'  => __( 'Search suggestions', 'directorist-network' ),
					'not_found'     => __( 'No suggestions yet.', 'directorist-network' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => defined( 'ATBDP_POST_TYPE' ) ? 'edit.php?post_type=' . ATBDP_POST_TYPE : true,
				'supports'     => array( 'title' ),
				'map_meta_cap' => false,
				'capabilities' => array(
					'edit_post'              => $cap,
					'read_post'              => $cap,
					'delete_post'            => $cap,
					'edit_posts'             => $cap,
					'edit_others_posts'      => $cap,
					'delete_posts'           => $cap,
					'delete_others_posts'    => $cap,
					'publish_posts'          => $cap,
					'read_private_posts'     => $cap,
					'delete_private_posts'   => $cap,
					'delete_published_posts' => $cap,
					'edit_private_posts'     => $cap,
					'edit_published_posts'   => $cap,
					'create_posts'           => 'do_not_allow',
				),
			)
		);
	}

	public static function fields() {
		return array(
			'general'    => __( 'General information', 'directorist-network' ),
			'name'       => __( 'Name', 'directorist-network' ),
			'contact'    => __( 'Address, phone or e-mail', 'directorist-network' ),
			'website'    => __( 'Website or social profiles', 'directorist-network' ),
			'description' => __( 'Description', 'directorist-network' ),
			'category'   => __( 'Category or roles', 'directorist-network' ),
			'network'    => __( 'Network or group membership', 'directorist-network' ),
			'closed'     => __( 'It no longer exists, or it is a duplicate', 'directorist-network' ),
			'other'      => __( 'Something else', 'directorist-network' ),
		);
	}

	/* ------------------------------------------------------------ the form on the entity page */

	private static function token( $post_id, $ts ) {
		return hash_hmac( 'sha256', (int) $post_id . '|' . (int) $ts, wp_salt( 'nonce' ) );
	}

	/** The collapsible form for one entity (also usable from a theme). */
	public static function button( $post_id, $summary = '', $frame = true ) {
		$post_id = (int) $post_id;
		$ts      = time();
		$msg     = '';
		if ( isset( $_GET['dirnet_suggested'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- display only
			$ok  = '1' === sanitize_text_field( wp_unslash( $_GET['dirnet_suggested'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$msg = '<p role="status" style="margin:0 0 10px;padding:8px 12px;border-radius:4px;background:' . ( $ok ? '#e6f4ea' : '#fdecea' ) . '"><strong>'
				. esc_html( $ok ? __( 'Thank you: your suggestion has reached the editors. Nothing changes until they have checked it.', 'directorist-network' ) : __( 'The suggestion was not sent. Please check the form and try again in a few minutes.', 'directorist-network' ) )
				. '</strong></p>';
		}
		$h  = '<details class="dirnet-suggest" id="dirnet-suggest"' . ( $msg ? ' open' : '' ) . ( $frame ? ' style="margin:24px 0;padding:10px 14px;border:1px solid #d9d9d9;border-radius:4px"' : '' ) . '>';
		$h .= '<summary style="cursor:pointer;font-weight:700">' . esc_html( '' !== $summary ? $summary : __( 'Suggest an edit', 'directorist-network' ) ) . '</summary>';
		$h .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px">' . $msg;
		$h .= '<input type="hidden" name="action" value="dirnet_suggest" />';
		$h .= '<input type="hidden" name="post_id" value="' . $post_id . '" />';
		$h .= '<input type="hidden" name="ts" value="' . $ts . '" />';
		$h .= '<input type="hidden" name="tk" value="' . esc_attr( self::token( $post_id, $ts ) ) . '" />';
		$h .= '<p style="position:absolute;left:-9999px" aria-hidden="true"><label>Website <input type="text" name="website2" tabindex="-1" autocomplete="off" /></label></p>';
		$h .= '<p><label><b>' . esc_html__( 'What should be changed?', 'directorist-network' ) . '</b><br><select name="field" style="max-width:100%">';
		foreach ( self::fields() as $k => $l ) {
			$h .= '<option value="' . esc_attr( $k ) . '">' . esc_html( $l ) . '</option>';
		}
		$h .= '</select></label></p>';
		$h .= '<p><label><b>' . esc_html__( 'Your suggestion', 'directorist-network' ) . '</b> *<br><textarea name="message" rows="5" maxlength="2000" required style="width:100%"></textarea></label></p>';
		$h .= '<p><label>' . esc_html__( 'Where can the editors check it? (a link, optional)', 'directorist-network' ) . '<br><input type="url" name="source" maxlength="300" style="width:100%" /></label></p>';
		$h .= '<p><label>' . esc_html__( 'Your name (optional)', 'directorist-network' ) . '<br><input type="text" name="name" maxlength="80" autocomplete="name" style="width:100%" /></label></p>';
		$h .= '<p><label>' . esc_html__( 'Your e-mail, only if you want a reply (optional)', 'directorist-network' ) . '<br><input type="email" name="email" maxlength="120" autocomplete="email" style="width:100%" /></label></p>';
		$h .= '<p style="font-size:.9em"><label><input type="checkbox" name="consent" value="1" /> ' . esc_html__( 'If I gave an e-mail or a name, the editors may use it to answer me. It is deleted with the suggestion, within 180 days of being handled.', 'directorist-network' ) . '</label></p>';
		$h .= '<p><button type="submit" class="dn-btn" style="cursor:pointer">' . esc_html__( 'Send the suggestion', 'directorist-network' ) . '</button></p>';
		$h .= '</form></details>';
		return $h;
	}

	private static $placed = array();

	public function append_button( $content ) {
		if ( ! defined( 'ATBDP_POST_TYPE' ) || ! is_singular( ATBDP_POST_TYPE ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		return $this->with_button( $content );
	}

	/** Same block when the page is Directorist's own template (its description widget). */
	public function append_button_directorist( $content ) {
		if ( ! defined( 'ATBDP_POST_TYPE' ) || ! is_singular( ATBDP_POST_TYPE ) ) {
			return $content;
		}
		return $this->with_button( $content );
	}

	private function with_button( $content ) {
		$id = (int) get_the_ID();
		/** Return false to hide the button (for example on one entity type). */
		if ( ! $id || Widgets::placed( 'dirnet_suggest' ) || isset( self::$placed[ $id ] ) || ! apply_filters( 'dirnet_show_suggest_button', true, $id ) ) {
			return $content;
		}
		self::$placed[ $id ] = true;
		return $content . self::button( $id );
	}

	/* ------------------------------------------------------------ receiving */

	public function receive() {
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- signed token below
		$back    = $post_id && 'publish' === get_post_status( $post_id ) ? get_permalink( $post_id ) : home_url( '/' );
		$fail    = static function () use ( $back ) {
			wp_safe_redirect( add_query_arg( 'dirnet_suggested', '0', $back ) . '#dirnet-suggest' );
			exit;
		};
		if ( ! defined( 'ATBDP_POST_TYPE' ) || ! $post_id || ATBDP_POST_TYPE !== get_post_type( $post_id ) || 'publish' !== get_post_status( $post_id ) ) {
			$fail();
		}
		$ts = isset( $_POST['ts'] ) ? absint( $_POST['ts'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$tk = isset( $_POST['tk'] ) ? sanitize_text_field( wp_unslash( $_POST['tk'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$age = time() - $ts;
		if ( ! $ts || ! hash_equals( self::token( $post_id, $ts ), $tk ) || $age < 4 || $age > 2 * DAY_IN_SECONDS || ! empty( $_POST['website2'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$fail();
		}
		$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$lock = 'dirnet_sg_' . md5( $ip . wp_salt( 'auth' ) );
		$n    = (int) get_transient( $lock );
		if ( $n >= self::PER_HOUR ) {
			$fail();
		}
		set_transient( $lock, $n + 1, HOUR_IN_SECONDS );

		$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$message = mb_substr( $message, 0, 2000 );
		if ( strlen( trim( $message ) ) < 5 ) {
			$fail();
		}
		$fields = self::fields();
		$field  = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification
		$field  = isset( $fields[ $field ] ) ? $field : 'other';
		$source = isset( $_POST['source'] ) ? esc_url_raw( wp_unslash( $_POST['source'] ), array( 'http', 'https' ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$consent = ! empty( $_POST['consent'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$name    = $consent && isset( $_POST['name'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['name'] ) ), 0, 80 ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$email   = $consent && isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$email   = is_email( $email ) ? $email : '';

		$sid = wp_insert_post(
			array(
				'post_type'    => self::CPT,
				'post_status'  => 'publish',
				'post_title'   => wp_strip_all_tags( get_the_title( $post_id ) . ' — ' . $fields[ $field ] ),
				'post_content' => $message,
			),
			true
		);
		if ( is_wp_error( $sid ) ) {
			$fail();
		}
		update_post_meta( $sid, '_dirnet_sg_target', $post_id );
		update_post_meta( $sid, '_dirnet_sg_field', $field );
		update_post_meta( $sid, '_dirnet_sg_source', $source );
		update_post_meta( $sid, '_dirnet_sg_name', $name );
		update_post_meta( $sid, '_dirnet_sg_email', $email );
		update_post_meta( $sid, self::STATUS, 'new' );
		$this->notify( $sid, $post_id, $fields[ $field ], $message, $source, $name, $email );

		wp_safe_redirect( add_query_arg( 'dirnet_suggested', '1', $back ) . '#dirnet-suggest' );
		exit;
	}

	/** Mail the owner of a claimed entity, otherwise the administrator. Return an array of addresses from the filter to change it. */
	private function notify( $sid, $post_id, $field_label, $message, $source, $name, $email ) {
		$to     = get_option( 'admin_email' );
		$author = (int) get_post_field( 'post_author', $post_id );
		$user   = $author ? get_userdata( $author ) : false;
		if ( $user && ! user_can( $user, 'manage_options' ) && is_email( $user->user_email ) ) {
			$to = $user->user_email;
		}
		$to   = apply_filters( 'dirnet_suggestion_recipients', $to, $post_id, $sid );
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$body = sprintf(
			/* translators: 1: entity name, 2: what to change, 3: the suggestion, 4: link, 5: sender, 6: edit link, 7: entity link */
			__( "A visitor suggests an edit to \"%1\$s\".\n\nWhat: %2\$s\n\n%3\$s\n\nSource: %4\$s\nFrom: %5\$s\n\nEntity: %7\$s\nReview it here: %6\$s\n\nNothing has been changed. Edit the entity yourself, then mark the suggestion as applied or dismissed.", 'directorist-network' ),
			get_the_title( $post_id ),
			$field_label,
			$message,
			$source ? $source : '-',
			( $name || $email ) ? trim( $name . ' ' . ( $email ? '<' . $email . '>' : '' ) ) : __( 'anonymous', 'directorist-network' ),
			admin_url( 'post.php?post=' . $sid . '&action=edit' ),
			get_permalink( $post_id )
		);
		$headers = $email ? array( 'Reply-To: ' . $email ) : array();
		wp_mail( $to, sprintf( /* translators: 1: site, 2: entity */ __( '[%1$s] Suggested edit: %2$s', 'directorist-network' ), $site, get_the_title( $post_id ) ), $body, $headers );
	}

	/* ------------------------------------------------------------ admin */

	public function box() {
		add_meta_box( 'dirnet_sg', __( 'Suggested edit', 'directorist-network' ), array( $this, 'box_html' ), self::CPT, 'normal', 'high' );
	}

	public function box_html( $post ) {
		wp_nonce_field( 'dirnet_sg', 'dirnet_sg_nonce' );
		$t      = (int) get_post_meta( $post->ID, '_dirnet_sg_target', true );
		$fields = self::fields();
		$f      = get_post_meta( $post->ID, '_dirnet_sg_field', true );
		$st     = get_post_meta( $post->ID, self::STATUS, true );
		$st     = $st ? $st : 'new';
		echo '<p><b>' . esc_html__( 'Entity', 'directorist-network' ) . ':</b> ';
		if ( $t && get_post( $t ) ) {
			echo '<a href="' . esc_url( get_edit_post_link( $t ) ) . '">' . esc_html( get_the_title( $t ) ) . '</a> (<a href="' . esc_url( get_permalink( $t ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'view', 'directorist-network' ) . '</a>)';
		} else {
			echo esc_html__( 'deleted', 'directorist-network' );
		}
		echo '</p><p><b>' . esc_html__( 'What', 'directorist-network' ) . ':</b> ' . esc_html( isset( $fields[ $f ] ) ? $fields[ $f ] : $f ) . '</p>';
		echo '<p><b>' . esc_html__( 'Suggestion', 'directorist-network' ) . ':</b></p><div style="padding:10px;background:#f6f7f7;border:1px solid #dcdcde;white-space:pre-wrap">' . esc_html( $post->post_content ) . '</div>';
		$src = get_post_meta( $post->ID, '_dirnet_sg_source', true );
		if ( $src ) {
			echo '<p><b>' . esc_html__( 'Source', 'directorist-network' ) . ':</b> <a href="' . esc_url( $src ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $src ) . '</a></p>';
		}
		$n = get_post_meta( $post->ID, '_dirnet_sg_name', true );
		$e = get_post_meta( $post->ID, '_dirnet_sg_email', true );
		echo '<p><b>' . esc_html__( 'From', 'directorist-network' ) . ':</b> ' . esc_html( ( $n || $e ) ? trim( $n . ' ' . $e ) : __( 'anonymous', 'directorist-network' ) ) . '</p><hr>';
		echo '<p><b>' . esc_html__( 'Status', 'directorist-network' ) . ':</b> ';
		foreach ( self::statuses() as $k => $l ) {
			echo '<label style="margin-right:14px"><input type="radio" name="dirnet_sg_status" value="' . esc_attr( $k ) . '"' . checked( $st, $k, false ) . ' /> ' . esc_html( $l ) . '</label>';
		}
		echo '</p><p class="description">' . esc_html( sprintf( /* translators: %d: days */ __( 'Handled suggestions are deleted after %d days.', 'directorist-network' ), self::KEEP ) ) . '</p>';
	}

	public static function statuses() {
		return array(
			'new'       => __( 'New', 'directorist-network' ),
			'applied'   => __( 'Applied', 'directorist-network' ),
			'dismissed' => __( 'Dismissed', 'directorist-network' ),
		);
	}

	public function save( $post_id, $post ) {
		if ( ! isset( $_POST['dirnet_sg_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dirnet_sg_nonce'] ) ), 'dirnet_sg' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$st = isset( $_POST['dirnet_sg_status'] ) ? sanitize_key( wp_unslash( $_POST['dirnet_sg_status'] ) ) : 'new';
		if ( isset( self::statuses()[ $st ] ) ) {
			update_post_meta( $post_id, self::STATUS, $st );
			if ( 'new' !== $st ) {
				update_post_meta( $post_id, '_dirnet_sg_handled', time() );
			} else {
				delete_post_meta( $post_id, '_dirnet_sg_handled' );
			}
		}
	}

	public function columns( $cols ) {
		return array(
			'cb'     => isset( $cols['cb'] ) ? $cols['cb'] : '',
			'title'  => __( 'Entity — what', 'directorist-network' ),
			'sg_msg' => __( 'Suggestion', 'directorist-network' ),
			'sg_st'  => __( 'Status', 'directorist-network' ),
			'date'   => __( 'Date', 'directorist-network' ),
		);
	}

	public function column( $col, $post_id ) {
		if ( 'sg_msg' === $col ) {
			echo esc_html( wp_trim_words( get_post_field( 'post_content', $post_id ), 22 ) );
		} elseif ( 'sg_st' === $col ) {
			$st = get_post_meta( $post_id, self::STATUS, true );
			$l  = self::statuses();
			echo '<b>' . esc_html( isset( $l[ $st ] ) ? $l[ $st ] : $l['new'] ) . '</b>';
		}
	}

	/** Delete handled suggestions after KEEP days (they may hold a name and an e-mail). */
	public function purge() {
		$ids = get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'any',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array( 'key' => '_dirnet_sg_handled', 'value' => time() - self::KEEP * DAY_IN_SECONDS, 'compare' => '<', 'type' => 'NUMERIC' ),
				),
			)
		);
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
	}
}

