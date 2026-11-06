<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Solidaros sectors and Directorist categories are the same list. With Directorist Network active the list is managed
 * here (the site categories) and Solidaros only reads it; the categories chosen on the entry are the sectors of the subject (both ways).
 */
class SectorsSync {

	private static $busy = false;

	public function boot() {
		add_filter( 'solidaros_classification', array( $this, 'terms' ), 20 );
		add_action( 'solidaros_classification_changed', array( $this, 'to_entry' ), 10, 1 );
		add_action( 'set_object_terms', array( $this, 'from_entry' ), 20, 6 );
	}

	private static function tax() {
		return defined( 'ATBDP_CATEGORY' ) ? ATBDP_CATEGORY : '';
	}

	/** With Directorist Network the list of sectors is the list of the site categories (managed here); Solidaros keeps the rules of each code. */
	public function terms( $list ) {
		$tax = self::tax();
		if ( '' === $tax ) {
			return $list;
		}
		$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) );
		if ( is_wp_error( $terms ) || ! $terms ) {
			return $list;
		}
		$known = array();
		foreach ( (array) $list as $t ) {
			if ( is_array( $t ) && ! empty( $t['code'] ) ) {
				$known[ (string) $t['code'] ] = $t;
			}
		}
		$byid = array();
		foreach ( $terms as $t ) {
			$byid[ $t->term_id ] = $t;
		}
		$out = array();
		foreach ( $terms as $t ) {
			$item           = isset( $known[ $t->slug ] ) ? $known[ $t->slug ] : array();
			$item['code']   = $t->slug;
			$item['label']  = $t->name;
			$item['parent'] = ( $t->parent && isset( $byid[ $t->parent ] ) ) ? $byid[ $t->parent ]->slug : '';
			$out[]          = $item;
		}
		return $out;
	}

	private static function entries_of( $tax_id ) {
		if ( '' === $tax_id || ! defined( 'ATBDP_POST_TYPE' ) ) {
			return array();
		}
		$users = get_users( array( 'meta_key' => 'sm_company_tax_id', 'meta_value' => $tax_id, 'fields' => 'ID', 'number' => 5 ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( ! $users ) {
			return array();
		}
		return array_map( 'intval', get_posts( array( 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'any', 'numberposts' => 20, 'fields' => 'ids', 'author__in' => array_map( 'intval', $users ) ) ) );
	}

	/** Sectors chosen in Solidaros -> categories of the entry. */
	public function to_entry( $tax_id ) {
		$tax_id = (string) $tax_id;
		if ( self::$busy || '' === $tax_id || '' === self::tax() || ! ( SolidarosBridge::active() && class_exists( '\\Solidaros\\Classification\\Classification' ) ) ) {
			return;
		}
		$entries = self::entries_of( $tax_id );
		if ( ! $entries ) {
			return;
		}
		$ids = array();
		foreach ( \Solidaros\Classification\Classification::codes( $tax_id ) as $code ) {
			$t = get_term_by( 'slug', $code, self::tax() );
			if ( $t ) {
				$ids[] = (int) $t->term_id;
			}
		}
		self::$busy = true;
		foreach ( $entries as $id ) {
			wp_set_object_terms( $id, $ids, self::tax() );
		}
		self::$busy = false;
	}

	/** Categories changed on the entry -> sectors of the subject in Solidaros. */
	public function from_entry( $object_id, $terms, $tt_ids, $taxonomy ) {
		if ( self::$busy || $taxonomy !== self::tax() || ! ( SolidarosBridge::active() && class_exists( '\\Solidaros\\Classification\\Classification' ) ) ) {
			return;
		}
		if ( ! defined( 'ATBDP_POST_TYPE' ) || get_post_type( $object_id ) !== ATBDP_POST_TYPE ) {
			return;
		}
		$owner  = (int) get_post_field( 'post_author', $object_id );
		$tax_id = $owner ? (string) get_user_meta( $owner, 'sm_company_tax_id', true ) : ''; // the subject is the owner of the entry
		if ( '' === $tax_id ) {
			return;
		}
		$codes = array();
		foreach ( (array) wp_get_object_terms( $object_id, self::tax(), array( 'fields' => 'slugs' ) ) as $slug ) {
			$codes[] = $slug;
		}
		self::$busy = true;
		\Solidaros\Classification\Classification::save( $tax_id, $codes );
		self::$busy = false;
	}

	/** @var string[] Product types shown as checkboxes in the rules table. */
	private $rule_types = array();

	/** Under the roles list: categories live in Directorist; here only the questionnaire rules of each category. */
	public function rules_box() {
		if ( ! ( SolidarosBridge::active() && class_exists( '\\Solidaros\\Classification\\Classification' ) ) ) {
			return;
		}
		$terms = \Solidaros\Classification\Classification::terms();
		echo '<h2>' . esc_html__( 'Categories', 'directorist-network' ) . '</h2>';
		if ( '' !== self::tax() ) {
			echo '<p><a href="' . esc_url( admin_url( 'edit-tags.php?taxonomy=' . self::tax() . '&post_type=at_biz_dir' ) ) . '">' . esc_html__( 'Manage the categories in Directorist', 'directorist-network' ) . '</a></p>';
		}
		echo '<h3>' . esc_html__( 'Questionnaire rules of the categories', 'directorist-network' ) . '</h3>';
		if ( ! $terms ) {
			echo '<p>' . esc_html__( 'No categories yet.', 'directorist-network' ) . '</p>';
			return;
		}
		$types = ( SolidarosBridge::active() && class_exists( '\\Solidaros\\Admin\\ClassificationPage' ) ) ? (array) \Solidaros\Admin\ClassificationPage::productTypesList() : array();
		echo '<p>' . esc_html__( 'Here you decide, for each category, which questionnaires the entities of that category fill in. Tick "Company entry" if they have to fill in the company questionnaire (Scheda azienda, for farms and producers), and tick the product types they can describe with product questionnaires (Schede prodotto, with the source price). The social report is always available. A category with nothing ticked gets no company or product questionnaire.', 'directorist-network' ) . '</p>';
		$this->rule_types = $types;
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dirnet_sector_rules">';
		wp_nonce_field( 'dirnet_sector_rules' );
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Category', 'directorist-network' ) . '</th><th>' . esc_html__( 'Code', 'directorist-network' ) . '</th><th>' . esc_html__( 'Company entry', 'directorist-network' ) . '</th><th>' . esc_html__( 'Product entries', 'directorist-network' ) . '</th></tr></thead><tbody>';
		$this->rule_rows( '', 0, $terms );
		echo '</tbody></table><p><button class="button button-primary">' . esc_html__( 'Save rules', 'directorist-network' ) . '</button></p></form>';
	}

	private function rule_rows( $parent, $depth, $terms ) {
		foreach ( $terms as $code => $t ) {
			if ( ! is_array( $t ) || (string) ( $t['parent'] ?? '' ) !== (string) $parent ) {
				continue;
			}
			$code = (string) ( $t['code'] ?? $code );
			echo '<tr><td>' . esc_html( str_repeat( '— ', $depth ) . (string) ( $t['label'] ?? $code ) ) . '</td><td><code>' . esc_html( $code ) . '</code></td>';
			echo '<td><input type="checkbox" name="farm[' . esc_attr( $code ) . ']" value="1"' . checked( ! empty( $t['farm'] ), true, false ) . '></td>';
			$sel = (array) ( $t['products'] ?? array() );
			echo '<td>';
			foreach ( (array) $this->rule_types as $ty ) {
				echo '<label style="display:inline-block;margin:0 12px 2px 0"><input type="checkbox" name="products[' . esc_attr( $code ) . '][]" value="' . esc_attr( $ty ) . '"' . checked( in_array( $ty, $sel, true ), true, false ) . '> ' . esc_html( $ty ) . '</label>';
			}
			echo '</td></tr>';
			if ( $depth < 6 ) {
				$this->rule_rows( $code, $depth + 1, $terms );
			}
		}
	}

	public function save_rules() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'directorist-network' ) );
		}
		check_admin_referer( 'dirnet_sector_rules' );
		if ( ( SolidarosBridge::active() && class_exists( '\\Solidaros\\Classification\\Classification' ) ) ) {
			$valid = ( SolidarosBridge::active() && class_exists( '\\Solidaros\\Admin\\ClassificationPage' ) ) ? (array) \Solidaros\Admin\ClassificationPage::productTypesList() : array();
			$farm  = (array) wp_unslash( $_POST['farm'] ?? array() );
			$prod  = (array) wp_unslash( $_POST['products'] ?? array() );
			$terms = \Solidaros\Classification\Classification::terms();
			foreach ( $terms as $k => $t ) {
				if ( ! is_array( $t ) ) {
					continue;
				}
				$code = (string) ( $t['code'] ?? $k );
				unset( $t['farm'], $t['products'] );
				if ( isset( $farm[ $code ] ) ) {
					$t['farm'] = true;
				}
				$p = array_values( array_intersect( $valid, array_map( 'sanitize_key', array_map( 'trim', is_array( $prod[ $code ] ?? null ) ? $prod[ $code ] : explode( ',', (string) ( $prod[ $code ] ?? '' ) ) ) ) ) );
				if ( $p ) {
					$t['products'] = $p;
				}
				$terms[ $k ] = $t;
			}
			\Solidaros\Classification\Classification::store( array_values( $terms ) );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
