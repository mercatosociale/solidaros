<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Data repair: everything that puts data in order after an import (listing template, merge roles, change the
 * type of list, move entries to another category, territories, entities with missing data). Linked from the
 * end of "Import and export data"; top-level menu entry; it can be removed as a whole once the data is in order.
 */
class Repairs {

	const SLUG     = 'dirnet-repairs';
	const API_SLUG = 'dirnet-api';

	public function boot() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'drop_estero' ) );
		add_action( 'admin_post_dirnet_api_revoke', array( $this, 'api_revoke' ) );
		add_action( 'admin_post_dirnet_prod_food', array( $this, 'handle_prod_food' ) );
		add_action( 'admin_post_dirnet_noplace', array( $this, 'handle_noplace' ) );
		add_action( 'admin_post_dirnet_gas_commerce', array( $this, 'handle_gas_commerce' ) );
	}

	/** API: who has keys and which producers agreed to share their data with whom. */
	public function api_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$grants = get_option( \Solidaros\Api\ApiAccess::GRANTS, array() );
		$grants = is_array( $grants ) ? $grants : array();
		$users  = get_users( array( 'meta_key' => \Solidaros\Api\ApiAccess::KEYS, 'meta_compare' => 'EXISTS', 'number' => 500 ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		?>
		<div class="wrap">
			<h1>API</h1>
			<p class="description">Le API permettono a GAS, empori e negozi (soggetti della categoria Commercio) di leggere in automatico i listini dei produttori che hanno dato il consenso. Endpoint: <code><?php echo esc_html( rest_url( 'solidaros/v1/feed' ) ); ?></code></p>
			<h2>Chiavi</h2>
			<table class="widefat striped"><thead><tr><th>Soggetto</th><th>Chiave</th><th>Creata</th><th>Ultimo uso</th><th>Produttori con consenso</th><th></th></tr></thead><tbody>
			<?php foreach ( $users as $u ) : foreach ( array_filter( (array) get_user_meta( $u->ID, \Solidaros\Api\ApiAccess::KEYS, true ), 'is_array' ) as $k ) : ?>
				<tr>
					<td><?php echo esc_html( $u->display_name . ' (' . $u->user_email . ')' ); ?></td>
					<td><?php echo esc_html( '' !== $k['label'] ? $k['label'] : $k['id'] ); ?></td>
					<td><?php echo esc_html( wp_date( 'd/m/Y', (int) $k['created'] ) ); ?></td>
					<td><?php echo $k['last'] ? esc_html( wp_date( 'd/m/Y H:i', (int) $k['last'] ) ) : '—'; ?></td>
					<td><?php echo (int) count( (array) ( $grants[ $u->ID ] ?? array() ) ); ?></td>
					<td><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'dirnet_api_revoke' ); ?><input type="hidden" name="action" value="dirnet_api_revoke" /><input type="hidden" name="uid" value="<?php echo (int) $u->ID; ?>" /><input type="hidden" name="id" value="<?php echo esc_attr( $k['id'] ); ?>" /><button class="button" onclick="return confirm('Revocare la chiave?');">Revoca</button></form></td>
				</tr>
			<?php endforeach; endforeach; ?>
			</tbody></table>
			<h2>Consensi dei produttori</h2>
			<table class="widefat striped"><thead><tr><th>Soggetto Commercio</th><th>Produttori che gli consegnano i dati</th></tr></thead><tbody>
			<?php foreach ( $grants as $cuid => $prods ) : if ( ! $prods ) { continue; } $cu = get_userdata( (int) $cuid ); ?>
				<tr><td><?php echo esc_html( $cu ? $cu->display_name : '#' . (int) $cuid ); ?></td><td><?php echo esc_html( implode( ', ', array_values( (array) $prods ) ) ); ?></td></tr>
			<?php endforeach; ?>
			</tbody></table>
		</div>
		<?php
	}

	public function api_revoke() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'dirnet_api_revoke' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'directorist-network' ) );
		}
		$uid = (int) ( $_POST['uid'] ?? 0 );
		$id  = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		if ( $uid && '' !== $id ) {
			$keys = array_values( array_filter( (array) get_user_meta( $uid, \Solidaros\Api\ApiAccess::KEYS, true ), static function ( $k ) use ( $id ) {
				return is_array( $k ) && (string) $k['id'] !== $id;
			} ) );
			update_user_meta( $uid, \Solidaros\Api\ApiAccess::KEYS, $keys );
		}
		wp_safe_redirect( admin_url( 'edit.php?post_type=at_biz_dir&page=' . self::API_SLUG ) );
		exit;
	}

	/** IDs of the catalogue-type terms that mean "gas" (from the applied profiles, plus the slug "gas"). */
	private static function gas_term_ids() {
		$ids = array();
		foreach ( Generator::applied() as $types ) {
			if ( ! empty( $types['gas'] ) ) {
				$ids[] = (int) $types['gas'];
			}
		}
		$t = get_term_by( 'slug', 'gas', 'atbdp_listing_types' );
		if ( $t && ! is_wp_error( $t ) ) {
			$ids[] = (int) $t->term_id;
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	private static function gas_listing_ids() {
		$tt = self::gas_term_ids();
		if ( ! $tt ) {
			return array();
		}
		return get_posts( array(
			'post_type'      => ATBDP_POST_TYPE,
			'post_status'    => array( 'publish', 'pending', 'draft', 'private', 'future' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( array( 'key' => '_directory_type', 'value' => $tt, 'compare' => 'IN' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
		) );
	}

	/** Top-level category "Commercio" (found by name or slug; created if it does not exist and $create). */
	private static function commerce_term( $create ) {
		foreach ( (array) get_terms( array( 'taxonomy' => ATBDP_CATEGORY, 'hide_empty' => false, 'parent' => 0 ) ) as $t ) {
			if ( is_object( $t ) && ( 'commercio' === $t->slug || 0 === strcasecmp( $t->name, 'Commercio' ) ) ) {
				return (int) $t->term_id;
			}
		}
		if ( $create ) {
			$r = wp_insert_term( 'Commercio', ATBDP_CATEGORY, array( 'slug' => 'commercio' ) );
			if ( ! is_wp_error( $r ) ) {
				return (int) $r['term_id'];
			}
		}
		return 0;
	}

	/** Producers with no category at all go to "Alimentazione" (found by name or slug; created on apply). */
	public function handle_prod_food() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'dirnet_prod_food' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'directorist-network' ) );
		}
		$apply = ! empty( $_POST['apply'] );
		$tids  = array();
		foreach ( Generator::applied() as $types ) {
			if ( ! empty( $types['producer'] ) ) {
				$tids[] = (int) $types['producer'];
			}
		}
		$tids = array_values( array_unique( array_filter( array_map( 'intval', (array) apply_filters( 'dirnet_producer_type_ids', $tids ) ) ) ) );
		$todo = array();
		if ( $tids ) {
			foreach ( get_posts( array(
				'post_type' => ATBDP_POST_TYPE, 'post_status' => array( 'publish', 'pending', 'draft', 'private', 'future' ), 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true,
				'meta_query' => array( array( 'key' => '_directory_type', 'value' => $tids, 'compare' => 'IN' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
			) ) as $id ) {
				$cur = wp_get_object_terms( $id, ATBDP_CATEGORY, array( 'fields' => 'ids' ) );
				if ( empty( $cur ) || is_wp_error( $cur ) ) {
					$todo[] = $id;
				}
			}
		}
		$log = array();
		if ( ! $tids ) {
			$log[] = '!Tipo produttore non trovato.';
		} elseif ( $apply && $todo ) {
			$cid = 0;
			foreach ( (array) get_terms( array( 'taxonomy' => ATBDP_CATEGORY, 'hide_empty' => false, 'parent' => 0 ) ) as $t ) {
				if ( is_object( $t ) && ( 'alimentazione' === $t->slug || 0 === strcasecmp( $t->name, 'Alimentazione' ) ) ) {
					$cid = (int) $t->term_id;
					break;
				}
			}
			if ( ! $cid ) {
				$r   = wp_insert_term( 'Alimentazione', ATBDP_CATEGORY, array( 'slug' => 'alimentazione' ) );
				$cid = is_wp_error( $r ) ? 0 : (int) $r['term_id'];
			}
			if ( $cid ) {
				foreach ( $todo as $id ) {
					wp_set_object_terms( $id, array( $cid ), ATBDP_CATEGORY );
				}
				$log[] = sprintf( 'Fatto: %d produttori senza categoria messi in "Alimentazione".', count( $todo ) );
			} else {
				$log[] = '!Impossibile creare la categoria "Alimentazione".';
			}
		} else {
			$log[] = sprintf( $apply ? 'Nessun produttore senza categoria.' : 'Anteprima: %d produttori senza categoria andrebbero in "Alimentazione".', count( $todo ) );
			foreach ( array_slice( $todo, 0, 30 ) as $id ) {
				$log[] = get_the_title( $id );
			}
		}
		set_transient( 'dirnet_log_' . get_current_user_id(), $log, 120 );
		wp_safe_redirect( admin_url( 'edit.php?post_type=at_biz_dir&page=' . self::SLUG ) );
		exit;
	}

	/** One time: the location "Estero" is deleted (entries that had only it stay without location). */
	public function drop_estero() {
		if ( get_option( 'dirnet_estero_dropped' ) || ! defined( 'ATBDP_LOCATION' ) || ! taxonomy_exists( ATBDP_LOCATION ) ) {
			return;
		}
		$t = get_term_by( 'slug', 'estero', ATBDP_LOCATION );
		if ( $t && ! is_wp_error( $t ) ) {
			foreach ( (array) get_terms( array( 'taxonomy' => ATBDP_LOCATION, 'parent' => $t->term_id, 'hide_empty' => false ) ) as $ch ) {
				if ( is_object( $ch ) ) {
					wp_update_term( $ch->term_id, ATBDP_LOCATION, array( 'parent' => 0 ) );
				}
			}
			wp_delete_term( $t->term_id, ATBDP_LOCATION );
		}
		update_option( 'dirnet_estero_dropped', 1, false );
	}

	/** Entries with no location at all (no region, province or town) go to "Senza luogo" (slug noluogo). */
	public function handle_noplace() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'dirnet_noplace' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'directorist-network' ) );
		}
		$apply = ! empty( $_POST['apply'] );
		$ids   = get_posts( array(
			'post_type'      => ATBDP_POST_TYPE,
			'post_status'    => array( 'publish', 'pending', 'draft', 'private', 'future' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );
		$todo = array();
		foreach ( $ids as $id ) {
			$cur = wp_get_object_terms( $id, ATBDP_LOCATION, array( 'fields' => 'ids' ) );
			if ( empty( $cur ) || is_wp_error( $cur ) ) {
				$todo[] = $id;
			}
		}
		$log = array();
		if ( $apply && $todo ) {
			$t = get_term_by( 'slug', 'noluogo', ATBDP_LOCATION );
			if ( $t ) {
				$tid = (int) $t->term_id;
			} else {
				$r   = wp_insert_term( 'Senza luogo', ATBDP_LOCATION, array( 'slug' => 'noluogo', 'parent' => 0 ) );
				$tid = is_wp_error( $r ) ? 0 : (int) $r['term_id'];
			}
			if ( $tid ) {
				foreach ( $todo as $id ) {
					wp_set_object_terms( $id, array( $tid ), ATBDP_LOCATION );
				}
				$log[] = sprintf( 'Fatto: %d schede senza luogo assegnate a "Senza luogo".', count( $todo ) );
			} else {
				$log[] = '!Impossibile creare la località "Senza luogo".';
			}
		} else {
			$log[] = sprintf( $apply ? 'Nessuna scheda senza luogo.' : 'Anteprima: %d schede senza regione, provincia e comune andrebbero in "Senza luogo".', count( $todo ) );
			foreach ( array_slice( $todo, 0, 30 ) as $id ) {
				$log[] = get_the_title( $id );
			}
		}
		set_transient( 'dirnet_log_' . get_current_user_id(), $log, 120 );
		wp_safe_redirect( admin_url( 'edit.php?post_type=at_biz_dir&page=' . self::SLUG ) );
		exit;
	}

	public function handle_gas_commerce() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'dirnet_gas_commerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'directorist-network' ) );
		}
		$apply = ! empty( $_POST['apply'] );
		$ids   = self::gas_listing_ids();
		$cid   = self::commerce_term( $apply );
		$log   = array();
		if ( ! $ids ) {
			$log[] = '!Nessun soggetto di tipo GAS trovato.';
		} else {
			$n = 0;
			$r = 0;
			foreach ( $ids as $id ) {
				$cur = wp_get_object_terms( $id, ATBDP_CATEGORY, array( 'fields' => 'ids' ) );
				$cur = is_wp_error( $cur ) ? array() : array_map( 'intval', $cur );
				if ( $cid && array( $cid ) === $cur ) {
					continue;
				}
				$n++;
				$r += count( array_diff( $cur, array( $cid ) ) );
				if ( $apply ) {
					wp_set_object_terms( $id, array( $cid ), ATBDP_CATEGORY );
				} elseif ( $n <= 30 ) {
					$log[] = get_the_title( $id ) . ( $cur ? ' (ora: ' . implode( ', ', wp_list_pluck( array_map( 'get_term', $cur ), 'name' ) ) . ')' : ' (nessuna categoria)' );
				}
			}
			array_unshift( $log, sprintf( $apply ? 'Fatto: %1$d soggetti GAS messi in "Commercio"; %2$d altre categorie rimosse (su %3$d GAS in totale).' : 'Anteprima: %1$d soggetti GAS sarebbero messi in "Commercio"; %2$d altre categorie sarebbero rimosse (su %3$d GAS in totale).', $n, $r, count( $ids ) ) );
			if ( ! $cid && ! $apply ) {
				$log[] = 'La categoria "Commercio" non esiste: verrà creata alla conferma.';
			}
		}
		set_transient( 'dirnet_log_' . get_current_user_id(), $log, 120 );
		wp_safe_redirect( admin_url( 'edit.php?post_type=at_biz_dir&page=' . self::SLUG ) );
		exit;
	}

	public function menu() {
		add_submenu_page( 'edit.php?post_type=at_biz_dir', 'API', 'API', 'manage_options', self::API_SLUG, array( $this, 'api_page' ) );
		add_submenu_page( 'edit.php?post_type=at_biz_dir', __( 'Data repair', 'directorist-network' ), __( 'Data repair', 'directorist-network' ), 'manage_options', self::SLUG, array( $this, 'page' ) );
	}

	public function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$log = get_transient( 'dirnet_log_' . get_current_user_id() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Data repair', 'directorist-network' ); ?></h1>
			<p><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=at_biz_dir&page=directorist-network-import' ) ); ?>">&larr; <?php esc_html_e( 'Import and export data', 'directorist-network' ); ?></a></p>
			<?php if ( $log ) : delete_transient( 'dirnet_log_' . get_current_user_id() ); ?>
				<div class="notice notice-info"><ul><?php foreach ( $log as $line ) : ?><li><?php echo esc_html( ltrim( $line, '!' ) ); ?></li><?php endforeach; ?></ul></div>
			<?php endif; ?>

			<?php ( new Sidebar() )->render( true ); ?>

			<hr />
			<h2>Completa dati dei soggetti</h2>
			<p class="description">Cerca in rete i dati mancanti (sito, email, telefono, indirizzo, coordinate). Nulla viene salvato senza la tua conferma.</p>
			<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'edit.php?post_type=at_biz_dir&page=' . Enrich::SLUG ) ); ?>">Apri Completa dati</a></p>

			<hr />
			<?php Functions::merge_panel(); ?>


			<hr />
			<h2><?php esc_html_e( 'Merge two entries', 'directorist-network' ); ?></h2>
			<p class="description"><?php esc_html_e( 'For a duplicate: the data of the duplicate is added to the entry you keep, and the duplicate is closed. The merge can be undone below.', 'directorist-network' ); ?></p>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'dirnet_merge' ); ?>
				<input type="hidden" name="action" value="dirnet_merge" />
				<p><label><?php esc_html_e( 'ID of the duplicate', 'directorist-network' ); ?> <input type="number" name="new" min="1" required /></label>
				<label style="margin-left:14px"><?php esc_html_e( 'ID of the entry to keep', 'directorist-network' ); ?> <input type="number" name="target" min="1" required /></label>
				<?php submit_button( __( 'Merge', 'directorist-network' ), 'secondary', 'submit', false ); ?></p>
			</form>

			<h3><?php esc_html_e( 'Merges that can be undone', 'directorist-network' ); ?></h3>
			<?php
			$merged = defined( 'ATBDP_POST_TYPE' ) ? get_posts( array( 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'any', 'numberposts' => 200, 'fields' => 'ids', 'meta_key' => '_dirnet_merge_prev' ) ) : array(); // phpcs:ignore WordPress.DB.SlowDBQuery
			if ( ! $merged ) :
				?>
				<p><?php esc_html_e( 'None.', 'directorist-network' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:800px"><tbody>
				<?php foreach ( $merged as $pid ) : $prev = get_post_meta( $pid, '_dirnet_merge_prev', true ); ?>
					<tr><td><a href="<?php echo esc_url( (string) get_edit_post_link( $pid ) ); ?>"><?php echo esc_html( get_the_title( $pid ) ); ?></a> <small>#<?php echo (int) $pid; ?><?php echo is_array( $prev ) && ! empty( $prev['new'] ) ? ' &larr; #' . (int) $prev['new'] : ''; ?></small></td>
					<td><a class="button" href="<?php echo esc_url( add_query_arg( array( 'action' => 'dirnet_merge_undo', 'target' => $pid, '_wpnonce' => wp_create_nonce( 'dirnet_merge_undo' ) ), admin_url( 'admin-post.php' ) ) ); ?>"><?php esc_html_e( 'Undo the merge', 'directorist-network' ); ?></a></td></tr>
				<?php endforeach; ?>
				</tbody></table>
			<?php endif; ?>

			<hr />
			<h2><?php esc_html_e( 'Change the type of list of the entities', 'directorist-network' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Moves all the entries that have the chosen roles or categories to another catalogue type (for example from Producers to Distributors). Fields that the new type does not have stay stored but are not shown. Preview first, and back up before applying.', 'directorist-network' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'dirnet_retype' ); ?>
				<input type="hidden" name="action" value="dirnet_retype" />
				<p><label><?php esc_html_e( 'Roles (hold Ctrl or Cmd to choose several)', 'directorist-network' ); ?><br />
				<select name="roles[]" multiple size="8" style="min-width:420px">
				<?php foreach ( (array) get_terms( array( 'taxonomy' => Functions::TAX, 'hide_empty' => false, 'orderby' => 'name' ) ) as $rt ) : ?>
					<?php if ( is_object( $rt ) ) : ?><option value="<?php echo (int) $rt->term_id; ?>"><?php echo esc_html( ( $rt->parent ? '— ' : '' ) . $rt->name . ' (' . (int) $rt->count . ')' ); ?></option><?php endif; ?>
				<?php endforeach; ?>
				</select></label></p>
				<p class="description"><?php esc_html_e( 'Choose roles, categories or both: the entries that have any of them are moved.', 'directorist-network' ); ?></p>
				<p><label><?php esc_html_e( 'Categories (hold Ctrl or Cmd to choose several)', 'directorist-network' ); ?><br />
				<select name="cats[]" multiple size="10" style="min-width:420px">
				<?php foreach ( Categories::move_options() as $cid => $clab ) : ?>
					<option value="<?php echo (int) $cid; ?>"><?php echo esc_html( $clab ); ?></option>
				<?php endforeach; ?>
				</select></label></p>
				<p><label><?php esc_html_e( 'New catalogue type', 'directorist-network' ); ?>
				<select name="to"><option value="">—</option>
				<?php foreach ( (array) get_terms( array( 'taxonomy' => 'atbdp_listing_types', 'hide_empty' => false ) ) as $tt ) : ?>
					<?php if ( is_object( $tt ) ) : ?><option value="<?php echo (int) $tt->term_id; ?>"><?php echo esc_html( $tt->name ); ?></option><?php endif; ?>
				<?php endforeach; ?>
				</select></label></p>
				<p><label><input type="checkbox" name="children" value="1" checked /> <?php esc_html_e( 'Include the subcategories of the chosen categories', 'directorist-network' ); ?></label></p>
				<button class="button" name="apply" value="0"><?php esc_html_e( 'Preview', 'directorist-network' ); ?></button>
				<button class="button button-primary" name="apply" value="1" onclick="return confirm('<?php echo esc_js( __( 'Change the catalogue type of these entries? Back up first.', 'directorist-network' ) ); ?>');"><?php esc_html_e( 'Apply', 'directorist-network' ); ?></button>
			</form>
			<hr />
			<?php ( new Categories() )->body_move( false ); ?>

			<hr />
			<h2>Soggetti GAS nella categoria Commercio</h2>
			<p class="description">Mette tutti i soggetti di tipo GAS nella categoria "Commercio" e toglie ogni altra categoria assegnata. Prima guarda l'anteprima; fai una copia di sicurezza prima di confermare.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'dirnet_gas_commerce' ); ?>
				<input type="hidden" name="action" value="dirnet_gas_commerce" />
				<button class="button" name="apply" value="0">Anteprima</button>
				<button class="button button-primary" name="apply" value="1" onclick="return confirm('Mettere tutti i GAS in Commercio e togliere le altre categorie? Hai fatto la copia di sicurezza?');">Applica a tutti i GAS</button>
			</form>

			<hr />
			<h2>Produttori senza categoria</h2>
			<p class="description">I produttori che non hanno nessuna categoria vengono messi in "Alimentazione" (creata se manca). Quelli che hanno già una categoria non vengono toccati.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'dirnet_prod_food' ); ?>
				<input type="hidden" name="action" value="dirnet_prod_food" />
				<button class="button" name="apply" value="0">Anteprima</button>
				<button class="button button-primary" name="apply" value="1" onclick="return confirm('Mettere in Alimentazione tutti i produttori senza categoria?');">Applica</button>
			</form>

			<hr />
			<h2>Schede senza luogo</h2>
			<p class="description">Le schede senza regione, provincia e comune vengono assegnate alla località "Senza luogo" (noluogo), creata se manca.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'dirnet_noplace' ); ?>
				<input type="hidden" name="action" value="dirnet_noplace" />
				<button class="button" name="apply" value="0">Anteprima</button>
				<button class="button button-primary" name="apply" value="1" onclick="return confirm('Assegnare a Senza luogo tutte le schede senza località?');">Applica</button>
			</form>

			<?php ListingBase::panel(); ?>

			<?php Cleanup::panel(); ?>

			<hr />
			<h2><?php esc_html_e( 'Import territories', 'directorist-network' ); ?></h2>
			<?php ( new ImportaTerritori() )->body(); ?>
		</div>
		<?php
	}
}
