<?php
/**
 * "Directorist Network" entry of the Directorist menu: the census of every shortcode of the plugin (and of Solidaros),
 * with what it does, its attributes and the pages where it is used. The plugin's own entries are grouped after it.
 */

namespace DirectoristNetwork;

defined( 'ABSPATH' ) || exit;

class ShortcodeIndex {

	const SLUG = 'dirnet-shortcodes';

	/** tag => array( what it does, attributes ) */
	private static function items() {
		return array(
			'dirnet_access'            => array( __( 'Access: e-mail and consent, then the link that signs in. People not yet registered are sent to the Registration page; everyone lands in the dashboard.', 'directorist-network' ), '' ),
			'dirnet_entities'          => array( __( 'List of the entities, with types, roles, filters and map.', 'directorist-network' ), 'per_page, types, sr, location, category, filters, bar, tiles, children, paging' ),
			'dirnet_map'               => array( __( 'Only the map of the entities.', 'directorist-network' ), 'types, sr, location, category, height, tiles' ),
			'dirnet_territory'         => array( __( 'Territories page: regions, provinces, cities and their entities.', 'directorist-network' ), '' ),
			'dirnet_categories'        => array( __( 'Categories of the entities as coloured tiles.', 'directorist-network' ), '' ),
			'dirnet_social_report'     => array( __( 'Form of the social report of the entity.', 'directorist-network' ), '' ),
			'dirnet_registration'      => array( __( 'Registration: all the personal data (name, tax code, town, phone, e-mail) and consent; a link by e-mail confirms it and opens the dashboard. People not yet registered are sent here from the access page.', 'directorist-network' ), '' ),
			'dirnet_assignment'        => array( __( 'List of the entries with a name like the entity, with the button "Request assignment" (an administrator confirms). Sign-in required.', 'directorist-network' ), '' ),
			'dirnet_entry'             => array( __( 'Old page, no longer needed: the entry is created with the registration and the entity only edits it. Do not use.', 'directorist-network' ), '' ),
			'dirnet_entry_form'        => array( __( 'Form to register a producer, a GAS, a network or a distributor (one page per kind; sign-in required).', 'directorist-network' ), 'type: producer | gas | network | distributor' ),
			'dirnet_relations'         => array( __( 'Relations of an entity (member of, supplies, projects).', 'directorist-network' ), '' ),
			'dirnet_dashboard'         => array( __( 'Personal area (also available inside the Directorist dashboard).', 'directorist-network' ), 'part: profile | questionnaires' ),
			'so-socialbalance'         => array( __( 'Solidaros: social report form (sign-in and permissions handled by Solidaros).', 'directorist-network' ), '' ),
			'so-entity'                => array( __( 'Solidaros: company sheet form.', 'directorist-network' ), '' ),
			'so-products'              => array( __( 'Solidaros: product sheets form.', 'directorist-network' ), 'type: product type' ),
			'so-tools'                 => array( __( 'Solidaros: Tools page, one button for each tool (the description is in the page of the tool).', 'directorist-network' ), '' ),
			'so-sourceprice'           => array( __( 'Solidaros: source price sheet form.', 'directorist-network' ), '' ),
			'so-pricelist'             => array( __( 'Solidaros: price list import of the producer (CSV or by hand). It is a step of the guided path ([so-wizard]); a page of its own is needed only for use outside it.', 'directorist-network' ), '' ),
			'so-pgs-process'           => array( __( 'Solidaros: PGS evaluation (process, requests, reviewer work area).', 'directorist-network' ), '' ),
			'so-api'                   => array( __( 'Solidaros: the API page of the Bacheca: the public list of Autocertificazioni with the code to read it', 'directorist-network' ), '' ),
			'so-faq'                   => array( __( 'Solidaros: the FAQ, each on its own white band with accordion question, in the site font: For everyone, For producers, For distributors, For GAS, For networks, For reviewers. Managed in SolidarOS > FAQ; the administrators group is never shown on the site.', 'directorist-network' ), 'group: all, producers, distributors, gas, networks, reviewers (several separated by commas; empty = all the groups). Keys: tutti, produttori, distributori, gas, reti, valutatori' ),
			'so-mydocs'                => array( __( 'Solidaros: my documents and questionnaires under evaluation (already inside the personal area).', 'directorist-network' ), '' ),
			'directorist_add_listing'  => array( __( 'Directorist: the add-listing form, used to edit the entry (the entry is created with the registration).', 'directorist-network' ), '' ),
			'directorist_signin_signup' => array( __( 'Directorist: sign-in and registration page. Not to be used: visitors are sent to the DN access page.', 'directorist-network' ), '' ),
			'directorist_user_dashboard' => array( __( 'Directorist: the user dashboard, replaced by [dirnet_my_space] when Directorist Network is active.', 'directorist-network' ), '' ),
			'directorist_all_listing'  => array( __( 'Directorist: all listings page (the Territories page uses it).', 'directorist-network' ), '' ),
			'dirnet_login'             => array( __( 'Old name of [dirnet_access].', 'directorist-network' ), '' ),
		);
	}

