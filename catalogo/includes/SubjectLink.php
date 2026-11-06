<?php
namespace DirectoristNetwork;

defined( 'ABSPATH' ) || exit;

/**
 * Codice unico del soggetto: lo assegna Solidaros (una volta, stabile) e DN lo conserva nella scheda
 * (meta _dirnet_code). Chiave dei questionari = ID della scheda (SolidarosBridge::company_id).
 * Con Solidaros standalone poi affiancato da DN, "Collega soggetti" scrive i codici nelle schede.
 */
class SubjectLink {

	const META = '_dirnet_code';

	public function boot() {
		add_action( 'solidaros_settings_other', array( $this, 'panel' ) ); // the panel lives in Solidaros → Impostazioni → Altre impostazioni
		add_action( 'admin_post_dirnet_link_subjects', array( $this, 'link' ) );
		add_filter( 'solidaros_subject_profile', array( $this, 'card_profile' ), 10, 2 ); // the public card in Solidaros: photo, description, contacts and map of the entry
	}

	/** Farm flag from the registration: true | false, null when not asked (so Solidaros keeps everything open). */
	private static function farm( $uid ) {
		$v = (string) get_user_meta( $uid, 'dirnet_farm', true );
		return '' === $v ? null : '1' === $v;
	}

	/** Registra la scheda in Solidaros e ne salva il codice. Restituisce il codice ('' se Solidaros non c'e'). */
	public static function register( $pid ) {
		$pid = (int) $pid;
		if ( ! $pid || ! has_action( 'solidaros_subject_register' ) ) {
			return '';
		}
		$key = SolidarosBridge::company_id( $pid );
		do_action( 'solidaros_subject_register', array(
			'ref'     => $key,
			'key'     => $key,
			'tax_id'  => (string) get_post_meta( $pid, SolidarosBridge::TAX_META, true ),
			'name'    => get_the_title( $pid ),
			'profile' => (string) get_user_meta( (int) get_post_field( 'post_author', $pid ), 'dirnet_entity_type', true ), // the type chosen at registration
			'farm'    => self::farm( (int) get_post_field( 'post_author', $pid ) ),
			'roles'   => class_exists( __NAMESPACE__ . '\\Functions' ) ? (array) wp_get_object_terms( $pid, Functions::TAX, array( 'fields' => 'slugs' ) ) : array(),
		) );
		$code = (string) apply_filters( 'solidaros_subject_code', '', $key );
		if ( '' !== $code ) {
			update_post_meta( $pid, self::META, $code );
		}
		return $code;
	}

	/** What the entry adds to the public card of Solidaros (the key of an entity of this directory is its entry ID). */
	public function card_profile( $p, $key ) {
		$pid = (int) $key;
		if ( ! $pid || ! defined( 'ATBDP_POST_TYPE' ) || ATBDP_POST_TYPE !== get_post_type( $pid ) || 'publish' !== get_post_status( $pid ) ) {
			return $p;
		}
		$m = function ( $k ) use ( $pid ) {
			return trim( (string) get_post_meta( $pid, $k, true ) );
		};
		if ( '' === $p['name'] ) {
			$p['name'] = get_the_title( $pid );
		}
		$p['photo'] = (string) get_the_post_thumbnail_url( $pid, 'large' );
		$desc       = trim( wp_strip_all_tags( (string) get_post_field( 'post_content', $pid ) ) );
		if ( '' === $desc ) {
			$desc = trim( wp_strip_all_tags( (string) get_post_field( 'post_excerpt', $pid ) ) );
		}
		$p['description'] = wp_trim_words( $desc, 80, '…' );
		$p['email']       = $m( '_email' ) ?: $p['email'];
		$p['phone']       = $p['phone'] ?: $m( '_phone' );
		$p['email2']      = $m( '_custom-email2' );
		$p['phone2']      = $m( '_custom-phone2' );
		$p['email2']      = $m( '_custom-email2' );
		$p['phone2']      = $m( '_custom-phone2' );
		$p['website']     = $p['website'] ?: $m( '_website' );
		$p['address']     = $p['address'] ?: $m( '_address' );
		return $p;
	}

