<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One sign-up, then a personal page.
 *
 * [dirnet_register]  account + registry data, first choice: legal person or natural person.
 * [dirnet_dashboard]  what the member can do next: edit / create the entity, Solidaros questionnaires
 *                    (producer, evaluator: on request, active at once, an administrator confirms or cancels).
 * Requests wait in "Directorist > Authorisation requests"; authorising gives the Solidaros WordPress role.
 */
class Member {

	const KIND      = 'dirnet_kind';       // legal | natural
	const NAME      = 'dirnet_name';
	const TAX       = 'dirnet_tax_id';
	const VIEW_AS   = 'dirnet_view_as';    // administrators only: the profile they are looking at the site as (test)
	const REQUEST   = '_dirnet_request_';  // + producer|evaluator : pending | approved | rejected
	const ROLES     = array( 'producer' => 'sm_subject', 'evaluator' => 'sm_evaluator' );
	const PRODUCER  = 'dirnet_producer';
	const VERIFIED  = 'dirnet_email_verified';
	const SUBMIT    = 'solidaros_submit_assessment';
	const EVALUATE  = 'solidaros_evaluate_assessment';

	public function boot() {
		if ( ! SolidarosBridge::delegated() ) {
			return; // standalone Solidaros: its own pages and links, no WordPress sign-in from here
		}
		add_action( 'directorist_before_add_listing_from_frontend', array( $this, 'add_hint' ) );
		add_action( 'transition_post_status', array( $this, 'entry_published' ), 10, 3 );
		// One shortcode for the whole access: e-mail + consent, then (signed in) the choice of function and the entry form.
		add_shortcode( 'dirnet_access', function () { return $this->register_form( 'legal' ); } );
		foreach ( array( 'dirnet_register', 'dirnet_enter', 'dirnet_login' ) as $old ) { // older pages keep working
			add_shortcode( $old, function () { return $this->register_form( 'legal' ); } );
		}
		add_shortcode( 'dirnet_registration_evaluator', array( $this, 'evaluator_form' ) ); // old pages only: the reviewer form is now inside [dirnet_registration]
		add_shortcode( 'dirnet_register_evaluator', array( $this, 'evaluator_form' ) ); // old name
		add_action( 'admin_post_dirnet_evaluator', array( $this, 'evaluator_save' ) );
		add_filter( 'wp_robots', array( $this, 'noindex' ) );
		add_filter( 'the_content', array( $this, 'request_on_page' ), 5 );
		add_shortcode( 'dirnet_dashboard', array( $this, 'my_space' ) );
		add_shortcode( 'dirnet_my_space', array( $this, 'my_space' ) ); // old name
		add_shortcode( 'dirnet_entry_form', array( $this, 'entry_form' ) );
		add_shortcode( 'dirnet_registration', function () { return $this->register_form( 'register' ); } );
		add_shortcode( 'dirnet_entity_start', array( $this, 'entity_start' ) ); // old name
		add_shortcode( 'dirnet_profile_form', array( $this, 'profile_form' ) );
		add_shortcode( 'dirnet_entry', array( $this, 'entity_next' ) );
		add_shortcode( 'dirnet_assignment', array( $this, 'assignment' ) );
		add_shortcode( 'dirnet_entity_next', array( $this, 'entity_next' ) ); // old name
		add_action( 'admin_post_dirnet_profile', array( $this, 'profile_save' ) );
		add_action( 'admin_post_dirnet_person', array( $this, 'person_save' ) );
		add_action( 'admin_post_nopriv_dirnet_code', array( $this, 'code_verify' ) );
		add_action( 'admin_post_dirnet_code', array( $this, 'code_verify' ) );
		add_filter( 'directorist_dashboard_tabs', array( $this, 'tabs' ) );
		add_filter( 'directorist_dashboard_tabs', function ( $tabs ) { // campaigns are no longer part of the site: no leftover tab, whoever adds it
			foreach ( (array) $tabs as $k => $t ) {
				if ( false !== stripos( (string) $k, 'campaign' ) || ( is_array( $t ) && isset( $t['title'] ) && in_array( strtolower( (string) $t['title'] ), array( 'my campaigns', 'le mie campagne' ), true ) ) ) {
					unset( $tabs[ $k ] );
				}
			}
			return $tabs;
		}, 99 );
		// WordPress' own registration and back office are for administrators and editors; everyone else uses the site.
		add_action( 'login_init', array( $this, 'wp_register_closed' ) );
		add_filter( 'register_url', array( $this, 'register_url' ) );
		add_filter( 'login_redirect', array( $this, 'wp_login_redirect' ), 99, 3 );
		add_action( 'admin_init', array( $this, 'keep_out_of_admin' ) );
		add_filter( 'show_admin_bar', array( $this, 'admin_bar_for_staff' ), 99 );
		add_action( 'wp_head', array( $this, 'hide_submit_button' ) );
		add_action( 'wp_footer', array( $this, 'prefill_entry' ) );
		add_action( 'solidaros_registration_store', array( __CLASS__, 'sync_user' ), 30 );
		add_action( 'dirnet_cleanup_incomplete', array( __CLASS__, 'cleanup_incomplete' ) );
		add_filter( 'wp_email_auth_ip_hour', function ( $n ) { return (int) apply_filters( 'dirnet_code_ip_hour', $n ); } ); // i vecchi filtri valgono anche con WP Email Auth
		add_filter( 'wp_email_auth_site_day', function ( $n ) { return (int) apply_filters( 'dirnet_code_site_day', $n ); } );
		if ( ! wp_next_scheduled( 'dirnet_cleanup_incomplete' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'dirnet_cleanup_incomplete' );
		}
		add_action( 'solidaros_role_rejected', array( $this, 'registration_refused' ), 10, 2 );
		add_action( 'template_redirect', array( $this, 'redirect_login' ) );
		add_filter( 'gettext', array( $this, 'button_labels' ), 10, 3 );
		add_action( 'admin_post_nopriv_dirnet_join', array( $this, 'join' ) );
		add_action( 'admin_post_dirnet_join', array( $this, 'join' ) );
		add_action( 'template_redirect', array( $this, 'categories_saved' ) );
		add_action( 'admin_post_dirnet_request', array( $this, 'request' ) );
		add_action( 'init', array( $this, 'move_requests' ), 30 );
		add_action( 'admin_post_dirnet_claim', array( $this, 'claim' ) );
		add_action( 'admin_post_dirnet_choose', array( $this, 'choose' ) ); // the registrant picks, among the entries that may be theirs
		add_action( 'admin_post_dirnet_claim_decide', array( $this, 'claim_decide' ) );
		add_action( 'admin_post_dirnet_qcats', array( $this, 'save_cats' ) );
		add_action( 'template_redirect', array( $this, 'magic' ) );
		add_action( 'atbdp_listing_inserted', array( $this, 'flag_bacheca' ), 30 );
		add_action( 'template_redirect', array( $this, 'go_bacheca' ), 5 );
		add_action( 'admin_menu', array( $this, 'menu' ), 31 );
		add_action( 'wp_dashboard_setup', array( $this, 'dashboard_widget' ) );
		add_action( 'init', array( $this, 'pages' ), 50 );
	}

	/* ------------------------------------------------------------ pages */

	/** The "Enter" page (slug accesso): e-mail and consent only. */
	private function enter_page() {
		if ( get_option( 'dirnet_enter_page_v1' ) ) {
			return;
		}
		update_option( 'dirnet_enter_page_v1', 1 );
		$id = (int) get_option( 'dirnet_enter_page' );
		if ( ! $id ) {
			$id = (int) Pages::id( 'login' ); // the sign-in page becomes the access page
		}
		if ( $id && get_post( $id ) && 'trash' !== get_post_status( $id ) ) {
			wp_update_post( array( 'ID' => $id, 'post_title' => __( 'Enter', 'directorist-network' ), 'post_name' => 'accesso', 'post_content' => '[dirnet_access]' ) );
			update_option( 'dirnet_enter_page', $id, false );
			return;
		}
	}

	/** A new entry has just been submitted: the author is sent to the dashboard (My space) at the next page. */
	public function flag_bacheca( $listing_id ) {
		$uid = (int) get_post_field( 'post_author', (int) $listing_id );
		if ( $uid && (int) get_option( 'dirnet_myspace_page' ) ) {
			set_transient( 'dirnet_to_bacheca_' . $uid, 1, 10 * MINUTE_IN_SECONDS );
		}
	}

	/** One-time redirect to My space after the data entry. Not on ajax, admin, forms or the dashboard itself. */
	public function go_bacheca() {
		if ( ! is_user_logged_in() || is_admin() || wp_doing_ajax() || 'GET' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) {
			return;
		}
		$key = 'dirnet_to_bacheca_' . get_current_user_id();
		if ( ! get_transient( $key ) ) {
			return;
		}
		$d = (int) get_option( 'dirnet_myspace_page' );
		if ( ! $d || 'publish' !== get_post_status( $d ) ) {
			delete_transient( $key );
			return;
		}
		delete_transient( $key );
		if ( is_page( $d ) ) {
			return; // already there
		}
		wp_safe_redirect( (string) get_permalink( $d ) );
		exit;
	}

	/** "My space" is the Directorist dashboard page itself: our hub is put in front of its shortcode (and taken out again on deactivation). */
	private function dashboard() {
		$d = function_exists( 'get_directorist_option' ) ? (int) get_directorist_option( 'user_dashboard' ) : 0;
		if ( ! $d || ! get_post( $d ) || 'trash' === get_post_status( $d ) ) {
			return;
		}
		if ( get_option( 'dirnet_dash_seen' ) === $d . '|' . DIRNET_VERSION ) {
			return; // already checked for this dashboard page and plugin version: no more page reads on every request
		}
		update_option( 'dirnet_dash_seen', $d . '|' . DIRNET_VERSION, false );
		$set = (int) get_option( 'dirnet_myspace_page' ); // assigned in System pages: it stays; otherwise Directorist's dashboard
		if ( ! $set || ! get_post( $set ) || 'publish' !== get_post_status( $set ) ) {
			update_option( 'dirnet_myspace_page', $d, false );
		}
		update_option( 'dirnet_create_page', (int) get_option( 'dirnet_enter_page' ), false );
		$c = (string) get_post_field( 'post_content', $d );
		if ( false !== strpos( $c, '[dirnet_my_space]' ) || false !== strpos( $c, '[dirnet_dashboard]' ) ) { // left by an earlier version
			self::restore();
			update_option( 'dirnet_myspace_page', $d, false );
			update_option( 'dirnet_create_page', (int) get_option( 'dirnet_enter_page' ), false );
		}
	}

	

