<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin {

	public function boot() {
		if ( ! get_option( 'dirnet_sheet_opts_cleared' ) ) { // once: the two removed settings (categories with entity/product profiles, social report shortcode) go back to the defaults
			delete_option( 'dirnet_sheet_categories' );
			delete_option( 'dirnet_social_report_shortcode' );
			update_option( 'dirnet_sheet_opts_cleared', 1, false );
		}
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_dirnet_apply', array( $this, 'handle_apply' ) );
		add_action( 'admin_post_dirnet_geo_continue', array( $this, 'handle_geo_continue' ) );
		add_action( 'admin_post_dirnet_profile_upload', array( $this, 'handle_profile_upload' ) );
		add_action( 'admin_post_dirnet_profile_remove', array( $this, 'handle_profile_remove' ) );
		add_action( 'admin_post_dirnet_retype', array( $this, 'handle_retype' ) );
		add_action( 'add_meta_boxes', array( $this, 'type_box' ) );
		add_action( 'save_post', array( $this, 'type_save' ), 20, 2 );
		add_action( 'admin_post_dirnet_profile_export', array( $this, 'handle_profile_export' ) );
		add_action( 'admin_post_dirnet_download', array( $this, 'handle_download' ) );
		add_action( 'admin_post_dirnet_import_start', array( $this, 'handle_import_start' ) );
		add_action( 'admin_post_dirnet_import_step', array( $this, 'handle_import_step' ) );
		add_action( 'admin_post_dirnet_template', array( $this, 'handle_template' ) );
		add_action( 'admin_post_dirnet_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_dirnet_resolve', array( $this, 'handle_resolve' ) );
		add_action( 'admin_footer-post.php', array( $this, 'no_browser_validation' ) );
		add_action( 'admin_footer-post-new.php', array( $this, 'no_browser_validation' ) );
	}

	/**
	 * On the listing edit screen the browser must not block "Update": the type's required fields (category, consent
	 * checkbox) are meant for the public form, and a required field left empty in a closed box stops the submit silently.
	 */
	public function no_browser_validation() {
		if ( ! defined( 'ATBDP_POST_TYPE' ) || get_post_type() !== ATBDP_POST_TYPE ) {
			return;
		}
		echo '<script>(function(){var f=document.getElementById("post");if(f){f.setAttribute("novalidate","novalidate");}})();</script>';
	}

	public function menu() {
		add_submenu_page(
			'edit.php?post_type=at_biz_dir',
			__( 'Quick setup', 'directorist-network' ),
			__( 'Network setup', 'directorist-network' ),
			'manage_options',
			'directorist-network-setup',
			array( $this, 'render_setup' )
		);
		add_submenu_page(
			'edit.php?post_type=at_biz_dir',
			__( 'Import and export (CSV)', 'directorist-network' ),
			__( 'Import / export data', 'directorist-network' ),
			'manage_options',
			'directorist-network-import',
			array( $this, 'render_import' )
		);
	}

	public function render_setup() {
		echo '<div class="wrap"><h1>' . esc_html__( 'Configuration', 'directorist-network' ) . '</h1></div>';
		if ( get_option( 'dirnet_geo_seed_pending' ) ) {
			$go = wp_nonce_url( admin_url( 'admin-post.php?action=dirnet_geo_continue' ), 'dirnet_geo_continue' );
			echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Loading regions and provinces from GitHub…', 'directorist-network' ) . '</strong> ' . esc_html__( 'The page continues by itself; do not close it.', 'directorist-network' ) . ' <a href="' . esc_url( $go ) . '">' . esc_html__( 'Continue now', 'directorist-network' ) . '</a></p></div><script>setTimeout(function(){location.href=' . wp_json_encode( $go ) . ';},1500);</script>';
		}
		$this->render( true );
		echo '<div class="wrap"><hr /><h2>' . esc_html__( 'Demo data', 'directorist-network' ) . '</h2>';
		DemoData::panel();
		echo '</div>';
	}

	public function render( $embedded = false ) {
		$profiles = Profile::all();
		$applied  = Generator::applied();
		$ready    = Generator::directorist_ready();
		$log      = get_transient( 'dirnet_log_' . get_current_user_id() );
		if ( $log ) {
			delete_transient( 'dirnet_log_' . get_current_user_id() );
		}
		?>
		<div class="wrap">
			<?php if ( ! $embedded ) : ?><h1><?php esc_html_e( 'Configuration', 'directorist-network' ); ?></h1><?php endif; ?>
			<h2><?php esc_html_e( 'Initial setup', 'directorist-network' ); ?></h2>
			<p><?php esc_html_e( 'Tick the profiles to use, choose the site options and press Generate. Catalogue types, fields, search filters, categories, tags, territory page and menu are created and Directorist is configured.', 'directorist-network' ); ?></p>

			<?php if ( ! $ready ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Directorist (with the Directory Builder) is not active. Activate it first.', 'directorist-network' ); ?></p></div>
			<?php endif; ?>

			<?php if ( $log ) : ?>
				<div class="notice notice-info"><ul>
				<?php foreach ( $log as $line ) : ?>
					<li><?php echo ( '!' === substr( $line, 0, 1 ) ) ? '<strong>' . esc_html( substr( $line, 1 ) ) . '</strong>' : esc_html( $line ); ?></li>
				<?php endforeach; ?>
				</ul></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:1100px;">
				<?php wp_nonce_field( 'dirnet_apply' ); ?>
				<input type="hidden" name="action" value="dirnet_apply" />
				<?php foreach ( $profiles as $id => $p ) :
					$any     = ! empty( $applied[ $id ] );
					$checked = $any ? false : ( 'solidarity-economy' === $id );
					if ( $any && 'solidarity-economy' === $id ) {
						$checked = true;
					}
					?>
				<div style="margin:0 0 10px;">
					<label><input type="checkbox" name="profiles[]" value="<?php echo esc_attr( $id ); ?>" <?php checked( $checked ); ?> />
					<strong><?php echo esc_html( $p->label() ); ?></strong> <code><?php echo esc_html( $id ); ?>.json</code><?php if ( Profile::is_custom( $id ) ) : ?> <small>(<?php esc_html_e( 'uploaded', 'directorist-network' ); ?>)</small><?php endif; ?></label>
					<p class="description" style="margin:4px 0 4px 24px;"><?php echo esc_html( $p->description() ); ?></p>
					<ul style="list-style:disc;margin:0 0 4px 42px;">
					<?php foreach ( $p->types() as $slug => $def ) : ?>
						<li><?php echo esc_html( $p->t( isset( $def['label'] ) ? $def['label'] : $slug ) ); ?>
						<?php if ( ! empty( $applied[ $id ][ $slug ] ) ) : ?><em>(<?php esc_html_e( 'generated', 'directorist-network' ); ?>)</em><?php endif; ?>
						 &nbsp;<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dirnet_download&profile=' . rawurlencode( $id ) . '&type=' . rawurlencode( $slug ) ), 'dirnet_dl_' . $id . $slug ) ); ?>">JSON</a></li>
					<?php endforeach; ?>
					</ul>
				</div>
				<?php endforeach; ?>
				<h3><?php esc_html_e( 'Site', 'directorist-network' ); ?></h3>
				<p><label><input type="checkbox" name="remove_general" value="1" checked /> <?php esc_html_e( 'Delete the starter "General" catalogue type (only if it has no listings)', 'directorist-network' ); ?></label></p>
				<p><label><input type="checkbox" name="self_register" value="1" checked /> <?php esc_html_e( 'Let entities register themselves from the website (entries are held for your approval)', 'directorist-network' ); ?></label></p>
				<p><label><input type="checkbox" name="osm_map" value="1" checked /> <?php esc_html_e( 'Use OpenStreetMap (no API key)', 'directorist-network' ); ?></label></p>
				<p><label><input type="checkbox" name="menu" value="1" checked /> <?php esc_html_e( 'Create a "Catalogue" menu with the main pages', 'directorist-network' ); ?></label></p>
				<?php $dis = $ready ? array() : array( 'disabled' => 'disabled' ); ?>
				<p>
				<?php submit_button( __( 'Generate', 'directorist-network' ), 'primary', 'op', false, array_merge( $dis, array( 'value' => 'generate' ) ) ); ?>
				<?php submit_button( __( 'Regenerate missing types', 'directorist-network' ), 'secondary', 'op', false, array_merge( $dis, array( 'value' => 'missing' ) ) ); ?>
				<?php submit_button( __( 'Restore defaults', 'directorist-network' ), 'secondary', 'op', false, $ready ? array( 'value' => 'reset', 'onclick' => "return confirm('" . esc_js( __( 'This overwrites the fields and layouts of the selected catalogue types, including changes you made in the Directory Builder. Continue?', 'directorist-network' ) ) . "');" ) : $dis ); ?>
				</p>
				<p class="description"><?php esc_html_e( 'Generate: creates the selected profiles not generated yet. Regenerate missing types: creates only the types that do not exist yet, the others are left untouched. Restore defaults: overwrites the selected types with the profile defaults. Fields and layouts can be changed in Directory Builder (Directory → Directory Types).', 'directorist-network' ); ?></p>
			</form>
			<hr />
			<h2><?php esc_html_e( 'New profile', 'directorist-network' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Add a profile, or replace an existing one, by uploading its JSON file.', 'directorist-network' ); ?></p>
			<div class="card" style="max-width:1100px;margin:0;padding:12px 16px;">
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<?php wp_nonce_field( 'dirnet_profile_upload' ); ?>
					<input type="hidden" name="action" value="dirnet_profile_upload" />
					<table class="form-table"><tbody>
						<tr><th><?php esc_html_e( 'Profile file (profile.json)', 'directorist-network' ); ?></th><td><input type="file" name="profile_json" accept=".json,application/json" required /></td></tr>
						<tr><th><?php esc_html_e( 'Profile ID', 'directorist-network' ); ?></th><td><input type="text" name="profile_id" class="regular-text" placeholder="solidarity-economy" />
							<p class="description"><?php esc_html_e( 'Letters, numbers and dashes. Leave empty to use the "id" inside the file. An ID that already exists (also a bundled one) is replaced by the uploaded file.', 'directorist-network' ); ?></p></td></tr>
						<tr><th><?php esc_html_e( 'Translations (optional)', 'directorist-network' ); ?></th><td><input type="file" name="i18n_json" accept=".json,application/json" />
							<p class="description"><?php esc_html_e( 'JSON object: English text => translation, for the current site language.', 'directorist-network' ); ?></p></td></tr>
					</tbody></table>
					<p class="description"><?php esc_html_e( 'The profile is saved in wp-content/uploads/directorist-network/profiles/ and survives plugin updates. Then press Generate, or Generate missing types if the profile was already generated.', 'directorist-network' ); ?></p>
					<?php submit_button( __( 'Upload profile', 'directorist-network' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>
			<hr />
			<h2><?php esc_html_e( 'Delete a profile', 'directorist-network' ); ?></h2>
			<p class="description"><?php esc_html_e( 'The profile is removed from the list. Catalogue types already generated are not deleted.', 'directorist-network' ); ?></p>
			<?php foreach ( $profiles as $id => $p ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 8px;">
				<?php wp_nonce_field( 'dirnet_profile_remove_' . $id ); ?>
				<input type="hidden" name="action" value="dirnet_profile_remove" />
				<input type="hidden" name="profile" value="<?php echo esc_attr( $id ); ?>" />
				<strong><?php echo esc_html( $p->label() ); ?></strong> <code><?php echo esc_html( $id ); ?>.json</code>
				<?php submit_button( __( 'Delete', 'directorist-network' ), 'link-delete', 'submit', false, array( 'onclick' => "return confirm('" . esc_js( __( 'Delete this profile? Catalogue types already generated are not deleted.', 'directorist-network' ) ) . "');" ) ); ?>
			</form>
			<?php endforeach; ?>
		</div>
		<?php
	}

	public function handle_apply() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'dirnet_apply' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'directorist-network' ) );
		}
		$ids = isset( $_POST['profiles'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['profiles'] ) ) : array();
		$op  = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : 'generate';
		if ( ! $ids ) {
			$log = array( '!' . __( 'Tick at least one profile.', 'directorist-network' ) );
		} else {
			$log = Setup::run( $ids, array(
				'remove_general' => ! empty( $_POST['remove_general'] ),
				'osm_map'        => ! empty( $_POST['osm_map'] ),
				'self_register'  => ! empty( $_POST['self_register'] ),
				'menu'           => ! empty( $_POST['menu'] ),
				'overwrite'      => 'reset' === $op,
				'skip_applied'   => 'generate' === $op,
			) );
		}
		set_transient( 'dirnet_log_' . get_current_user_id(), $log, 120 );
		wp_safe_redirect( admin_url( 'edit.php?post_type=at_biz_dir&page=directorist-network-setup&section=profiles' ) );
		exit;
	}

	/** Next slice of the territories load started by the setup. */
	public function handle_geo_continue() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'dirnet_geo_continue' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'directorist-network' ) );
		}
		$r = Territories::seed_geo( 20 );
		if ( $r[0] ) {
			delete_option( 'dirnet_geo_seed_pending' );
		}
		set_transient( 'dirnet_log_' . get_current_user_id(), array( $r[3] ), 120 );
		wp_safe_redirect( admin_url( 'edit.php?post_type=at_biz_dir&page=directorist-network-setup&section=profiles' ) );
		exit;
	}

	/** Save an uploaded profile.json (and optional translations) in the uploads folder. */
	public function handle_profile_upload() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'dirnet_profile_upload' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'directorist-network' ) );
		}
		$log  = array();
		$file = isset( $_FILES['profile_json'] ) ? $_FILES['profile_json'] : null; // phpcs:ignore WordPress.Security
		if ( ! $file || empty( $file['tmp_name'] ) || UPLOAD_ERR_OK !== (int) $file['error'] || (int) $file['size'] > 524288 ) {
			$log[] = '!' . __( 'No valid file received (JSON, up to 512 KB).', 'directorist-network' );
		} else {
			$data = json_decode( (string) file_get_contents( $file['tmp_name'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$id   = isset( $_POST['profile_id'] ) ? sanitize_key( wp_unslash( $_POST['profile_id'] ) ) : '';
			if ( '' === $id && is_array( $data ) && ! empty( $data['id'] ) && is_string( $data['id'] ) ) {
				$id = sanitize_key( $data['id'] );
			}
			$ok = is_array( $data ) && ! empty( $data['types'] ) && is_array( $data['types'] );
			if ( $ok ) {
				foreach ( $data['types'] as $t ) {
					if ( ! is_array( $t ) || empty( $t['label'] ) || ! is_string( $t['label'] ) ) {
						$ok = false;
						break;
					}
				}
			}
			$base = Profile::custom_base();
			if ( ! $ok ) {
				$log[] = '!' . __( 'The file is not a valid profile: it needs a non-empty "types" list, each type with a "label".', 'directorist-network' );
			} elseif ( '' === $id || 0 === strpos( $id, '_' ) ) {
				$log[] = '!' . __( 'Choose a profile ID (letters, numbers, dashes).', 'directorist-network' );
			} elseif ( '' === $base || ! wp_mkdir_p( $base . $id . '/i18n' ) ) {
				$log[] = '!' . __( 'Could not write to the uploads folder.', 'directorist-network' );
			} else {
				// Written back from the decoded data, never copied as-is.
				Profile::undelete( $id );
				file_put_contents( $base . $id . '/profile.json', wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$log[] = sprintf( /* translators: %s: profile id */ __( 'Profile saved: %s.', 'directorist-network' ), $id );
				$tr = isset( $_FILES['i18n_json'] ) ? $_FILES['i18n_json'] : null; // phpcs:ignore WordPress.Security
				if ( $tr && ! empty( $tr['tmp_name'] ) && UPLOAD_ERR_OK === (int) $tr['error'] && (int) $tr['size'] <= 524288 ) {
					$d = json_decode( (string) file_get_contents( $tr['tmp_name'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
					if ( is_array( $d ) ) {
						$clean = array();
						foreach ( $d as $k => $v ) {
							if ( is_string( $k ) && is_string( $v ) ) {
								$clean[ $k ] = $v;
							}
						}
						$loc = preg_replace( '/[^A-Za-z_]/', '', function_exists( 'determine_locale' ) ? determine_locale() : get_locale() );
						file_put_contents( $base . $id . '/i18n/' . $loc . '.json', wp_json_encode( $clean, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
						$log[] = sprintf( /* translators: %s: locale */ __( 'Translations saved (%s).', 'directorist-network' ), $loc );
					} else {
						$log[] = '!' . __( 'The translations file is not valid JSON; it was ignored.', 'directorist-network' );
					}
				}
			}
		}
		set_transient( 'dirnet_log_' . get_current_user_id(), $log, 120 );
		wp_safe_redirect( admin_url( 'edit.php?post_type=at_biz_dir&page=directorist-network-setup&section=profiles' ) );
		exit;
	}

	/** Delete a profile: its uploaded copy is removed and the profile is hidden from the list. */
	public function handle_profile_remove() {
		$id = isset( $_POST['profile'] ) ? sanitize_key( wp_unslash( $_POST['profile'] ) ) : '';
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'dirnet_profile_remove_' . $id ) ) {
			wp_die( esc_html__( 'Permission denied.', 'directorist-network' ) );
		}
		$base = Profile::custom_base();
		$log  = array();
		if ( '' !== $id && '' !== $base && is_dir( $base . $id ) ) {
			foreach ( (array) glob( $base . $id . '/i18n/*.json' ) as $f ) {
				@unlink( $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			}
			@unlink( $base . $id . '/profile.json' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			@rmdir( $base . $id . '/i18n' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			@rmdir( $base . $id ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
		if ( '' !== $id ) {
			Profile::delete_listed( $id ); // a profile of the plugin is hidden too, so it is really gone from the list
			$log[] = sprintf( /* translators: %s: profile id */ __( 'Profile deleted: %s.', 'directorist-network' ), $id );
		}
		set_transient( 'dirnet_log_' . get_current_user_id(), $log, 120 );
		wp_safe_redirect( admin_url( 'edit.php?post_type=at_biz_dir&page=directorist-network-setup&section=profiles' ) );
		exit;
	}

	/** Move the entries of the chosen categories to another directory type (preview or apply). */
	public function handle_retype() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'dirnet_retype' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'directorist-network' ) );
		}
		$cats  = isset( $_POST['cats'] ) ? array_filter( array_map( 'intval', (array) wp_unslash( $_POST['cats'] ) ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$roles = isset( $_POST['roles'] ) ? array_filter( array_map( 'intval', (array) wp_unslash( $_POST['roles'] ) ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$to    = isset( $_POST['to'] ) ? (int) $_POST['to'] : 0;
		$apply = ! empty( $_POST['apply'] );
		$log   = array();
		$term  = $to ? get_term( $to, 'atbdp_listing_types' ) : null;
		if ( ( ! $cats && ! $roles ) || ! $term || is_wp_error( $term ) ) {
			$log[] = '!' . __( 'Choose at least one role or category and the new catalogue type.', 'directorist-network' );
		} else {
			$tq = array( 'relation' => 'OR' );
			if ( $cats ) {
				$tq[] = array( 'taxonomy' => ATBDP_CATEGORY, 'field' => 'term_id', 'terms' => $cats, 'include_children' => ! empty( $_POST['children'] ) );
			}
			if ( $roles ) {
				$tq[] = array( 'taxonomy' => Functions::TAX, 'field' => 'term_id', 'terms' => $roles, 'include_children' => ! empty( $_POST['children'] ) );
			}
			$ids = get_posts( array(
				'post_type'      => ATBDP_POST_TYPE,
				'post_status'    => array( 'publish', 'pending', 'draft', 'private', 'future' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'tax_query'      => $tq, // phpcs:ignore WordPress.DB.SlowDBQuery
			) );
			$n = 0;
			foreach ( $ids as $id ) {
				if ( (int) get_post_meta( $id, '_directory_type', true ) === $to ) {
					continue;
				}
				$n++;
				if ( $apply ) {
					update_post_meta( $id, '_directory_type', $to );
					wp_set_object_terms( $id, $to, 'atbdp_listing_types' );
				} elseif ( $n <= 30 ) {
					$log[] = get_the_title( $id );
				}
			}
			array_unshift( $log, sprintf( /* translators: 1: number of entries, 2: type name */ $apply ? __( 'Moved to "%2$s": %1$d entries.', 'directorist-network' ) : __( 'Preview: %1$d entries would move to "%2$s" (first 30 listed). Nothing was changed.', 'directorist-network' ), $n, $term->name ) );
		}
		set_transient( 'dirnet_log_' . get_current_user_id(), $log, 120 );
		wp_safe_redirect( admin_url( 'admin.php?page=dirnet-repairs' ) );
		exit;
	}

	/** Box in the side column of the entry: change its directory type (administrators). */
	public function type_box() {
		if ( defined( 'ATBDP_POST_TYPE' ) && current_user_can( 'manage_options' ) ) {
			add_meta_box( 'dirnet_dirtype', __( 'Catalogue type', 'directorist-network' ), array( $this, 'type_box_html' ), ATBDP_POST_TYPE, 'side', 'high' );
		}
	}

	public function type_box_html( $post ) {
		$cur = (int) get_post_meta( $post->ID, '_directory_type', true );
		wp_nonce_field( 'dirnet_dirtype_' . $post->ID, 'dirnet_dirtype_nonce' );
		echo '<p><select name="dirnet_dirtype" style="width:100%">';
		foreach ( (array) get_terms( array( 'taxonomy' => 'atbdp_listing_types', 'hide_empty' => false ) ) as $t ) {
			if ( is_object( $t ) ) {
				echo '<option value="' . (int) $t->term_id . '"' . selected( $cur, (int) $t->term_id, false ) . '>' . esc_html( $t->name ) . '</option>';
			}
		}
		echo '</select></p><p class="description">' . esc_html__( 'After saving, the fields of the new type are shown. Fields that the new type does not have stay stored but hidden. Roles and categories do not change.', 'directorist-network' ) . '</p>';
	}

	public function type_save( $post_id, $post ) {
		if ( ! defined( 'ATBDP_POST_TYPE' ) || ATBDP_POST_TYPE !== $post->post_type || ! isset( $_POST['dirnet_dirtype'], $_POST['dirnet_dirtype_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dirnet_dirtype_nonce'] ) ), 'dirnet_dirtype_' . $post_id ) || ! current_user_can( 'manage_options' ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$to = (int) $_POST['dirnet_dirtype'];
		if ( $to && $to !== (int) get_post_meta( $post_id, '_directory_type', true ) && get_term( $to, 'atbdp_listing_types' ) && ! is_wp_error( get_term( $to, 'atbdp_listing_types' ) ) ) {
			update_post_meta( $post_id, '_directory_type', $to );
			wp_set_object_terms( $post_id, $to, 'atbdp_listing_types' );
		}
	}

	/** Download the generated JSON so it can also be imported by hand in the Directory Builder. */
	public function handle_profile_export() {
		$id = isset( $_GET['profile'] ) ? sanitize_key( wp_unslash( $_GET['profile'] ) ) : '';
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'dirnet_pexp_' . $id ) ) {
			wp_die( esc_html__( 'Permission denied.', 'directorist-network' ) );
		}
		$file = '';
		foreach ( Profile::dirs() as $base ) { // the uploaded copy comes last and wins
			if ( is_readable( $base . $id . '/profile.json' ) ) {
				$file = $base . $id . '/profile.json';
			}
		}
		if ( '' === $id || '' === $file ) {
			wp_die( esc_html__( 'Nothing to download.', 'directorist-network' ) );
		}
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $id . '.json"' );
		echo file_get_contents( $file ); // phpcs:ignore WordPress.Security.EscapeOutput, WordPress.WP.AlternativeFunctions
		exit;
	}

	public function handle_download() {
		$id   = isset( $_GET['profile'] ) ? sanitize_key( wp_unslash( $_GET['profile'] ) ) : '';
		$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'dirnet_dl_' . $id . $type ) ) {
			wp_die( esc_html__( 'Permission denied.', 'directorist-network' ) );
		}
		$p   = Profile::get( $id );
		$cfg = ( $p && defined( 'DIRECTORIST_ASSETS_DIR' ) ) ? ( new DirectoryBuilder( DIRECTORIST_ASSETS_DIR ) )->build( $p, $type ) : null;
		if ( ! $cfg ) {
			wp_die( esc_html__( 'Nothing to download.', 'directorist-network' ) );
		}
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $id . '-' . $type . '.json"' );
		echo wp_json_encode( $cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/* ------------------------------------------------------------------ CSV import */

	private static function import_dir() {
		$up  = wp_upload_dir( null, false );
		$dir = trailingslashit( $up['basedir'] ) . 'directorist-network/tmp/';
		wp_mkdir_p( $dir );
		if ( ! file_exists( $dir . 'index.php' ) ) {
			file_put_contents( $dir . 'index.php', "<?php // silence\n" );
			file_put_contents( $dir . '.htaccess', "Deny from all\n" );
		}
		return $dir;
	}

	private static function state_key() {
		return 'dirnet_imp_' . get_current_user_id();
	}

	public function render_import() {
		$profiles = Profile::all();
		$applied  = Generator::applied();
		$state    = get_option( self::state_key() );
		$report   = get_transient( 'dirnet_imp_report_' . get_current_user_id() );
		?>
		<div class="wrap">
			<?php $tab = ( isset( $_GET['tab'] ) && 'export' === sanitize_key( wp_unslash( $_GET['tab'] ) ) ) ? 'export' : 'import'; // phpcs:ignore WordPress.Security.NonceVerification ?>
			<h1><?php esc_html_e( 'Import / export data', 'directorist-network' ); ?></h1>
			<nav class="nav-tab-wrapper">
				<a class="nav-tab<?php echo 'import' === $tab ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'edit.php?post_type=at_biz_dir&page=directorist-network-import&tab=import' ) ); ?>"><?php esc_html_e( 'Import', 'directorist-network' ); ?></a>
				<a class="nav-tab<?php echo 'export' === $tab ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'edit.php?post_type=at_biz_dir&page=directorist-network-import&tab=export' ) ); ?>"><?php esc_html_e( 'Export', 'directorist-network' ); ?></a>
			</nav>
			<?php if ( 'import' === $tab ) : ?>
			<h2><?php esc_html_e( 'Import entries', 'directorist-network' ); ?></h2>

			<?php
			$log = get_transient( 'dirnet_log_' . get_current_user_id() );
			if ( $log ) :
				delete_transient( 'dirnet_log_' . get_current_user_id() );
				?>
				<div class="notice notice-error"><ul><?php foreach ( $log as $line ) : ?><li><?php echo esc_html( ltrim( $line, '!' ) ); ?></li><?php endforeach; ?></ul></div>
			<?php endif; ?>

			<?php if ( $report ) : ?>
				<div class="notice notice-success"><p><strong><?php echo esc_html( sprintf( /* translators: 1: created, 2: updated, 3: errors */ __( 'Import finished: %1$d created, %2$d updated, %3$d errors.', 'directorist-network' ), $report['created'], $report['updated'], count( $report['errors'] ) ) . ( ! empty( $report['skipped'] ) ? ' ' . sprintf( /* translators: %d: number of entities */ __( '%d left untouched because edited by their owner.', 'directorist-network' ), $report['skipped'] ) : '' ) ); ?></strong></p>
				<?php if ( $report['errors'] ) : ?><ul style="margin-left:18px;list-style:disc;"><?php foreach ( array_slice( $report['errors'], 0, 50 ) as $e ) : ?><li><?php echo esc_html( $e ); ?></li><?php endforeach; ?></ul><?php endif; ?>
				</div>
				<?php delete_transient( 'dirnet_imp_report_' . get_current_user_id() ); ?>
			<?php endif; ?>

			<?php if ( is_array( $state ) ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'An import is in progress.', 'directorist-network' ); ?>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dirnet_import_step' ), 'dirnet_import_step' ) ); ?>"><?php esc_html_e( 'Continue', 'directorist-network' ); ?></a></p></div>
			<?php endif; ?>

			<?php
			$options = array();
			foreach ( $applied as $pid => $types ) {
				if ( empty( $profiles[ $pid ] ) ) {
					continue;
				}
				foreach ( (array) $types as $slug => $term_id ) {
					$def = $profiles[ $pid ]->types();
					if ( isset( $def[ $slug ] ) ) {
						$options[ $pid . '|' . $slug ] = $profiles[ $pid ]->label() . ' — ' . $profiles[ $pid ]->t( isset( $def[ $slug ]['label'] ) ? $def[ $slug ]['label'] : $slug );
					}
				}
			}
			if ( ! $options ) :
				?>
				<p><?php esc_html_e( 'Generate a profile first (Site profile), then import its data here.', 'directorist-network' ); ?></p>
			<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<?php wp_nonce_field( 'dirnet_import_start' ); ?>
				<input type="hidden" name="action" value="dirnet_import_start" />
				<table class="form-table"><tbody>
					<tr><th><?php esc_html_e( 'Catalogue type', 'directorist-network' ); ?></th><td>
						<select name="target"><?php foreach ( $options as $v => $l ) : ?><option value="<?php echo esc_attr( $v ); ?>"><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select>
						<?php foreach ( $options as $v => $l ) : list( $pid, $slug ) = explode( '|', $v ); ?>
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dirnet_template&profile=' . rawurlencode( $pid ) . '&type=' . rawurlencode( $slug ) ), 'dirnet_tpl_' . $pid . $slug ) ); ?>" style="margin-left:10px;"><?php echo esc_html( sprintf( /* translators: %s: type label */ __( 'CSV template: %s', 'directorist-network' ), $l ) ); ?></a>
						<?php endforeach; ?>
					</td></tr>
					<tr><th><?php esc_html_e( 'CSV file', 'directorist-network' ); ?></th><td><input type="file" name="csv" accept=".csv,text/csv" required />
						<p class="description"><?php esc_html_e( 'Comma or semicolon separated, UTF-8. Header names: the field keys, or the field labels in English or in the site language. Categories and locations: separate several with ";" and write a hierarchy as "Parent > Child". The file is deleted when the import ends.', 'directorist-network' ); ?></p></td></tr>
					<tr><th><?php esc_html_e( 'Listing status', 'directorist-network' ); ?></th><td><select name="status"><option value="publish"><?php esc_html_e( 'Published', 'directorist-network' ); ?></option><option value="draft"><?php esc_html_e( 'Draft', 'directorist-network' ); ?></option></select></td></tr>
					<tr><th><?php esc_html_e( 'Options', 'directorist-network' ); ?></th><td>
						<label><input type="checkbox" name="create_terms" value="1" checked /> <?php esc_html_e( 'Create missing categories, locations and tags', 'directorist-network' ); ?></label><br />
						<label><input type="checkbox" name="images" value="1" /> <?php esc_html_e( 'Download images from the image column (URLs)', 'directorist-network' ); ?></label><br />
						<label><input type="checkbox" name="overwrite" value="1" /> <?php esc_html_e( 'Also overwrite entities already edited by their owner', 'directorist-network' ); ?></label><br />
						<label><input type="checkbox" name="link_only" value="1" /> <?php esc_html_e( 'Do not change entities already present: only add the relations (e.g. the campaign)', 'directorist-network' ); ?></label><br />
						<label><input type="checkbox" name="geocode" value="1" /> <?php esc_html_e( 'Find coordinates for rows without them (OpenStreetMap Nominatim, about 1 row per second)', 'directorist-network' ); ?></label>
					</td></tr>
				</tbody></table>
				<?php submit_button( __( 'Import', 'directorist-network' ) ); ?>
			</form>
			<?php endif; ?>

			<?php if ( $options ) : ?>
			<hr />
			<h2><?php esc_html_e( 'Entry relations', 'directorist-network' ); ?></h2>
			<p><?php esc_html_e( 'Relation columns in a CSV list the key (or title) of the related listings. Those not found at import time stay pending; run this after importing the other types.', 'directorist-network' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'dirnet_resolve' ); ?>
				<input type="hidden" name="action" value="dirnet_resolve" />
				<?php submit_button( __( 'Resolve pending relations', 'directorist-network' ), 'secondary', 'submit', false ); ?>
			</form>
			<?php endif; ?>
			<hr />
			<?php ( new Categories() )->body_io( false, 'import' ); ?>
			<?php else : ?>
			<?php if ( $options ) : ?>
			<h2><?php esc_html_e( 'Export entries', 'directorist-network' ); ?></h2>
			<p><?php esc_html_e( 'Download all the entities of a catalogue type as a CSV file with the same columns as the import: edit it and import it again to update them.', 'directorist-network' ); ?></p>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'dirnet_export' ); ?>
				<input type="hidden" name="action" value="dirnet_export" />
				<select name="target"><?php foreach ( $options as $v => $l ) : ?><option value="<?php echo esc_attr( $v ); ?>"><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select>
				<?php submit_button( __( 'Export CSV', 'directorist-network' ), 'secondary', 'submit', false ); ?>
			</form>
			<?php endif; ?>

			<hr />
			<h2><?php esc_html_e( 'Profiles export (JSON)', 'directorist-network' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Download the profile file (profile.json) as it is used by the site: edit it and upload it again in Import.', 'directorist-network' ); ?></p>
			<ul style="list-style:disc;margin-left:18px;">
			<?php foreach ( $profiles as $pid => $pp ) : ?>
				<li><?php echo esc_html( $pp->label() ); ?> — <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dirnet_profile_export&profile=' . rawurlencode( $pid ) ), 'dirnet_pexp_' . $pid ) ); ?>"><?php echo esc_html( $pid ); ?>.json</a></li>
			<?php endforeach; ?>
			</ul>
			<hr />
			<?php ( new Categories() )->body_io( false, 'export' ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	public function handle_resolve() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'dirnet_resolve' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'directorist-network' ) );
		}
		$res = array( 'created' => 0, 'updated' => 0, 'errors' => array() );
		$n   = 0;
		foreach ( Profile::all() as $p ) {
			$r  = Relations::resolve_all( $p );
			$n += $r['resolved'];
			foreach ( $r['unresolved'] as $u ) {
				$res['errors'][] = sprintf( /* translators: %s: relation that could not be resolved */ __( 'Relation not resolved: %s', 'directorist-network' ), $u );
			}
		}
		array_unshift( $res['errors'], sprintf( /* translators: %d: number of relations */ __( '%d relations resolved.', 'directorist-network' ), $n ) );
		set_transient( 'dirnet_imp_report_' . get_current_user_id(), $res, 300 );
		wp_safe_redirect( admin_url( 'edit.php?post_type=at_biz_dir&page=directorist-network-import' ) );
		exit;
	}

	public function handle_import_start() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'dirnet_import_start' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'directorist-network' ) );
		}
		$back   = admin_url( 'edit.php?post_type=at_biz_dir&page=directorist-network-import' );
		$target = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : '';
		list( $pid, $slug ) = array_pad( explode( '|', $target ), 2, '' );
		$profile = Profile::get( sanitize_key( $pid ) );
		$applied = Generator::applied();
		$term_id = isset( $applied[ $pid ][ $slug ] ) ? (int) $applied[ $pid ][ $slug ] : 0;
		$file    = isset( $_FILES['csv'] ) ? $_FILES['csv'] : null; // phpcs:ignore WordPress.Security
		$err     = '';
		if ( ! $profile || ! $term_id ) {
			$err = __( 'Unknown catalogue type.', 'directorist-network' );
		} elseif ( ! $file || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) || 'csv' !== strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) ) {
			$err = __( 'Upload a .csv file.', 'directorist-network' );
		} elseif ( $file['size'] > 10 * MB_IN_BYTES ) {
			$err = __( 'The file is too large (10 MB max).', 'directorist-network' );
		}
		if ( ! $err ) {
			list( $header, $rows ) = Csv::parse( (string) file_get_contents( $file['tmp_name'] ) );
			$cols = ColumnMap::for_type( $profile, $slug );
			$map  = ColumnMap::match_header( $cols, $header );
			if ( ! in_array( 'title', $map, true ) ) {
				$err = __( 'No title column found.', 'directorist-network' );
			} elseif ( ! $rows ) {
				$err = __( 'The file has no data rows.', 'directorist-network' );
			}
		}
		if ( $err ) {
			set_transient( 'dirnet_log_' . get_current_user_id(), array( '!' . $err ), 120 );
			wp_safe_redirect( $back );
			exit;
		}
		$path = self::import_dir() . wp_generate_password( 20, false ) . '.csv';
		move_uploaded_file( $file['tmp_name'], $path );
		$unknown = array();
		foreach ( $map as $i => $k ) {
			if ( ! $k ) {
				$unknown[] = $header[ $i ];
			}
		}
		update_option(
			self::state_key(),
			array(
				'file' => $path, 'profile' => $pid, 'slug' => $slug, 'term_id' => $term_id, 'from' => 0, 'total' => count( $rows ),
				'created' => 0, 'updated' => 0, 'errors' => array(), 'unknown' => $unknown, 'skipped' => 0,
				'opts' => array(
					'status'       => ( isset( $_POST['status'] ) && 'draft' === $_POST['status'] ) ? 'draft' : 'publish',
					'create_terms' => ! empty( $_POST['create_terms'] ),
					'geocode'      => ! empty( $_POST['geocode'] ),
					'images'       => ! empty( $_POST['images'] ),
					'overwrite'    => ! empty( $_POST['overwrite'] ),
					'link_only'    => ! empty( $_POST['link_only'] ),
				),
			),
			false
		);
		wp_safe_redirect( add_query_arg( '_wpnonce', wp_create_nonce( 'dirnet_import_step' ), admin_url( 'admin-post.php?action=dirnet_import_step' ) ) );
		exit;
	}

	public function handle_import_step() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'dirnet_import_step' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'directorist-network' ) );
		}
		$back  = admin_url( 'edit.php?post_type=at_biz_dir&page=directorist-network-import' );
		$state = get_option( self::state_key() );
		$prof  = is_array( $state ) ? Profile::get( $state['profile'] ) : null;
		if ( ! $prof || ! is_readable( $state['file'] ) || ! Generator::directorist_ready() ) {
			delete_option( self::state_key() );
			wp_safe_redirect( $back );
			exit;
		}
		list( $header, $rows ) = Csv::parse( (string) file_get_contents( $state['file'] ) );
		$imp = new Importer( $prof, $state['slug'], $state['term_id'], $state['opts'] );
		$map = ColumnMap::match_header( $imp->columns(), $header );
		$res = $imp->run( $rows, $map, (int) $state['from'], 20 );

		$state['from']     = $res['next'];
		$state['created'] += $res['created'];
		$state['updated'] += $res['updated'];
		$state['skipped']  = ( isset( $state['skipped'] ) ? (int) $state['skipped'] : 0 ) + $res['skipped'];
		$state['errors']   = array_merge( $state['errors'], $res['errors'] );

		if ( $state['from'] >= $state['total'] ) {
			@unlink( $state['file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			delete_option( self::state_key() );
			$rel = Relations::resolve_all( $prof );
			foreach ( $rel['unresolved'] as $u ) {
				$state['errors'][] = sprintf( /* translators: %s: relation that could not be resolved */ __( 'Relation not resolved: %s', 'directorist-network' ), $u );
			}
			if ( $state['unknown'] ) {
				$state['errors'][] = sprintf( /* translators: %s: column names */ __( 'Ignored columns: %s', 'directorist-network' ), implode( ', ', $state['unknown'] ) );
			}
			set_transient( 'dirnet_imp_report_' . get_current_user_id(), $state, 300 );
			wp_safe_redirect( $back );
			exit;
		}
		update_option( self::state_key(), $state, false );
		$next = add_query_arg( '_wpnonce', wp_create_nonce( 'dirnet_import_step' ), admin_url( 'admin-post.php?action=dirnet_import_step' ) );
		echo '<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="refresh" content="1;url=' . esc_url( $next ) . '"><title>' . esc_html__( 'Importing…', 'directorist-network' ) . '</title></head><body style="font-family:sans-serif;padding:40px">';
		echo '<h2>' . esc_html__( 'Importing…', 'directorist-network' ) . '</h2><p>' . esc_html( sprintf( /* translators: 1: done, 2: total */ __( '%1$d of %2$d rows processed. Do not close this page.', 'directorist-network' ), $state['from'], $state['total'] ) ) . '</p></body></html>';
		exit;
	}

	public function handle_template() {
		$pid  = isset( $_GET['profile'] ) ? sanitize_key( wp_unslash( $_GET['profile'] ) ) : '';
		$slug = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'dirnet_tpl_' . $pid . $slug ) ) {
			wp_die( esc_html__( 'Permission denied.', 'directorist-network' ) );
		}
		$p = Profile::get( $pid );
		if ( ! $p ) {
			wp_die( esc_html__( 'Unknown profile.', 'directorist-network' ) );
		}
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $pid . '-' . $slug . '-template.csv"' );
		echo "\xEF\xBB\xBF"; // phpcs:ignore
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array_values( array_diff( array_keys( ColumnMap::for_type( $p, $slug ) ), array( 'location' ) ) ) ); // region/province/city replace the single location column
		fclose( $out );
		exit;
	}

	/** Entities of one directory type as an import-compatible CSV. */
	public function handle_export() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'dirnet_export' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'directorist-network' ) );
		}
		$t = isset( $_GET['target'] ) ? sanitize_text_field( wp_unslash( $_GET['target'] ) ) : '';
		$parts = explode( '|', $t );
		$pid   = isset( $parts[0] ) ? sanitize_key( $parts[0] ) : '';
		$slug  = isset( $parts[1] ) ? sanitize_key( $parts[1] ) : '';
		$p     = Profile::get( $pid );
		$applied = Generator::applied();
		$term_id = isset( $applied[ $pid ][ $slug ] ) ? (int) $applied[ $pid ][ $slug ] : 0;
		if ( ! $p || ! $term_id ) {
			wp_die( esc_html__( 'Unknown profile.', 'directorist-network' ) );
		}
		$cols = ColumnMap::for_type( $p, $slug );
		unset( $cols['location'] );
		$head = array_keys( $cols );
		$fn_slugs = array();
		$ids = get_posts( array(
			'post_type' => ATBDP_POST_TYPE, 'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
			'numberposts' => -1, 'fields' => 'ids', 'orderby' => 'title', 'order' => 'ASC',
			'meta_query' => array( array( 'key' => '_directory_type', 'value' => $term_id ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
		) );
		$rows = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			$row  = array();
			foreach ( $cols as $key => $c ) {
				$row[ $key ] = self::export_cell( $post, $key, $c );
			}
			foreach ( Functions::descs( $id ) as $fs => $txt ) {
				$fn_slugs[ $fs ] = true;
				$row[ 'fdesc:' . $fs ] = $txt;
			}
			$rows[] = $row;
		}
		foreach ( array_keys( $fn_slugs ) as $fs ) {
			$head[] = 'fdesc:' . $fs;
		}
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $pid . '-' . $slug . '-' . gmdate( 'Y-m-d' ) . '.csv"' );
		echo "\xEF\xBB\xBF"; // phpcs:ignore
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, $head, ';' );
		foreach ( $rows as $row ) {
			$line = array();
			foreach ( $head as $h ) {
				$line[] = isset( $row[ $h ] ) ? $row[ $h ] : '';
			}
			fputcsv( $out, $line, ';' );
		}
		fclose( $out );
		exit;
	}

	private static function term_path( $term ) {
		$path = array( $term->name );
		while ( $term->parent ) {
			$term = get_term( $term->parent );
			if ( ! $term || is_wp_error( $term ) ) {
				break;
			}
			array_unshift( $path, $term->name );
		}
		return $path;
	}

	private static function export_cell( $post, $key, array $c ) {
		$id = $post->ID;
		switch ( $c['kind'] ) {
			case 'title':
				return $post->post_title;
			case 'content':
				return 'excerpt' === $key ? $post->post_excerpt : $post->post_content;
			case 'meta':
				$v = get_post_meta( $id, '_' . $c['meta'], true );
				return is_scalar( $v ) ? (string) $v : '';
			case 'choice':
				$v = get_post_meta( $id, '_' . $c['meta'], true );
				return is_array( $v ) ? implode( ';', $v ) : (string) $v;
			case 'tax_category':
			case 'tax_tag':
			case 'tax_function':
				$tax = 'tax_category' === $c['kind'] ? ATBDP_CATEGORY : ( 'tax_tag' === $c['kind'] ? ATBDP_TAGS : Functions::TAX );
				$ts  = wp_get_object_terms( $id, $tax );
				$out = array();
				if ( ! is_wp_error( $ts ) ) {
					foreach ( $ts as $t ) {
						$out[] = 'tax_category' === $c['kind'] ? implode( ' > ', self::term_path( $t ) ) : $t->name;
					}
				}
				return implode( '; ', $out );
			case 'geo':
				$ts = wp_get_object_terms( $id, ATBDP_LOCATION );
				if ( is_wp_error( $ts ) || ! $ts ) {
					return '';
				}
				$deep = $ts[0];
				foreach ( $ts as $t ) {
					if ( count( self::term_path( $t ) ) > count( self::term_path( $deep ) ) ) {
						$deep = $t;
					}
				}
				$idx  = array( 'region' => 0, 'province' => 1, 'city' => 2, 'district' => 3 );
				$path = self::term_path( $deep );
				return isset( $path[ $idx[ $key ] ] ) ? $path[ $idx[ $key ] ] : '';
			case 'image':
				$att = (int) get_post_meta( $id, '_listing_prv_img', true );
				$url = $att ? wp_get_attachment_url( $att ) : '';
				return $url ? $url : '';
			case 'relation':
				$out = array();
				foreach ( (array) get_post_meta( $id, '_dirnet_rel_' . $key, true ) as $tid ) {
					if ( $tid && get_post( $tid ) ) {
						$out[] = get_the_title( $tid );
					}
				}
				foreach ( (array) get_post_meta( $id, '_dirnet_pending_' . $key, true ) as $pend ) {
					if ( is_scalar( $pend ) && '' !== (string) $pend ) {
						$out[] = (string) $pend;
					}
				}
				return implode( '; ', $out );
		}
		return '';
	}
}
