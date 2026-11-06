<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Directorist's single-page "contact the owner" form mails the listing AUTHOR by default. Entities loaded
 * by CSV are all authored by the administrator, so messages would reach the administrator, not the entity.
 * This makes the form mail the entity's own email field instead, whenever the listing has one.
 */
class ContactForm {

	public function boot() {
		add_filter( 'get_user_metadata', array( $this, 'recipient' ), 10, 4 );
	}

	public function recipient( $value, $user_id, $meta_key, $single ) {
		if ( 'directorist_contact_owner_recipient' !== $meta_key || null !== $value ) {
			return $value;
		}
		$post_id = isset( $_POST['atbdp-post-id'] ) ? absint( $_POST['atbdp-post-id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- Directorist verifies the nonce before reading this
		if ( $post_id && is_email( get_post_meta( $post_id, '_email', true ) ) && Relations::type_of( $post_id ) ) {
			return 'listing_email';
		}
		return $value;
	}
}
