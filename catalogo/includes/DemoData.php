<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Demo data (entries, owners, reviewers) loaded and removed in bulk. Everything is marked, so clearing touches nothing else. */
class DemoData {

	const POST = '_dirnet_demo';
	const USER = 'dirnet_demo';

	public function boot() {
		add_action( 'admin_post_dirnet_demo', array( $this, 'handle' ) );
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'pre_get_posts', array( $this, 'hide' ) );
	}

	/** The demo entry never shows in the public lists (subjects, territories, categories, map, tiles): only its own page is reachable. */
	public function hide( $q ) {
		if ( is_admin() || ! ( $q instanceof \WP_Query ) || $q->is_singular() || $q->get( 'p' ) || $q->get( 'name' ) || $q->get( 'post__in' ) ) {
			return;
		}
		$pt = $q->get( 'post_type' );
		$pt = is_array( $pt ) ? $pt : array( $pt );
		if ( ! in_array( defined( 'ATBDP_POST_TYPE' ) ? ATBDP_POST_TYPE : 'at_biz_dir', $pt, true ) ) {
			return;
		}
		$mq   = (array) $q->get( 'meta_query' );
		$mq[] = array( 'key' => self::POST, 'compare' => 'NOT EXISTS' );
		$q->set( 'meta_query', $mq );
	}

	const SLUG = 'dirnet-demo';

	/** The page of the demo data: no menu entry, it is linked from Settings. */
	public function menu() {
		add_submenu_page( null, __( 'Demo data', 'directorist-network' ), __( 'Demo data', 'directorist-network' ), 'manage_options', self::SLUG, array( $this, 'page' ) );
	}

	public function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Demo data', 'directorist-network' ) . '</h1><p><a href="' . esc_url( admin_url( 'edit.php?post_type=at_biz_dir&page=directorist-network-setup' ) ) . '">&larr; ' . esc_html__( 'Settings', 'directorist-network' ) . '</a></p>';
		self::panel();
		echo '</div>';
	}

	private static function counts() {
		$p = get_posts( array( 'post_type' => defined( 'ATBDP_POST_TYPE' ) ? ATBDP_POST_TYPE : 'post', 'post_status' => 'any', 'meta_key' => self::POST, 'fields' => 'ids', 'numberposts' => -1 ) );
		$u = get_users( array( 'meta_key' => self::USER, 'fields' => 'ID' ) );
		return array( count( $p ), count( $u ), $p, $u );
	}

	public function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}
		check_admin_referer( 'dirnet_demo' );
		$msg = ( isset( $_POST['do'] ) && 'clear' === $_POST['do'] ) ? self::clear() : self::load();
		set_transient( 'dirnet_demo_msg_' . get_current_user_id(), $msg, 300 );
		wp_safe_redirect( admin_url( 'edit.php?post_type=at_biz_dir&page=directorist-network-setup&section=demo' ) );
		exit;
	}

	/** Every required field of the registration (ente + referente), so that the demo users are never stopped by the "complete your profile" check. */
	private static function complete_profile( $uid, $name, $type, $taxId ) {
		$m = array(
			'company_name' => $name, 'company_tax_id' => $taxId, 'address_street' => 'Via della Prova 1', 'address_province' => 'RM', 'address_city' => 'Roma', 'address_zip' => '00100',
			'phone' => '06 0000000', 'email' => $name . '@example.test', 'entity_type' => $type,
			'referent_name' => $name, 'referent_tax_id' => 'TSTRNT80A01H501X', 'referent_street' => 'Via della Prova 1', 'referent_province' => 'RM', 'referent_city' => 'Roma',
			'referent_zip' => '00100', 'referent_email' => $name . '@example.test', 'referent_phone' => '333 0000000',
		);
		foreach ( $m as $k => $v ) {
			update_user_meta( $uid, 'sm_' . $k, $v );
		}
	}

	private static function user( $login, $name, $kind ) {
		$pass = wp_generate_password( 14, false );
		$id   = wp_insert_user( array( 'user_login' => $login, 'user_pass' => $pass, 'user_email' => $login . '@example.invalid', 'display_name' => $name, 'first_name' => $name, 'role' => 'subscriber' ) );
		if ( is_wp_error( $id ) ) {
			return array( 0, '' );
		}
		update_user_meta( $id, self::USER, 1 );
		update_user_meta( $id, Member::KIND, $kind );
		return array( (int) $id, $pass );
	}

	/** Replaces previous demo data with one complete "prova-produttore" (user, entry and, with Autocertificazione, all questionnaires). */
	public static function load() {
		$tStart = microtime( true );
		if ( ! Generator::directorist_ready() ) {
			return array( __( 'Directorist is not active.', 'directorist-network' ) );
		}
		self::clear();
		$types = array();
		foreach ( Generator::applied() as $t ) {
			foreach ( (array) $t as $slug => $tid ) {
				$types[ $slug ] = (int) $tid;
			}
		}
		if ( empty( $types['producer'] ) ) {
			return array( __( 'The producer type does not exist: run the configuration first.', 'directorist-network' ) );
		}
		list( $uid, $pass ) = self::user( 'prova-produttore', 'prova-produttore', 'legal' );
		if ( ! $uid ) {
			return array( __( 'The demo user could not be created.', 'directorist-network' ) );
		}
		wp_update_user( array( 'ID' => $uid, 'last_name' => '', 'user_email' => 'prova-produttore@example.test' ) );
		$id = wp_insert_post( array(
			'post_title'   => 'prova-produttore',
			'post_type'    => ATBDP_POST_TYPE,
			'post_status'  => 'publish',
			'post_author'  => $uid,
			'post_content' => 'Azienda agricola di prova a conduzione familiare: orto, frutteto e trasformazione. Tutti i dati di questa scheda sono inventati e servono a mostrare come appare una scheda completa.',
		), true );
		if ( is_wp_error( $id ) || ! $id ) {
			return array( __( 'The demo entry could not be created.', 'directorist-network' ) );
		}
		$tid = $types['producer'];
		update_post_meta( $id, self::POST, 1 );
		update_post_meta( $id, '_directory_type', $tid );
		wp_set_object_terms( $id, $tid, ATBDP_DIRECTORY_TYPE );
		if ( function_exists( 'calc_listing_expiry_date' ) ) {
			update_post_meta( $id, '_expiry_date', calc_listing_expiry_date( '', '', $tid ) );
		}
		update_post_meta( $id, '_featured', 0 );
		update_post_meta( $id, '_listing_status', 'post_status' );
		// native fields
		$native = array(
			'_email' => 'prova-produttore@example.test', '_phone' => '06 0000000', '_website' => 'https://example.test/prova-produttore',
			'_address' => 'Via della Prova 1, 00100 Roma (RM)', '_zip' => '00100', '_tax_code' => '01234567890',
			'_manual_lat' => '41.9028', '_manual_lng' => '12.4964', '_hide_map' => 0,
		);
		foreach ( $native as $k => $v ) {
			update_post_meta( $id, $k, $v );
		}
		update_post_meta( $id, '_social', array(
			array( 'id' => 'facebook', 'url' => 'https://facebook.com/provaproduttore' ), array( 'id' => 'instagram', 'url' => 'https://instagram.com/provaproduttore' ),
			array( 'id' => 'twitter', 'url' => 'https://x.com/provaproduttore' ), array( 'id' => 'linkedin', 'url' => 'https://linkedin.com/company/provaproduttore' ),
			array( 'id' => 'youtube', 'url' => 'https://youtube.com/@provaproduttore' ), array( 'id' => 'tiktok', 'url' => 'https://tiktok.com/@provaproduttore' ),
			array( 'id' => 'telegram', 'url' => 'https://t.me/provaproduttore' ),
		) );
		// custom fields of the builder (_custom-<key>)
		$custom = array(
			'tax_code' => '01234567890', 'legal_form' => 'sole_trader', 'legal_form_2' => 'other', 'founded' => '2015-03-01', 'data_source' => 'Dati di prova',
			'opening_hours' => "Lun-Ven 8:00-18:00\nSab 8:00-13:00", 'instagram' => 'https://instagram.com/provaproduttore', 'facebook' => 'https://facebook.com/provaproduttore',
			'social_report' => 'https://example.test/bilancio-sociale', 'company_sheet' => 'https://example.test/scheda-azienda',
			'product_sheets' => "Prodotto A | https://example.test/prodotto-a\nProdotto B | https://example.test/prodotto-b\nProdotto C | https://example.test/prodotto-c",
			'sol-entity-type' => 'producer', 'sol-social-x' => 'https://x.com/provaproduttore', 'sol-social-linkedin' => 'https://linkedin.com/company/provaproduttore',
			'sol-social-youtube' => 'https://youtube.com/@provaproduttore', 'sol-social-tiktok' => 'https://tiktok.com/@provaproduttore', 'sol-social-telegram' => 'https://t.me/provaproduttore',
			'sol-referent-name' => 'prova-produttore', 'sol-referent-tax-id' => 'TSTRNT80A01H501X', 'sol-referent-street' => 'Via della Prova 1', 'sol-referent-province' => 'RM',
			'sol-referent-city' => 'Roma', 'sol-referent-zip' => '00100', 'sol-referent-email' => 'prova-produttore@example.test', 'sol-referent-phone' => '333 0000000',
			'phone2' => '06 1111111', 'email2' => 'info@example.test', 'producer_type' => 'goods',
		);
		foreach ( $custom as $k => $v ) {
			update_post_meta( $id, '_custom-' . $k, $v );
		}
		// category, tags, territory
		$food = 0; // category "Alimentazione" (created if missing): the demo user is a farm
		foreach ( (array) get_terms( array( 'taxonomy' => ATBDP_CATEGORY, 'hide_empty' => false, 'parent' => 0 ) ) as $ct ) {
			if ( is_object( $ct ) && ( 'alimentazione' === $ct->slug || 0 === strcasecmp( $ct->name, 'Alimentazione' ) ) ) {
				$food = (int) $ct->term_id;
				break;
			}
		}
		if ( ! $food ) {
			$r    = wp_insert_term( 'Alimentazione', ATBDP_CATEGORY, array( 'slug' => 'alimentazione' ) );
			$food = is_wp_error( $r ) ? 0 : (int) $r['term_id'];
		}
		if ( $food ) {
			wp_set_object_terms( $id, array( $food ), ATBDP_CATEGORY );
		}
		update_user_meta( $uid, 'dirnet_farm', '1' ); // farm: all the questionnaires are open
		if ( taxonomy_exists( 'at_biz_dir-tags' ) ) {
			wp_set_object_terms( $id, array( 'biologico', 'filiera corta', 'km zero' ), 'at_biz_dir-tags' );
		}
		Functions::assign( $id, '', 'producer' );
		Geo::apply_by_sigla( $id, 'RM', 'Roma' );
		update_user_meta( $uid, 'sm_entity_id', (string) $id ); // the key Solidaros uses
		update_user_meta( $uid, 'sm_company_tax_id', '01234567890' );
		self::complete_profile( $uid, 'prova-produttore', 'impresa_agricola', '01234567890' );
		if ( SolidarosBridge::active() && class_exists( '\\Solidaros\\Security\\Capabilities' ) ) {
			\Solidaros\Security\Capabilities::grantProfile( get_userdata( $uid ), 'producer' );
		}
		// the reviewer demo user: registered as a PGS reviewer for the province of Rome
		list( $eid, $epass ) = self::user( 'prova-valutatore', 'prova-valutatore', 'natural' );
		if ( $eid ) {
			wp_update_user( array( 'ID' => $eid, 'user_email' => 'prova-valutatore@example.test' ) );
			foreach ( array(
				'dirnet_reg_type' => 'evaluator', 'dirnet_entity_type' => 'evaluator', 'sm_evaluator_name' => 'prova-valutatore', 'sm_company_tax_id' => 'TSTVLT80A01H501Y',
				'sm_address_city' => 'Roma', 'sm_address_province' => 'RM', 'sm_address_street' => 'Via della Prova 2', 'sm_address_zip' => '00100', 'sm_phone' => '333 0000001',
				'sm_evaluation_territory' => 'RM', 'sm_network_affiliation' => 'GAS di prova', 'sm_appointment_date' => gmdate( 'Y-m-d', strtotime( '-30 days' ) ),
			) as $mk => $mv ) {
				update_user_meta( $eid, $mk, $mv );
			}
			if ( SolidarosBridge::active() && class_exists( '\\Solidaros\\Security\\Capabilities' ) ) {
				\Solidaros\Security\Capabilities::grantProfile( get_userdata( $eid ), 'evaluator' );
			}
		}
		// GAS and distributor demo users, each with a small entry in the same province
		$extra = array();
		foreach ( array( 'gas' => array( 'prova-gas', 'GAS' ), 'distributor' => array( 'prova-distributore', 'distributore' ) ) as $ek => $ev ) {
			list( $xid, $xpass ) = self::user( $ev[0], $ev[0], 'legal' );
			if ( ! $xid ) {
				continue;
			}
			wp_update_user( array( 'ID' => $xid, 'user_email' => $ev[0] . '@example.test' ) );
			$xe = wp_insert_post( array( 'post_title' => $ev[0], 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'publish', 'post_author' => $xid, 'post_content' => 'Scheda di prova (' . $ev[1] . '): dati inventati.' ), true );
			if ( ! is_wp_error( $xe ) && $xe ) {
				update_post_meta( $xe, self::POST, 1 );
				if ( ! empty( $types[ $ek ] ) ) {
					update_post_meta( $xe, '_directory_type', $types[ $ek ] );
					wp_set_object_terms( $xe, $types[ $ek ], ATBDP_DIRECTORY_TYPE );
				}
				if ( function_exists( 'calc_listing_expiry_date' ) && ! empty( $types[ $ek ] ) ) {
					update_post_meta( $xe, '_expiry_date', calc_listing_expiry_date( '', '', $types[ $ek ] ) );
				}
				update_post_meta( $xe, '_listing_status', 'post_status' );
				update_post_meta( $xe, '_email', $ev[0] . '@example.test' );
				update_post_meta( $xe, '_phone', '06 0000002' );
				update_post_meta( $xe, '_address', 'Via della Prova 3, 00100 Roma (RM)' );
				Functions::assign( $xe, '', $ek );
				Geo::apply_by_sigla( $xe, 'RM', 'Roma' );
				update_user_meta( $xid, 'sm_entity_id', (string) $xe );
			}
			self::complete_profile( $xid, $ev[0], 'gas' === $ek ? 'gas' : 'impresa', '0123456789' . ( 'gas' === $ek ? '1' : '2' ) );
			foreach ( array( 'dirnet_reg_type' => $ek, 'dirnet_entity_type' => $ek, 'dirnet_entity_name' => $ev[0], 'sm_address_city' => 'Roma', 'sm_address_province' => 'RM', 'sm_phone' => '333 0000002' ) as $mk => $mv ) {
				update_user_meta( $xid, $mk, $mv );
			}
			if ( has_action( 'solidaros_grant_role_provisional' ) ) {
				do_action( 'solidaros_grant_role_provisional', (int) $xid, $ek );
			}
			$extra[] = $ev[0] . ' / ' . $xpass;
		}
		$log = array( __( 'Demo data loaded: the user "prova-produttore" with a complete entry.', 'directorist-network' ) );
		if ( SolidarosBridge::active() && class_exists( '\\Solidaros\\Core\\DemoData' ) ) {
			$tCat  = microtime( true ) - $tStart;
			$r     = \Solidaros\Core\DemoData::loadFor( (string) $id, 'prova-produttore' );
			$log[] = (string) $r['msg'] . ' Catalogo (utenti e schede): ' . round( $tCat, 1 ) . ' s.';
		}
		$log[] = __( 'Login (shown once, write it down):', 'directorist-network' ) . ' prova-produttore / ' . $pass . ( $eid ? ' — prova-valutatore / ' . $epass : '' ) . ( $extra ? ' — ' . implode( ' — ', $extra ) : '' );
		return $log;
	}

	/** Removes the demo entries and users (called also by the Solidaros clear). */
	public static function clear_own() {
		list( $np, $nu, $posts, $users ) = self::counts();
		foreach ( $posts as $pid ) {
			wp_delete_post( (int) $pid, true );
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $users as $uid ) {
			wp_delete_user( (int) $uid );
		}
		return array( $np, $nu );
	}

	public static function clear() {
		list( $np, $nu ) = self::clear_own();
		if ( SolidarosBridge::active() && class_exists( '\\Solidaros\\Core\\DemoData' ) && ! self::$busy ) {
			self::$busy = true;
			\Solidaros\Core\DemoData::clear();
			self::$busy = false;
		}
		/* translators: 1: entries, 2: users */
		return array( sprintf( __( 'Demo data removed: %1$d entries, %2$d users.', 'directorist-network' ), $np, $nu ) );
	}

	private static $busy = false;

	/** The block shown in Import / export data. */
	public static function panel() {
		$msg = get_transient( 'dirnet_demo_msg_' . get_current_user_id() );
		if ( $msg ) {
			delete_transient( 'dirnet_demo_msg_' . get_current_user_id() );
			echo '<div class="notice notice-info"><ul>';
			foreach ( $msg as $l ) {
				echo '<li>' . esc_html( $l ) . '</li>';
			}
			echo '</ul></div>';
		}
		$c = self::counts();
		echo '<h2>' . esc_html__( 'Demo data', 'directorist-network' ) . '</h2><p>' . esc_html( sprintf( /* translators: 1: entries, 2: users */ __( 'Loaded now: %1$d entries, %2$d users. Loading replaces any previous demo data with one user, \"prova-produttore\", with a complete entry and, if Autocertificazione is active, all its questionnaires.', 'directorist-network' ), $c[0], $c[1] ) ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'dirnet_demo', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="dirnet_demo" />';
		echo '<button class="button" name="do" value="load">' . esc_html__( 'Load demo data', 'directorist-network' ) . '</button> ';
		echo '<button class="button" name="do" value="clear" onclick="return confirm(\'' . esc_js( __( 'Delete all demo entries and users?', 'directorist-network' ) ) . '\')">' . esc_html__( 'Clear demo data', 'directorist-network' ) . '</button></form>';
	}
}
