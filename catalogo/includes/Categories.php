<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin screen "Network categories": the category tree (categories and subcategories) with add,
 * rename and delete, plus CSV import/export (columns: category, subcategory). New categories are
 * made available to every directory type created by this plugin.
 */
class Categories {

	const SLUG = 'directorist-network-categories';

	const OPT_MANAGED = 'dirnet_categories_managed';

	private static $dirty = false;

	public function boot() {
		foreach ( array( 'created_term', 'edited_term', 'delete_term' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'touched' ), 10, 3 );
		}
		add_action( 'shutdown', array( __CLASS__, 'flush' ) );
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_post_dirnet_cat', array( $this, 'handle' ) );
		add_action( 'admin_post_dirnet_cat_import', array( $this, 'handle_import' ) );
		add_action( 'admin_post_dirnet_cat_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_dirnet_cat_move', array( $this, 'handle_move' ) );
	}

	public function menu() {
		add_submenu_page(
			'edit.php?post_type=at_biz_dir',
			__( 'Categories and subcategories', 'directorist-network' ),
			__( 'Network categories', 'directorist-network' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/** A category changed anywhere (this screen, Directorist's own, imports): rewrite the JSON file on shutdown. */
	public static function touched( $term_id, $tt_id = 0, $taxonomy = '' ) {
		if ( ATBDP_CATEGORY === $taxonomy ) {
			self::$dirty = true;
		}
	}

	public static function flush() {
		if ( self::$dirty ) {
			self::sync();
		}
	}

	/** Folder that survives plugin updates. */
	public static function file() {
		$up = wp_upload_dir( null, false );
		return empty( $up['basedir'] ) ? '' : trailingslashit( $up['basedir'] ) . 'directorist-network/categories.json';
	}

	const ORDER = 'dirnet_order';

	/** Children of $parent sorted by saved position, then name. */
	private static $memo = null; // filled only while the page is drawn: the tree is read once per level, not once per row

	public static function children( $parent ) {
		if ( is_array( self::$memo ) && isset( self::$memo[ (int) $parent ] ) ) {
			return self::$memo[ (int) $parent ];
		}
		$terms = taxonomy_exists( ATBDP_CATEGORY ) ? get_terms( array( 'taxonomy' => ATBDP_CATEGORY, 'hide_empty' => false, 'parent' => (int) $parent, 'orderby' => 'name' ) ) : array();
		$terms = is_wp_error( $terms ) ? array() : $terms;
		$pos   = array();
		foreach ( $terms as $i => $t ) {
			$o         = get_term_meta( $t->term_id, self::ORDER, true );
			$pos[ $i ] = array( '' === $o ? 99999 : (int) $o, $i );
		}
		uksort( $terms, function ( $x, $y ) use ( $pos ) {
			return $pos[ $x ] <=> $pos[ $y ];
		} );
		$terms = array_values( $terms );
		if ( is_array( self::$memo ) ) {
			self::$memo[ (int) $parent ] = $terms;
		}
		return $terms;
	}

	/** Save the current order of the children of $parent as 0..n. */
	private static function renumber( $parent ) {
		foreach ( self::children( $parent ) as $i => $t ) {
			update_term_meta( $t->term_id, self::ORDER, $i );
		}
	}

	/** Current category tree as profile-style data, any depth: [ { name, children: [ ... ] } ]. */
	public static function tree( $parent = 0 ) {
		$out = array();
		foreach ( self::children( $parent ) as $t ) {
			$k     = self::tree( $t->term_id );
			$out[] = $k ? array( 'name' => $t->name, 'children' => $k ) : array( 'name' => $t->name, 'children' => array() );
		}
		return $out;
	}

	/** Write the tree to uploads/directorist-network/categories.json. */
	public static function sync() {
		self::$dirty = false;
		$f = self::file();
		if ( '' === $f ) {
			return false;
		}
		wp_mkdir_p( dirname( $f ) );
		return false !== @file_put_contents( $f, wp_json_encode( self::tree(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/** The saved list when the admin has taken over the categories (edited or imported them), else null. */
	public static function stored() {
		if ( ! get_option( self::OPT_MANAGED ) ) {
			return null;
		}
		$f = self::file();
		$d = ( '' !== $f && is_readable( $f ) ) ? json_decode( (string) file_get_contents( $f ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions
		return is_array( $d ) ? $d : null;
	}

	private static function url() {
		return admin_url( 'edit.php?post_type=at_biz_dir&page=directorist-network-import' );
	}

	private static function done( array $log ) {
		update_option( self::OPT_MANAGED, 1, false );
		if ( ! self::sync() ) {
			$log[] = '!' . __( 'The category file could not be written (check that the uploads folder is writable).', 'directorist-network' );
		}
		set_transient( 'dirnet_cat_log_' . get_current_user_id(), $log, 120 );
		wp_safe_redirect( self::url() );
		exit;
	}

	/** Make a category usable in every directory type of this plugin. */
	public static function assign( $term_id ) {
		if ( ! function_exists( 'directorist_update_term_directory' ) ) {
			return;
		}
		$types = array_keys( Relations::registry() );
		if ( $types ) {
			directorist_update_term_directory( (int) $term_id, array_map( 'intval', $types ), true );
		}
	}

	/** Find or create a category by name under $parent; returns the term id (0 on error); $created counts new terms. */
	private static function ensure( $name, $parent, &$created ) {
		$name = trim( wp_strip_all_tags( $name ) );
		if ( '' === $name ) {
			return 0;
		}
		$e = term_exists( $name, ATBDP_CATEGORY, $parent );
		if ( $e ) {
			return (int) ( is_array( $e ) ? $e['term_id'] : $e );
		}
		$t = wp_insert_term( $name, ATBDP_CATEGORY, $parent ? array( 'parent' => $parent ) : array() );
		if ( is_wp_error( $t ) ) {
			return 0;
		}
		self::assign( $t['term_id'] );
		$created++;
		return (int) $t['term_id'];
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Categories and subcategories', 'directorist-network' ); ?></h1>
			<?php $this->body( false ); ?>
		</div>
		<?php
	}

	/** The content of the page; $nested = shown inside another page (subtitles one level down). */
	/** Import and export of the category list (CSV): on the Import / export page. */
	public function body_io( $nested = true, $part = 'all' ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$log = get_transient( 'dirnet_cat_log_' . get_current_user_id() );
		if ( $log ) {
			delete_transient( 'dirnet_cat_log_' . get_current_user_id() );
		}
		ob_start();
		?>
			<?php foreach ( (array) $log as $line ) : ?>
				<div class="notice notice-<?php echo ( 0 === strpos( $line, '!' ) ) ? 'warning' : 'success'; ?> is-dismissible"><p><?php echo esc_html( ltrim( $line, '!' ) ); ?></p></div>
			<?php endforeach; ?>
			<p class="description"><?php echo esc_html( sprintf( /* translators: %s: file path */ __( 'Every change is also saved to %s, and that list is the one used when you generate a profile.', 'directorist-network' ), str_replace( ABSPATH, '', self::file() ) ) ); ?></p>
			<p class="description"><?php esc_html_e( 'The list is yours to change at any time: add, rename or delete categories and subcategories. Deleting a category does not delete its entities; they just lose that category.', 'directorist-network' ); ?></p>

			<h2><?php esc_html_e( 'Categories import', 'directorist-network' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Columns: category, subcategory (the subcategory may be empty). Existing names are kept; only the missing ones are added. Header names in Italian (categoria, sottocategoria) work too.', 'directorist-network' ); ?></p>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dirnet_cat_import" />
				<?php wp_nonce_field( 'dirnet_cat_import' ); ?>
				<input type="file" name="csv" accept=".csv,text/csv" required />
				<?php submit_button( __( 'Import categories', 'directorist-network' ), 'primary', 'submit', false ); ?>
			</form>


			<!--dirnet-split-->
			<h2><?php esc_html_e( 'Categories export', 'directorist-network' ); ?></h2>
				<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dirnet_cat_export' ), 'dirnet_cat_export' ) ); ?>"><?php esc_html_e( 'Download current list (CSV)', 'directorist-network' ); ?></a>

		<?php
		$html = ob_get_clean();
		echo $nested ? str_replace( array( '<h2>', '</h2>' ), array( '<h3>', '</h3>' ), $html ) : $html; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** Move the entries to another category: on the Data repair page. */
	public function body_move( $nested = true ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$log = get_transient( 'dirnet_cat_log_' . get_current_user_id() );
		if ( $log ) {
			delete_transient( 'dirnet_cat_log_' . get_current_user_id() );
		}
		ob_start();
		?>
			<?php foreach ( (array) $log as $line ) : ?>
				<div class="notice notice-<?php echo ( 0 === strpos( $line, '!' ) ) ? 'warning' : 'success'; ?> is-dismissible"><p><?php echo esc_html( ltrim( $line, '!' ) ); ?></p></div>
			<?php endforeach; ?>
			<h2><?php esc_html_e( 'Move entities to another category', 'directorist-network' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Entities lose the "from" category and get the "to" one; their other categories stay. Only entities assigned directly to the category are moved (not those of its subcategories). The "from" category is not deleted. Back up first.', 'directorist-network' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dirnet_cat_move" />
				<?php wp_nonce_field( 'dirnet_cat_move' ); ?>
				<?php $mv = self::move_options(); ?>
				<?php foreach ( array( 'from' => __( 'From', 'directorist-network' ), 'to' => __( 'To', 'directorist-network' ) ) as $n => $l ) : ?>
					<label><?php echo esc_html( $l ); ?>: <select name="move_<?php echo esc_attr( $n ); ?>"><option value="">—</option>
					<?php foreach ( $mv as $id => $lab ) : ?>
						<option value="<?php echo (int) $id; ?>"><?php echo esc_html( $lab ); ?></option>
					<?php endforeach; ?>
					</select></label>
				<?php endforeach; ?>
				<button class="button" name="move_apply" value="0"><?php esc_html_e( 'Preview', 'directorist-network' ); ?></button>
				<button class="button button-primary" name="move_apply" value="1" onclick="return confirm('<?php echo esc_js( __( 'Move the entities? Back up first.', 'directorist-network' ) ); ?>')"><?php esc_html_e( 'Move', 'directorist-network' ); ?></button>
			</form>
		<?php
		$html = ob_get_clean();
		echo $nested ? str_replace( array( '<h2>', '</h2>' ), array( '<h3>', '</h3>' ), $html ) : $html; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** The whole content (the categories page itself). */
	public function body( $nested = true ) {
		$this->body_io( $nested );
		$this->body_move( $nested );
	}

	/** id => "Parent > Child (n)" for every category. */
	public static function move_options() {
		$terms = taxonomy_exists( ATBDP_CATEGORY ) ? get_terms( array( 'taxonomy' => ATBDP_CATEGORY, 'hide_empty' => false ) ) : array();
		$out   = array();
		global $wpdb;
		$n = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT tr.term_taxonomy_id AS tt, COUNT(*) AS c FROM {$wpdb->term_relationships} tr JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE p.post_type = %s AND p.post_status NOT IN ('trash','auto-draft') GROUP BY tr.term_taxonomy_id", ATBDP_POST_TYPE ) ) as $r ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$n[ (int) $r->tt ] = (int) $r->c;
		}
		foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) {
			$p = array( $t->name );
			$c = $t;
			while ( $c->parent && ( $c = get_term( $c->parent, ATBDP_CATEGORY ) ) && ! is_wp_error( $c ) ) {
				array_unshift( $p, $c->name );
			}
			$out[ $t->term_id ] = implode( ' > ', $p ) . ' (' . ( isset( $n[ $t->term_taxonomy_id ] ) ? (int) $n[ $t->term_taxonomy_id ] : 0 ) . ')';
		}
		asort( $out );
		return $out;
	}

	private static function move_ids( $term_id ) {
		return get_posts( array(
			'post_type' => ATBDP_POST_TYPE, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids',
			'tax_query' => array( array( 'taxonomy' => ATBDP_CATEGORY, 'field' => 'term_id', 'terms' => (int) $term_id, 'include_children' => false ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
		) );
	}

	public function handle_move() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'directorist-network' ) );
		}
		check_admin_referer( 'dirnet_cat_move' );
		$from = isset( $_POST['move_from'] ) ? (int) $_POST['move_from'] : 0;
		$to   = isset( $_POST['move_to'] ) ? (int) $_POST['move_to'] : 0;
		$log  = array();
		if ( ! $from || ! $to || $from === $to || ! term_exists( $from, ATBDP_CATEGORY ) || ! term_exists( $to, ATBDP_CATEGORY ) ) {
			$log[] = '!' . __( 'Choose two different categories.', 'directorist-network' );
		} else {
			$ids = self::move_ids( $from );
			$a   = get_term( $from, ATBDP_CATEGORY );
			$b   = get_term( $to, ATBDP_CATEGORY );
			if ( ! empty( $_POST['move_apply'] ) ) {
				foreach ( $ids as $pid ) {
					wp_remove_object_terms( $pid, $from, ATBDP_CATEGORY );
					wp_set_object_terms( $pid, $to, ATBDP_CATEGORY, true );
				}
				/* translators: 1: number, 2: from, 3: to */
				$log[] = sprintf( __( 'Moved %1$d entities from "%2$s" to "%3$s".', 'directorist-network' ), count( $ids ), $a->name, $b->name );
			} else {
				/* translators: 1: number, 2: from, 3: to */
				$log[] = '!' . sprintf( __( 'Preview: %1$d entities would be moved from "%2$s" to "%3$s". Nothing changed.', 'directorist-network' ), count( $ids ), $a->name, $b->name );
			}
		}
		set_transient( 'dirnet_cat_log_' . get_current_user_id(), $log, 120 );
		wp_safe_redirect( self::url() );
		exit;
	}

	/** Rows of the children of $parent, recursively, each followed by its "add subcategory" line. */
	private function rows( $parent, $depth ) {
		$list = self::children( $parent );
		foreach ( $list as $i => $t ) {
			$kids = self::children( $t->term_id );
			$this->row( $t, $depth, ! empty( $kids ), 0 === $i, count( $list ) - 1 === $i );
			$this->rows( $t->term_id, $depth + 1 );
			?>
			<tr>
				<td colspan="3" style="padding-left:<?php echo (int) ( 36 + $depth * 26 ); ?>px">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:6px">
						<input type="hidden" name="action" value="dirnet_cat" />
						<input type="hidden" name="op" value="add" />
						<input type="hidden" name="parent" value="<?php echo (int) $t->term_id; ?>" />
						<?php wp_nonce_field( 'dirnet_cat' ); ?>
						<input type="text" name="name" required placeholder="<?php echo esc_attr( sprintf( /* translators: %s: category name */ __( 'New subcategory of "%s"', 'directorist-network' ), $t->name ) ); ?>" style="min-width:280px" />
						<button class="button button-small"><?php esc_html_e( 'Add subcategory', 'directorist-network' ); ?></button>
					</form>
				</td>
			</tr>
			<?php
		}
	}

	/** Options for the "move under" menu: every category except $self and its descendants. */
	private function parent_options( $self, $current, $parent = 0, $depth = 0 ) {
		foreach ( self::children( $parent ) as $t ) {
			if ( (int) $t->term_id === (int) $self ) {
				continue;
			}
			printf( '<option value="%d"%s>%s%s</option>', (int) $t->term_id, selected( (int) $current, (int) $t->term_id, false ), esc_html( str_repeat( '— ', $depth ) ), esc_html( $t->name ) );
			$this->parent_options( $self, $current, $t->term_id, $depth + 1 );
		}
	}

	private function row( $t, $depth, $has_kids, $first = false, $last = false ) {
		?>
		<tr>
			<td style="padding-left:<?php echo (int) ( 10 + $depth * 26 ); ?>px">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:6px">
					<input type="hidden" name="action" value="dirnet_cat" />
					<input type="hidden" name="op" value="rename" />
					<input type="hidden" name="term_id" value="<?php echo (int) $t->term_id; ?>" />
					<?php wp_nonce_field( 'dirnet_cat' ); ?>
					<input type="text" name="name" value="<?php echo esc_attr( $t->name ); ?>" required style="min-width:320px;<?php echo $depth ? '' : 'font-weight:600'; ?>" />
					<button class="button button-small"><?php esc_html_e( 'Save', 'directorist-network' ); ?></button>
				</form>
			</td>
			<td><?php echo (int) $t->count; ?></td>
			<td style="white-space:nowrap">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-flex;gap:3px;align-items:center;margin-right:8px">
					<input type="hidden" name="action" value="dirnet_cat" />
					<input type="hidden" name="term_id" value="<?php echo (int) $t->term_id; ?>" />
					<?php wp_nonce_field( 'dirnet_cat' ); ?>
					<button class="button button-small" name="op" value="up" title="<?php esc_attr_e( 'Move up', 'directorist-network' ); ?>"<?php disabled( $first ); ?>>&uarr;</button>
					<button class="button button-small" name="op" value="down" title="<?php esc_attr_e( 'Move down', 'directorist-network' ); ?>"<?php disabled( $last ); ?>>&darr;</button>
					<select name="parent" onchange="var i=document.createElement('input');i.type='hidden';i.name='op';i.value='move';this.form.appendChild(i);this.form.submit();" title="<?php esc_attr_e( 'Move under…', 'directorist-network' ); ?>" style="max-width:150px">
						<option value="-1"><?php esc_html_e( 'Move under…', 'directorist-network' ); ?></option>
						<option value="0"><?php esc_html_e( '(top level)', 'directorist-network' ); ?></option>
						<?php $this->parent_options( $t->term_id, -1 ); ?>
					</select>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline"
					onsubmit="return confirm('<?php echo esc_js( $has_kids ? __( 'This category has subcategories: delete them first.', 'directorist-network' ) : __( 'Delete this category?', 'directorist-network' ) ); ?>');">
					<input type="hidden" name="action" value="dirnet_cat" />
					<input type="hidden" name="op" value="delete" />
					<input type="hidden" name="term_id" value="<?php echo (int) $t->term_id; ?>" />
					<?php wp_nonce_field( 'dirnet_cat' ); ?>
					<button class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'directorist-network' ); ?></button>
				</form>
			</td>
		</tr>
		<?php
	}

	public function handle() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'dirnet_cat' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'directorist-network' ) );
		}
		$op   = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$id   = isset( $_POST['term_id'] ) ? (int) $_POST['term_id'] : 0;
		$log  = array();
		if ( 'add' === $op ) {
			$parent = isset( $_POST['parent'] ) ? (int) $_POST['parent'] : 0;
			$n      = 0;
			if ( self::ensure( $name, $parent, $n ) && $n ) {
				$log[] = sprintf( /* translators: %s: name */ __( '"%s" added.', 'directorist-network' ), $name );
			} else {
				$log[] = '!' . sprintf( /* translators: %s: name */ __( '"%s" already exists here or could not be added.', 'directorist-network' ), $name );
			}
		} elseif ( in_array( $op, array( 'up', 'down' ), true ) && $id ) {
			$t = get_term( $id, ATBDP_CATEGORY );
			if ( $t && ! is_wp_error( $t ) ) {
				self::renumber( $t->parent );
				$ids = wp_list_pluck( self::children( $t->parent ), 'term_id' );
				$pos = array_search( $id, array_map( 'intval', $ids ), true );
				$to  = 'up' === $op ? $pos - 1 : $pos + 1;
				if ( false !== $pos && isset( $ids[ $to ] ) ) {
					update_term_meta( $ids[ $to ], self::ORDER, $pos );
					update_term_meta( $id, self::ORDER, $to );
				}
			}
			$log[] = __( 'Order saved.', 'directorist-network' );
		} elseif ( 'move' === $op && $id ) {
			$new = isset( $_POST['parent'] ) ? (int) $_POST['parent'] : -1;
			$t   = get_term( $id, ATBDP_CATEGORY );
			if ( $new < 0 || ! $t || is_wp_error( $t ) ) {
				$log[] = '!' . __( 'Nothing moved.', 'directorist-network' );
			} elseif ( $new === (int) $t->parent ) {
				$log[] = '!' . __( 'It is already there.', 'directorist-network' );
			} elseif ( $new && ( $new === $id || term_is_ancestor_of( $id, $new, ATBDP_CATEGORY ) ) ) {
				$log[] = '!' . __( 'A category cannot be moved inside itself.', 'directorist-network' );
			} elseif ( term_exists( $t->name, ATBDP_CATEGORY, $new ) ) {
				$log[] = '!' . __( 'A category with this name already exists there.', 'directorist-network' );
			} else {
				$old = (int) $t->parent;
				$r   = wp_update_term( $id, ATBDP_CATEGORY, array( 'parent' => $new ) );
				if ( is_wp_error( $r ) ) {
					$log[] = '!' . $r->get_error_message();
				} else {
					update_term_meta( $id, self::ORDER, 99999 );
					self::renumber( $new );
					self::renumber( $old );
					$log[] = sprintf( /* translators: %s: name */ __( '"%s" moved.', 'directorist-network' ), $t->name );
				}
			}
		} elseif ( 'rename' === $op && $id && '' !== $name ) {
			$r     = wp_update_term( $id, ATBDP_CATEGORY, array( 'name' => $name ) );
			$log[] = is_wp_error( $r ) ? '!' . $r->get_error_message() : sprintf( /* translators: %s: name */ __( 'Saved: "%s".', 'directorist-network' ), $name );
		} elseif ( 'delete' === $op && $id ) {
			$kids = get_terms( array( 'taxonomy' => ATBDP_CATEGORY, 'parent' => $id, 'hide_empty' => false, 'fields' => 'ids' ) );
			if ( ! is_wp_error( $kids ) && $kids ) {
				$log[] = '!' . __( 'This category has subcategories: delete them first.', 'directorist-network' );
			} else {
				$r     = wp_delete_term( $id, ATBDP_CATEGORY );
				$log[] = is_wp_error( $r ) || ! $r ? '!' . __( 'Could not delete the category.', 'directorist-network' ) : __( 'Category deleted.', 'directorist-network' );
			}
		}
		self::done( $log );
	}

	public function handle_import() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'dirnet_cat_import' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'directorist-network' ) );
		}
		$tmp = isset( $_FILES['csv']['tmp_name'] ) ? $_FILES['csv']['tmp_name'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! $tmp || ! is_uploaded_file( $tmp ) ) {
			self::done( array( '!' . __( 'No file received.', 'directorist-network' ) ) );
		}
		$created = 0;
		$rows    = self::parse( (string) file_get_contents( $tmp ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		foreach ( $rows as $r ) {
			$p = 0;
			foreach ( array_filter( array_map( 'trim', explode( '>', $r[0] ) ), 'strlen' ) as $step ) { // "Group > Category" paths: any depth.
				$p = self::ensure( $step, $p, $created );
			}
			if ( $p && '' !== $r[1] ) {
				self::ensure( $r[1], $p, $created );
			}
		}
		self::done( array( sprintf( /* translators: 1: rows, 2: new terms */ __( 'Import done: %1$d rows read, %2$d new categories or subcategories created.', 'directorist-network' ), count( $rows ), $created ) ) );
	}

	/** @return array<int,array{0:string,1:string}> [category, subcategory] rows from CSV text (comma or semicolon). */
	public static function parse( $text ) {
		$text = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $text );
		$lines = preg_split( '/\r\n|\r|\n/', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! $lines ) {
			return array();
		}
		$delim = substr_count( $lines[0], ';' ) > substr_count( $lines[0], ',' ) ? ';' : ',';
		$out   = array();
		$first = true;
		foreach ( $lines as $line ) {
			$c = str_getcsv( $line, $delim );
			if ( $first ) {
				$first = false;
				$h     = strtolower( trim( (string) $c[0] ) );
				if ( in_array( $h, array( 'category', 'categoria', 'categorie', 'categories' ), true ) ) {
					continue; // header row
				}
			}
			$cat = isset( $c[0] ) ? trim( (string) $c[0] ) : '';
			$sub = isset( $c[1] ) ? trim( (string) $c[1] ) : '';
			if ( '' !== $cat ) {
				$out[] = array( $cat, $sub );
			}
		}
		return $out;
	}

	public function handle_export() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'dirnet_cat_export' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'directorist-network' ) );
		}
		$terms = get_terms( array( 'taxonomy' => ATBDP_CATEGORY, 'hide_empty' => false, 'orderby' => 'name' ) );
		$kids  = array();
		foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) {
			$kids[ (int) $t->parent ][] = $t;
		}
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="categories.csv"' );
		$o = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $o, array( 'category', 'subcategory' ) );
		$walk = function ( $parent, $trail ) use ( &$walk, $kids, $o ) {
			foreach ( isset( $kids[ $parent ] ) ? $kids[ $parent ] : array() as $t ) {
				$path = array_merge( $trail, array( $t->name ) );
				if ( empty( $kids[ (int) $t->term_id ] ) ) {
					fputcsv( $o, count( $path ) > 1 ? array( implode( ' > ', array_slice( $path, 0, -1 ) ), $t->name ) : array( $t->name, '' ) );
				} else {
					$walk( (int) $t->term_id, $path );
				}
			}
		};
		$walk( 0, array() );
		fclose( $o ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
}
