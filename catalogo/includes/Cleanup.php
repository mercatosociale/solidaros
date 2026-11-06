<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-time cleanup after the removal of campaigns and news from the plugin (2.37.0).
 * The plugin no longer uses these data; this panel (Data repair) counts what is left on the site and, after an explicit
 * confirmation by an administrator, deletes it. Nothing is deleted without the confirmation.
 */
class Cleanup {

	const ACTION = 'dirnet_cleanup';

	public function boot() {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'run' ) );
	}

	/** Term IDs of the old campaign directory type(s), from the registry of the applied types. */
	private static function terms( $slug = 'campaign' ) {
		$ids = array();
		foreach ( Relations::registry() as $term_id => $info ) {
			if ( $slug === $info[1] ) {
				$ids[] = (int) $term_id;
			}
		}
		return $ids;
	}

	/** @return int[] entries of the old campaign type. */
	private static function entries( $limit = 200, $slug = 'campaign' ) {
		$t = self::terms( $slug );
		if ( ! $t || ! defined( 'ATBDP_POST_TYPE' ) ) {
			return array();
		}
		return get_posts( array( 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'any', 'numberposts' => $limit, 'fields' => 'ids', 'meta_query' => array( array( 'key' => '_directory_type', 'value' => $t, 'compare' => 'IN' ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}

	/** @return int[] pages that hold a system page of campaigns or news (set in System pages, or holding the old shortcodes). */
	private static function pages() {
		global $wpdb;
		$ids = array();
		$o   = (array) get_option( Pages::OPTION, array() );
		foreach ( array( 'campaigns', 'campaign_new', 'news' ) as $k ) {
			if ( ! empty( $o[ $k ] ) && get_post( (int) $o[ $k ] ) ) {
				$ids[] = (int) $o[ $k ];
			}
		}
		foreach ( array( 'dirnet_campaigns', 'dirnet_campaign_form', 'dirnet_news' ) as $sc ) {
			$found = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status NOT IN ('trash','auto-draft') AND post_content LIKE %s", '%[' . $wpdb->esc_like( $sc ) . '%' ) ); // phpcs:ignore WordPress.DB
			$ids   = array_merge( $ids, array_map( 'intval', (array) $found ) );
		}
		return array_values( array_unique( $ids ) );
	}

	private static function users() {
		return get_users( array( 'fields' => 'ID', 'number' => 500, 'meta_query' => array( 'relation' => 'OR', // phpcs:ignore WordPress.DB.SlowDBQuery
			array( 'key' => 'dirnet_reg_type', 'value' => 'campaign' ),
			array( 'key' => 'dirnet_campaign_title', 'compare' => 'EXISTS' ),
			array( 'key' => 'dirnet_campaign_org', 'compare' => 'EXISTS' ),
			array( 'key' => Member::REQUEST . 'campaign', 'compare' => 'EXISTS' ),
		) ) );
	}

	private static function counts() {
		global $wpdb;
		$rel = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN ('_dirnet_rel_project','_dirnet_by_project','_dirnet_campaign_org_id','_custom-campaign-status','_custom-campaign-year')" ); // phpcs:ignore WordPress.DB
		return array(
			'entries' => count( self::entries() ),
			'meta'    => $rel,
			'pages'   => count( self::pages() ),
			'users'   => count( self::users() ),
			'gardens' => count( self::entries( 500, 'garden' ) ),
			'news'    => ( '' !== (string) get_option( 'dirnet_news_feeds', '' ) || false !== get_option( 'dirnet_news_cache', false ) || wp_next_scheduled( 'dirnet_news_refresh' ) ) ? 1 : 0,
		);
	}

	/** The panel, in Data repair. */
	public static function panel() {
		$c = self::counts();
		?>
		<hr />
		<h2><?php esc_html_e( 'Remove campaigns and news', 'directorist-network' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Campaigns and news are no longer part of the plugin. This deletes what is left of them on the site. It cannot be undone: make a backup first.', 'directorist-network' ); ?></p>
		<?php if ( ! array_sum( $c ) ) : ?>
			<p><?php esc_html_e( 'Nothing left to remove.', 'directorist-network' ); ?></p>
		<?php else : ?>
			<ul style="list-style:disc;margin-left:20px">
				<li><?php echo esc_html( sprintf( /* translators: %d: number */ __( 'Campaign entries: %d (permanently deleted)', 'directorist-network' ), $c['entries'] ) ); ?></li>
				<li><?php echo esc_html( sprintf( /* translators: %d: number */ __( 'Adhesion links and campaign data stored in the entries: %d fields', 'directorist-network' ), $c['meta'] ) ); ?></li>
				<li><?php echo esc_html( sprintf( /* translators: %d: number */ __( 'Pages of campaigns and news (moved to the trash): %d', 'directorist-network' ), $c['pages'] ) ); ?></li>
				<li><?php echo esc_html( sprintf( /* translators: %d: number */ __( 'Users with a campaign proposal or the campaign permission: %d (the accounts stay)', 'directorist-network' ), $c['users'] ) ); ?></li>
				<li><?php echo esc_html( $c['news'] ? __( 'News: feeds, cache and schedule', 'directorist-network' ) : __( 'News: nothing', 'directorist-network' ) ); ?></li>
			</ul>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<p><label><input type="checkbox" name="confirm" value="1" required /> <?php esc_html_e( 'I have a backup and I want to delete these data', 'directorist-network' ); ?></label></p>
				<?php if ( $c['gardens'] ) : ?>
					<p><label><input type="checkbox" name="gardens" value="1" /> <?php echo esc_html( sprintf( /* translators: %d: number */ __( 'Also delete the social gardens (%d entries): they are no longer a type of the plugin', 'directorist-network' ), $c['gardens'] ) ); ?></label></p>
				<?php endif; ?>
				<?php submit_button( __( 'Delete campaigns and news', 'directorist-network' ), 'delete', 'submit', false ); ?>
			</form>
		<?php endif; ?>
		<?php
	}

	public function run() {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::ACTION ) || empty( $_POST['confirm'] ) ) {
			wp_safe_redirect( admin_url( 'edit.php?post_type=at_biz_dir&page=' . Repairs::SLUG ) );
			exit;
		}
		$log = array();
		// 1. Campaign entries (in batches: if there are more, run it again).
		$n = 0;
		Owners::$importing = true;
		foreach ( self::entries( 200 ) as $pid ) {
			if ( wp_delete_post( $pid, true ) ) {
				++$n;
			}
		}
		Owners::$importing = false;
		$log[] = sprintf( /* translators: %d: number */ __( 'Campaign entries deleted: %d', 'directorist-network' ), $n );
		if ( count( self::entries( 1 ) ) ) {
			$log[] = __( 'There are more campaign entries: run the deletion again.', 'directorist-network' );
		}
		if ( ! empty( $_POST['gardens'] ) ) { // only when ticked: the gardens may be real data
			$g = 0;
			Owners::$importing = true;
			foreach ( self::entries( 500, 'garden' ) as $pid ) {
				if ( wp_delete_post( $pid, true ) ) {
					++$g;
				}
			}
			Owners::$importing = false;
			$log[] = sprintf( /* translators: %d: number */ __( 'Social gardens deleted: %d', 'directorist-network' ), $g );
		}
		// 2. Links and fields.
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_dirnet_rel_project','_dirnet_by_project','_dirnet_campaign_org_id','_custom-campaign-status','_custom-campaign-year')" ); // phpcs:ignore WordPress.DB
		// 3. Pages (to the trash, so they can be recovered) and their place in the system pages.
		$p = 0;
		foreach ( self::pages() as $pid ) {
			if ( wp_trash_post( $pid ) ) {
				++$p;
			}
		}
		$o = (array) get_option( Pages::OPTION, array() );
		foreach ( array( 'campaigns', 'campaign_new', 'news' ) as $k ) {
			unset( $o[ $k ] );
		}
		update_option( Pages::OPTION, $o );
		$log[] = sprintf( /* translators: %d: number */ __( 'Pages moved to the trash: %d', 'directorist-network' ), $p );
		// 4. Users: proposal data and permission; the accounts stay.
		foreach ( self::users() as $uid ) {
			$u = get_userdata( (int) $uid );
			if ( $u ) {
				$u->remove_cap( 'dirnet_campaign' );
			}
			foreach ( array( 'dirnet_campaign_org', 'dirnet_campaign_title', 'dirnet_campaign_text', Member::REQUEST . 'campaign', Member::REQUEST . 'campaign_at', '_dirnet_next', '_dirnet_camp_done' ) as $m ) {
				delete_user_meta( (int) $uid, $m );
			}
			if ( 'campaign' === (string) get_user_meta( (int) $uid, 'dirnet_reg_type', true ) ) {
				delete_user_meta( (int) $uid, 'dirnet_reg_type' );
			}
		}
		// 5. News.
		foreach ( array( 'dirnet_news_feeds', 'dirnet_news_cache', 'dirnet_news_lock', 'dirnet_camp_slug2' ) as $opt ) {
			delete_option( $opt );
		}
		wp_clear_scheduled_hook( 'dirnet_news_refresh' );
		$log[] = __( 'News: feeds, cache and schedule removed.', 'directorist-network' );
		$log[] = __( 'The catalogue types "Campaigns" and "Social gardens" stay in Directorist: delete them in Directorist > Directory Builder if you want.', 'directorist-network' );
		set_transient( 'dirnet_log_' . get_current_user_id(), $log, 300 );
		wp_safe_redirect( admin_url( 'edit.php?post_type=at_biz_dir&page=' . Repairs::SLUG ) );
		exit;
	}
}