	/** [dirnet_entry_form type="producer|gas|network"]: the add-listing form of that kind of entity; sign-in required. */
	public function entry_form( $atts ) {
		$atts = shortcode_atts( array( 'type' => 'producer' ), $atts, 'dirnet_entry_form' );
		if ( ! is_user_logged_in() ) {
			$login = Pages::id( 'login' ) ? get_permalink( Pages::id( 'login' ) ) : wp_login_url( get_permalink() );
			return '<p>' . esc_html__( 'Sign in to register your entity.', 'directorist-network' ) . ' <a href="' . esc_url( $login ) . '">' . esc_html__( 'Sign in', 'directorist-network' ) . '</a></p>';
		}
		$rows = self::type_rows();
		$k    = sanitize_key( $atts['type'] );
		if ( ! isset( $rows[ $k ] ) ) {
			return '<p>' . esc_html__( 'This kind of registration is not available yet.', 'directorist-network' ) . '</p>';
		}
		// Sequence: access -> registration -> social report (producers). Who already registered this kind of entry is sent on.
		$term = get_term_by( 'slug', $rows[ $k ][1], 'atbdp_listing_types' );
		$done = array();
		if ( $term && ! user_can( get_current_user_id(), 'edit_others_posts' ) && empty( $_GET['dirnet_new'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			foreach ( self::own( get_current_user_id() ) as $pid ) {
				if ( (int) get_post_meta( $pid, '_directory_type', true ) === (int) $term->term_id ) {
					$done[] = $pid;
				}
			}
		}
		if ( $done ) {
			$pub  = 'publish' === get_post_status( $done[0] );
			$out  = '<p><strong>' . esc_html( get_the_title( $done[0] ) ) . '</strong> – ' . esc_html( $pub ? __( 'your entry is published.', 'directorist-network' ) : __( 'your entry was received and is waiting for review.', 'directorist-network' ) ) . '</p>';
			if ( 'producer' === $k && Pages::id( 'report' ) ) {
				$out .= '<p>' . esc_html__( 'Next step: fill in the social report.', 'directorist-network' ) . '</p><p><a class="button button-primary" href="' . esc_url( (string) get_permalink( Pages::id( 'report' ) ) ) . '">' . esc_html__( 'Go to the social report', 'directorist-network' ) . '</a></p>';
			} else {
				$out .= '<p><a class="button button-primary" href="' . esc_url( self::url( 'dirnet_myspace_page' ) ) . '">' . esc_html__( 'Go to your space', 'directorist-network' ) . '</a></p>';
			}
			return $out . '<p><small><a href="' . esc_url( add_query_arg( 'dirnet_new', 1, get_permalink() ) ) . '">' . esc_html__( 'Register another entry of this kind', 'directorist-network' ) . '</a></small></p>';
		}
		return do_shortcode( '[directorist_add_listing directory_type="' . esc_attr( $rows[ $k ][1] ) . '"]' );
	}

	

	

	

	

	

	

	

	

	

	

	

	

	/** The user's entries. */
	private static function own( $uid ) {
		if ( ! defined( 'ATBDP_POST_TYPE' ) ) {
			return array();
		}
		return get_posts( array( 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'any', 'author' => (int) $uid, 'fields' => 'ids', 'numberposts' => 100 ) );
	}

	private function entry_tiles( $ids ) {
		$h = '';
		foreach ( $ids as $pid ) {
			$edit = class_exists( '\\ATBDP_Permalink' ) ? \ATBDP_Permalink::get_edit_listing_page_link( $pid ) : get_permalink( $pid );
			$st   = get_post_status( $pid );
			$h   .= $this->tile( get_the_title( $pid ), 'publish' === $st ? __( 'Published in the catalogue.', 'directorist-network' ) : __( 'Waiting for review.', 'directorist-network' ), $edit, __( 'Edit', 'directorist-network' ) );
		}
		return $h;
	}

	/** Page title: same markup (and so same size) as the title of Directorist's Preferences tab. */
	private static function page_title( $t ) {
		return '<div class="directorist-card directorist-user-profile-box"><div class="directorist-card__header"><h3 class="directorist-card__header__title">' . esc_html( $t ) . '</h3></div></div>';
	}

	private static function sub_title( $t ) {
		return '<h4 style="margin:24px 0 12px">' . esc_html( $t ) . '</h4>';
	}

	/** A long strip: title and description on the left, the button at the end. */
	/** Address of the Solidaros evaluators area ([so-evaluator-area]); '' when that page is not set up. */
	private static function evaluator_area() {
		$own = Pages::id( 'evaluator' ); // chosen in the system pages
		if ( $own && 'publish' === get_post_status( $own ) ) {
			return (string) get_permalink( $own );
		}
		if ( ! ( SolidarosBridge::active() && class_exists( '\\Solidaros\\Core\\Pages' ) ) || ! \Solidaros\Core\Pages::id( 'evaluator' ) ) {
			return '';
		}
		return self::sol_page( 'evaluator' );
	}

	private function band( $title, $text, $url = '', $button = '' ) {
		$h = '<div class="dirnet-band" style="display:flex;flex-wrap:wrap;align-items:center;gap:12px 20px;border:1px solid #d9d9d9;border-radius:4px;padding:16px;background:#fff;margin-bottom:12px"><div style="flex:1 1 280px"><strong>' . esc_html( $title ) . '</strong>';
		if ( '' !== $text ) {
			$h .= '<p style="margin:4px 0 0">' . esc_html( $text ) . '</p>';
		}
		$h .= '</div>';
		if ( $url ) {
			$h .= '<a class="button" style="margin-left:auto" href="' . esc_url( $url ) . '">' . esc_html( $button ? $button : __( 'Open', 'directorist-network' ) ) . '</a>';
		}
		return $h . '</div>';
	}

	/** The Directorist dashboard (Bacheca) menu: questionnaires, then Directorist's own messages, favourites, preferences. */
	public function tabs( $tabs ) {
		if ( ! is_user_logged_in() ) {
			return $tabs;
		}
		$uid  = get_current_user_id();
		$out  = array();
		$st   = array( 'publish' => __( 'Published', 'directorist-network' ), 'pending' => __( 'Waiting for approval', 'directorist-network' ), 'draft' => __( 'Draft', 'directorist-network' ), 'future' => __( 'Scheduled', 'directorist-network' ), 'private' => __( 'Private', 'directorist-network' ) );
		$kind = (string) get_user_meta( $uid, self::KIND, true );

		// First item: the registration data (editable) and, below, Directorist's account form (e-mail, password).
		$out['dirnet_personal'] = array( 'title' => __( 'Personal data', 'directorist-network' ), 'icon' => 'las la-id-card', 'content' => '<div class="dirnet-membership">' . self::page_title( __( 'Personal data', 'directorist-network' ) ) . ( isset( $_GET['dirnet_saved'] ) ? '<p role="status"><strong>' . esc_html__( 'Saved.', 'directorist-network' ) . '</strong></p>' : '' ) . $this->band( __( 'Personal data', 'directorist-network' ), __( 'The data given at registration.', 'directorist-network' ), Pages::id( 'registration' ) ? add_query_arg( 'dirnet_edit', 1, (string) get_permalink( Pages::id( 'registration' ) ) ) : '', __( 'Send', 'directorist-network' ) ) . '</div>' ); // phpcs:ignore WordPress.Security.NonceVerification


		// 0. My entry: the census entry is the profile (edit it, or create it). Restored: it had been removed by mistake.
		$grid = 'display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;margin-bottom:24px';
		$c    = self::page_title( __( 'My entry', 'directorist-network' ) ) ;
		if ( ! self::person_complete( $uid ) && ! self::is_staff( $uid ) ) {
			$part = '<p>' . esc_html__( 'First complete your personal data.', 'directorist-network' ) . '</p><p><a class="button button-primary" href="' . esc_url( self::url( 'dirnet_myspace_page' ) . '#dirnet_personal' ) . '" onclick="var t=document.querySelector(\'[target=dirnet_personal],[data-target=dirnet_personal],a[href=&quot;#dirnet_personal&quot;]\');if(t&amp;&amp;t!==this){t.click();return false;}location.hash=\'dirnet_personal\';location.reload();return false;">' . esc_html__( 'Go to Personal data', 'directorist-network' ) . '</a></p>';
		} else {
			$part = trim( (string) $this->my_space( array( 'part' => 'profile' ) ) ); // the entry (edit), or the step to create it
			if ( '' === wp_strip_all_tags( $part ) && 'evaluator' === self::reg_type( $uid ) && ! self::is_staff( $uid ) ) {
				$part = '<p>' . esc_html__( 'You are registered as a PGS reviewer: your questionnaires are in My questionnaires.', 'directorist-network' ) . '</p>';
			} elseif ( '' === wp_strip_all_tags( $part ) && ! self::is_staff( $uid ) ) {
				$part = $this->start_box();
			} elseif ( '' === wp_strip_all_tags( $part ) ) {
				$part = '<div>' . $this->band( __( 'Create your entry', 'directorist-network' ), __( 'You are not in the catalogue yet.', 'directorist-network' ), self::add_url(), __( 'Create the entry', 'directorist-network' ) ) . '</div>';
			}
		}
		$c      .= $part;
		$as = (int) Pages::id( 'entity_assign' ); // under create / edit entry: the strip to ask for the assignment of an existing entry
		if ( $as && 'publish' === get_post_status( $as ) && ! self::is_staff( $uid ) ) {
			$c .= '<div>' . $this->band( __( 'Entry assignment', 'directorist-network' ), __( 'Your entity is already in the catalogue? Search it by name or tax code and ask for the assignment: an administrator checks the request.', 'directorist-network' ), (string) get_permalink( $as ), __( 'Search and request', 'directorist-network' ) ) . '</div>';
		}
		if ( isset( $_GET['dirnet_step'] ) && 'categories' === $_GET['dirnet_step'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			$c = '<div class="notice" role="status" style="border-left:4px solid #2271b1;padding:8px 12px;margin-bottom:12px;background:#f6f7f7"><strong>' . esc_html__( 'Registration complete. Next step: choose your categories.', 'directorist-network' ) . '</strong></div>' . $c;
		}
		// A PGS reviewer has no entry of their own: the tab is not shown.
		if ( ! ( in_array( self::reg_type( $uid ), array( 'evaluator' ), true ) && ! self::is_staff( $uid ) && ! self::producer_entries( $uid ) ) ) {
			$out['dashboard_profile'] = array( 'title' => __( 'My entry', 'directorist-network' ), 'icon' => 'las la-user', 'content' => '<div class="dirnet-membership">' . $c . '</div>' );
		}

		// 1. My self-certification (subjects) and 2. My evaluations (reviewers): the tabs send to the Solidaros area pages.
		if ( self::solidaros() && ( 'natural' === $kind || self::producer_entries( $uid ) || self::can( 'producer' ) || self::can( 'evaluator' ) ) ) {
			$page = function ( $key ) {
				$id = Pages::id( $key );
				return ( $id && 'publish' === get_post_status( $id ) ) ? (string) get_permalink( $id ) : '';
			};
			if ( 'natural' !== $kind || self::can( 'producer' ) || self::producer_entries( $uid ) ) {
				$c = self::page_title( __( 'My self-certification', 'directorist-network' ) );
				if ( ( self::is_producer( $uid ) && self::producer_entries( $uid ) ) || 'producer' === self::sim() || ( '' === self::sim() && current_user_can( 'edit_others_posts' ) ) ) {
					if ( self::can( 'producer' ) ) {
						$ar = $page( 'area' );
						if ( '' !== $ar ) {
							$c .= $this->band( __( 'Subject area', 'directorist-network' ), __( 'Your self-certification: the guided wizard and the list of your questionnaires.', 'directorist-network' ), $ar, __( 'Open', 'directorist-network' ) );
						} elseif ( current_user_can( 'manage_options' ) ) {
							$c .= '<p class="description">' . esc_html__( 'Administrators: the subject area page is not set (Settings → System pages), so the button is hidden.', 'directorist-network' ) . '</p>';
						}
					} else {
						$c .= $this->ask( 'producer', __( 'Fill in questionnaires as a producer', 'directorist-network' ), __( 'Social report, entity and product profiles. It works at once; an administrator confirms it afterwards.', 'directorist-network' ) );
					}
				} else {
					$c .= '<p>' . esc_html__( 'Questionnaires are available once your entry is in the catalogue.', 'directorist-network' ) . '</p>';
				}
				$out['dirnet_questionnaires'] = array( 'title' => __( 'My self-certification', 'directorist-network' ), 'icon' => 'las la-clipboard-list', 'content' => '<div class="dirnet-questionnaires">' . $c . '</div>' );
			}
			if ( self::can( 'evaluator' ) || 'natural' === $kind ) {
				$c = self::page_title( __( 'My evaluations', 'directorist-network' ) );
				if ( self::can( 'evaluator' ) ) {
					$ea = $page( 'evaluator' );
					if ( '' !== $ea ) {
						$c .= $this->band( __( 'Evaluators area', 'directorist-network' ), __( 'The evaluation wizard, your assigned reviews and the evaluation process.', 'directorist-network' ), $ea, __( 'Open', 'directorist-network' ) );
					} elseif ( current_user_can( 'manage_options' ) ) {
						$c .= '<p class="description">' . esc_html__( 'Administrators: the evaluators area page is not set (Settings → System pages), so the button is hidden.', 'directorist-network' ) . '</p>';
					}
				} else {
					$c .= $this->ask( 'evaluator', __( 'Review questionnaires as a PGS reviewer', 'directorist-network' ), __( 'Verify the places and products questionnaires of other entities. It works at once; an administrator confirms it afterwards.', 'directorist-network' ) );
				}
				$out['dirnet_evaluations'] = array( 'title' => __( 'My evaluations', 'directorist-network' ), 'icon' => 'las la-check-double', 'content' => '<div class="dirnet-evaluations">' . $c . '</div>' );
			}
		}

		// API: a tab of its own in the Bacheca, always there (the content is the [so-api] shortcode).
		if ( class_exists( '\\Solidaros\\Api\\ApiAccess' ) && \Solidaros\Api\ApiAccess::eligible() ) {
			$api_page = Pages::id( 'api' ) ? (string) get_permalink( Pages::id( 'api' ) ) : '';
			$api_body = $api_page
				? $this->band( 'API', __( 'The public list of the self-certifications, with the code to read it.', 'directorist-network' ), $api_page, __( 'Open', 'directorist-network' ) )
				: \Solidaros\Api\ApiAccess::shortcode();
			$out['dirnet_api'] = array( 'title' => 'API', 'icon' => 'las la-plug', 'content' => '<div class="dirnet-membership">' . self::page_title( 'API' ) . $api_body . '</div>' );
		}

		// 4. Directorist's own tabs, preferences. The rest (listings, profile, orders) is not shown.
		if ( isset( $tabs['dashboard_preferences'] ) ) {
			$t            = $tabs['dashboard_preferences'];
			$t['title']   = __( 'Preferences', 'directorist-network' );
			$out['dashboard_preferences'] = $t;
		}
		return $out;
	}

	/**
	 * Daily: accounts made by the access page that never got their personal data (and have no entry, no role, no post)
	 * are removed after 7 days (filter 'dirnet_incomplete_days'). Nothing is created before the e-mail code is confirmed,
	 * so this only catches accounts left over from older links. Invited, staff and multi-role accounts are never touched.
	 */
	public static function cleanup_incomplete() {
		$days = max( 3, (int) apply_filters( 'dirnet_incomplete_days', 7 ) );
		$q    = new \WP_User_Query( array(
			'role'         => 'subscriber',
			'number'       => 100,
			'date_query'   => array( array( 'before' => $days . ' days ago' ) ),
			'meta_query'   => array(
				'relation' => 'AND',
				array( 'key' => self::VERIFIED, 'value' => 1 ),
				array( 'key' => 'sm_company_tax_id', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_dirnet_invite', 'compare' => 'NOT EXISTS' ),
			),
		) );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $q->get_results() as $u ) {
			if ( user_can( $u, 'edit_others_posts' ) || count( (array) $u->roles ) > 1 || count_user_posts( $u->ID, 'any' ) > 0 ) {
				continue;
			}
			if ( defined( 'ATBDP_POST_TYPE' ) && count_user_posts( $u->ID, ATBDP_POST_TYPE ) > 0 ) {
				continue;
			}
			wp_delete_user( $u->ID );
		}
	}

	/** The administrator refused a producer: the entry is removed (the questionnaires are removed by Solidaros) and the person goes back to a plain account. */
	public function registration_refused( $uid, $role ) {
		$uid = (int) $uid;
		if ( ! in_array( $role, array( 'producer', 'gas', 'network', 'distributor' ), true ) || ! $uid || user_can( $uid, 'edit_others_posts' ) || ! defined( 'ATBDP_POST_TYPE' ) ) {
			return;
		}
		Owners::$importing = true;
		foreach ( get_posts( array( 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'any', 'author' => $uid, 'fields' => 'ids', 'numberposts' => 20 ) ) as $pid ) {
			wp_delete_post( $pid, true );
		}
		Owners::$importing = false;
		foreach ( array( 'dirnet_entity_type', 'dirnet_entity_name', 'dirnet_entity_vat', 'dirnet_reg_type', 'sm_entity_id', self::PRODUCER ) as $m ) {
			delete_user_meta( $uid, $m );
		}
		update_user_meta( $uid, self::KIND, 'natural' );
	}

	/** On the add-listing form of a new entry: everything already known from the registration is filled in (empty fields only). */
	public function prefill_entry() {
		if ( ! is_user_logged_in() || ! is_singular() || isset( $_GET['listing_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( ! has_shortcode( (string) get_post_field( 'post_content', get_queried_object_id() ), 'directorist_add_listing' ) ) {
			return;
		}
		$u   = wp_get_current_user();
		$m   = function ( $k ) use ( $u ) {
			return (string) get_user_meta( $u->ID, $k, true );
		};
		$full = trim( $u->first_name . ' ' . $u->last_name );
		$data = array(
			'title'   => $m( 'dirnet_entity_name' ),
			'email'   => (string) $u->user_email,
			'phone'   => $m( 'sm_phone' ),
			'phone2'  => $m( 'sm_phone2' ),
			'email2'  => $m( 'sm_email2' ),
			'city'    => $m( 'sm_address_city' ),
			'name'    => $full,
			'tax'     => $m( 'sm_company_tax_id' ),
			'vat'     => $m( 'dirnet_entity_vat' ),
			'network' => $m( 'sm_network_affiliation' ),
		);
		if ( '' !== $data['vat'] ) {
			$data['tax'] = $data['vat']; // the entity's own code wins over the person's
		}
		echo '<script>document.addEventListener("DOMContentLoaded",function(){var d=' . wp_json_encode( $data ) . ',map=[["listing_title","title"],["^(email|custom-.*e?mail.*)$","email"],["^(phone|phone2|custom-.*(phone|tel|cell).*)$","phone"],["^(address|custom-.*(city|comune|town).*)$","city"],["custom-.*(referent|referente|contact|contatto|responsabile|owner|nome_cognome|name).*","name"],["custom-.*(vat|piva|p_iva|partita|tax|fiscale|cf).*","tax"],["custom-.*(network|rete|gas_|affiliation|appartenenza).*","network"]];document.querySelectorAll("input[name],textarea[name]").forEach(function(i){if(i.value||i.type==="hidden"||i.type==="checkbox"||i.type==="radio")return;map.forEach(function(r){if(i.value)return;if(new RegExp(r[0],"i").test(i.name)&&d[r[1]]){i.value=d[r[1]];i.dispatchEvent(new Event("input",{bubbles:true}))}})})});</script>';
	}

	/** Directorist's own login/registration pages lead to the DN access page: one door only. */
	public function redirect_login() {
		if ( is_user_logged_in() || ! is_singular( 'page' ) || is_admin() ) {
			return;
		}
		$to = (int) get_option( 'dirnet_enter_page' );
		$id = get_queried_object_id();
		if ( ! $to || $to === $id || ! get_post( $to ) ) {
			return;
		}
		$ours = array();
		if ( function_exists( 'get_directorist_option' ) ) {
			foreach ( array( 'user_login', 'custom_registration' ) as $o ) {
				$ours[] = (int) get_directorist_option( $o );
			}
		}
		$c = (string) get_post_field( 'post_content', $id );
		if ( in_array( $id, array_filter( $ours ), true ) || has_shortcode( $c, 'directorist_signin_signup' ) || has_shortcode( $c, 'directorist_user_login' ) || has_shortcode( $c, 'directorist_custom_registration' ) ) {
			wp_safe_redirect( (string) get_permalink( $to ) );
			exit;
		}
	}

	/**
	 * No "Submit an entry" link anywhere (menus, dashboard header): the entry is created with the registration and edited from
	 * "My entry". Only links are hidden, never the save button of the form. Links with listing_id (edit) and the ones inside
	 * "My entry" stay.
	 */
	public function hide_submit_button() {
		$add  = self::add_url();
		$path = (string) wp_parse_url( $add, PHP_URL_PATH );
		$css  = '';
		if ( strlen( trim( $path, '/' ) ) >= 2 ) { // never hide links to the home page
			$p   = esc_attr( $path );
			$css = 'a[href*="' . $p . '"]:not([href*="listing_id"]):not([href*="edit"]):not(.dirnet-membership a),li:has(> a[href*="' . $p . '"]:not([href*="listing_id"])):not(.dirnet-membership li){display:none!important}';
		}
		// One weight for every title of the dashboard: page titles, sub-titles, bands, tiles.
		$css .= '.directorist-user-dashboard .dirnet-questionnaires .sm-sb-box{max-width:none;margin:0 0 12px;padding:0;border:0;background:transparent;border-radius:0}';
		$css .= '.directorist-user-dashboard .dirnet-membership :is(h1,h2,h3,h4,h5,strong,b,label),.directorist-user-dashboard .dirnet-questionnaires :is(h1,h2,h3,h4,h5,strong,b,label),.directorist-user-dashboard .dirnet-band strong,.directorist-user-dashboard .dirnet-tile h3,.directorist-user-dashboard .sm-sb-band strong,.directorist-user-dashboard .sm-sb-box :is(h2,h3,h4,strong),.directorist-user-dashboard .directorist-card__header__title{font-weight:500!important}.directorist-user-dashboard .dirnet-membership :is(h3,h4),.directorist-user-dashboard .dirnet-questionnaires :is(h3,h4){font-size:1.1em}';
		echo '<style>' . $css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput
		$labels = wp_json_encode( array( __( 'Submit an entry', 'directorist-network' ), 'Submit Listing', 'Invia scheda', 'Invia Scheda' ) );
		echo '<script>document.addEventListener("DOMContentLoaded",function(){var L=' . $labels . ';function h(){document.querySelectorAll("a").forEach(function(e){if(e.closest(".dirnet-membership,form"))return;var t=(e.textContent||"").trim();if(L.indexOf(t)>-1){var li=e.closest("li");(li&&li.children.length===1?li:e).style.display="none"}})}h();setTimeout(h,800)});</script>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** Labels of the Directorist buttons next to the menu: Submit Listing -> "Submit an entry", Sign Out -> "Sign out". */
	public function button_labels( $translation, $text, $domain ) {
		if ( 'directorist' === $domain ) {
			if ( 'Submit Listing' === $text ) {
				return __( 'Submit an entry', 'directorist-network' );
			}
			if ( 'Sign Out' === $text ) {
				return __( 'Sign out', 'directorist-network' );
			}
		}
		return $translation;
	}

	/** Deactivation: the dashboard page goes back to the ordinary Directorist one. */
	public static function restore() {
		$d = function_exists( 'get_directorist_option' ) ? (int) get_directorist_option( 'user_dashboard' ) : 0;
		if ( $d && get_post( $d ) ) {
			$c = (string) get_post_field( 'post_content', $d );
			$n = trim( str_replace( array( '[dirnet_my_space]', '[dirnet_dashboard]' ), '', $c ) );
			if ( $n !== trim( $c ) ) {
				wp_update_post( array( 'ID' => $d, 'post_content' => '' !== $n ? $n : '[directorist_user_dashboard]' ) );
			}
		}
		delete_option( 'dirnet_myspace_page' );
		delete_option( 'dirnet_create_page' );
	}

	public function pages() {
		$this->enter_page();
		$this->dashboard();
		if ( get_option( 'dirnet_member_pages_v2' ) ) {
			return;
		}
		update_option( 'dirnet_member_pages_v2', 1 );
		foreach ( array(
			'dirnet_evaluator_page' => array( __( 'Reviewer registration', 'directorist-network' ), '[dirnet_registration_evaluator]' ),
		) as $opt => $def ) {
			$id = (int) get_option( $opt );
			if ( $id && get_post( $id ) && 'trash' !== get_post_status( $id ) ) {
				continue;
			}
		}
		// The existing registration page now carries the new form.
		$reg = (int) get_option( Register::PAGE_OPTION );
		if ( $reg && get_post( $reg ) ) {
			wp_update_post( array( 'ID' => $reg, 'post_content' => '[dirnet_access]' ) );
		}
	}

	/** Directorist's own "add listing" page: it asks which directory type, then shows that type's complete form. */
	/** Above the add-listing form: look for your entry first (an existing one is joined by the administrator). */
	public function add_hint() {
		if ( isset( $_GET['listing_id'] ) || ! is_user_logged_in() ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$s = home_url( '/?s=&post_type=' . ( defined( 'ATBDP_POST_TYPE' ) ? ATBDP_POST_TYPE : 'at_biz_dir' ) );
		echo '<p class="dirnet-hint">' . sprintf( /* translators: %s: search link */ wp_kses_post( __( 'Is your organisation already listed? <a href="%s">Search for it</a> before creating a new entry.', 'directorist-network' ) ), esc_url( $s ) ) . '</p>';
	}

	private static function add_url() {
		return class_exists( '\ATBDP_Permalink' ) ? (string) \ATBDP_Permalink::get_add_listing_page_link() : home_url( '/' );
	}

	/** Administrators and editors: the only ones who use WordPress' own registration, login destination and back office. */
	private static function wp_staff( $user = null ) {
		$user = $user instanceof \WP_User ? $user : wp_get_current_user();
		return $user && $user->exists() && $user->has_cap( 'edit_others_posts' );
	}

	/** wp-login.php?action=register: closed, registration is on the site. */
	public function wp_register_closed() {
		$act = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'register' === $act ) {
			wp_safe_redirect( self::url( 'dirnet_enter_page' ) );
			exit;
		}
	}

	public function register_url( $url ) {
		return (int) get_option( 'dirnet_enter_page' ) ? self::url( 'dirnet_enter_page' ) : $url;
	}

	/** After signing in at wp-login.php anyone but administrators and editors lands in the dashboard. */
	public function wp_login_redirect( $to, $requested, $user ) {
		if ( $user instanceof \WP_User && ! self::wp_staff( $user ) && (int) get_option( 'dirnet_myspace_page' ) ) {
			return self::url( 'dirnet_myspace_page' );
		}
		return $to;
	}

	/** wp-admin is closed to everyone but administrators and editors (admin-ajax and admin-post stay open: the site uses them). */
	public function keep_out_of_admin() {
		if ( ! is_user_logged_in() || wp_doing_ajax() || self::wp_staff() || ! (int) get_option( 'dirnet_myspace_page' ) ) {
			return;
		}
		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( (string) $_SERVER['SCRIPT_NAME'] ) : '';
		if ( in_array( $script, array( 'admin-post.php', 'admin-ajax.php' ), true ) ) {
			return;
		}
		wp_safe_redirect( self::url( 'dirnet_myspace_page' ) );
		exit;
	}

	public function admin_bar_for_staff( $show ) {
		return self::wp_staff() ? $show : false;
	}

	private static function url( $opt ) {
		$id = (int) get_option( $opt );
		if ( ! $id && 'dirnet_myspace_page' === $opt ) {
			$id = self::find_dashboard(); // not stored yet: look for the Directorist dashboard before falling back to the home page
		}
		return $id ? (string) get_permalink( $id ) : home_url( '/' );
	}

	/** The Directorist dashboard page ("Bacheca"): its setting, then its link helper, then the page that holds its shortcode. Stored when found. */
	private static function find_dashboard() {
		$d = function_exists( 'get_directorist_option' ) ? (int) get_directorist_option( 'user_dashboard' ) : 0;
		if ( ! $d && class_exists( '\\ATBDP_Permalink' ) && method_exists( '\\ATBDP_Permalink', 'get_dashboard_page_link' ) ) {
			$d = (int) url_to_postid( (string) \ATBDP_Permalink::get_dashboard_page_link() );
		}
		if ( ! $d ) {
			$d = (int) SystemPages::find( 'myspace' ); // the page that holds the dashboard shortcode
		}
		if ( $d && get_post( $d ) && 'trash' !== get_post_status( $d ) ) {
			update_option( 'dirnet_myspace_page', $d, false );
			return $d;
		}
		return 0;
	}

	/* ------------------------------------------------------------ sign-up */

	/** The evaluators' page is not public: no search engines (the editor can also protect it with a page password). */
	public function noindex( $r ) {
		$id = (int) get_option( 'dirnet_evaluator_page' );
		if ( $id && is_page( $id ) ) {
			$r['noindex']  = true;
			$r['nofollow'] = true;
		}
		return $r;
	}

	/** "Log out" button (back to the home page). */
	private static function logout_button() {
		return '<a class="button" href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">' . esc_html__( 'Log out', 'directorist-network' ) . '</a>';
	}

	/** The intermediate step of the single registration: who are you registering (producer, GAS, network)? Links to the matching add-listing form. */
	/** slug (producer|gas|network) => array( label, directory type slug ) for the types that exist. */
	public static function type_rows_public() {
		return self::type_rows();
	}

	

	private static function type_rows() {
		$rows = array();
		foreach ( Generator::applied() as $pid => $types ) {
			$profile = Profile::get( $pid );
			if ( ! $profile ) {
				continue;
			}
			$defs = $profile->types();
			foreach ( array( 'producer', 'gas', 'network', 'distributor' ) as $slug ) {
				if ( empty( $types[ $slug ] ) ) {
					continue;
				}
				$term = get_term( (int) $types[ $slug ], 'atbdp_listing_types' );
				if ( $term && ! is_wp_error( $term ) ) {
					$rows[ $slug ] = array( $profile->t( isset( $defs[ $slug ]['label'] ) ? $defs[ $slug ]['label'] : $slug ), $term->slug );
				}
			}
		}
		return $rows;
	}

	/** [dirnet_profile_form type="producer|gas|network"]: first name, last name and name of the entity; e-mail already known, user name and password assigned and not shown. */
	public function profile_form( $atts ) {
		$atts = shortcode_atts( array( 'type' => 'producer' ), $atts, 'dirnet_profile_form' );
		$k    = in_array( $atts['type'], array( 'producer', 'gas', 'network', 'distributor' ), true ) ? $atts['type'] : 'producer';
		if ( ! is_user_logged_in() ) {
			$login = Pages::id( 'login' ) ? get_permalink( Pages::id( 'login' ) ) : wp_login_url( get_permalink() );
			return '<p>' . esc_html__( 'First create your account or sign in.', 'directorist-network' ) . ' <a class="button" href="' . esc_url( $login ) . '">' . esc_html__( 'Sign in', 'directorist-network' ) . '</a></p>';
		}
		$u   = wp_get_current_user();
		$out = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:560px"><input type="hidden" name="action" value="dirnet_profile" /><input type="hidden" name="type" value="' . esc_attr( $k ) . '" />' . wp_nonce_field( 'dirnet_profile', '_wpnonce', true, false );
		$out .= '<p><label>' . esc_html__( 'First name', 'directorist-network' ) . ' *<br /><input type="text" name="first" required value="' . esc_attr( $u->first_name ) . '" style="width:100%" /></label></p>';
		$out .= '<p><label>' . esc_html__( 'Last name', 'directorist-network' ) . ' *<br /><input type="text" name="last" required value="' . esc_attr( $u->last_name ) . '" style="width:100%" /></label></p>';
		$label = array( 'producer' => __( 'Name of the producer', 'directorist-network' ), 'gas' => __( 'Name of the GAS', 'directorist-network' ), 'network' => __( 'Name of the network', 'directorist-network' ), 'distributor' => __( 'Name of the distributor', 'directorist-network' ) );
		$out .= '<p><label>' . esc_html( $label[ $k ] ) . ' *<br /><input type="text" name="entity" required value="' . esc_attr( (string) get_user_meta( $u->ID, 'dirnet_entity_name', true ) ) . '" style="width:100%" /></label></p>';
		$out .= '<p><label>' . esc_html__( 'E-mail', 'directorist-network' ) . '<br /><input type="email" value="' . esc_attr( $u->user_email ) . '" readonly style="width:100%" /></label></p>';
		// Same fields as the Solidaros reviewer form (user meta sm_<field>), so both plugins hold the same data.
		foreach ( array( 'address_city' => array( __( 'Town', 'directorist-network' ), true ), 'phone' => array( __( 'Phone', 'directorist-network' ), true ), 'phone2' => array( __( 'Second phone', 'directorist-network' ), false ), 'email2' => array( __( 'Second e-mail', 'directorist-network' ), false ), 'network_affiliation' => array( __( 'GAS / network you belong to', 'directorist-network' ), false ) ) as $f => $d ) {
			$out .= '<p><label>' . esc_html( $d[0] ) . ( $d[1] ? ' *' : '' ) . '<br /><input type="text" name="' . esc_attr( $f ) . '"' . ( $d[1] ? ' required' : '' ) . ' value="' . esc_attr( (string) get_user_meta( $u->ID, 'sm_' . $f, true ) ) . '" style="width:100%" /></label></p>';
		}
		$out .= '<p><button type="submit" class="button button-primary">' . esc_html__( 'Continue', 'directorist-network' ) . '</button></p></form>';
		return $out;
	}

	/** Url of a Solidaros page, or '' when it is not set (never the home page: a button would lead nowhere). */
	public static function sol_page( $key ) {
		$own = in_array( $key, array( 'wizard', 'evalwizard', 'evaluator' ), true ) ? Pages::id( $key ) : 0;
		if ( $own && 'publish' === get_post_status( $own ) ) {
			return (string) get_permalink( $own );
		}
		if ( ! ( SolidarosBridge::active() && class_exists( '\\Solidaros\\Core\\Pages' ) ) ) {
			return '';
		}
		$u = (string) \Solidaros\Core\Pages::url( $key );
		return ( '' === $u || untrailingslashit( $u ) === untrailingslashit( home_url( '/' ) ) ) ? '' : $u;
	}

	public function profile_save() {
		if ( ! is_user_logged_in() || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'dirnet_profile' ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
		$k      = isset( $_POST['type'] ) && in_array( $_POST['type'], array( 'producer', 'gas', 'network', 'distributor' ), true ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'producer';
		$first  = isset( $_POST['first'] ) ? sanitize_text_field( wp_unslash( $_POST['first'] ) ) : '';
		$last   = isset( $_POST['last'] ) ? sanitize_text_field( wp_unslash( $_POST['last'] ) ) : '';
		$entity = isset( $_POST['entity'] ) ? sanitize_text_field( wp_unslash( $_POST['entity'] ) ) : '';
		$uid    = get_current_user_id();
		$u      = wp_get_current_user();
		$first  = '' !== $first ? $first : (string) $u->first_name; // the personal data are given once, in "My entry"
		$last   = '' !== $last ? $last : (string) $u->last_name;
		if ( '' === $entity ) {
			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : home_url( '/' ) );
			exit;
		}
		if ( '' !== $first && '' !== $last ) {
			wp_update_user( array( 'ID' => $uid, 'first_name' => $first, 'last_name' => $last, 'display_name' => trim( $first . ' ' . $last ) ) );
			update_user_meta( $uid, self::NAME, trim( $first . ' ' . $last ) );
		}
		update_user_meta( $uid, 'dirnet_entity_name', $entity );
		update_user_meta( $uid, 'dirnet_entity_type', $k );
		$fc = self::farm_choice( $k, isset( $_POST['farm'] ) ? sanitize_text_field( wp_unslash( $_POST['farm'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' !== $fc ) {
			update_user_meta( $uid, 'dirnet_farm', $fc );
		} else {
			delete_user_meta( $uid, 'dirnet_farm' );
		}
		update_user_meta( $uid, self::KIND, 'legal' );
		if ( 'producer' === $k ) {
			update_user_meta( $uid, self::PRODUCER, 1 );
		} else {
			delete_user_meta( $uid, self::PRODUCER );
		}
		$next = Pages::id( 'entity_next' );
		wp_safe_redirect( $next ? (string) get_permalink( $next ) : self::add_url() );
		exit;
	}

	/** Personal data complete? (the same fields the reviewer form of Solidaros reads). */
	private static function person_complete( $uid ) {
		$u = get_userdata( $uid );
		return $u && '' !== (string) $u->first_name && '' !== (string) $u->last_name && '' !== (string) get_user_meta( $uid, 'sm_company_tax_id', true ) && '' !== (string) get_user_meta( $uid, 'sm_address_city', true ) && '' !== (string) get_user_meta( $uid, 'sm_phone', true );
	}

	/** Step 2 of the registration, inside "My entry": who the person is. */
	private function person_form() {
		$u   = wp_get_current_user();
		$out = '<p>' . esc_html__( 'The data given at registration: you can change them here. They are used for your entry, the questionnaires and the PGS evaluation.', 'directorist-network' ) . '</p>';
		$out .= '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:560px"><input type="hidden" name="action" value="dirnet_person" />' . wp_nonce_field( 'dirnet_person', '_wpnonce', true, false );
		if ( self::sol_data() ) { // the same data list as Solidaros: entity + holder/referent, or the reviewer data
			$role = 'legal' === (string) get_user_meta( $u->ID, self::KIND, true ) ? 'producer' : 'evaluator';
			$vals = (array) apply_filters( 'solidaros_registration_values', array(), $u->ID, $role );
			$out .= '<input type="hidden" name="sol_role" value="' . esc_attr( $role ) . '" />' . (string) apply_filters( 'solidaros_registration_form', '', $role, $vals, 'sol[' . $role . ']' );
		} else {
		$fields = array(
				'first' => array( __( 'First name', 'directorist-network' ), true, $u->first_name ),
				'last'  => array( __( 'Last name', 'directorist-network' ), true, $u->last_name ),
				'tax'   => array( __( 'Tax code', 'directorist-network' ), true, (string) get_user_meta( $u->ID, 'sm_company_tax_id', true ) ),
				'address_city' => array( __( 'Town', 'directorist-network' ), true, (string) get_user_meta( $u->ID, 'sm_address_city', true ) ),
				'phone' => array( __( 'Phone', 'directorist-network' ), true, (string) get_user_meta( $u->ID, 'sm_phone', true ) ),
				'phone2' => array( __( 'Second phone', 'directorist-network' ), false, (string) get_user_meta( $u->ID, 'sm_phone2', true ) ),
				'email2' => array( __( 'Second e-mail', 'directorist-network' ), false, (string) get_user_meta( $u->ID, 'sm_email2', true ) ),
				'network_affiliation' => array( __( 'GAS / network you belong to', 'directorist-network' ), false, (string) get_user_meta( $u->ID, 'sm_network_affiliation', true ) ),
			);
			if ( '' !== (string) get_user_meta( $u->ID, 'sm_evaluation_territory', true ) ) {
				$fields['evaluation_territory'] = array( __( 'Evaluation territory (provinces)', 'directorist-network' ), true, (string) get_user_meta( $u->ID, 'sm_evaluation_territory', true ) );
			}
			foreach ( $fields as $name => $d ) {
				if ( 'evaluation_territory' === $name ) {
					$out .= '<p><strong>' . esc_html( $d[0] ) . ' *</strong></p>' . self::territory_field( $d[2] );
					continue;
				}
				$out .= '<p><label>' . esc_html( $d[0] ) . ( $d[1] ? ' *' : '' ) . '<br /><input type="text" name="' . esc_attr( $name ) . '"' . ( $d[1] ? ' required' : '' ) . ' value="' . esc_attr( $d[2] ) . '" style="width:100%" /></label></p>';
			}
		}
		$out .= '<p><label>' . esc_html__( 'E-mail', 'directorist-network' ) . ' *<br /><input type="email" name="email" required value="' . esc_attr( $u->user_email ) . '" style="width:100%" autocomplete="email" /></label></p>';
		$out .= '<p><label>' . esc_html__( 'New password', 'directorist-network' ) . ' <small>(' . esc_html__( 'leave empty to keep the current one', 'directorist-network' ) . ')</small><br /><input type="password" name="pass" minlength="8" autocomplete="new-password" style="width:100%" /></label></p>';
		if ( isset( $_GET['dirnet_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$out = '<p role="status"><strong>' . esc_html__( 'Saved.', 'directorist-network' ) . '</strong></p>' . $out;
		}
		$box = '<div class="dirnet-box" style="background:#fff;border:1px solid #d9d9d9;border-radius:4px;padding:16px">' . $out . '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save', 'directorist-network' ) . '</button></p></form></div>';
		$sectors = ( self::sol_data() && 'legal' === (string) get_user_meta( get_current_user_id(), self::KIND, true ) ) ? (string) apply_filters( 'solidaros_profile_sectors', '' ) : ''; // business sectors (Solidaros), a separate form
		return $box . $sectors;
	}

	/** The entry is created and published with the registration (the entity can edit it at once), filled with the registration data: the entity only edits it. */
	/**
	 * The entry of a legal person who registers, at once and without a second step:
	 * 1. an entry with the same tax code that nobody real holds is assigned to the new user;
	 * 2. an entry held by another user, or only similar by name: the registrant is asked to choose (never a duplicate, never silent);
	 * 3. no entry in the directory: a new one is created and filled with the registration data.
	 *
	 * @return string assigned | choose | created | none
	 */
	private function entry_for( $uid ) {
		$type = (string) get_user_meta( $uid, 'dirnet_entity_type', true );
		if ( '' === $type || 'evaluator' === $type || '' === (string) get_user_meta( $uid, 'dirnet_entity_name', true ) || ! defined( 'ATBDP_POST_TYPE' ) ) {
			return 'none'; // a natural person, or a reviewer: no entry
		}
		if ( get_posts( array( 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'any', 'author' => $uid, 'fields' => 'ids', 'numberposts' => 1 ) ) ) {
			return 'assigned'; // already has one
		}
		$status = $this->match_entries( $uid );
		if ( 'none' !== $status ) {
			return $status;
		}
		return $this->create_entry( $uid ) ? 'created' : 'none';
	}

	private function create_entry( $uid, $force = false ) {
		if ( ! defined( 'ATBDP_POST_TYPE' ) || ! defined( 'ATBDP_DIRECTORY_TYPE' ) ) {
			return 0;
		}
		$type = (string) get_user_meta( $uid, 'dirnet_entity_type', true );
		$name = (string) get_user_meta( $uid, 'dirnet_entity_name', true );
		$rows = self::type_rows();
		if ( '' === $type || '' === $name || ! isset( $rows[ $type ] ) || get_posts( array( 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'any', 'author' => $uid, 'fields' => 'ids', 'numberposts' => 1 ) ) ) {
			return 0;
		}
		$exists = get_posts( array( 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'any', 'title' => $name, 'fields' => 'ids', 'numberposts' => 1 ) );
		if ( $exists && ! $force ) {
			return 0; // already in the directory: the entity asks for it to be assigned, no duplicate
		}
		$term = get_term_by( 'slug', $rows[ $type ][1], ATBDP_DIRECTORY_TYPE );
		if ( ! $term ) {
			return 0;
		}
		Owners::$importing = true;
		$id = wp_insert_post( array( 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'publish', 'post_title' => $name, 'post_author' => $uid ), true );
		Owners::$importing = false;
		if ( is_wp_error( $id ) ) {
			return 0;
		}
		update_post_meta( $id, '_directory_type', (int) $term->term_id );
		wp_set_object_terms( $id, (int) $term->term_id, ATBDP_DIRECTORY_TYPE );
		if ( function_exists( 'calc_listing_expiry_date' ) ) {
			update_post_meta( $id, '_expiry_date', calc_listing_expiry_date( '', '', (int) $term->term_id ) );
		}
		update_post_meta( $id, '_featured', 0 );
		update_post_meta( $id, '_listing_status', 'post_status' );
		$u = get_userdata( $uid );
		update_post_meta( $id, '_email', $u ? (string) $u->user_email : '' );
		update_post_meta( $id, '_phone', (string) get_user_meta( $uid, 'sm_phone', true ) );
		update_post_meta( $id, '_custom-phone2', (string) get_user_meta( $uid, 'sm_phone2', true ) );
		update_post_meta( $id, '_custom-email2', (string) get_user_meta( $uid, 'sm_email2', true ) );
		update_post_meta( $id, '_address', (string) get_user_meta( $uid, 'sm_address_city', true ) );
		if ( self::sol_data() ) {
			self::fill_entry( $id, $uid );
		}
		$vat = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) get_user_meta( $uid, 'dirnet_entity_vat', true ) ) );
		if ( '' !== $vat && '' === (string) get_post_meta( $id, '_tax_code', true ) ) {
			update_post_meta( $id, '_tax_code', $vat ); // the key the importer and the Solidaros bridge read
		}
		update_user_meta( $uid, 'sm_entity_id', (string) $id );
		SubjectLink::register( $id ); // the unique code of the entity, given by Solidaros and kept in the entry
		do_action( 'solidaros_grant_role_provisional', (int) $uid, $type ); // works at once; the administrator confirms or refuses (refusal removes the entry and, for a producer, the questionnaires)
		return (int) $id;
	}

	/**
	 * Looks for the entries that may be the entity of a legal person who registers (tax code, name, e-mail, phone, town: each adds to a score).
	 * One entry clearly ahead (same tax code, or same name) that nobody real holds: assigned at once. Any other case is an
	 * ambiguity and the registrant chooses (see choose()), and no duplicate is made silently.
	 *
	 * @return string assigned | choose | none
	 */
	private function match_entries( $uid ) {
		if ( ! defined( 'ATBDP_POST_TYPE' ) ) {
			return 'none';
		}
		$vat  = (string) get_user_meta( $uid, 'dirnet_entity_vat', true ); // the entity's VAT number, when asked; otherwise the tax code stored for the entity
		$tax  = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', '' !== $vat ? $vat : (string) get_user_meta( $uid, 'sm_company_tax_id', true ) ) );
		$name = (string) get_user_meta( $uid, 'dirnet_entity_name', true );
		$u     = get_userdata( $uid );
		$mail  = $u ? strtolower( (string) $u->user_email ) : '';
		$phone = preg_replace( '/\D/', '', (string) get_user_meta( $uid, 'sm_phone', true ) );
		$city  = strtolower( trim( (string) get_user_meta( $uid, 'sm_address_city', true ) ) );
		$norm  = static function ( $t ) { // name without punctuation, accents and legal forms
			$t = strtolower( remove_accents( (string) $t ) );
			$t = preg_replace( '/\b(aps|odv|ets|ssd|asd|srl|s\.r\.l|snc|sas|spa|soc|societa|cooperativa|coop|agricola|associazione|ass|az|azienda|di|del|della|il|la|e|the)\b/', ' ', $t );
			return trim( preg_replace( '/[^a-z0-9]+/', ' ', $t ) );
		};
		$nn   = $norm( $name );
		$cand = array(); // the same search of "Entry assignment": name first, then every other field; tax code, name and e-mail
		foreach ( array_filter( array( $tax, $name, $nn, $mail ), function ( $t ) {
			return strlen( (string) $t ) >= 3;
		} ) as $t ) {
			foreach ( self::find_entries( $t, $uid, true ) as $post ) {
				$cand[] = (int) $post->ID;
			}
		}
		$all = array_values( array_unique( array_map( 'intval', $cand ) ) );
		if ( ! $all ) {
			return 'none';
		}
		$score = array();
		foreach ( $all as $pid ) {
			$p  = 0;
			$tt = $norm( get_the_title( $pid ) );
			if ( strlen( $tax ) >= 8 && strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) get_post_meta( $pid, '_tax_code', true ) ) ) === $tax ) {
				$p += 100;
			}
			if ( '' !== $nn && $tt === $nn ) {
				$p += 70;
			} elseif ( '' !== $nn && '' !== $tt ) {
				similar_text( $nn, $tt, $pc );
				$p += $pc >= 85 ? 40 : 0;
			}
			if ( '' !== $mail && ( strtolower( (string) get_post_meta( $pid, '_email', true ) ) === $mail || strtolower( (string) get_post_meta( $pid, '_custom-email2', true ) ) === $mail ) ) {
				$p += 30;
			}
			if ( strlen( $phone ) >= 6 && substr( preg_replace( '/\D/', '', (string) get_post_meta( $pid, '_phone', true ) ), -9 ) === substr( $phone, -9 ) ) {
				$p += 20;
			}
			if ( '' !== $city && false !== strpos( strtolower( (string) get_post_meta( $pid, '_address', true ) ), $city ) ) {
				$p += 10;
			}
			$score[ $pid ] = $p;
		}
		arsort( $score );
		$ids = array_keys( $score );
		$top = $ids[0];
		$two = isset( $ids[1] ) ? $score[ $ids[1] ] : 0;
		if ( $score[ $top ] >= 70 && $two < 70 && self::is_vacant( $top ) ) { // same tax code, or the same name (plus other data): assigned at once
			self::assign_entry( $top, $uid );
			self::apply_registration( $top, $uid ); // the registration data prevail on those already in the entry
			update_user_meta( $uid, 'sm_entity_id', (string) $top );
			return 'assigned';
		}
		$all = array_slice( $ids, 0, 5 );
		update_user_meta( $uid, '_dirnet_choose', $all ); // ambiguity: the registrant decides
		return 'choose';
	}

	/** Nobody real holds the entry: no author, staff, or a user created by an invitation who never signed in. */
	private static function is_vacant( $pid ) {
		$old = (int) get_post_field( 'post_author', $pid );
		return ! $old || ! get_userdata( $old ) || user_can( $old, 'edit_others_posts' ) || self::onboarding( $old );
	}

	/** The registration data prevail on those already in the entry (only the ones the registrant gave). */
	private static function apply_registration( $pid, $uid ) {
		$name = (string) get_user_meta( $uid, 'dirnet_entity_name', true );
		if ( '' !== $name && get_the_title( $pid ) !== $name ) {
			Owners::$importing = true;
			wp_update_post( array( 'ID' => $pid, 'post_title' => $name ) );
			Owners::$importing = false;
		}
		$u   = get_userdata( $uid );
		$vat = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) get_user_meta( $uid, 'dirnet_entity_vat', true ) ) );
		$map = array(
			'_email'         => $u ? (string) $u->user_email : '',
			'_phone'         => (string) get_user_meta( $uid, 'sm_phone', true ),
			'_custom-phone2' => (string) get_user_meta( $uid, 'sm_phone2', true ),
			'_custom-email2' => (string) get_user_meta( $uid, 'sm_email2', true ),
			'_address'       => (string) get_user_meta( $uid, 'sm_address_city', true ),
			'_tax_code'      => $vat,
		);
		$staff = $u && user_can( $u, 'manage_options' ); // an administrator who tests the registration must not replace the contact data of an imported entry
		foreach ( $map as $k => $v ) {
			if ( '' !== $v && ! ( $staff && in_array( $k, array( '_email', '_phone', '_custom-phone2', '_custom-email2' ), true ) && '' !== (string) get_post_meta( $pid, $k, true ) ) ) {
				update_post_meta( $pid, $k, $v );
			}
		}
		if ( self::sol_data() ) {
			self::fill_entry( $pid, $uid );
		}
	}

	/** The choice box: the entries that may be the registrant's, or "none of these". '' when there is nothing to choose. */
	private function choose_box( $uid ) {
		$ids = array_filter( array_map( 'intval', (array) get_user_meta( $uid, '_dirnet_choose', true ) ) );
		if ( ! $ids || ! defined( 'ATBDP_POST_TYPE' ) ) {
			return '';
		}
		$out  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="dirnet-choose"><h3>' . esc_html__( 'Is one of these your entity?', 'directorist-network' ) . '</h3>';
		$out .= '<p>' . esc_html__( 'These entries may be yours. Choose one: your registration data will replace the ones in the entry. If none is yours, a new entry is created.', 'directorist-network' ) . '</p>';
		$out .= '<input type="hidden" name="action" value="dirnet_choose" />' . wp_nonce_field( 'dirnet_choose', '_wpnonce', true, false );
		foreach ( $ids as $i => $pid ) {
			if ( ATBDP_POST_TYPE !== get_post_type( $pid ) ) {
				continue;
			}
			$city = (string) get_post_meta( $pid, '_address', true );
			$out .= '<p><label><input type="radio" name="entry" value="' . (int) $pid . '"' . ( 0 === $i ? ' checked' : '' ) . ' /> ' . esc_html( get_the_title( $pid ) . ( '' !== $city ? ' — ' . $city : '' ) ) . '</label></p>';
		}
		$out .= '<p><label><input type="radio" name="entry" value="0" /> ' . esc_html__( 'None of these: create a new entry', 'directorist-network' ) . '</label></p>';
		$out .= '<p><button type="submit" class="button">' . esc_html__( 'Confirm', 'directorist-network' ) . '</button></p></form>';
		return $out;
	}

	/** The registrant's choice. A free entry is assigned at once; one held by another user becomes a request for the administrators. */
	public function choose() {
		$uid = get_current_user_id();
		if ( ! $uid || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'dirnet_choose' ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
		$ids = array_filter( array_map( 'intval', (array) get_user_meta( $uid, '_dirnet_choose', true ) ) );
		$pid = isset( $_POST['entry'] ) ? (int) $_POST['entry'] : 0;
		$to  = (string) get_permalink( (int) get_option( 'dirnet_myspace_page' ) );
		if ( '' === $to ) {
			$to = home_url( '/' );
		}
		if ( $pid && in_array( $pid, $ids, true ) && (int) get_post_field( 'post_author', $pid ) !== $uid ) {
			if ( self::is_vacant( $pid ) ) {
				self::assign_entry( $pid, $uid );
				self::apply_registration( $pid, $uid );
				update_user_meta( $uid, 'sm_entity_id', (string) $pid );
				update_user_meta( $uid, '_dirnet_match', 'assigned' );
			} else {
				update_post_meta( $pid, '_dirnet_claim_uid', $uid );
				update_post_meta( $pid, '_dirnet_claim_at', time() );
				update_user_meta( $uid, '_dirnet_match', 'claim' );
				$u = get_userdata( $uid );
				wp_mail(
					get_option( 'admin_email' ),
					sprintf( /* translators: %s: site name */ __( '[%s] Request to be assigned an entry', 'directorist-network' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
					sprintf( /* translators: 1: user, 2: e-mail, 3: entry */ __( '%1$s (%2$s) says that "%3$s" is theirs.', 'directorist-network' ), $u ? $u->display_name : '', $u ? $u->user_email : '', get_the_title( $pid ) ) . "\n\n" . admin_url( 'edit.php?post_type=at_biz_dir&page=dirnet-community' )
				);
				$to = add_query_arg( 'dirnet_claimed', 1, $to );
			}
		} elseif ( 0 === $pid ) {
			$this->create_entry( $uid, true ); // none is theirs: a new entry, filled with the registration data
			update_user_meta( $uid, '_dirnet_match', 'created' );
		}
		delete_user_meta( $uid, '_dirnet_choose' );
		wp_safe_redirect( $to );
		exit;
	}

	/** The personal data, saved once (registration, or the "My profile" form). $d: first,last,tax,address_city,phone,network_affiliation. */
	private static function save_person( $uid, array $d ) {
		$first = isset( $d['first'] ) ? sanitize_text_field( $d['first'] ) : '';
		$last  = isset( $d['last'] ) ? sanitize_text_field( $d['last'] ) : '';
		$tax   = isset( $d['tax'] ) ? strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', sanitize_text_field( $d['tax'] ) ) ) : '';
		if ( '' === $first || '' === $last || '' === $tax ) {
			return false;
		}
		$name = trim( $first . ' ' . $last );
		wp_update_user( array( 'ID' => $uid, 'first_name' => $first, 'last_name' => $last, 'display_name' => $name ) );
		update_user_meta( $uid, self::NAME, $name );
		update_user_meta( $uid, 'sm_evaluator_name', $name );
		update_user_meta( $uid, 'sm_evaluator_contact', isset( $d['phone'] ) ? sanitize_text_field( $d['phone'] ) : '' ); // the reviewer profile of Solidaros reads it
		update_user_meta( $uid, self::TAX, $tax );
		update_user_meta( $uid, 'sm_company_tax_id', $tax );
		foreach ( array( 'address_city', 'phone', 'phone2', 'email2', 'network_affiliation', 'evaluation_territory' ) as $f ) {
			update_user_meta( $uid, 'sm_' . $f, isset( $d[ $f ] ) ? sanitize_text_field( $d[ $f ] ) : '' );
		}
		if ( '' === (string) get_user_meta( $uid, self::KIND, true ) ) {
			update_user_meta( $uid, self::KIND, 'natural' ); // a person until an entity is chosen
		}
		$type = isset( $d['type'] ) ? sanitize_key( $d['type'] ) : '';
		if ( in_array( $type, array( 'producer', 'gas', 'network', 'distributor', 'evaluator' ), true ) ) { // chosen in the registration
			update_user_meta( $uid, 'dirnet_reg_type', $type );
			if ( 'evaluator' !== $type ) {
				update_user_meta( $uid, 'dirnet_entity_type', $type );
				update_user_meta( $uid, 'dirnet_entity_name', isset( $d['entity'] ) ? sanitize_text_field( $d['entity'] ) : '' );
				update_user_meta( $uid, self::KIND, 'legal' );
				$fc = self::farm_choice( $type, isset( $d['farm'] ) ? $d['farm'] : '' );
				if ( '' !== $fc ) {
					update_user_meta( $uid, 'dirnet_farm', $fc );
				} else {
					delete_user_meta( $uid, 'dirnet_farm' );
				}
				if ( ! empty( $d['vat'] ) ) {
					update_user_meta( $uid, 'dirnet_entity_vat', sanitize_text_field( $d['vat'] ) );
				}
				if ( 'producer' === $type ) {
					update_user_meta( $uid, self::PRODUCER, 1 );
				} else {
					delete_user_meta( $uid, self::PRODUCER );
				}
			}
		}
		return true;
	}

	/** The evaluation territory of a POST: a list of provinces (Solidaros guided list) or, without Solidaros, free text. */
	private static function territory_post() {
		$raw = isset( $_POST['evaluation_territory'] ) ? wp_unslash( $_POST['evaluation_territory'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
		if ( has_filter( 'solidaros_territory_value' ) ) {
			return (string) apply_filters( 'solidaros_territory_value', '', $raw );
		}
		return is_array( $raw ) ? implode( ', ', array_map( 'sanitize_text_field', $raw ) ) : sanitize_text_field( $raw );
	}

	/** The guided province list (from Solidaros) or a text field when Solidaros is not active. */
	private static function territory_field( $stored ) {
		$html = (string) apply_filters( 'solidaros_territory_picker', '', 'evaluation_territory', (string) $stored );
		return '' !== $html ? $html : '<input type="text" name="evaluation_territory" value="' . esc_attr( (string) $stored ) . '" style="width:100%" />';
	}

	/* ------------------------------------------------ shared registration data (Solidaros) */

	/** True when Solidaros provides the registration data list: the same fields on both plugins. */
	private static function sol_data() {
		return (bool) has_filter( 'solidaros_registration_form' );
	}

	private static function sol_role( $type ) {
		return 'evaluator' === $type ? 'evaluator' : 'producer';
	}

	/** The Solidaros data of a POST (field names sol[<role>][…]), checked by Solidaros; null if not valid. */
	private static function sol_collect( $role ) {
		$raw = isset( $_POST['sol'][ $role ] ) && is_array( $_POST['sol'][ $role ] ) ? wp_unslash( $_POST['sol'][ $role ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
		$r   = apply_filters( 'solidaros_registration_collect', null, $role, $raw );
		if ( ! is_array( $r ) || ! isset( $r[0] ) || ! is_array( $r[0] ) ) {
			return null;
		}
		if ( 'producer' === $role ) {
			$r[0]['photo'] = (string) apply_filters( 'solidaros_registration_photo', '', 'sol[producer]' ); // uploaded image (logo or photo)
		}
		return $r[0];
	}

	/** The DN person array (first, last, tax…) from the Solidaros data; null if the name is not "first last". */
	private static function sol_person( $role, array $data, $type = '' ) {
		$name  = trim( (string) ( 'evaluator' === $role ? ( isset( $data['evaluator_name'] ) ? $data['evaluator_name'] : '' ) : ( isset( $data['referent_name'] ) ? $data['referent_name'] : '' ) ) );
		$parts = preg_split( '/\s+/', $name );
		if ( count( $parts ) < 2 ) {
			return null;
		}
		$last = array_pop( $parts );
		$pd   = array(
			'first'                => implode( ' ', $parts ),
			'last'                 => $last,
			'tax'                  => (string) ( 'evaluator' === $role ? ( isset( $data['tax_id'] ) ? $data['tax_id'] : '' ) : ( isset( $data['referent_tax_id'] ) ? $data['referent_tax_id'] : '' ) ),
			'address_city'         => isset( $data['address_city'] ) ? (string) $data['address_city'] : '',
			'phone'                => isset( $data['phone'] ) ? (string) $data['phone'] : '',
			'network_affiliation'  => isset( $data['network_affiliation'] ) ? (string) $data['network_affiliation'] : '',
			'evaluation_territory' => isset( $data['evaluation_territory'] ) ? (string) $data['evaluation_territory'] : '',
			'type'                 => $type,
			'entity'               => isset( $data['company_name'] ) ? (string) $data['company_name'] : '',
			'vat'                  => 'evaluator' === $role ? '' : ( isset( $data['tax_id'] ) ? (string) $data['tax_id'] : '' ),
			'farm'                 => isset( $_POST['farm'] ) ? sanitize_text_field( wp_unslash( $_POST['farm'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			'sol'                  => array( 'role' => $role, 'data' => $data ),
		);
		return $pd;
	}

	/** Gives the Solidaros data to Solidaros (user meta sm_*): after save_person(), which it completes. */
	private static function sol_store( $uid, array $pd ) {
		if ( ! empty( $pd['sol']['role'] ) && ! empty( $pd['sol']['data'] ) ) {
			do_action( 'solidaros_registration_store', (int) $uid, (string) $pd['sol']['role'], (array) $pd['sol']['data'] );
		}
	}

	/** The entry is filled with the registration data: address, postcode, website, social networks, place (region > province > town). */
	public static function fill_entry( $id, $uid ) {
		$m = function ( $k ) use ( $uid ) {
			return (string) get_user_meta( $uid, 'sm_' . ( 'tax_id' === $k ? 'company_tax_id' : $k ), true );
		};
		// every Solidaros field is kept on the entry (post meta _sol_<field>): nothing of the registration is lost
		$defs = apply_filters( 'solidaros_registration_fields', array(), 'producer' );
		$map = array( 'tax_id' => 'tax_code', 'social_instagram' => 'instagram', 'social_facebook' => 'facebook' ); // fields that already exist in the builder
		foreach ( array_keys( (array) $defs ) as $k ) {
			if ( in_array( $k, array( 'company_name', 'address_street', 'address_province', 'address_city', 'address_zip', 'phone', 'phone2', 'email', 'email2', 'website', 'photo' ), true ) ) {
				continue; // these go to the native Directorist fields below
			}
			$fk = isset( $map[ $k ] ) ? $map[ $k ] : 'sol-' . str_replace( '_', '-', $k );
			update_post_meta( $id, '_custom-' . $fk, $m( $k ) ); // the builder field custom-<key> reads _custom-<key>
		}
		if ( '' !== $m( 'tax_id' ) ) {
			update_post_meta( $id, '_tax_code', $m( 'tax_id' ) ); // key used by the importer and the Solidaros bridge
		}
		if ( '' !== $m( 'company_name' ) && get_post_field( 'post_title', $id ) !== $m( 'company_name' ) ) {
			wp_update_post( array( 'ID' => $id, 'post_title' => $m( 'company_name' ) ) );
		}
		if ( '' !== $m( 'email' ) && ! ( user_can( $uid, 'manage_options' ) && '' !== (string) get_post_meta( $id, '_email', true ) ) ) {
			update_post_meta( $id, '_email', $m( 'email' ) );
		}
		if ( '' !== $m( 'phone' ) ) {
			update_post_meta( $id, '_phone', $m( 'phone' ) );
		}
		update_post_meta( $id, '_custom-phone2', $m( 'phone2' ) );
		update_post_meta( $id, '_custom-email2', $m( 'email2' ) );
		if ( '' === $m( 'address_street' ) ) {
			return;
		}
		if ( '' !== trim( $m( 'address_street' ) ) ) {
			update_post_meta( $id, '_address', trim( $m( 'address_street' ) . ', ' . $m( 'address_zip' ) . ' ' . $m( 'address_city' ) . ' (' . $m( 'address_province' ) . ')' ) );
		} else {
			delete_post_meta( $id, '_address' ); // no street: the address is built from municipality, province and region (Geo::fill_address)
		}
		update_post_meta( $id, '_zip', $m( 'address_zip' ) );
		if ( '' !== $m( 'website' ) ) {
			update_post_meta( $id, '_website', $m( 'website' ) );
		}
		$social = array();
		foreach ( array( 'facebook' => 'facebook', 'instagram' => 'instagram', 'x' => 'twitter', 'linkedin' => 'linkedin', 'youtube' => 'youtube', 'tiktok' => 'tiktok', 'telegram' => 'telegram' ) as $k => $dir ) {
			if ( '' !== $m( 'social_' . $k ) ) {
				$social[] = array( 'id' => $dir, 'url' => $m( 'social_' . $k ) );
			}
		}
		if ( $social ) {
			update_post_meta( $id, '_social', $social );
		}
		if ( '' !== $m( 'photo' ) ) {
			update_post_meta( $id, '_dirnet_photo', $m( 'photo' ) );
			$att = attachment_url_to_postid( $m( 'photo' ) );
			if ( $att && ! has_post_thumbnail( $id ) ) {
				set_post_thumbnail( $id, $att );
				update_post_meta( $id, '_listing_prv_img', $att );
			}
		}
		if ( '' !== $m( 'address_province' ) ) {
			Geo::apply_by_sigla( $id, $m( 'address_province' ), $m( 'address_city' ) );
		}
	}

	/** Solidaros data changed: the entry of that user is aligned (Solidaros is the source). */
	public static function sync_user( $uid ) {
		$id = (int) get_user_meta( $uid, 'sm_entity_id', true );
		if ( $id && defined( 'ATBDP_POST_TYPE' ) && ATBDP_POST_TYPE === get_post_type( $id ) ) {
			self::fill_entry( $id, $uid );
		}
	}

	/** Repair: aligns all entries whose owner has Solidaros data. Returns the number of entries. */
	public static function sync_all() {
		$n = 0;
		foreach ( get_users( array( 'meta_key' => 'sm_entity_id', 'fields' => 'ID', 'number' => -1 ) ) as $uid ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			self::sync_user( (int) $uid );
			++$n;
		}
		return $n;
	}

	/** Farm choice of a producer: '1' (Azienda agricola) | '0' (Altra azienda) | '' (not asked / not a producer). */
	private static function farm_choice( $type, $v ) {
		return 'producer' === $type && in_array( (string) $v, array( '0', '1' ), true ) ? (string) $v : '';
	}

	/** The farm select shown to producers: Bilancio sociale for all, the other questionnaires only for farms. */
	private static function farm_field( $current = '' ) {
		return '<p><label>' . esc_html__( 'Kind of business', 'directorist-network' ) . ' *<br /><select name="farm" style="width:100%"><option value="">' . esc_html__( '— choose —', 'directorist-network' ) . '</option><option value="1"' . selected( '1', $current, false ) . '>' . esc_html__( 'Farm (self-certification: all questionnaires)', 'directorist-network' ) . '</option><option value="0"' . selected( '0', $current, false ) . '>' . esc_html__( 'Other business (self-certification: social balance only)', 'directorist-network' ) . '</option></select></label></p>';
	}

	/** The person fields of a POST, unslashed. */
	private static function person_post() {
		$d = array();
		foreach ( array( 'first', 'last', 'tax', 'address_city', 'phone', 'phone2', 'email2', 'network_affiliation', 'evaluation_territory', 'type', 'entity', 'vat', 'farm' ) as $f ) {
			$d[ $f ] = 'evaluation_territory' === $f ? self::territory_post() : ( isset( $_POST[ $f ] ) ? sanitize_text_field( wp_unslash( $_POST[ $f ] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		return $d;
	}

	public function person_save() {
		if ( ! is_user_logged_in() || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'dirnet_person' ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
		$uid   = get_current_user_id();
		$pd = self::person_post();
		if ( self::sol_data() && ! empty( $_POST['sol_role'] ) ) { // the same data list as Solidaros
			$role = self::sol_role( sanitize_key( wp_unslash( $_POST['sol_role'] ) ) );
			$data = self::sol_collect( $role );
			$sp   = null === $data ? null : self::sol_person( $role, $data );
			if ( null === $sp ) {
				wp_safe_redirect( add_query_arg( 'dirnet_invalid', 1, self::url( 'dirnet_myspace_page' ) ) . '#dashboard_profile' );
				exit;
			}
			$pd = $sp;
		}
		if ( ! empty( $_POST['email'] ) ) {
			$em = sanitize_email( wp_unslash( $_POST['email'] ) );
			$cu = wp_get_current_user();
			if ( is_email( $em ) && strtolower( $em ) !== strtolower( $cu->user_email ) && ! email_exists( $em ) ) {
				wp_update_user( array( 'ID' => $uid, 'user_email' => $em ) );
			}
		}
		if ( ! empty( $_POST['pass'] ) && strlen( (string) wp_unslash( $_POST['pass'] ) ) >= 8 ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			wp_set_password( (string) wp_unslash( $_POST['pass'] ), $uid ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			wp_set_auth_cookie( $uid, true );
		}
		self::save_person( $uid, $pd );
		self::sol_store( $uid, $pd );
		if ( self::person_complete( $uid ) && get_user_meta( $uid, '_dirnet_pd_saved', true ) ) {
			wp_safe_redirect( add_query_arg( 'dirnet_saved', 1, self::url( 'dirnet_myspace_page' ) ) . '#dirnet_personal' );
			exit;
		}
		update_user_meta( $uid, '_dirnet_pd_saved', 1 );
		wp_safe_redirect( self::url( 'dirnet_myspace_page' ) . '#dashboard_profile' );
		exit;
	}

	/** Step 3, inside "My entry": what the person registers (type + name), then the creation or the claim of the entry. */
	private function start_box() {
		$rows = self::type_rows();
		if ( ! $rows ) {
			return $this->band( __( 'Create your entry', 'directorist-network' ), '', self::add_url(), __( 'Create the entry', 'directorist-network' ) );
		}
		$opts = '<option value="">' . esc_html__( '— choose —', 'directorist-network' ) . '</option>';
		foreach ( $rows as $slug => $r ) {
			$opts .= '<option value="' . esc_attr( $slug ) . '">' . esc_html( $r[0] ) . '</option>';
		}
		$out  = '<p>' . esc_html__( 'Choose what you register and its name: then you create the entry, or ask for the one already in the catalogue.', 'directorist-network' ) . '</p>';
		$out .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:560px"><input type="hidden" name="action" value="dirnet_profile" />' . wp_nonce_field( 'dirnet_profile', '_wpnonce', true, false );
		$out .= '<p><label>' . esc_html__( 'What do you want to register?', 'directorist-network' ) . ' *<br /><select name="type" required style="width:100%">' . $opts . '</select></label></p>';
		$out .= '<div class="dirnet-for-farm">' . self::farm_field( (string) get_user_meta( get_current_user_id(), 'dirnet_farm', true ) ) . '</div><script>(function(){var f=document.currentScript.closest("form"),t=f.querySelector("[name=type]"),w=f.querySelector(".dirnet-for-farm"),s=w.querySelector("select");function u(){var on=t.value==="producer";w.style.display=on?"":"none";s.disabled=!on;s.required=on}t.addEventListener("change",u);u()})();</script>';
		$out .= '<p><label>' . esc_html__( 'Name of the entity', 'directorist-network' ) . ' *<br /><input type="text" name="entity" required style="width:100%" value="' . esc_attr( (string) get_user_meta( get_current_user_id(), 'dirnet_entity_name', true ) ) . '" /></label></p>';
		return '<div class="dirnet-box" style="background:#fff;border:1px solid #d9d9d9;border-radius:4px;padding:16px">' . $out . '<p><button type="submit" class="button button-primary">' . esc_html__( 'Continue', 'directorist-network' ) . '</button></p></form></div>';
	}

	/** [dirnet_register_evaluator]: the reviewer process, apart from the registration of the entity. Same account (sign-in by e-mail), then first name, last name and tax code. */
	public function evaluator_form() {
		if ( ! is_user_logged_in() ) {
			$login = Pages::id( 'login' ) ? get_permalink( Pages::id( 'login' ) ) : wp_login_url( get_permalink() );
			return '<p>' . esc_html__( 'First create your account or sign in.', 'directorist-network' ) . ' <a class="button" href="' . esc_url( $login ) . '">' . esc_html__( 'Sign in', 'directorist-network' ) . '</a></p>';
		}
		if ( ! self::solidaros() ) {
			return '<p>' . esc_html__( 'Reviewer registration is not available yet.', 'directorist-network' ) . '</p>';
		}
		if ( self::can( 'evaluator' ) ) {
			return '<p>' . esc_html__( 'You are already a reviewer.', 'directorist-network' ) . '</p>';
		}
		$u = wp_get_current_user();
		if ( 'pending' === self::req_status( $u->ID, 'evaluator' ) || isset( $_GET['dirnet_asked'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return '<p role="status"><strong>' . esc_html__( 'Done: you can start now. An administrator will confirm it; you will receive an e-mail.', 'directorist-network' ) . '</strong></p>';
		}
		$out  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:560px"><input type="hidden" name="action" value="dirnet_evaluator" />' . wp_nonce_field( 'dirnet_evaluator', '_wpnonce', true, false );
		$out .= '<p><label>' . esc_html__( 'First name', 'directorist-network' ) . ' *<br /><input type="text" name="first" required value="' . esc_attr( $u->first_name ) . '" style="width:100%" /></label></p>';
		$out .= '<p><label>' . esc_html__( 'Last name', 'directorist-network' ) . ' *<br /><input type="text" name="last" required value="' . esc_attr( $u->last_name ) . '" style="width:100%" /></label></p>';
		$out .= '<p><label>' . esc_html__( 'Tax code', 'directorist-network' ) . ' *<br /><input type="text" name="tax" required maxlength="20" value="' . esc_attr( (string) get_user_meta( $u->ID, self::TAX, true ) ) . '" style="width:100%" /></label></p>';
		$out .= '<p><label>' . esc_html__( 'E-mail', 'directorist-network' ) . '<br /><input type="email" value="' . esc_attr( $u->user_email ) . '" readonly style="width:100%" /></label></p>';
		$out .= '<p><button type="submit" class="button button-primary">' . esc_html__( 'Request authorisation', 'directorist-network' ) . '</button></p></form>';
		return $out;
	}

	public function evaluator_save() {
		if ( ! is_user_logged_in() || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'dirnet_evaluator' ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
		$first = isset( $_POST['first'] ) ? sanitize_text_field( wp_unslash( $_POST['first'] ) ) : '';
		$last  = isset( $_POST['last'] ) ? sanitize_text_field( wp_unslash( $_POST['last'] ) ) : '';
		$tax   = isset( $_POST['tax'] ) ? strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', sanitize_text_field( wp_unslash( $_POST['tax'] ) ) ) ) : '';
		$uid   = get_current_user_id();
		$back  = wp_get_referer() ? remove_query_arg( 'dirnet_asked', wp_get_referer() ) : home_url( '/' );
		if ( '' === $first || '' === $last || '' === $tax || ! self::solidaros() ) {
			wp_safe_redirect( $back );
			exit;
		}
		wp_update_user( array( 'ID' => $uid, 'first_name' => $first, 'last_name' => $last ) );
		update_user_meta( $uid, self::TAX, $tax );
		update_user_meta( $uid, 'sm_evaluator_name', trim( $first . ' ' . $last ) );
		update_user_meta( $uid, 'sm_company_tax_id', $tax ); // the real tax code (Solidaros reads it as the "tax_id" field)
		foreach ( array( 'address_city', 'phone', 'phone2', 'email2', 'network_affiliation', 'evaluation_territory' ) as $f ) {
			update_user_meta( $uid, 'sm_' . $f, 'evaluation_territory' === $f ? self::territory_post() : ( isset( $_POST[ $f ] ) ? sanitize_text_field( wp_unslash( $_POST[ $f ] ) ) : '' ) );
		}
		$this->open_request( $uid, 'evaluator' );
		wp_safe_redirect( add_query_arg( 'dirnet_asked', 1, $back ) );
		exit;
	}

	/** One-hour, one-time sign-in link for this e-mail (same as the Access page sends). */
	public static function magic_link( $email, $ttl = HOUR_IN_SECONDS ) {
		$token = wp_generate_password( 32, false );
		set_transient( 'dirnet_magic_' . $token, array( 'email' => $email ), (int) $ttl );
		return add_query_arg( 'dirnet_login', $token, home_url( '/' ) );
	}

	/** URL of the "Register your entity" page (step 2), falling back to the Access page. */
	private static function start_url() {
		return Pages::id( 'entity_start' ) ? (string) get_permalink( Pages::id( 'entity_start' ) ) : self::url( 'dirnet_enter_page' );
	}

	/** [dirnet_entity_start]: step 2, apart from the account: assign an existing entry or register a new one. Sign-in required. */
	public function entity_start() {
		if ( ! is_user_logged_in() ) {
			$login = Pages::id( 'login' ) ? get_permalink( Pages::id( 'login' ) ) : wp_login_url( get_permalink() );
			return '<p>' . esc_html__( 'First create your account or sign in.', 'directorist-network' ) . ' <a class="button" href="' . esc_url( $login ) . '">' . esc_html__( 'Sign in', 'directorist-network' ) . '</a></p>';
		}
		$t = isset( $_GET['t'] ) ? sanitize_key( wp_unslash( $_GET['t'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'evaluator' === $t || isset( $_GET['dirnet_asked'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return '<p><a href="' . esc_url( remove_query_arg( array( 't', 'dirnet_asked' ) ) ) . '">&larr; ' . esc_html__( 'Change', 'directorist-network' ) . '</a></p>' . $this->evaluator_form();
		}
		if ( in_array( $t, array( 'producer', 'gas', 'network', 'distributor' ), true ) ) {
			return '<p><a href="' . esc_url( remove_query_arg( 't' ) ) . '">&larr; ' . esc_html__( 'Change', 'directorist-network' ) . '</a></p>' . $this->profile_form( array( 'type' => $t ) );
		}
		return self::step_entry();
	}

	/** Step 2 after the sign-in: register a new entity, or ask for the assignment of an entry that is already in the directory. */
	private static function step_entry() {
		return '<h3>' . esc_html__( 'Your account is ready. Now your entity:', 'directorist-network' ) . '</h3>' . self::choose_entity();
	}

	/** [dirnet_entity_next type=…]: step 3, after the details: create the entry, or ask for an existing one to be assigned. */
	public function entity_next() {
		if ( ! is_user_logged_in() ) {
			return $this->entity_start();
		}
		$k = (string) get_user_meta( get_current_user_id(), 'dirnet_entity_type', true );
		if ( ! in_array( $k, array( 'producer', 'gas', 'network', 'distributor' ), true ) ) {
			return '<p>' . esc_html__( 'First choose what you register.', 'directorist-network' ) . ' <a class="button" href="' . esc_url( self::start_url() ) . '">' . esc_html__( 'Registration', 'directorist-network' ) . '</a></p>';
		}
		if ( isset( $_GET['mode'] ) && 'create' === $_GET['mode'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return $this->entry_form( array( 'type' => $k ) );
		}
		$out  = '<ul style="list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px">';
		$out .= '<li><a class="button button-primary" style="display:block;padding:14px;text-align:center" href="' . esc_url( add_query_arg( 'mode', 'create', get_permalink() ) ) . '">' . esc_html__( 'Create the entry', 'directorist-network' ) . '</a></li>';
		$as   = Pages::id( 'entity_assign' );
		if ( $as ) {
			$out .= '<li><a class="button" style="display:block;padding:14px;text-align:center" href="' . esc_url( (string) get_permalink( $as ) ) . '">' . esc_html__( 'My entity is already in the catalogue', 'directorist-network' ) . '</a></li>';
		}
		return $out . '</ul>';
	}


	/**
	 * The one search of the entries of the catalogue, used by "Entry assignment" and by the registration:
	 * first the name (title: whole text and single words), then every other field (description, excerpt, custom fields),
	 * the tax code first of all. Direct queries: no search filter of the theme or Directorist can empty the result.
	 *
	 * @param string $term    Name, tax code or any word of the entry.
	 * @param int    $uid     Entries written by this user are left out.
	 * @param bool   $pending Also entries not yet published.
	 * @return \WP_Post[] Best matches first (at most 12).
	 */
	public static function find_entries( $term, $uid = 0, $pending = false ) {
		$term = trim( (string) $term );
		if ( strlen( $term ) < 3 || ! defined( 'ATBDP_POST_TYPE' ) ) {
			return array();
		}
		$st = $pending ? "'publish','pending','draft','private','future'" : "'publish'";
	$norm  = function ( $x ) {
		return trim( preg_replace( '/[^a-z0-9]+/', ' ', strtolower( remove_accents( (string) $x ) ) ) );
	};
	$words = array_slice( array_values( array_filter( explode( ' ', $norm( $term ) ), function ( $w ) {
		return strlen( $w ) >= 3 && ! in_array( $w, array( 'the', 'del', 'della', 'delle', 'dei', 'degli', 'societa', 'agricola', 'azienda', 'soc', 'coop', 'cooperativa', 'srl', 'snc', 'sas', 'associazione' ), true );
	} ) ), 0, 4 );
	// Direct queries (title / tax code): no search filter of the theme or of Directorist can empty the result.
	global $wpdb;
	$like = function ( $w ) use ( $wpdb ) {
		return '%' . $wpdb->esc_like( $w ) . '%';
	};
	$ids = array();
	foreach ( array_merge( array( array( $term, 10 ) ), array_map( function ( $w ) {
		return array( $w, 15 );
	}, $words ) ) as $pair ) {
		$ids = array_merge( $ids, $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND post_status IN ($st) AND post_title LIKE %s ORDER BY post_title LIMIT %d", ATBDP_POST_TYPE, $like( $pair[0] ), $pair[1] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
	// then every other field of the entry: description, excerpt and all the custom fields (address, tax code, contacts...)
	$ids = array_merge( $ids, $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id=p.ID WHERE p.post_type=%s AND p.post_status IN ($st) AND ( p.post_content LIKE %s OR p.post_excerpt LIKE %s OR m.meta_value LIKE %s ) LIMIT 40", ATBDP_POST_TYPE, $like( $term ), $like( $term ), $like( $term ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	$taxv   = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $term ) );
	$taxIds = strlen( $taxv ) >= 8 ? $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key='_tax_code' WHERE p.post_type=%s AND p.post_status IN ($st) AND UPPER(REPLACE(m.meta_value,' ',''))=%s LIMIT 10", ATBDP_POST_TYPE, $taxv ) ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL
	$found  = array_values( array_filter( array_map( 'get_post', array_unique( array_map( 'intval', $ids ) ) ) ) );
	$tax    = array_values( array_filter( array_map( 'get_post', array_map( 'intval', $taxIds ) ) ) );
	$score = array();
	$byid  = array();
	foreach ( array_merge( $tax, $found ) as $post ) {
		if ( isset( $byid[ $post->ID ] ) || (int) $post->post_author === (int) $uid ) {
			continue;
		}
		$pct = 0;
		similar_text( $norm( $term ), $norm( $post->post_title ), $pct );
		$hits = 0;
		foreach ( $words as $w ) {
			if ( false !== strpos( $norm( $post->post_title ), $w ) ) {
				$hits++;
			}
		}
		$byid[ $post->ID ]  = $post;
		$score[ $post->ID ] = $pct + 15 * $hits + ( in_array( (int) $post->ID, array_map( 'intval', $taxIds ), true ) ? 100 : 0 );
	}
	arsort( $score );
	$found = array();
	foreach ( array_slice( array_keys( $score ), 0, 12, true ) as $id ) {
		$found[] = $byid[ $id ];
	}
		return $found;
	}

	/** [dirnet_assignment]: entries with a name like the entity; "Request assignment" asks an administrator. */
	public function assignment() {
		if ( ! is_user_logged_in() ) {
			return $this->entity_start();
		}
		$out = '<h3>' . esc_html__( 'Is your entity already in the catalogue?', 'directorist-network' ) . '</h3>';
		if ( isset( $_GET['dirnet_claimed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$out .= '<p role="status"><strong>' . esc_html__( 'Request sent: an administrator will check it and assign the entry to you. You will receive an e-mail.', 'directorist-network' ) . '</strong></p>';
		}
		$q    = isset( $_GET['dirnet_find'] ) ? sanitize_text_field( wp_unslash( $_GET['dirnet_find'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$out .= '<form method="get" action="' . esc_url( get_permalink() ) . '" style="display:flex;gap:8px;max-width:560px"><input type="search" name="dirnet_find" value="' . esc_attr( $q ) . '" placeholder="' . esc_attr__( 'Name or tax code', 'directorist-network' ) . '" style="flex:1" /><button class="button">' . esc_html__( 'Search', 'directorist-network' ) . '</button></form>';
		$name = (string) get_user_meta( get_current_user_id(), 'dirnet_entity_name', true );
		$term = '' !== $q ? $q : $name; // without a search, the entries with a name like the one given at registration
		if ( strlen( $term ) >= 3 && defined( 'ATBDP_POST_TYPE' ) ) {
			$found = self::find_entries( $term, get_current_user_id() );
			$tax   = array();
			$seen  = array();
			$out  .= '<p>' . esc_html( '' !== $q ? __( 'Results:', 'directorist-network' ) : __( 'Entries with a name like yours. Choose yours:', 'directorist-network' ) ) . '</p>';
			$out  .= '<ul style="list-style:none;margin:12px 0;padding:0;max-width:560px">';
			foreach ( array_merge( $found, $tax ) as $post ) {
				if ( isset( $seen[ $post->ID ] ) ) {
					continue;
				}
				$seen[ $post->ID ] = 1;
				$out .= '<li style="display:flex;justify-content:space-between;gap:10px;align-items:center;padding:8px 0;border-bottom:1px solid #e5e5e5"><a href="' . esc_url( get_permalink( $post ) ) . '" target="_blank" rel="noopener">' . esc_html( get_the_title( $post ) ) . '</a>'
					. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dirnet_claim" /><input type="hidden" name="post" value="' . (int) $post->ID . '" />' . wp_nonce_field( 'dirnet_claim', '_wpnonce', true, false ) . '<button class="button">' . esc_html__( 'Request assignment', 'directorist-network' ) . '</button></form></li>';
			}
			$out .= '</ul>';
			if ( ! $seen ) {
				$out .= '<p>' . esc_html__( 'No entry found with this name.', 'directorist-network' ) . '</p>';
			}
		}
		$k  = (string) get_user_meta( get_current_user_id(), 'dirnet_entity_type', true );
		$nx = Pages::id( 'entity_next' );
		if ( $nx ) {
			$out .= '<p style="margin-top:20px">' . esc_html__( 'Not in the list?', 'directorist-network' ) . ' <a class="button" href="' . esc_url( add_query_arg( 'mode', 'create', (string) get_permalink( $nx ) ) ) . '">' . esc_html__( 'Create the entry', 'directorist-network' ) . '</a></p>';
		}
		return $out;
	}

	/** The signed-in user asks for the assignment of an existing entry: an administrator decides. */
	/** Entry to a new owner. The silent account of the import (never signed in, no other entries) is removed; any other account stays. */
	/** Directory type ids that count as "producer". */
	private static function producer_type_ids() {
		$tids = array();
		foreach ( Generator::applied() as $types ) {
			if ( isset( $types['producer'] ) ) {
				$tids[] = (int) $types['producer'];
			}
		}
		return array_map( 'intval', (array) apply_filters( 'dirnet_producer_type_ids', $tids ) );
	}

	private static function is_producer_entry( $pid ) {
		return in_array( (int) get_post_meta( $pid, '_directory_type', true ), self::producer_type_ids(), true );
	}

	/** One approval: whoever approves the entry (assignment accepted, new entry published) also authorises the producer role. */
	private static function grant_producer( $uid ) {
		if ( $uid && has_action( 'solidaros_grant_role' ) && ! user_can( (int) $uid, self::SUBMIT ) ) {
			do_action( 'solidaros_grant_role', (int) $uid, 'producer' );
		}
	}

	/** A new producer entry published by an administrator (or editor): its owner becomes a producer. */
	public function entry_published( $new, $old, $post ) {
		if ( $new !== 'publish' || $old === 'publish' || ! defined( 'ATBDP_POST_TYPE' ) || ATBDP_POST_TYPE !== $post->post_type || Owners::$importing ) {
			return;
		}
		$author = (int) $post->post_author;
		if ( ! $author || user_can( $author, 'edit_others_posts' ) || ! current_user_can( 'edit_others_posts' ) || (int) get_current_user_id() === $author ) {
			return; // staff-owned, or published by the owner himself: no approval happened
		}
		if ( self::is_producer_entry( $post->ID ) ) {
			self::grant_producer( $author );
		}
	}

	private static function assign_entry( $pid, $uid ) {
		$old = (int) get_post_field( 'post_author', $pid );
		wp_update_post( array( 'ID' => $pid, 'post_author' => $uid ) );
		$o = $old && $old !== $uid ? get_userdata( $old ) : false;
		if ( $o && 'pending' === get_user_meta( $old, '_dirnet_invite', true ) && array( 'subscriber' ) === array_values( (array) $o->roles ) && ! get_posts( array( 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'any', 'author' => $old, 'fields' => 'ids', 'numberposts' => 1 ) ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $old, $uid );
		}
	}

	/** Held by staff (administrator, editor) or by nobody: assigned at once. Held by another user: an administrator decides. */
	private static function is_free( $pid ) {
		$old = (int) get_post_field( 'post_author', $pid );
		if ( $old && get_userdata( $old ) && ! user_can( $old, 'edit_others_posts' ) ) {
			return false; // held by another user
		}
		$mail = strtolower( (string) get_post_meta( $pid, '_email', true ) );
		$me   = wp_get_current_user();
		return '' === $mail || strtolower( $me->user_email ) === $mail; // held by the administrators: at once if the entry has no e-mail or it is the user's; otherwise an administrator decides
	}

	public function claim() {
		if ( ! is_user_logged_in() || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'dirnet_claim' ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
		$pid = isset( $_POST['post'] ) ? (int) $_POST['post'] : 0;
		$uid = get_current_user_id();
		$to  = Pages::id( 'entity_assign' ) ? (string) get_permalink( Pages::id( 'entity_assign' ) ) : self::start_url();
		if ( $pid && defined( 'ATBDP_POST_TYPE' ) && ATBDP_POST_TYPE === get_post_type( $pid ) && (int) get_post_field( 'post_author', $pid ) !== $uid && self::is_free( $pid ) ) {
			self::assign_entry( $pid, $uid ); // held by the administrators: the user gets it at once, then edits it
			if ( self::is_producer_entry( $pid ) ) {
				$this->open_request( $uid, 'producer' ); // no administrator looked at it: the role is requested in the same step and approved in Solidaros
			}
			$u = get_userdata( $uid );
			wp_mail(
				get_option( 'admin_email' ),
				sprintf( /* translators: %s: site name */ __( '[%s] Entry assigned', 'directorist-network' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
				sprintf( /* translators: 1: user, 2: e-mail, 3: entry */ __( '%1$s (%2$s) chose "%3$s" as their entry: it is now assigned to them. To undo it, change the author in the entry.', 'directorist-network' ), $u->display_name, $u->user_email, get_the_title( $pid ) ) . "\n\n" . get_edit_post_link( $pid, 'raw' )
			);
			wp_safe_redirect( class_exists( '\\ATBDP_Permalink' ) ? (string) \ATBDP_Permalink::get_edit_listing_page_link( $pid ) : (string) get_permalink( $pid ) );
			exit;
		}
		if ( $pid && defined( 'ATBDP_POST_TYPE' ) && ATBDP_POST_TYPE === get_post_type( $pid ) && (int) get_post_field( 'post_author', $pid ) !== $uid ) {
			update_post_meta( $pid, '_dirnet_claim_uid', $uid );
			update_post_meta( $pid, '_dirnet_claim_at', time() );
			$u = get_userdata( $uid );
			wp_mail(
				get_option( 'admin_email' ),
				sprintf( /* translators: %s: site name */ __( '[%s] Request to be assigned an entry', 'directorist-network' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
				sprintf( /* translators: 1: user, 2: e-mail, 3: entry */ __( '%1$s (%2$s) says that "%3$s" is theirs.', 'directorist-network' ), $u->display_name, $u->user_email, get_the_title( $pid ) ) . "\n\n" . admin_url( 'edit.php?post_type=at_biz_dir&page=dirnet-community' )
			);
			$to = add_query_arg( 'dirnet_claimed', 1, $to );
		}
		wp_safe_redirect( $to );
		exit;
	}

	/** Pending claims: array of array( post id, user ). */
	private static function claims() {
		$out = array();
		if ( ! defined( 'ATBDP_POST_TYPE' ) ) {
			return $out;
		}
		foreach ( get_posts( array( 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'any', 'numberposts' => 100, 'meta_key' => '_dirnet_claim_uid', 'meta_compare' => 'EXISTS' ) ) as $post ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			$u = get_userdata( (int) get_post_meta( $post->ID, '_dirnet_claim_uid', true ) );
			if ( $u ) {
				$out[] = array( $post, $u );
			}
		}
		return $out;
	}

	public function claim_decide() {
		if ( ! current_user_can( 'edit_others_posts' ) || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'dirnet_claim_decide' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'directorist-network' ) );
		}
		$pid = isset( $_POST['post'] ) ? (int) $_POST['post'] : 0;
		$ok  = isset( $_POST['act'] ) && 'approve' === $_POST['act'];
		$uid = (int) get_post_meta( $pid, '_dirnet_claim_uid', true );
		$u   = $uid ? get_userdata( $uid ) : false;
		if ( $u && $pid ) {
			if ( $ok ) {
				self::assign_entry( $pid, $uid );
				SubjectLink::register( $pid );
				if ( self::is_producer_entry( $pid ) ) {
					self::grant_producer( $uid ); // the administrator approved the assignment: that is the approval of the role too
				}
			}
			delete_post_meta( $pid, '_dirnet_claim_uid' );
			delete_post_meta( $pid, '_dirnet_claim_at' );
			wp_mail(
				$u->user_email,
				sprintf( /* translators: %s: site name */ __( '[%s] Your request', 'directorist-network' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
				( $ok ? __( 'The entry has been assigned to you. Open it to check and complete it:', 'directorist-network' ) : __( 'We could not assign the entry to you. Write to us if you want to know why.', 'directorist-network' ) ) . "\n\n" . ( $ok ? ( class_exists( '\\ATBDP_Permalink' ) ? (string) \ATBDP_Permalink::get_edit_listing_page_link( $pid ) : get_permalink( $pid ) ) : self::url( 'dirnet_myspace_page' ) )
			);
		}
		wp_safe_redirect( admin_url( 'edit.php?post_type=at_biz_dir&page=dirnet-community' ) );
		exit;
	}

	private static function choose_entity() {
		$rows = self::type_rows();
		if ( ! $rows ) {
			return '<p><a class="button button-primary" href="' . esc_url( self::add_url() ) . '">' . esc_html__( 'Create your entry', 'directorist-network' ) . '</a></p>';
		}
		// One drop-down: the kinds of entity and, when Solidaros is present, the PGS reviewer role (its own form).
		$opts = '<option value="">' . esc_html__( '— choose —', 'directorist-network' ) . '</option>';
		foreach ( $rows as $slug => $r ) {
			$opts .= '<option value="' . esc_attr( $slug ) . '">' . esc_html( $r[0] ) . '</option>';
		}
		if ( self::solidaros() ) {
			$opts .= '<option value="evaluator">' . esc_html__( 'PGS reviewer', 'directorist-network' ) . '</option>';
		}
		return '<form method="get" action="' . esc_url( self::start_url() ) . '" style="max-width:420px"><p><label><strong>' . esc_html__( 'What do you want to register?', 'directorist-network' ) . '</strong><br /><select name="t" required style="width:100%">' . $opts . '</select></label></p><p><button type="submit" class="button button-primary">' . esc_html__( 'Continue', 'directorist-network' ) . '</button></p></form>';
	}

	/** A user created by an invitation who has not done the registration path yet (type, data, categories). */
	public static function onboarding( $uid ) {
		return $uid && get_user_meta( $uid, '_dirnet_invited', true ) && ! get_user_meta( $uid, '_dirnet_onboarded', true ) && ! user_can( $uid, 'edit_others_posts' );
	}

	private function register_form( $kind ) {
		$html = $this->register_form_body( $kind );
		$d    = (int) get_option( 'dirnet_myspace_page' );
		if ( is_user_logged_in() && 'legal' === $kind && $d && 'publish' === get_post_status( $d ) && empty( $_GET['dirnet_stay'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			// Signed in on the Access page: on to the dashboard. Done in the browser, not with a server redirect (a redirect from this page gave a blank page); the buttons stay if scripts are off.
			$go    = (string) get_permalink( $d );
			$html .= '<script>window.location.replace(' . wp_json_encode( $go ) . ');</script><noscript><meta http-equiv="refresh" content="0;url=' . esc_url( $go ) . '"></noscript>';
		}
		return $html;
	}

	private function register_form_body( $kind ) {
		if ( 'register' === $kind && is_user_logged_in() && self::onboarding( get_current_user_id() ) ) { // invited: the whole registration path, with the entry they already have
			$ou = get_current_user_id();
			$ot = (string) get_user_meta( $ou, '_dirnet_onb_ticket', true );
			if ( '' === $ot ) {
				$ot = sanitize_key( wp_generate_password( 20, false ) );
				update_user_meta( $ou, '_dirnet_onb_ticket', $ot );
			}
			set_transient( 'dirnet_regmail_' . $ot, (string) wp_get_current_user()->user_email, 3 * HOUR_IN_SECONDS );
			$_GET['dirnet_reg'] = $ot;
		}
		if ( 'register' === $kind && is_user_logged_in() && ! empty( $_GET['dirnet_edit'] ) && ! self::onboarding( get_current_user_id() ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return '<div class="dirnet-membership">' . $this->person_form() . '</div>'; // the Dashboard strip leads here: the registration data of the signed-in person
		}
		if ( 'register' === $kind && is_user_logged_in() && isset( $_GET['dirnet_step'] ) && 'categories' === $_GET['dirnet_step'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			$box = (string) apply_filters( 'solidaros_profile_sectors', '' );
			if ( '' !== $box ) { // step 3: the categories; Save goes on to the dashboard
				return '<p><strong>' . esc_html__( 'Last step: choose your categories.', 'directorist-network' ) . '</strong></p>' . $box . '<p><a href="' . esc_url( self::url( 'dirnet_myspace_page' ) ) . '">' . esc_html__( 'Skip and go to your space', 'directorist-network' ) . '</a></p>';
			}
		}

		if ( is_user_logged_in() && in_array( $kind, array( 'legal', 'register' ), true ) && ! ( 'register' === $kind && self::onboarding( get_current_user_id() ) ) ) {
			// Signed in: straight to Directorist (add listing for who has no entry yet, the dashboard otherwise).
			$mine = defined( 'ATBDP_POST_TYPE' ) ? get_posts( array( 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'any', 'author' => get_current_user_id(), 'fields' => 'ids', 'numberposts' => 1 ) ) : array();
			if ( ! $mine ) {
				return '<p>' . esc_html__( 'Your account is ready.', 'directorist-network' ) . '</p><p><a class="button button-primary" href="' . esc_url( self::url( 'dirnet_myspace_page' ) ) . '">' . esc_html__( 'Continue: register your entity', 'directorist-network' ) . '</a> ' . self::logout_button() . '</p>';
			}
			$next = ( Pages::id( 'report' ) && self::producer_entries( get_current_user_id() ) )
				? '<a class="button button-primary" href="' . esc_url( (string) get_permalink( Pages::id( 'report' ) ) ) . '">' . esc_html__( 'Go to the social report', 'directorist-network' ) . '</a> <a class="button" href="' . esc_url( self::url( 'dirnet_myspace_page' ) ) . '">' . esc_html__( 'Go to your space', 'directorist-network' ) . '</a>'
				: '<a class="button button-primary" href="' . esc_url( self::url( 'dirnet_myspace_page' ) ) . '">' . esc_html__( 'Go to your space', 'directorist-network' ) . '</a>';
			return '<p>' . $next . ' ' . self::logout_button() . '</p>';
		}
		if ( is_user_logged_in() && ! ( 'register' === $kind && self::onboarding( get_current_user_id() ) ) ) {
			return '<p>' . esc_html__( 'You already have an account.', 'directorist-network' ) . ' <a href="' . esc_url( self::url( 'dirnet_myspace_page' ) ) . '">' . esc_html__( 'Go to your space', 'directorist-network' ) . '</a> ' . self::logout_button() . '</p>';
		}
		if ( isset( $_GET['dirnet_ref'] ) && self::code_exists( sanitize_text_field( wp_unslash( $_GET['dirnet_ref'] ) ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return $this->code_form( sanitize_text_field( wp_unslash( $_GET['dirnet_ref'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		$ticket = '';
		$tmail  = '';
		if ( 'register' === $kind ) {
			$ticket = isset( $_GET['dirnet_reg'] ) ? sanitize_key( wp_unslash( $_GET['dirnet_reg'] ) ) : ( isset( $_POST['reg'] ) ? sanitize_key( wp_unslash( $_POST['reg'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification
			$tmail  = '' !== $ticket ? (string) get_transient( 'dirnet_regmail_' . $ticket ) : '';
			if ( ! is_email( $tmail ) ) { // the e-mail has to be confirmed first, in the access page
				$enter = (int) get_option( 'dirnet_enter_page' );
				return '<p>' . esc_html__( 'To register, first enter your e-mail and the code we send you.', 'directorist-network' ) . ( $enter ? ' <a class="button" href="' . esc_url( (string) get_permalink( $enter ) ) . '">' . esc_html__( 'Enter', 'directorist-network' ) . '</a>' : '' ) . '</p>';
			}
		}
		$msg = '';
		if ( isset( $_GET['dirnet_join'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$map = array(
				'exists' => __( 'This e-mail already has an account. We sent a link to it to choose a new password and sign in.', 'directorist-network' ),
				'bad'    => __( 'Please fill in all the required fields (password: at least 8 characters).', 'directorist-network' ),
				'sent'   => __( 'If the e-mail is correct, we have sent you a link: open it to continue.', 'directorist-network' ),
				'wait'   => __( 'Too many attempts. Please try again later.', 'directorist-network' ),
				'expired' => __( 'Code expired. Ask for a new one.', 'directorist-network' ),
			);
			$k   = sanitize_key( wp_unslash( $_GET['dirnet_join'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$msg = isset( $map[ $k ] ) ? '<p role="status"><strong>' . esc_html( $map[ $k ] ) . '</strong></p>' : '';
		}
		$id  = 'dn' . wp_rand( 100, 999 );
		$out = '<form class="dirnet-join" method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:560px">' . $msg;
		$out .= '<input type="hidden" name="action" value="dirnet_join" />' . wp_nonce_field( 'dirnet_join', '_wpnonce', true, false );
		$out .= '<p style="position:absolute;left:-9999px" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off" /></label></p>';
		$out .= '<input type="hidden" name="kind" value="' . esc_attr( $kind ) . '" />';
		$back = isset( $_GET['dirnet_back'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_GET['dirnet_back'] ) ), '' ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- return to the page the person came from (same site only)
		if ( '' !== $back ) {
			$out .= '<input type="hidden" name="back" value="' . esc_url( $back ) . '" />';
		}
		if ( 'natural' === $kind ) {
			$out .= '<p><label>' . esc_html__( 'First name', 'directorist-network' ) . ' *<br /><input type="text" name="first" required style="width:100%" /></label></p>';
			$out .= '<p><label>' . esc_html__( 'Last name', 'directorist-network' ) . ' *<br /><input type="text" name="last" required style="width:100%" /></label></p>';
			$out .= '<p><label>' . esc_html__( 'Tax code', 'directorist-network' ) . ' *<br /><input type="text" name="tax" required maxlength="20" style="width:100%" /></label></p>';
			$out .= '<p><label>' . esc_html__( 'Town', 'directorist-network' ) . ' *<br /><input type="text" name="address_city" required style="width:100%" /></label></p>';
			$out .= '<p><label>' . esc_html__( 'Phone', 'directorist-network' ) . ' *<br /><input type="text" name="phone" required style="width:100%" /></label></p>';
			$out .= '<p><label>' . esc_html__( 'GAS / network you belong to', 'directorist-network' ) . '<br /><input type="text" name="network_affiliation" style="width:100%" /></label></p>';
		}
		if ( 'register' === $kind ) {
			$out .= '<input type="hidden" name="mode" value="register" />';
			$opts = '<option value="">' . esc_html__( '— choose —', 'directorist-network' ) . '</option>';
			foreach ( array( 'producer' => __( 'I am a producer', 'directorist-network' ), 'distributor' => __( 'I am a distributor', 'directorist-network' ), 'gas' => __( 'I represent a GAS', 'directorist-network' ), 'network' => __( 'I represent a network', 'directorist-network' ), 'evaluator' => __( 'I am a PGS reviewer', 'directorist-network' ) ) as $tk => $tl ) {
				$opts .= '<option value="' . esc_attr( $tk ) . '">' . esc_html( $tl ) . '</option>';
			}
			$rt = (string) get_transient( 'dirnet_regtype_' . $ticket );
			if ( '' !== $rt ) { // step 2: the data for the chosen type
				$out .= '<input type="hidden" name="type" value="' . esc_attr( $rt ) . '" /><input type="hidden" name="step" value="2" />';
			} else { // step 1: type and privacy
				$out .= '<input type="hidden" name="step" value="1" />';
				$out .= '<p><label>' . esc_html__( 'Which type of user are you?', 'directorist-network' ) . ' *<br /><select name="type" required style="width:100%">' . $opts . '</select></label></p>';
				$out .= '<p style="font-size:.9em"><label><input type="checkbox" name="consent" value="1" required /> ' . ( function_exists( 'get_privacy_policy_url' ) && get_privacy_policy_url() ? '<a href="' . esc_url( get_privacy_policy_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'I accept the Privacy Policy', 'directorist-network' ) . '</a>' : esc_html__( 'I accept the Privacy Policy', 'directorist-network' ) ) . '</label></p>';
			}
			if ( '' !== $rt ) {
			$out .= '<div class="dirnet-for" data-for="producer" style="display:none">' . self::farm_field() . '</div>';
			$sol = self::sol_data();
			if ( ! $sol ) {
			$out .= '<div class="dirnet-for" data-for="producer,gas,network,distributor" style="display:none"><p><label><span class="dirnet-entity-label">' . esc_html__( 'Name of the entity', 'directorist-network' ) . '</span> *<br /><input type="text" name="entity" style="width:100%" /></label></p></div>';
			$out .= '<div class="dirnet-for" data-for="producer,gas,network,distributor" style="display:none"><p><label>' . esc_html__( 'VAT number or tax code of the entity', 'directorist-network' ) . '<br /><input type="text" name="vat" style="width:100%" /></label></p></div>';
			}
			$out .= '<script>(function(){var f=document.currentScript.closest("form"),t=f.querySelector("[name=type]");function u(){f.querySelectorAll(".dirnet-for").forEach(function(d){var on=d.getAttribute("data-for").split(",").indexOf(t.value)>-1;d.style.display=on?"":"none";d.querySelectorAll("input[name=entity],input[name=network_affiliation],select[name=farm]").forEach(function(i){i.required=on})})}t.addEventListener("change",u);u()})();</script>';
			if ( $sol ) { // the same data list as Solidaros: ente + titolare/referente (entities) or the reviewer data
				$out .= '<div class="dirnet-for" data-for="producer,gas,network,distributor" style="display:none">' . (string) apply_filters( 'solidaros_registration_form', '', 'producer', array(), 'sol[producer]' ) . '</div>';
				$out .= '<div class="dirnet-for" data-for="evaluator" style="display:none">' . (string) apply_filters( 'solidaros_registration_form', '', 'evaluator', array(), 'sol[evaluator]' ) . '</div>';
				$out .= '<div class="dirnet-for" data-for="none" style="display:none">';
			$out .= '<p><label>' . esc_html__( 'First name', 'directorist-network' ) . ' *<br /><input type="text" name="first" required style="width:100%" /></label></p><p><label>' . esc_html__( 'Last name', 'directorist-network' ) . ' *<br /><input type="text" name="last" required style="width:100%" /></label></p><p><label>' . esc_html__( 'Tax code', 'directorist-network' ) . ' *<br /><input type="text" name="tax" required maxlength="20" style="width:100%" /></label></p>';
			$out .= '<p><label>' . esc_html__( 'Town', 'directorist-network' ) . ' *<br /><input type="text" name="address_city" required style="width:100%" /></label></p>';
			$out .= '<p><label>' . esc_html__( 'Phone', 'directorist-network' ) . ' *<br /><input type="text" name="phone" required style="width:100%" /></label></p>';
				$out .= '</div><script>(function(){var f=document.currentScript.closest("form"),t=f.querySelector("[name=type]");function d(){f.querySelectorAll(".dirnet-for").forEach(function(w){var on=w.style.display!=="none";w.querySelectorAll("input,select,textarea").forEach(function(i){i.disabled=!on})})}t.addEventListener("change",d);d()})();</script>';
			} else {
			$out .= '<p><label>' . esc_html__( 'First name', 'directorist-network' ) . ' *<br /><input type="text" name="first" required style="width:100%" /></label></p><p><label>' . esc_html__( 'Last name', 'directorist-network' ) . ' *<br /><input type="text" name="last" required style="width:100%" /></label></p><p><label>' . esc_html__( 'Tax code', 'directorist-network' ) . ' *<br /><input type="text" name="tax" required maxlength="20" style="width:100%" /></label></p>';
			$out .= '<p><label>' . esc_html__( 'Town', 'directorist-network' ) . ' *<br /><input type="text" name="address_city" required style="width:100%" /></label></p>';
			$out .= '<p><label>' . esc_html__( 'Phone', 'directorist-network' ) . ' *<br /><input type="text" name="phone" required style="width:100%" /></label></p>';
			$out .= '<div class="dirnet-for" data-for="evaluator" style="display:none"><p><label>' . esc_html__( 'GAS / network you belong to', 'directorist-network' ) . ' *<br /><input type="text" name="network_affiliation" style="width:100%" /></label></p><p><strong>' . esc_html__( 'Evaluation territory (provinces)', 'directorist-network' ) . ' *</strong></p>' . self::territory_field( '' ) . '</div>';
			}
			}
			$out .= '<input type="hidden" name="reg" value="' . esc_attr( $ticket ) . '" /><p><label>' . esc_html__( 'E-mail', 'directorist-network' ) . '<br /><input type="email" value="' . esc_attr( $tmail ) . '" readonly style="width:100%" /></label></p>';
		} else {
			$out .= '<p><label>' . esc_html__( 'E-mail', 'directorist-network' ) . ' *<br /><input type="email" name="email" required style="width:100%" autocomplete="email" /></label></p>';
		}
		if ( 'natural' === $kind ) {
			$out .= '<p><label>' . esc_html__( 'Password', 'directorist-network' ) . ' * <small>(' . esc_html__( 'at least 8 characters', 'directorist-network' ) . ')</small><br /><input type="password" name="pass" required minlength="8" autocomplete="new-password" style="width:100%" /></label></p>';
		} else {
			$out .= '<p>' . esc_html__( 'Registration and sign-in are reserved to producers, distributors, GAS, networks and PGS reviewers. Enter your e-mail: we will send you a code to register or sign in.', 'directorist-network' ) . '</p>';
		}
		if ( 'legal' !== $kind && 'register' !== $kind ) { // the access page asks only the e-mail; in the registration the Privacy Policy is accepted in step 1
		$out .= '<p style="font-size:.9em"><label><input type="checkbox" name="consent" value="1" required /> ' . ( function_exists( 'get_privacy_policy_url' ) && get_privacy_policy_url() ? '<a href="' . esc_url( get_privacy_policy_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'I accept the Privacy Policy', 'directorist-network' ) . '</a>' : esc_html__( 'I accept the Privacy Policy', 'directorist-network' ) ) . '</label></p>';
		}
		$out .= '<p><button type="submit" class="button" style="cursor:pointer">' . esc_html__( 'natural' === $kind ? 'Register as a reviewer' : ( 'register' === $kind ? 'Continue' : 'Send' ), 'directorist-network' ) . '</button></p></form>';
		return $out;
	}

	private function back( $code, $ref = '', $ticket = '' ) {
		$url = wp_get_referer();
		$url = $url ? remove_query_arg( array( 'dirnet_join', 'dirnet_ref', 'dirnet_reg' ), $url ) : home_url( '/' );
		if ( '' !== $ticket ) {
			$url = add_query_arg( 'dirnet_reg', $ticket, $url );
		}
		wp_safe_redirect( '' !== $ref ? add_query_arg( 'dirnet_ref', $ref, $url ) : add_query_arg( 'dirnet_join', $code, $url ) );
		exit;
	}

	/* ------------------------------------------------------------ code by e-mail (no link to open: on a phone one would have to leave the page) */

	/** Sends a 6-digit code. @return string reference for the code form, '' if too soon / invalid */
	private static function code_start( $email, $purpose, array $payload ) {
		if ( ! is_email( $email ) ) {
			return '';
		}
		if ( function_exists( 'wp_email_auth_start' ) ) { // plugin WP Email Auth attivo: lo usa; senza, resta il codice interno qui sotto
			return (string) wp_email_auth_start( $email, $purpose, $payload );
		}
		$lock = 'dirnet_code_lock_' . md5( strtolower( $email ) );
		if ( get_transient( $lock ) ) {
			return '';
		}
		// Anti-spam: per visitor (IP) and per day for the whole site (filters: dirnet_code_ip_hour, dirnet_code_site_day).
		$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ipk  = 'dirnet_code_ip_' . md5( $ip );
		$dayk = 'dirnet_code_day_' . gmdate( 'Ymd' );
		$ipn  = (int) get_transient( $ipk );
		$dayn = (int) get_transient( $dayk );
		if ( $ipn >= (int) apply_filters( 'dirnet_code_ip_hour', 5 ) || $dayn >= (int) apply_filters( 'dirnet_code_site_day', 300 ) ) {
			return '';
		}
		set_transient( $ipk, $ipn + 1, HOUR_IN_SECONDS );
		set_transient( $dayk, $dayn + 1, DAY_IN_SECONDS );
		set_transient( $lock, 1, 60 );
		$code = (string) wp_rand( 100000, 999999 );
		$ref  = wp_generate_password( 20, false );
		set_transient( 'dirnet_code_' . $ref, array( 'email' => $email, 'purpose' => $purpose, 'hash' => hash_hmac( 'sha256', $code, wp_salt( 'auth' ) ), 'payload' => $payload, 'tries' => 0 ), 600 );
		wp_mail(
			$email,
			sprintf( /* translators: %s: site name */ __( '[%s] Your access code', 'directorist-network' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			sprintf( /* translators: %s: code */ __( 'Your code is: %s', 'directorist-network' ), $code ) . "\n\n" . __( 'Enter it in the page where you asked for it. It is valid for 10 minutes. If you did not ask for it, ignore this e-mail.', 'directorist-network' )
		);
		return $ref;
	}

	private static function code_exists( $ref ) {
		if ( function_exists( 'wp_email_auth_exists' ) ) {
			return (bool) wp_email_auth_exists( $ref );
		}
		return is_array( get_transient( 'dirnet_code_' . sanitize_key( $ref ) ) );
	}

	/** @return array|null */
	private static function code_check( $ref, $code ) {
		if ( function_exists( 'wp_email_auth_check' ) ) {
			$r = wp_email_auth_check( $ref, $code );
			return is_array( $r ) ? $r : null;
		}
		$key = 'dirnet_code_' . sanitize_key( $ref );
		$d   = get_transient( $key );
		if ( ! is_array( $d ) ) {
			return null;
		}
		if ( (int) $d['tries'] >= 5 ) {
			delete_transient( $key );
			return null;
		}
		$d['tries']++;
		if ( ! hash_equals( (string) $d['hash'], hash_hmac( 'sha256', preg_replace( '/\D/', '', (string) $code ), wp_salt( 'auth' ) ) ) ) {
			set_transient( $key, $d, 600 );
			return null;
		}
		delete_transient( $key );
		return $d;
	}

	private function code_form( $ref ) {
		$msg = '';
		if ( isset( $_GET['dirnet_join'] ) && in_array( $_GET['dirnet_join'], array( 'code', 'expired' ), true ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$msg = 'code' === $_GET['dirnet_join'] ? __( 'Wrong code. Try again.', 'directorist-network' ) : __( 'Code expired. Ask for a new one.', 'directorist-network' ); // phpcs:ignore WordPress.Security.NonceVerification
			$msg = '<p role="alert"><strong>' . esc_html( $msg ) . '</strong></p>';
		}
		return $msg . '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:420px"><input type="hidden" name="action" value="dirnet_code" /><input type="hidden" name="ref" value="' . esc_attr( $ref ) . '" />' . wp_nonce_field( 'dirnet_code', '_wpnonce', true, false )
			. '<p>' . esc_html__( 'We sent a 6-digit code to your e-mail. Enter it here.', 'directorist-network' ) . '</p>'
			. '<p><label>' . esc_html__( 'Code', 'directorist-network' ) . '<br /><input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required autofocus style="font-size:1.4em;letter-spacing:.3em;width:10em" /></label></p>'
			. '<p><button type="submit" class="button button-primary">' . esc_html__( 'Confirm', 'directorist-network' ) . '</button></p></form>';
	}

	public function code_verify() {
		$back = wp_get_referer();
		$back = $back ? remove_query_arg( array( 'dirnet_join', 'dirnet_ref' ), $back ) : home_url( '/' );
		$ref  = isset( $_POST['ref'] ) ? sanitize_text_field( wp_unslash( $_POST['ref'] ) ) : '';
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'dirnet_code' ) ) {
			wp_safe_redirect( $back );
			exit;
		}
		$d = self::code_check( $ref, isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '' );
		if ( ! $d ) {
			wp_safe_redirect( self::code_exists( $ref ) ? add_query_arg( array( 'dirnet_ref' => $ref, 'dirnet_join' => 'code' ), $back ) : add_query_arg( 'dirnet_join', 'expired', $back ) );
			exit;
		}
		$u = get_user_by( 'email', $d['email'] );
		$reg = Pages::id( 'registration' );
		$back = ( isset( $d['payload']['back'] ) && is_string( $d['payload']['back'] ) ) ? wp_validate_redirect( $d['payload']['back'], '' ) : '';
		if ( ! $u && $reg ) { // not registered: the e-mail is confirmed, now the personal data
			$t = wp_generate_password( 20, false );
			set_transient( 'dirnet_regmail_' . $t, $d['email'], 3 * HOUR_IN_SECONDS );
			if ( '' !== $back ) {
				set_transient( 'dirnet_regback_' . $t, $back, 3 * HOUR_IN_SECONDS );
			}
			wp_safe_redirect( add_query_arg( 'dirnet_reg', $t, (string) get_permalink( $reg ) ) );
			exit;
		}
		$this->enter( array( 'email' => $d['email'], 'back' => $back ) );
	}

	/** Step 3 of the registration: the categories are saved by Solidaros' box; then on to the dashboard. */
	public function categories_saved() {
		if ( ! is_user_logged_in() || empty( $_POST['so_wiz_pick'] ) || ! isset( $_GET['dirnet_step'] ) || 'categories' !== $_GET['dirnet_step'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		apply_filters( 'solidaros_profile_sectors', '' ); // saves (nonce checked inside)
		wp_safe_redirect( self::url( 'dirnet_myspace_page' ) );
		exit;
	}

	public function join() {
		$onb = is_user_logged_in() && self::onboarding( get_current_user_id() ) && isset( $_POST['mode'] ) && 'register' === $_POST['mode'] && isset( $_POST['reg'] ) && sanitize_key( wp_unslash( $_POST['reg'] ) ) === (string) get_user_meta( get_current_user_id(), '_dirnet_onb_ticket', true ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( is_user_logged_in() && ! $onb ) {
			wp_safe_redirect( self::url( 'dirnet_myspace_page' ) );
			exit;
		}
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'dirnet_join' ) || ! empty( $_POST['website'] ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
		$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$lock = 'dirnet_join_' . md5( $ip );
		$n    = (int) get_transient( $lock );
		if ( $n >= 6 ) {
			$this->back( 'wait' );
		}
		set_transient( $lock, $n + 1, HOUR_IN_SECONDS );
		$kind  = ( isset( $_POST['kind'] ) && 'natural' === $_POST['kind'] ) ? 'natural' : 'legal';
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( 'legal' === $kind ) {
			if ( isset( $_POST['mode'] ) && 'register' === $_POST['mode'] ) {
				// Step 2 of a new person: the e-mail was already confirmed with the code (ticket); now the personal data.
				$t     = isset( $_POST['reg'] ) ? sanitize_key( wp_unslash( $_POST['reg'] ) ) : '';
				$email = (string) get_transient( 'dirnet_regmail_' . $t );
				if ( ! is_email( $email ) ) {
					$this->back( 'expired' );
				}
				$tk = 'dirnet_regtype_' . $t;
				$rt = (string) get_transient( $tk );
				if ( '' === $rt ) { // step 1: type and privacy, then the data for that type
					$ty = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
					if ( empty( $_POST['consent'] ) || ! in_array( $ty, array( 'none', 'producer', 'gas', 'network', 'distributor', 'evaluator' ), true ) ) {
						$this->back( 'bad', '', $t );
					}
					set_transient( $tk, $ty, 3 * HOUR_IN_SECONDS );
					$ref = wp_get_referer();
					wp_safe_redirect( add_query_arg( 'dirnet_reg', $t, $ref ? remove_query_arg( array( 'dirnet_join', 'dirnet_ref', 'dirnet_reg' ), $ref ) : home_url( '/' ) ) );
					exit;
				}
				$_POST['type'] = $rt; // step 2: the type chosen in step 1
				$pd     = self::person_post();
				$ok     = array( 'none', 'producer', 'gas', 'network', 'distributor', 'evaluator' );
				$sol_ok = false;
				if ( self::sol_data() && in_array( $pd['type'], $ok, true ) && 'none' !== $pd['type'] ) { // the same data list as Solidaros
					$role = self::sol_role( $pd['type'] );
					$data = self::sol_collect( $role );
					$pd   = null === $data ? null : self::sol_person( $role, $data, $pd['type'] );
					if ( null === $pd ) {
						$this->back( 'bad', '', $t );
					}
					$sol_ok = true;
				}
				if ( ! in_array( $pd['type'], $ok, true ) || ( ! in_array( $pd['type'], array( 'evaluator', 'none' ), true ) && '' === $pd['entity'] ) || ( 'evaluator' === $pd['type'] && ! $sol_ok && ( '' === $pd['network_affiliation'] || '' === $pd['evaluation_territory'] ) ) ) {
					$this->back( 'bad', '', $t );
				}
				foreach ( array( 'first', 'last', 'tax', 'address_city', 'phone' ) as $req ) {
					if ( '' === $pd[ $req ] ) {
						$this->back( 'bad', '', $t );
					}
				}
				$rb = (string) get_transient( 'dirnet_regback_' . $t ); // the page the person came from, if any
				delete_transient( 'dirnet_regmail_' . $t );
				delete_transient( 'dirnet_regback_' . $t );
				delete_transient( $tk );
				$this->enter( array( 'email' => $email, 'person' => $pd, 'back' => $rb ) );
			}
			// Access: the e-mail is confirmed with a code, whether the person is registered or not (the answer is the same).
			if ( ! is_email( $email ) ) {
				$this->back( 'bad' );
			}
			$back = isset( $_POST['back'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['back'] ) ), '' ) : '';
			$ref  = self::code_start( $email, 'access', '' !== $back ? array( 'back' => $back ) : array() );
			if ( '' === $ref ) {
				$this->back( 'wait' );
			}
			$this->back( 'ref', $ref );
		}
		$pass  = isset( $_POST['pass'] ) ? (string) wp_unslash( $_POST['pass'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$tax   = isset( $_POST['tax'] ) ? strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', sanitize_text_field( wp_unslash( $_POST['tax'] ) ) ) ) : '';
		$first = isset( $_POST['first'] ) ? sanitize_text_field( wp_unslash( $_POST['first'] ) ) : '';
		$last  = isset( $_POST['last'] ) ? sanitize_text_field( wp_unslash( $_POST['last'] ) ) : '';
		$name  = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( 'natural' === $kind ) {
			$name = trim( $first . ' ' . $last );
		}
		if ( ! is_email( $email ) || strlen( $pass ) < 8 || '' === $name || '' === $tax || empty( $_POST['consent'] ) || ( 'natural' === $kind && ( '' === $first || '' === $last ) ) ) {
			$this->back( 'bad' );
		}
		if ( email_exists( $email ) ) {
			// Never take over an existing account (imported entities already have one): send a link instead.
			$_POST['user_login'] = $email;
			retrieve_password( $email );
			$this->back( 'exists' );
		}
		$base  = sanitize_user( strstr( $email, '@', true ), true );
		$base  = '' !== $base ? $base : 'socio';
		$login = $base;
		$i     = 1;
		while ( username_exists( $login ) ) {
			$login = $base . ++$i;
		}
		$uid = wp_insert_user( array(
			'user_login'   => $login,
			'user_email'   => $email,
			'user_pass'    => $pass,
			'role'         => 'subscriber',
			'display_name' => $name,
			'first_name'   => 'natural' === $kind ? $first : '',
			'last_name'    => 'natural' === $kind ? $last : '',
		) );
		if ( is_wp_error( $uid ) ) {
			$this->back( 'bad' );
		}
		update_user_meta( $uid, self::KIND, $kind );
		update_user_meta( $uid, self::NAME, $name );
		update_user_meta( $uid, self::TAX, $tax );
		if ( 'legal' === $kind ) {
			update_user_meta( $uid, 'sm_company_tax_id', $tax ); // the real tax code; the key Solidaros reads is sm_entity_id (the entity ID), set when the entity is linked
		}
		if ( 'natural' === $kind ) { // the reviewer request is made from "My questionnaires"
			self::save_person( $uid, array_merge( self::person_post(), array( 'first' => $first, 'last' => $last, 'tax' => $tax ) ) );
		}
		wp_set_current_user( $uid );
		wp_set_auth_cookie( $uid, true );
		$reg = (int) get_option( Register::PAGE_OPTION );
		wp_safe_redirect( ( 'legal' === $kind && $reg ) ? (string) get_permalink( $reg ) : self::url( 'dirnet_myspace_page' ) );
		exit;
	}

	/** Step 1 ends here: the link proves the e-mail; the account is created now, not before. */
	/** Signs the person in (creating the account for a new registration). $data: email, optional person (registration data). */
	private function enter( array $data ) {
		$user = get_user_by( 'email', $data['email'] );
		if ( $user && user_can( $user, 'edit_others_posts' ) ) {
			wp_die( esc_html__( 'Staff accounts sign in from the normal login page.', 'directorist-network' ), '', array( 'response' => 403 ) );
		}
		$is_new = ! $user;
		if ( ! $user ) {
			if ( function_exists( 'wp_email_auth_create_user' ) ) { // WP Email Auth: nome utente casuale, senza dati della persona
				$uid = wp_email_auth_create_user( $data['email'] );
			} else {
				$base  = sanitize_user( strstr( $data['email'], '@', true ), true );
				$base  = '' !== $base ? $base : 'socio';
				$login = $base;
				$i     = 1;
				while ( username_exists( $login ) ) {
					$login = $base . ++$i;
				}
				$uid = wp_insert_user( array( 'user_login' => $login, 'user_email' => $data['email'], 'user_pass' => wp_generate_password( 24 ), 'role' => 'subscriber' ) );
			}
			if ( is_wp_error( $uid ) ) {
				wp_die( esc_html__( 'Could not create the account. Contact the site administrator.', 'directorist-network' ) );
			}
			$user = get_user_by( 'id', $uid ); // the kind (producer, GAS, network) is chosen in the registration, not here
		}
		update_user_meta( $user->ID, self::VERIFIED, 1 );
		$onb = ! $is_new && self::onboarding( $user->ID );
		if ( ( $is_new || $onb ) && ! empty( $data['person'] ) && is_array( $data['person'] ) ) {
			self::save_person( $user->ID, $data['person'] );
			self::sol_store( $user->ID, $data['person'] ); // before the entry: it reads the data
			if ( $onb ) { // the entries of the invitation are his: the data goes to them, no new entry
				foreach ( get_posts( array( 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'any', 'author' => $user->ID, 'numberposts' => 20, 'fields' => 'ids' ) ) as $eid ) {
					if ( self::sol_data() ) {
						self::fill_entry( $eid, $user->ID );
					}
					update_user_meta( $user->ID, 'sm_entity_id', (string) $eid );
					SubjectLink::register( $eid );
				}
				update_user_meta( $user->ID, '_dirnet_onboarded', time() );
			} else {
				$status = $this->entry_for( $user->ID ); // assigned to the entry already in the directory, or a new one filled with the registration data
				update_user_meta( $user->ID, '_dirnet_match', $status );
			}
			$rt = (string) get_user_meta( $user->ID, 'dirnet_reg_type', true ); // every profile works at once; the administrator approves or refuses
			if ( '' !== $rt ) {
				$this->open_request( $user->ID, $rt );
			}
		}
		// Entries held by the administrators whose e-mail is this user's (the invitation went to it) pass to him now.
		if ( defined( 'ATBDP_POST_TYPE' ) ) {
			foreach ( get_posts( array( 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'any', 'numberposts' => 50, 'fields' => 'ids', 'meta_key' => '_email', 'meta_value' => $user->user_email ) ) as $cp ) { // phpcs:ignore WordPress.DB.SlowDBQuery
				$ca = (int) get_post_field( 'post_author', $cp );
				if ( $ca !== (int) $user->ID && ( ! $ca || ! get_userdata( $ca ) || user_can( $ca, 'edit_others_posts' ) ) ) {
					Owners::$importing = true;
					wp_update_post( array( 'ID' => $cp, 'post_author' => $user->ID ) );
					Owners::$importing = false;
				}
			}
		}
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true );
		$reg = (int) get_option( 'dirnet_enter_page' );
		$has = defined( 'ATBDP_POST_TYPE' ) && get_posts( array( 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'any', 'author' => $user->ID, 'fields' => 'ids', 'numberposts' => 1 ) );
		$to = self::url( 'dirnet_myspace_page' ); // everyone lands in the dashboard: personal data, then the entry (create, claim or update)
		if ( self::onboarding( $user->ID ) && Pages::id( 'registration' ) ) { // invited: the complete registration path first
			wp_safe_redirect( (string) get_permalink( Pages::id( 'registration' ) ) );
			exit;
		}
		$back = isset( $data['back'] ) ? wp_validate_redirect( (string) $data['back'], '' ) : '';
		if ( '' !== $back ) {
			if ( $is_new ) {
				update_user_meta( $user->ID, '_dirnet_return', $back ); // a new person finishes the registration first; the dashboard reminds the way back
			} else {
				wp_safe_redirect( $back ); // already registered: straight back to where they came from
				exit;
			}
		}
		if ( in_array( (string) get_user_meta( $user->ID, 'dirnet_reg_type', true ), array( 'producer', 'distributor', 'gas', 'network' ), true ) ) { // after the personal data: the categories
			$rg = Pages::id( 'registration' );
			$to = $rg ? add_query_arg( 'dirnet_step', 'categories', (string) get_permalink( $rg ) ) : add_query_arg( 'dirnet_step', 'categories', $to ) . '#dashboard_profile';
		}
		wp_safe_redirect( $to );
		exit;
	}

	public function magic() {
		$d = (int) get_option( 'dirnet_myspace_page' );
		$e = (int) get_option( 'dirnet_enter_page' );
		if ( ! is_user_logged_in() && $d && $e && is_page( $d ) ) { // the dashboard is for signed-in people: the way in is /accesso/
			wp_safe_redirect( (string) get_permalink( $e ) );
			exit;
		}

		if ( empty( $_GET['dirnet_login'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$key  = 'dirnet_magic_' . sanitize_key( wp_unslash( $_GET['dirnet_login'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$data = get_transient( $key );
		if ( ! is_array( $data ) ) {
			wp_die( esc_html__( 'This link is not valid or has expired. Ask for a new one.', 'directorist-network' ), '', array( 'response' => 410 ) );
		}
		delete_transient( $key );
		$this->enter( $data );
	}

	/** Categories whose entities may fill in the questionnaires (names, one per line; also matched on parents). */
	private static function cats() {
		$raw = (string) get_option( 'dirnet_q_categories', "Agricoltura\nComunità" );
		return array_filter( array_map( function ( $x ) {
			return function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $x ) ) : strtolower( trim( $x ) );
		}, preg_split( '/\R/', $raw ) ) );
	}

	/** Entries of the user whose directory type is the producer one. */
	public static function producer_entries( $uid ) {
		if ( ! defined( 'ATBDP_POST_TYPE' ) ) {
			return array();
		}
		$tids = array();
		foreach ( Generator::applied() as $types ) {
			if ( isset( $types['producer'] ) ) {
				$tids[] = (int) $types['producer'];
			}
		}
		$tids = (array) apply_filters( 'dirnet_producer_type_ids', $tids );
		$out  = array();
		foreach ( get_posts( array( 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'any', 'author' => (int) $uid, 'fields' => 'ids', 'numberposts' => 20 ) ) as $pid ) {
			if ( in_array( (int) get_post_meta( $pid, '_directory_type', true ), $tids, true ) ) {
				$out[] = $pid;
			}
		}
		return $out;
	}

	public static function eligible( $uid ) {
		$names = self::cats();
		$ok    = false;
		if ( $names && defined( 'ATBDP_POST_TYPE' ) ) {
			foreach ( self::producer_entries( $uid ) as $pid ) {
				foreach ( (array) wp_get_object_terms( $pid, ATBDP_CATEGORY ) as $t ) {
					$chain = array_merge( array( $t->term_id ), get_ancestors( $t->term_id, ATBDP_CATEGORY, 'taxonomy' ) );
					foreach ( $chain as $tid ) {
						$term = get_term( $tid, ATBDP_CATEGORY );
						if ( $term && ! is_wp_error( $term ) ) {
							$n = function_exists( 'mb_strtolower' ) ? mb_strtolower( $term->name ) : strtolower( $term->name );
							if ( in_array( $n, $names, true ) ) {
								$ok = true;
							}
						}
					}
				}
			}
		}
		return (bool) apply_filters( 'dirnet_questionnaire_eligible', $ok, (int) $uid );
	}

	/** Step 3: the entry exists. Questionnaires now or later, only for the categories that have them. */
	private function step3() {
		$uid = get_current_user_id();
		$my  = self::url( 'dirnet_myspace_page' );
		$has = get_posts( array( 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'any', 'author' => $uid, 'fields' => 'ids', 'numberposts' => 1 ) );
		if ( ! $has ) {
			// Step 2: the complete entry. If it matches one already listed it is joined to it (see Merge).
			return '<h3>' . esc_html__( 'Now the data of your entry', 'directorist-network' ) . '</h3><p>' . esc_html__( 'If your entity is already in the catalogue, its data will be joined to the existing entry.', 'directorist-network' ) . '</p>' . do_shortcode( '[dirnet_create_entity]' );
		}
		$out = '<h3>' . esc_html__( 'Your entry is saved', 'directorist-network' ) . '</h3>';
		if ( self::solidaros() && self::producer_entries( $uid ) ) {
			$out .= '<p>' . esc_html__( 'You can fill in the social report and the entity and product profiles now, or later from your space. The entity and product profiles are specific to farms and agricultural processing businesses.', 'directorist-network' ) . '</p>';
			if ( self::can( 'producer' ) ) {
				$out .= '<p><a class="button" href="' . esc_url( $my ) . '">' . esc_html__( 'Go to the questionnaires', 'directorist-network' ) . '</a></p>';
			} elseif ( 'pending' === self::req_status( $uid, 'producer' ) ) {
				$out .= '<p>' . esc_html__( 'Done: you can start now. An administrator will confirm it; you will receive an e-mail.', 'directorist-network' ) . '</p><p><a class="button" href="' . esc_url( $my ) . '">' . esc_html__( 'Go to your space', 'directorist-network' ) . '</a></p>';
			} else {
				$out .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline"><input type="hidden" name="action" value="dirnet_request" /><input type="hidden" name="what" value="producer" />' . wp_nonce_field( 'dirnet_request', '_wpnonce', true, false ) . '<button type="submit" class="button button-primary" style="cursor:pointer">' . esc_html__( 'Fill in now', 'directorist-network' ) . '</button></form> <a class="button" href="' . esc_url( $my ) . '">' . esc_html__( 'Later', 'directorist-network' ) . '</a>';
			}
		} else {
			$out .= '<p><a class="button" href="' . esc_url( $my ) . '">' . esc_html__( 'Go to your space', 'directorist-network' ) . '</a></p>';
		}
		return $out;
	}

	public function save_cats() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'dirnet_qcats' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'directorist-network' ) );
		}
		update_option( 'dirnet_q_categories', isset( $_POST['cats'] ) ? sanitize_textarea_field( wp_unslash( $_POST['cats'] ) ) : '' );
		wp_safe_redirect( admin_url( 'edit.php?post_type=at_biz_dir&page=dirnet-community' ) );
		exit;
	}

	/* ------------------------------------------------------------ personal page */

	/** Profiles an administrator can look at the site as. */
	public static function view_profiles() {
		return array(
			'producer'    => __( 'Producer', 'directorist-network' ),
			'distributor' => __( 'Distributor', 'directorist-network' ),
			'gas'         => __( 'GAS', 'directorist-network' ),
			'network'     => __( 'Network', 'directorist-network' ),
			'evaluator'   => __( 'PGS reviewer', 'directorist-network' ),
		);
	}

	/** No "view as" any more: kept (always empty) for the code that still asks. */
	public static function sim( $default = '' ) {
		return ''; // no simulation: administrators and editors see the sections of every role
	}

	/** The registration profile of a user, or the simulated one. */
	private static function reg_type( $uid ) {
		$s = self::sim();
		return '' !== $s && (int) $uid === get_current_user_id() ? $s : (string) get_user_meta( $uid, 'dirnet_reg_type', true );
	}

	/** Staff, unless the administrator is looking at the site as a profile. */
	private static function is_staff( $uid ) {
		return '' === self::sim() && user_can( $uid, 'edit_others_posts' );
	}

	public static function can( $what ) {
		$s = self::sim();
		if ( '' !== $s ) {
			return $what === $s;
		}
		return current_user_can( 'producer' === $what ? self::SUBMIT : self::EVALUATE ) || current_user_can( 'edit_others_posts' );
	}

	/** Producers: legal entities, or natural persons who joined through the producer sign-up (individual producers). */
	public static function is_producer( $uid ) {
		$type = (string) get_user_meta( $uid, 'dirnet_entity_type', true ); // chosen in the registration: producer | gas | network
		if ( '' !== $type ) {
			return 'producer' === $type;
		}
		if ( get_user_meta( $uid, self::PRODUCER, true ) || 'legal' === (string) get_user_meta( $uid, self::KIND, true ) ) { // accounts made before the type existed
			return true;
		}
		return 'natural' !== (string) get_user_meta( $uid, self::KIND, true ) && (bool) self::producer_entries( $uid ); // imported owners
	}


	private static function solidaros() {
		return SolidarosBridge::active() && (bool) get_role( 'sm_subject' ); // the role stays in the database even if the module is off
	}

	/** The "My entry" part shows long bands (button at the end); everything else keeps the tiles. */
	private function card( $part, $title, $text, $url = '', $button = '' ) {
		return 'profile' === $part ? $this->band( $title, $text, $url, $button ) : $this->tile( $title, $text, $url, $button );
	}

	private function tile( $title, $text, $url = '', $button = '' ) {
		$h = '<div class="dirnet-tile" style="border:1px solid #d9d9d9;border-radius:4px;padding:16px;background:#fff"><h3 style="margin:0 0 6px">' . esc_html( $title ) . '</h3><p style="margin:0 0 10px">' . esc_html( $text ) . '</p>';
		if ( $url ) {
			$h .= '<a class="button" href="' . esc_url( $url ) . '">' . esc_html( $button ? $button : __( 'Open', 'directorist-network' ) ) . '</a>';
		}
		return $h . '</div>';
	}

	/** On the questionnaire pages and the reviewer page: a signed-in user without the role finds the authorisation request there too. */
	public function request_on_page( $content ) {
		if ( ! is_user_logged_in() || ! is_singular( 'page' ) || ! in_the_loop() || ! is_main_query() || ! self::solidaros() ) {
			return $content;
		}
		$here = get_queried_object_id();
		$uid  = get_current_user_id();
		$what = '';
		foreach ( array( 'report', 'entity', 'product' ) as $k ) {
			if ( Pages::id( $k ) === $here ) {
				$what = 'producer';
			}
		}
		if ( ! $what && (int) get_option( 'dirnet_evaluator_page' ) === $here ) {
			$what = 'evaluator';
		}
		if ( ! $what || self::can( $what ) ) {
			return $content;
		}
		if ( 'producer' === $what && ! self::is_producer( $uid ) ) {
			return $content;
		}
		if ( 'evaluator' === $what && 'natural' !== (string) get_user_meta( $uid, self::KIND, true ) && '' === (string) get_user_meta( $uid, self::TAX, true ) ) {
			return $content;
		}
		$box = 'producer' === $what
			? $this->ask( 'producer', __( 'Fill in questionnaires as a producer', 'directorist-network' ), __( 'Social report, entity and product profiles. It works at once; an administrator confirms it afterwards.', 'directorist-network' ) )
			: $this->ask( 'evaluator', __( 'Review questionnaires as a PGS reviewer', 'directorist-network' ), __( 'Verify the places and products questionnaires of other entities. It works at once; an administrator confirms it afterwards.', 'directorist-network' ) );
		return '<div style="max-width:420px;margin-bottom:24px">' . $box . '</div>' . $content;
	}

	private function ask( $what, $title, $text ) {
		$st = self::req_status( get_current_user_id(), $what );
		if ( 'pending' === $st ) {
			return $this->tile( $title, __( 'Done: you can start now. An administrator will confirm it; you will receive an e-mail.', 'directorist-network' ) );
		}
		$h  = '<div class="dirnet-tile" style="border:1px solid #d9d9d9;border-radius:4px;padding:16px;background:#fff"><h3 style="margin:0 0 6px">' . esc_html( $title ) . '</h3><p style="margin:0 0 10px">' . esc_html( $text ) . ( 'rejected' === $st ? ' ' . esc_html__( 'Your previous request was not approved.', 'directorist-network' ) : '' ) . '</p>';
		$h .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dirnet_request" /><input type="hidden" name="what" value="' . esc_attr( $what ) . '" />' . wp_nonce_field( 'dirnet_request', '_wpnonce', true, false ) . '<button type="submit" class="button" style="cursor:pointer">' . esc_html__( 'Request authorisation', 'directorist-network' ) . '</button></form></div>';
		return $h;
	}

	public function my_space( $atts ) {
		$ret    = is_user_logged_in() ? (string) get_user_meta( get_current_user_id(), '_dirnet_return', true ) : '';
		$banner = '' !== $ret ? '<p><a class="button button-primary" href="' . esc_url( $ret ) . '">' . esc_html__( 'Back to the page you came from', 'directorist-network' ) . '</a></p>' : '';
		return $banner . $this->my_space_body( $atts );
	}

	private function my_space_body( $atts ) {
		$part = ( is_array( $atts ) && isset( $atts['part'] ) ) ? (string) $atts['part'] : 'all';
		$prof = ( 'questionnaires' !== $part );
		$qs   = ( 'profile' !== $part );
		if ( ! is_user_logged_in() ) {
			return '<p><a class="button" href="' . esc_url( (string) get_permalink( (int) get_option( 'dirnet_enter_page' ) ) ) . '">' . esc_html__( 'Enter', 'directorist-network' ) . '</a></p>';
		}
		$user  = wp_get_current_user();
		$kind  = (string) get_user_meta( $user->ID, self::KIND, true );
		$tiles = array();
		$msg   = '';
		if ( isset( $_GET['dirnet_asked'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$msg = '<p role="status"><strong>' . esc_html__( 'Done: you can start now. An administrator will confirm it.', 'directorist-network' ) . '</strong></p>';
		}
		$msg .= $this->choose_box( $user->ID );
		$natural = ( 'natural' === $kind );
		$etype = (string) get_user_meta( $user->ID, 'dirnet_entity_type', true );
		if ( $prof && ! self::own( $user->ID ) && ! user_can( $user->ID, 'edit_others_posts' ) ) {
			if ( '' !== $etype && Pages::id( 'entity_assign' ) ) {
				$tiles[] = $this->card( $part,  __( 'Entry assignment', 'directorist-network' ), __( 'Your entity is already in the catalogue? Ask for the entry to be assigned to you.', 'directorist-network' ), (string) get_permalink( Pages::id( 'entity_assign' ) ), __( 'Continue', 'directorist-network' ) );
			}
		}
		if ( self::is_producer( $user->ID ) ) {
			// The entry, the producer questionnaires: legal persons.
			$mine = defined( 'ATBDP_POST_TYPE' ) ? get_posts( array( 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'any', 'author' => $user->ID, 'fields' => 'ids', 'numberposts' => 10 ) ) : array();
			if ( ! $prof ) {
				$mine = array();
			}
			if ( $mine ) {
				foreach ( $mine as $pid ) {
					$edit    = class_exists( '\ATBDP_Permalink' ) ? \ATBDP_Permalink::get_edit_listing_page_link( $pid ) : get_permalink( $pid );
					$tiles[] = $this->card( $part,  get_the_title( $pid ), Geo::to_complete( $pid ) ? __( 'To complete: the address is missing. Add the street to finish your entry.', 'directorist-network' ) : ( 'publish' === get_post_status( $pid ) ? __( 'Your entry in the catalogue.', 'directorist-network' ) : __( 'Your entry is waiting for review.', 'directorist-network' ) ), $edit, __( 'Edit the entry', 'directorist-network' ) );
				}
			} elseif ( $prof ) {
				$tiles[] = $this->card( $part,  __( 'Create the entry', 'directorist-network' ), __( 'You are not in the catalogue yet. If you were, sign in with the e-mail of the entry or ask us.', 'directorist-network' ), self::add_url(), __( 'Create the entry', 'directorist-network' ) );
			}
			if ( $qs && self::solidaros() && self::producer_entries( $user->ID ) ) {
				if ( self::can( 'producer' ) ) {
					$note = __( 'Farms only.', 'directorist-network' );
					foreach ( array( 'report' => array( __( 'Social report', 'directorist-network' ), '' ), 'entity' => array( __( 'Entity profile', 'directorist-network' ), $note ), 'product' => array( __( 'Product profiles', 'directorist-network' ), $note ), 'sourceprice' => array( __( 'Source price profiles', 'directorist-network' ), $note ) ) as $k => $d ) {
						if ( 'report' !== $k && '0' === (string) get_user_meta( $user->ID, 'dirnet_farm', true ) ) {
							continue; // other businesses: social report only
						}
						$pid = Pages::id( $k );
						if ( $pid ) {
							$tiles[] = $this->tile( $d[0], $d[1], (string) get_permalink( $pid ) );
						}
					}
				} else {
					$tiles[] = $this->ask( 'producer', __( 'Fill in questionnaires as a producer', 'directorist-network' ), __( 'Social report, entity and product profiles. It works at once; an administrator confirms it afterwards.', 'directorist-network' ) );
				}
			}
		}
		if ( $natural && self::solidaros() && $qs ) {
			// Reviewers: natural persons, authorised by an administrator.
			if ( self::can( 'evaluator' ) ) {
			} else {
				$tiles[] = $this->ask( 'evaluator', __( 'Review questionnaires as a PGS reviewer', 'directorist-network' ), __( 'Verify the places and products questionnaires of other entities. It works at once; an administrator confirms it afterwards.', 'directorist-network' ) );
			}
		}
		// API: a Bacheca item of its own (producers and Commercio subjects), with its own page.
		if ( $qs && class_exists( '\\Solidaros\\Api\\ApiAccess' ) && \Solidaros\Api\ApiAccess::eligible() ) { // no page to set up: without an assigned page the entry opens the API tab of the Bacheca
			$api_url = Pages::id( 'api' ) ? (string) get_permalink( Pages::id( 'api' ) ) : self::url( 'dirnet_myspace_page' ) . '#dirnet_api';
			$tiles[] = $this->card( $part, 'API', __( 'Public list of the self-certifications of the entities, with the link to each PDF.', 'directorist-network' ), $api_url, __( 'Open', 'directorist-network' ) );
		}
		$who = (string) get_user_meta( $user->ID, self::NAME, true );
		$out = $msg;
		if ( 'all' === $part ) {
			$out = '<h2>' . esc_html( sprintf( /* translators: %s: name */ __( 'Hello, %s', 'directorist-network' ), '' !== $who ? $who : $user->display_name ) ) . '</h2>' . $msg;
		}
		$docs = '';
		if ( $qs && self::solidaros() && self::can( 'evaluator' ) && ! self::can( 'producer' ) && self::evaluator_area() ) {
			$docs = $this->band( __( 'Evaluators area', 'directorist-network' ), __( 'Your assigned reviews and PGS evaluations are in the evaluators area.', 'directorist-network' ), self::evaluator_area(), __( 'Open', 'directorist-network' ) );
		} elseif ( $qs && self::solidaros() && ( self::can( 'producer' ) || self::can( 'evaluator' ) ) && shortcode_exists( 'so-mydocs' ) ) {
			$docs = '<h3 style="margin-top:28px">' . esc_html__( 'My documents', 'directorist-network' ) . '</h3>' . do_shortcode( '[so-mydocs pgs_only="1"]' );
		}
		if ( 'profile' === $part ) {
			return $out . '<div class="dirnet-bands" style="margin-bottom:24px">' . implode( '', $tiles ) . '</div>' . $docs;
		}
		return $out . '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;margin-bottom:24px">' . implode( '', $tiles ) . '</div>' . $docs;
	}

	/* ------------------------------------------------------------ requests */

	public function request() {
		if ( ! is_user_logged_in() || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'dirnet_request' ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
		$what = isset( $_POST['what'] ) && 'evaluator' === $_POST['what'] ? 'evaluator' : 'producer';
		$kind = (string) get_user_meta( get_current_user_id(), self::KIND, true );
		if ( ( 'producer' === $what && self::is_producer( get_current_user_id() ) ) || ( 'evaluator' === $what && 'natural' === $kind ) ) {
			$this->open_request( get_current_user_id(), $what );
		}
		wp_safe_redirect( add_query_arg( 'dirnet_asked', 1, self::url( 'dirnet_myspace_page' ) ) );
		exit;
	}

	/** Role requests live in Solidaros (one list, one approval); the old DN meta is only the fallback when Solidaros is absent. */
	private function open_request( $uid, $what ) {
		if ( in_array( $what, array( 'producer', 'evaluator', 'gas', 'network', 'distributor' ), true ) && has_action( 'solidaros_grant_role_provisional' ) ) {
			do_action( 'solidaros_grant_role_provisional', (int) $uid, $what ); // works at once; the administrator confirms or refuses
			return;
		}
		if ( has_action( 'solidaros_request_role' ) ) {
			do_action( 'solidaros_request_role', (int) $uid, $what );
			return;
		}
		if ( 'pending' === get_user_meta( $uid, self::REQUEST . $what, true ) ) {
			return;
		}
		update_user_meta( $uid, self::REQUEST . $what, 'pending' );
		update_user_meta( $uid, self::REQUEST . $what . '_at', time() );
	}

	/** Once: requests still waiting in the old DN meta are handed to Solidaros. */
	public function move_requests() {
		if ( get_option( 'dirnet_requests_moved' ) || ! has_action( 'solidaros_request_role' ) ) {
			return;
		}
		foreach ( array( 'producer', 'evaluator' ) as $what ) {
			foreach ( get_users( array( 'meta_key' => self::REQUEST . $what, 'meta_value' => 'pending', 'number' => 500 ) ) as $u ) { // phpcs:ignore WordPress.DB.SlowDBQuery
				do_action( 'solidaros_request_role', (int) $u->ID, $what );
				delete_user_meta( $u->ID, self::REQUEST . $what );
			}
		}
		update_option( 'dirnet_requests_moved', 1, false );
	}

	/** State of a role request: '' | pending | approved | rejected (Solidaros answers; the old meta is the fallback). */
	public static function req_status( $uid, $what ) {
		return (string) apply_filters( 'solidaros_role_status', (string) get_user_meta( $uid, self::REQUEST . $what, true ), (int) $uid, $what );
	}

	/** Pending role requests (users): read from Solidaros. */
	private static function pending() {
		return ( SolidarosBridge::active() && class_exists( '\\Solidaros\\Security\\RoleRequests' ) ) ? \Solidaros\Security\RoleRequests::pending() : array();
	}

	

	

	/** Dashboard ("Bacheca") box: who is waiting for an authorisation. */
	public function dashboard_widget() {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}
		wp_add_dashboard_widget( 'dirnet_requests', __( 'Roles to confirm', 'directorist-network' ), function () {
			$list   = self::pending();
			$claims = self::claims();
			$url  = admin_url( 'edit.php?post_type=at_biz_dir&page=dirnet-community' );
			if ( ! $list && ! $claims ) {
				echo '<p>' . esc_html__( 'No requests waiting.', 'directorist-network' ) . '</p>';
				return;
			}
			echo '<ul style="margin:0 0 10px">';
			foreach ( $list as $r ) {
				echo '<li>' . esc_html( $r[0]->display_name ) . ' <small>(' . esc_html( $r[0]->user_email ) . ') – ' . esc_html( 'evaluator' === $r[1] ? __( 'reviewer', 'directorist-network' ) : __( 'producer', 'directorist-network' ) ) . '</small></li>';
			}
			foreach ( $claims as $c ) {
				echo '<li>' . esc_html( $c[1]->display_name ) . ' <small>– ' . esc_html( sprintf( /* translators: %s: entry */ __( 'asks to be assigned "%s"', 'directorist-network' ), get_the_title( $c[0] ) ) ) . '</small></li>';
			}
			echo '</ul><a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Review the requests', 'directorist-network' ) . '</a>';
		} );
	}

	public function menu() {
		add_submenu_page( 'edit.php?post_type=at_biz_dir', __( 'Roles to confirm', 'directorist-network' ), __( 'Roles to confirm', 'directorist-network' ), 'edit_others_posts', 'dirnet-requests', array( $this, 'requests_page' ) );
	}

	public function requests_page() {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Roles to confirm', 'directorist-network' ) . '</h1>';
		$this->requests_body( false );
		echo '</div>';
	}

	/** The content of the page, shown inside Community. */
	public function requests_body( $nested = true ) {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}
		ob_start();
		if ( ! self::solidaros() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Solidaros is not active: authorising has no effect until it is.', 'directorist-network' ) . '</p></div>';
		}
		echo '<p>' . esc_html__( 'Producer and reviewer roles work at once; an administrator confirms or cancels them in Solidaros (a cancelled role is revoked and the user is told).', 'directorist-network' ) . ' <a class="button" href="' . esc_url( admin_url( 'admin.php?page=solidaros-users' ) ) . '">' . esc_html__( 'User authorisation', 'directorist-network' ) . '</a> (' . (int) count( self::pending() ) . ' ' . esc_html__( 'waiting', 'directorist-network' ) . ')</p>';
		echo '<h2 style="margin-top:28px">' . esc_html__( 'Requests to be assigned an entry', 'directorist-network' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Entry', 'directorist-network' ) . '</th><th>' . esc_html__( 'User', 'directorist-network' ) . '</th><th></th></tr></thead><tbody>';
		$cl = self::claims();
		foreach ( $cl as $c ) {
			echo '<tr><td><a href="' . esc_url( (string) get_edit_post_link( $c[0]->ID ) ) . '">' . esc_html( get_the_title( $c[0] ) ) . '</a></td><td>' . esc_html( $c[1]->display_name ) . '<br /><small>' . esc_html( $c[1]->user_email ) . '</small></td><td>';
			foreach ( array( 'approve' => __( 'Assign', 'directorist-network' ), 'reject' => __( 'Reject', 'directorist-network' ) ) as $act => $label ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline"><input type="hidden" name="action" value="dirnet_claim_decide" /><input type="hidden" name="post" value="' . (int) $c[0]->ID . '" /><input type="hidden" name="act" value="' . esc_attr( $act ) . '" />' . wp_nonce_field( 'dirnet_claim_decide', '_wpnonce', true, false ) . '<button class="button' . ( 'approve' === $act ? ' button-primary' : '' ) . '">' . esc_html( $label ) . '</button></form> ';
			}
			echo '</td></tr>';
		}
		if ( ! $cl ) {
			echo '<tr><td colspan="3">' . esc_html__( 'No requests waiting.', 'directorist-network' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		$html = ob_get_clean();
		echo $nested ? str_replace( array( '<h2>', '</h2>', '<h2 ' ), array( '<h3>', '</h3>', '<h3 ' ), $html ) : $html; // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