	public static function code( $pid ) {
		return (string) get_post_meta( (int) $pid, self::META, true );
	}

	/** @return array<int,array{pid:int,key:string,code:string,how:string}> proposte: soggetti Solidaros con una scheda certa e ancora senza codice. */
	private function proposals() {
		$out = array();
		foreach ( (array) apply_filters( 'solidaros_subjects_list', array() ) as $s ) {
			$key = (string) $s['key'];
			$pid = 0;
			$how = '';
			if ( ctype_digit( $key ) && ATBDP_POST_TYPE === get_post_type( (int) $key ) ) {
				$pid = (int) $key;
				$how = __( 'same ID', 'directorist-network' );
			} elseif ( '' !== $key ) {
				$ids = get_posts( array( 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'any', 'fields' => 'ids', 'numberposts' => 1, 'meta_query' => array( array( 'key' => SolidarosBridge::TAX_META, 'value' => $key ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
				if ( $ids ) {
					$pid = (int) $ids[0];
					$how = __( 'same tax code', 'directorist-network' );
				}
			}
			if ( $pid && self::code( $pid ) !== (string) $s['code'] ) {
				$out[] = array( 'pid' => $pid, 'key' => $key, 'code' => (string) $s['code'], 'how' => $how );
			}
		}
		return $out;
	}

	/** Panel shown in Solidaros → Impostazioni → Altre impostazioni (hook solidaros_settings_other). */
	public function panel() {
		if ( ! current_user_can( 'manage_options' ) || ! has_filter( 'solidaros_subjects_list' ) ) {
			return;
		}
		echo '<hr><h2>' . esc_html__( 'Link entities to Solidaros', 'directorist-network' ) . '</h2>';
		if ( isset( $_GET['done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success inline"><p>' . esc_html( sprintf( /* translators: %d: number */ __( '%d entities linked.', 'directorist-network' ), (int) $_GET['done'] ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		echo '<p>' . esc_html__( 'Solidaros gives each entity a unique code. Here the entries that match an entity of Solidaros with certainty (same ID or same tax code) and do not have its code yet. Entities without a sure match are left out: they get the code when they register.', 'directorist-network' ) . '</p>';
		$rows = $this->proposals();
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Nothing to link.', 'directorist-network' ) . '</p>';
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dirnet_link_subjects">';
		wp_nonce_field( 'dirnet_link_subjects' );
		echo '<table class="widefat striped"><thead><tr><th></th><th>' . esc_html__( 'Entry', 'directorist-network' ) . '</th><th>' . esc_html__( 'Solidaros key', 'directorist-network' ) . '</th><th>' . esc_html__( 'Code', 'directorist-network' ) . '</th><th>' . esc_html__( 'Match', 'directorist-network' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><td><input type="checkbox" name="pid[]" value="' . (int) $r['pid'] . '" checked></td><td>' . esc_html( get_the_title( $r['pid'] ) ) . '</td><td>' . esc_html( $r['key'] ) . '</td><td>' . esc_html( $r['code'] ) . '</td><td>' . esc_html( $r['how'] ) . '</td></tr>';
		}
		echo '</tbody></table><p><button class="button button-primary">' . esc_html__( 'Link selected', 'directorist-network' ) . '</button></p></form>';
	}

	public function link() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'directorist-network' ) );
		}
		check_admin_referer( 'dirnet_link_subjects' );
		$sel = array_map( 'intval', (array) ( isset( $_POST['pid'] ) ? wp_unslash( $_POST['pid'] ) : array() ) );
		$n   = 0;
		foreach ( $this->proposals() as $r ) { // ricalcolate: si scrive solo cio\' che e\' ancora una proposta valida
			if ( in_array( $r['pid'], $sel, true ) ) {
				update_post_meta( $r['pid'], self::META, $r['code'] );
				++$n;
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=solidaros-settings&tab=other&done=' . $n ) );
		exit;
	}
}
