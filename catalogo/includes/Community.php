<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Communications page: authorisation requests and suggested edits, one after the other. */
class Community {

	const SLUG = 'dirnet-community';

	public function boot() {
		add_action( 'admin_menu', array( $this, 'menu' ), 32 );
	}

	public function menu() {
		add_submenu_page( 'edit.php?post_type=at_biz_dir', __( 'Communications', 'directorist-network' ), __( 'Communications', 'directorist-network' ), 'edit_others_posts', self::SLUG, array( $this, 'page' ) );
	}

	public function page() {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Communications', 'directorist-network' ) . '</h1>';
		echo '<h2>' . esc_html__( 'Roles to confirm', 'directorist-network' ) . '</h2>';
		( new Member() )->requests_body();
		if ( current_user_can( 'manage_options' ) ) {
			echo '<hr /><h2>' . esc_html__( 'Suggested edits', 'directorist-network' ) . '</h2>';
			$this->suggestions();
		}
		echo '</div>';
	}

	/** The latest suggestions, with a link to the full list. */
	private function suggestions() {
		$list = get_posts( array( 'post_type' => Suggestions::CPT, 'post_status' => array( 'publish', 'draft', 'pending', 'private' ), 'numberposts' => 20, 'orderby' => 'date', 'order' => 'DESC' ) );
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Suggested edit', 'directorist-network' ) . '</th><th>' . esc_html__( 'Date', 'directorist-network' ) . '</th></tr></thead><tbody>';
		foreach ( $list as $p ) {
			echo '<tr><td><a href="' . esc_url( (string) get_edit_post_link( $p->ID ) ) . '">' . esc_html( get_the_title( $p ) ) . '</a></td><td>' . esc_html( get_the_date( '', $p ) ) . '</td></tr>';
		}
		if ( ! $list ) {
			echo '<tr><td colspan="2">' . esc_html__( 'No suggestions yet.', 'directorist-network' ) . '</td></tr>';
		}
		echo '</tbody></table><p><a class="button" href="' . esc_url( admin_url( 'edit.php?post_type=' . Suggestions::CPT ) ) . '">' . esc_html__( 'All suggested edits', 'directorist-network' ) . '</a></p>';
	}
}