	public function boot() {
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_menu', array( $this, 'group' ), PHP_INT_MAX - 1 );
		add_action( 'admin_head', array( $this, 'css' ) );
		add_action( 'admin_footer', array( $this, 'keep_open' ) );
		add_action( 'in_admin_header', array( $this, 'tab_bar' ) );
		add_filter( 'submenu_file', array( $this, 'current' ) );
		add_filter( 'parent_file', array( $this, 'parent_file' ) );
	}

	public function menu() {
		add_submenu_page( 'edit.php?post_type=at_biz_dir', __( 'Catalogue', 'directorist-network' ), __( 'Catalogue', 'directorist-network' ), 'manage_options', self::SLUG, array( $this, 'page' ) );
		add_submenu_page( null, __( 'Catalogue', 'directorist-network' ), __( 'Catalogue', 'directorist-network' ), 'manage_options', 'dirnet-home', array( $this, 'hub' ) ); // hidden page, like the others: it belongs to no Directorist screen
	}

	/** The Catalogo page: only the menu of links to its pages, nothing else. */
	public function hub() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Catalogo', 'directorist-network' ) . '</h1><ul style="list-style:disc;margin:16px 0 0 20px;padding:0;font-size:16px;line-height:1.6">';
		foreach ( self::$list as $it ) {
			if ( false !== strpos( (string) $it[2], 'page=dirnet-home' ) ) {
				continue;
			}
			echo '<li><a href="' . esc_url( admin_url( (string) $it[2] ) ) . '">' . esc_html( (string) $it[0] ) . '</a></li>';
		}
		echo '</ul></div>';
	}

	public function css() {
		echo '<style>#adminmenu .wp-submenu li.dirnet-head{margin-top:8px;padding-top:8px;border-top:1px solid rgba(255,255,255,.25)}#adminmenu .wp-submenu li.dirnet-head a{font-weight:600;pointer-events:none;cursor:default}#adminmenu .wp-submenu li.dirnet-hide{display:none}#adminmenu .wp-submenu li.dirnet-first{display:none}</style>' . "\n";
	}

	/**
	 * On our screens the Directorist Network menu stays open, like Directorist's own, and the entry being shown is
	 * highlighted. WordPress does it from the parent file; this repeats it on the page, so it holds whatever
	 * other plugin touches the menu. Another admin menu entry opens its own page and closes it, as usual.
	 */
	public function keep_open() {
		$act = self::active();
		if ( '' === $act ) {
			return;
		}
		?>
		<script>
		(function(){
			var m=document.getElementById('adminmenu'); if(!m){return;}
			var top=m.querySelector('li.menu-top > a.menu-top[href$="<?php echo esc_js( 'page=dirnet-home' ); ?>"]');
			if(!top){return;}
			var li=top.parentNode;
			m.querySelectorAll('li.menu-top.wp-has-current-submenu').forEach(function(x){ if(x!==li){ x.classList.remove('wp-has-current-submenu','wp-menu-open','current'); x.classList.add('wp-not-current-submenu'); var a=x.querySelector('a.menu-top'); if(a){ a.classList.remove('wp-has-current-submenu','wp-menu-open'); a.classList.add('wp-not-current-submenu'); } } });
			li.classList.remove('wp-not-current-submenu'); li.classList.add('wp-has-current-submenu','wp-menu-open');
			top.classList.remove('wp-not-current-submenu'); top.classList.add('wp-has-current-submenu','wp-menu-open');
			var want=<?php echo wp_json_encode( $act ); ?>;
			li.querySelectorAll('.wp-submenu li').forEach(function(s){ var a=s.querySelector('a'); var cur=a && (a.getAttribute('href')===want); s.classList.toggle('current',!!cur); if(a){ a.classList.toggle('current',!!cur); } });
		})();
		</script>
		<?php
	}

	/** Tabs of the Settings page: slug => label (each is the existing page, shown with a tab bar on top). */
	private static function tabs() {
		return array(
			'directorist-network-setup' => __( 'Quick setup and demo data', 'directorist-network' ),
		);
	}

	/** Tab bar on top of each settings page. */
	public function tab_bar() {
		if ( self::on_functions() && current_user_can( 'manage_options' ) ) { // title Classification, subtitle Roles
			echo '<script>document.addEventListener("DOMContentLoaded",function(){var h=document.querySelector(".wrap > h1.wp-heading-inline, .wrap > h1");if(!h){return;}var t=h.textContent;h.textContent=' . wp_json_encode( __( 'Classification', 'directorist-network' ) ) . ';var s=document.createElement("h2");s.textContent=t;h.parentNode.insertBefore(s,h.nextSibling);});</script>';
		}
		return; // no tab bar: settings are shown one after the other
		$cur  = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$tabs = self::tabs();
		if ( self::on_functions() ) {
			$cur = 'functions';
		}
		if ( ! isset( $tabs[ $cur ] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap dirnet-tabs" style="margin-bottom:0"><h1 style="margin-bottom:8px">' . esc_html__( 'Directorist Network — settings', 'directorist-network' ) . '</h1><nav class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			$href = 'functions' === $slug ? admin_url( 'edit-tags.php?taxonomy=' . Functions::TAX . '&post_type=at_biz_dir' ) : admin_url( 'edit.php?post_type=at_biz_dir&page=' . $slug );
			echo '<a class="nav-tab' . ( $slug === $cur ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $href ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav></div>';
	}

	/** True on the Functions taxonomy screen (edit-tags / term edit). */
	private static function on_functions() {
		return isset( $_GET['taxonomy'] ) && Functions::TAX === sanitize_key( wp_unslash( $_GET['taxonomy'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	const TOP = 'edit.php?post_type=at_biz_dir&page=directorist-network-setup';

	/** Entries of the Catalogo section of the menu: [ label, capability, slug, title ]. */
	public static $list = array();

	/** Menu URL of an entry: pages are reached on the Directorist parent, whatever menu shows them. */
	private static function url( $slug ) {
		return false !== strpos( $slug, '.php' ) ? $slug : 'edit.php?post_type=at_biz_dir&page=' . $slug;
	}

	/** The entry of our menu that the screen being shown belongs to ('' when it is not ours). */
	private static function active() {
		global $pagenow;
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'dirnet-entity-page' === $page ) {
			return self::TOP;
		}
		if ( Categories::SLUG === $page || ImportaTerritori::SLUG === $page ) {
			return self::url( 'directorist-network-import' );
		}
		if ( 'dirnet-requests' === $page ) {
			return self::url( Community::SLUG );
		}
		if ( SystemPages::SLUG === $page || self::SLUG === $page || DemoData::SLUG === $page || 'dirnet-home' === $page ) {
			return self::TOP;
		}
		if ( '' !== $page && isset( self::menu_items()[ $page ] ) ) {
			return self::url( $page );
		}
		$tax = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'edit-tags.php' === $pagenow && Functions::TAX === $tax ) {
			return 'edit-tags.php?taxonomy=' . Functions::TAX . '&post_type=at_biz_dir';
		}
		$pt = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'edit.php' === $pagenow && 'dirnet_suggestion' === $pt ) {
			return self::url( Community::SLUG );
		}
		return '';
	}

	/** Highlights the entry in the Directorist Network menu. */
	public function current( $file ) {
		$a = self::active();
		return '' !== $a ? $a : $file;
	}

	/** Our screens belong to our own top-level menu, not to Directorist's. */
	public function parent_file( $parent ) {
		return '' !== self::active() ? self::TOP : $parent;
	}

	/** The Directorist Network section of the menu, in order: slug => label. */
	private static function menu_items() {
		return array(
			'directorist-network-setup'                                         => __( 'Configuration', 'directorist-network' ),
			SystemPages::SLUG                                                   => __( 'System pages', 'directorist-network' ),
			'edit-tags.php?taxonomy=' . Functions::TAX . '&post_type=at_biz_dir' => __( 'Classification', 'directorist-network' ),
			'directorist-network-import'                                        => __( 'Import and export data', 'directorist-network' ),
			Repairs::SLUG                                                       => __( 'Data repair', 'directorist-network' ),
			Repairs::API_SLUG                                                   => 'API',
			Invitations::SLUG                                                   => __( 'Invitations', 'directorist-network' ),
			Community::SLUG                                                     => __( 'Communications', 'directorist-network' ),
			self::SLUG                                                          => __( 'Shortcodes', 'directorist-network' ),
		);
	}

	/**
	 * A top-level "Directorist Network" menu. The pages stay registered under Directorist (so their addresses,
	 * permissions and redirects do not change); there they are only hidden, and listed here.
	 */
	public function group() {
		global $submenu;
		$parent = 'edit.php?post_type=at_biz_dir';
		if ( empty( $submenu[ $parent ] ) ) {
			return;
		}
		$items = self::menu_items();
		$found = array();
		foreach ( $submenu[ $parent ] as $k => $item ) {
			$slug = isset( $item[2] ) ? str_replace( '&amp;', '&', (string) $item[2] ) : '';
			if ( isset( $items[ $slug ] ) ) {
				$found[ $slug ] = $item;
			}
			if ( isset( $items[ $slug ] ) || 'dirnet-entity-page' === $slug || Categories::SLUG === $slug || ImportaTerritori::SLUG === $slug || SystemPages::SLUG === $slug || self::SLUG === $slug || 'dirnet-requests' === $slug || 'edit.php?post_type=dirnet_suggestion' === $slug ) {
				$submenu[ $parent ][ $k ][4] = 'dirnet-hide'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			}
		}
		if ( ! isset( $found['directorist-network-setup'] ) ) {
			return;
		}
		$list = array();
		foreach ( $items as $slug => $label ) {
			if ( isset( $found[ $slug ] ) ) {
				$list[] = array( $label, $found[ $slug ][1], self::url( $slug ), $label );
			}
		}
		// A top-level "Catalogo" menu, between Impostazioni and Autocertificazione; Configurazione is its first entry.
		add_menu_page( __( 'Catalogue', 'directorist-network' ), __( 'Catalogue', 'directorist-network' ), 'manage_options', self::TOP, '', 'dashicons-networking', '25.2' );
		self::$list = $list;
		$submenu[ self::TOP ] = $list; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	}

	/** Pages whose content uses the shortcode. */
	private static function used( $tag ) {
		global $wpdb;
		$ck = 'dirnet_sc_used_' . md5( $tag );
		$hit = get_transient( $ck );
		if ( false !== $hit ) {
			return (array) $hit;
		}
		$like = '%' . $wpdb->esc_like( '[' . $tag ) . '%';
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type IN ('page','post') AND post_status IN ('publish','draft','private') AND post_content LIKE %s LIMIT 20", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		set_transient( $ck, $rows, 10 * MINUTE_IN_SECONDS );
		return $rows;
	}

	private function table( $rows ) {
		echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>' . esc_html__( 'Shortcode', 'directorist-network' ) . '</th><th>' . esc_html__( 'What it does', 'directorist-network' ) . '</th><th>' . esc_html__( 'Attributes', 'directorist-network' ) . '</th><th>' . esc_html__( 'Active', 'directorist-network' ) . '</th><th>' . esc_html__( 'Used in', 'directorist-network' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $tag => $r ) {
			$pages = array();
			foreach ( self::used( $tag ) as $p ) {
				$pages[] = '<a href="' . esc_url( (string) get_edit_post_link( (int) $p->ID ) ) . '">' . esc_html( $p->post_title ) . '</a>';
			}
			echo '<tr><td><code>[' . esc_html( $tag ) . ']</code></td><td>' . esc_html( $r[0] ) . '</td><td>' . ( '' !== $r[1] ? '<code>' . esc_html( $r[1] ) . '</code>' : '—' ) . '</td><td>' . ( shortcode_exists( $tag ) ? '✔' : '✖' ) . '</td><td>' . ( $pages ? implode( ', ', $pages ) : '—' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Directorist Network — shortcodes', 'directorist-network' ) . '</h1>';
		$this->body();
		echo '</div>';
	}

	/** The content of the page, shown inside Settings. */
	public function body() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<p>' . esc_html__( 'All the shortcodes of the plugin, and where they are used. The system pages are chosen in System pages.', 'directorist-network' ) . '</p>';
		$this->table( self::items() );
		if ( ( SolidarosBridge::active() && class_exists( '\\Solidaros\\Core\\Pages' ) ) ) {
			$sol = array();
			foreach ( \Solidaros\Core\Pages::shortcodes() as $tag => $label ) {
				$sol[ $tag ] = array( $label, '' );
			}
			echo '<h3 style="margin-top:28px">Solidaros</h3><p>' . esc_html__( 'Enabled or disabled in Solidaros › Settings › Shortcodes.', 'directorist-network' ) . '</p>';
			$this->table( $sol );
		}
	}
}
