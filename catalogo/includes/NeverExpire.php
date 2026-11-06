<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Every entry "Never expires": existing ones once (on the first admin load of this version), new and edited ones on save. */
class NeverExpire {

	const DONE = 'dirnet_never_expire_done';

	public function boot() {
		add_action( 'admin_init', array( $this, 'backfill' ) );
		add_action( 'save_post', array( $this, 'on_save' ), 99, 2 );
		add_action( 'atbdp_listing_inserted', array( $this, 'on_insert' ), 99 );
	}

	private static function type() {
		return defined( 'ATBDP_POST_TYPE' ) ? ATBDP_POST_TYPE : 'at_biz_dir';
	}

	public function on_save( $post_id, $post ) {
		if ( ! $post || self::type() !== $post->post_type || wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		$this->mark( (int) $post_id );
	}

	public function on_insert( $post_id ) {
		$this->mark( (int) $post_id );
	}

	private function mark( $post_id ) {
		if ( ! get_post_meta( $post_id, '_never_expire', true ) ) {
			update_post_meta( $post_id, '_never_expire', 1 );
		}
	}

	/** One time: the entries already in the directory. */
	public function backfill() {
		if ( get_option( self::DONE ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_never_expire' WHERE p.post_type = %s AND p.post_status <> 'trash' AND p.post_status <> 'auto-draft' AND ( m.meta_id IS NULL OR m.meta_value = '' OR m.meta_value = '0' )", self::type() ) ); // phpcs:ignore WordPress.DB
		foreach ( $ids as $id ) {
			update_post_meta( (int) $id, '_never_expire', 1 );
		}
		update_option( self::DONE, time(), false );
	}
}
