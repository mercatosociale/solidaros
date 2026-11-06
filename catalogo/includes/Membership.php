<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "I accept the membership conditions and request to join <network>" checkbox shown above
 * Directorist's privacy/cookie/terms checkbox when a NEW entry is submitted. The conditions page
 * (social report, company sheet, product sheets…) is set in Network setup. The time of acceptance is
 * stored on the entry (_dirnet_membership_accepted). It is required by the browser; the privacy
 * checkbox next to it is validated by Directorist itself.
 */
class Membership {

	const META = '_dirnet_membership_accepted';

	public function boot() {
		add_action( 'atbdp_before_terms_and_conditions_font', array( $this, 'checkbox' ) );
		add_action( 'atbdp_listing_inserted', array( $this, 'save' ) );
	}

	public static function network_name() {
		$n = trim( (string) get_option( 'dirnet_network_name', '' ) );
		return '' !== $n ? $n : get_bloginfo( 'name' );
	}

	public static function text() {
		$pid   = (int) Pages::id( 'membership' );
		$u     = $pid && 'publish' === get_post_status( $pid ) ? (string) get_permalink( $pid ) : (string) get_option( 'dirnet_membership_url', '' );
		$label = __( 'membership conditions', 'directorist-network' );
		$link  = '' !== $u ? '<a href="' . esc_url( $u ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a>' : esc_html( $label );
		return sprintf(
			/* translators: 1: "membership conditions" (a link), 2: network name */
			__( 'I accept the %1$s and request to join %2$s', 'directorist-network' ),
			$link,
			esc_html( self::network_name() )
		);
	}

	public function checkbox() {
		if ( get_query_var( 'atbdp_listing_id', 0 ) ) { // editing an existing entry
			return;
		}
		echo '<div class="directorist-form-privacy directorist-form-terms directorist-checkbox dirnet-membership" style="margin-bottom:10px">'
			. '<input id="dirnet_membership" type="checkbox" name="dirnet_membership" value="1" required>'
			. '<label for="dirnet_membership" class="directorist-checkbox__label">' . wp_kses( self::text(), array( 'a' => array( 'href' => true, 'target' => true, 'rel' => true ) ) ) . '</label>'
			. ' <span class="directorist-form-required"> *</span></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public function save( $listing_id ) {
		if ( ! empty( $_POST['dirnet_membership'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- Directorist verifies the submission
			update_post_meta( (int) $listing_id, self::META, current_time( 'mysql' ) );
		}
	}
}
