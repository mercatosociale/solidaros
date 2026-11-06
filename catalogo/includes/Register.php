<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public self-registration: [dirnet_register] shows a dropdown of directory types; the chosen type's
 * Directorist add-listing form appears below on the same page. Submissions are held as pending for approval.
 */
class Register {

	const PAGE_OPTION = 'dirnet_register_page';

	public function boot() {
		// the entry form is Directorist's own "Add listing"
	}

	/** [dirnet_register types="producer,gas" intro="..."] — slugs are profile type slugs; default: all generated types. */
	public function shortcode( $atts ) {
		$atts   = shortcode_atts( array( 'types' => '', 'intro' => '' ), $atts, 'dirnet_register' );
		$want   = array_filter( array_map( 'sanitize_key', explode( ',', $atts['types'] ) ) );
		$rows = array();
		foreach ( Generator::applied() as $pid => $types ) {
			$profile = Profile::get( $pid );
			if ( ! $profile ) {
				continue;
			}
			foreach ( (array) $types as $slug => $term_id ) {
				if ( $want && ! in_array( $slug, $want, true ) ) {
					continue;
				}
				$term = get_term( (int) $term_id, 'atbdp_listing_types' );
				if ( ! $term || is_wp_error( $term ) ) {
					continue;
				}
				$defs   = $profile->types();
				$label  = $profile->t( isset( $defs[ $slug ]['label'] ) ? $defs[ $slug ]['label'] : $slug );
				$rows[] = array( $label, $term->slug );
			}
		}
		if ( ! $rows ) {
			return '';
		}
		// The form lives on this same page: picking a type reloads it with that type's own Directorist form below.
		$current = isset( $_GET['directory_type'] ) ? sanitize_title( wp_unslash( $_GET['directory_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$slugs   = wp_list_pluck( $rows, 1 );
		if ( ! in_array( $current, $slugs, true ) ) {
			$current = '';
		}
		$base = remove_query_arg( 'directory_type' );
		$out  = '<div class="dirnet-register">';
		if ( ! $current ) {
			$out .= '<p>' . esc_html( $atts['intro'] ? $atts['intro'] : __( 'What is your function? Choose it: the form that follows is the one for your kind of entity. Your entry will appear in the catalogue after it has been reviewed.', 'directorist-network' ) ) . '</p><ul style="list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px">';
			foreach ( $rows as $r ) {
				$out .= '<li><a class="button" style="display:block;padding:14px;text-align:center" href="' . esc_url( add_query_arg( 'directory_type', $r[1], $base ) ) . '">' . esc_html( $r[0] ) . '</a></li>';
			}
			$out .= '</ul>';
		} else {
			$label = '';
			foreach ( $rows as $r ) {
				if ( $r[1] === $current ) {
					$label = $r[0];
				}
			}
			$out .= '<p><strong>' . esc_html( $label ) . '</strong> · <a href="' . esc_url( $base ) . '">' . esc_html__( 'Change function', 'directorist-network' ) . '</a></p>';
			$out .= '<div class="dirnet-register-form">' . do_shortcode( '[directorist_add_listing directory_type="' . esc_attr( $current ) . '"]' ) . '</div>';
		}
		$out .= '</div>';
		return $out;
	}

	public static function ensure_page() {
		$id = (int) get_option( self::PAGE_OPTION );
		if ( $id && 'trash' !== get_post_status( $id ) && get_post( $id ) ) {
			return $id;
		}
		return 0; // pages are never created here: the site assigns them in System pages
	}

	/** Moderated, open submissions: pending status, guests/registration allowed, admin notified. */
	public static function configure() {
		if ( ! function_exists( 'update_directorist_option' ) ) {
			return '!' . __( 'Directorist settings API not found.', 'directorist-network' );
		}
		update_directorist_option( 'new_listing_status', 'pending' );
		update_directorist_option( 'edit_listing_status', 'pending' );
		update_directorist_option( 'guest_listings', 1 );
		update_directorist_option( 'new_user_registration', 1 );
		$notify = (array) get_directorist_option( 'notify_admin', array() );
		if ( ! in_array( 'listing_submitted', $notify, true ) ) {
			$notify[] = 'listing_submitted';
			update_directorist_option( 'notify_admin', $notify );
		}
		return __( 'Self-registration enabled: new and edited entries wait for your approval and you get an email for each submission.', 'directorist-network' );
	}
}
