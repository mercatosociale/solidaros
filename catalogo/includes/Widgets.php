<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Widgets for the entity page (Appearance > Widgets, area "Listing sidebar"):
 * Entity data, Suggest an edit, Social report, Entity profile, Product profiles, Relations.
 * When one of them is placed in a widget area the same block is no longer added to the page content.
 */
class Widgets {

	public function boot() {
		add_action( 'widgets_init', array( $this, 'register' ) );
	}

	public function register() {
		foreach ( array( 'Identity_Widget', 'Suggest_Widget', 'Report_Widget', 'Selfcert_Widget', 'Evaluation_Widget', 'Company_Widget', 'Products_Widget', 'Relations_Widget' ) as $c ) {
			$class = __NAMESPACE__ . '\\' . $c;
			if ( class_exists( $class ) ) {
				register_widget( $class );
			}
		}
	}

	/** True when the widget with this base id is in a widget area: the content version then stays out. */
	public static function placed( $base ) {
		return (bool) is_active_widget( false, false, $base, true );
	}
}

abstract class Entity_Widget extends \WP_Widget {

	/** Html of the widget for one entity ('' = nothing to show). */
	abstract protected function body( $post_id );

	abstract protected function title_default();

	public function widget( $args, $inst ) {
		if ( ! defined( 'ATBDP_POST_TYPE' ) || ! is_singular( ATBDP_POST_TYPE ) ) {
			return;
		}
		$html = (string) $this->body( (int) get_the_ID() );
		if ( '' === $html ) {
			return;
		}
		$t = ! empty( $inst['title'] ) ? $inst['title'] : $this->title_default();
		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( '' !== $t ) {
			echo $args['before_title'] . esc_html( $t ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput
		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public function form( $inst ) {
		$t = isset( $inst['title'] ) ? $inst['title'] : '';
		echo '<p><label>' . esc_html__( 'Title:', 'directorist-network' ) . ' <input class="widefat" type="text" name="' . esc_attr( $this->get_field_name( 'title' ) ) . '" value="' . esc_attr( $t ) . '" placeholder="' . esc_attr( $this->title_default() ) . '" /></label></p>';
	}

	public function update( $new, $old ) {
		return array( 'title' => isset( $new['title'] ) ? sanitize_text_field( $new['title'] ) : '' );
	}
}

class Suggest_Widget extends Entity_Widget {
	public function __construct() {
		parent::__construct( 'dirnet_suggest', __( 'Suggest an edit', 'directorist-network' ), array( 'description' => __( 'Form to propose a correction to the entity.', 'directorist-network' ) ) );
	}
	protected function title_default() {
		return __( 'Suggest an edit', 'directorist-network' );
	}
	protected function body( $post_id ) {
		return apply_filters( 'dirnet_show_suggest_button', true, $post_id ) ? Suggestions::button( $post_id, __( 'Open the form', 'directorist-network' ), false ) : '';
	}
}

class Report_Widget extends Entity_Widget {
	public function __construct() {
		parent::__construct( 'dirnet_report', __( 'Social report', 'directorist-network' ), array( 'description' => __( 'Link to the social report of the entity and the earlier ones.', 'directorist-network' ) ) );
	}
	protected function title_default() {
		return __( 'Social report', 'directorist-network' );
	}
	protected function body( $post_id ) {
		$h = SocialReport::html( $post_id );
		return '' === $h ? '' : '<div class="dirnet-sr-wrap">' . $h . SocialReport::history_html( $post_id ) . '</div>';
	}
}

/**
 * Sidebar block under the entity data: "Autocertificazione" present / not present. When present: Bilancio sociale,
 * Autocertificazione partecipativa (Scheda Azienda, Scheda Prodotti, Scheda Prezzo sorgente) and,
 * when the entity has been evaluated, the link to the evaluated self-certification.
 */
class Selfcert_Widget extends \WP_Widget {
	public function __construct() {
		parent::__construct( 'dirnet_selfcert', __( 'Self-certification', 'directorist-network' ), array( 'description' => __( 'Self-certification of the entity from Solidaros: present or not present, with links to its questionnaires.', 'directorist-network' ) ) );
	}

	private static function previous( $key ) {
		$p = apply_filters( 'solidaros_selfcert_previous', array(), $key );
		if ( empty( $p['items'] ) ) {
			return '';
		}
		$h = '<details class="dirnet-selfcert-prev"><summary>' . esc_html( (string) $p['label'] ) . '</summary><ul style="margin:2px 0 8px 1.1em">';
		foreach ( (array) $p['items'] as $y => $u ) {
			$h .= '<li><a href="' . esc_url( $u ) . '" target="_blank" rel="noopener">' . esc_html( __( 'Self-certification', 'directorist-network' ) . ' ' . (int) $y ) . '</a></li>';
		}
		return $h . '</ul></details>';
	}

	public static function html( $pid ) {
		$key = SolidarosBridge::company_id( (int) $pid );
		$url = (string) apply_filters( 'solidaros_selfcert_pdf', '', $key ); // one document: Bilancio sociale + Autocertificazione partecipativa
		if ( '' !== $url ) {
			return '<p class="dirnet-selfcert-yes"><strong><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( __( 'Self-certification', 'directorist-network' ) . ' ' . (int) apply_filters( 'solidaros_selfcert_year', 0, $key ) ) . '</a></strong></p>' . self::previous( $key );
		}
		$wip = (bool) apply_filters( 'solidaros_selfcert_progress', false, $key ); // started, not yet complete
		return '<p class="dirnet-selfcert-none"><strong>' . esc_html( $wip ? __( 'Self-certification: in progress', 'directorist-network' ) : __( 'Self-certification: not present', 'directorist-network' ) ) . '</strong></p>';
	}

	public function widget( $args, $inst ) {
		if ( ! defined( 'ATBDP_POST_TYPE' ) || ! is_singular( ATBDP_POST_TYPE ) ) {
			return;
		}
		$t = Relations::type_of( (int) get_the_ID() );
		if ( ! $t || ! in_array( $t[1], array( 'producer', 'gas', 'network', 'distributor', 'association', 'company' ), true ) ) {
			return;
		}
		$title = ! empty( $inst['title'] ) ? $inst['title'] : __( 'Self-certification', 'directorist-network' );
		echo $args['before_widget'] . $args['before_title'] . esc_html( $title ) . $args['after_title'] . self::html( (int) get_the_ID() ) . $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public function form( $inst ) {
		echo '<p>' . esc_html__( 'Shows the self-certification: present (with its links) or not present.', 'directorist-network' ) . '</p>';
	}

	public function update( $new, $old ) {
		return array( 'title' => isset( $new['title'] ) ? sanitize_text_field( $new['title'] ) : '' );
	}
}

class Evaluation_Widget extends Entity_Widget {
	public function __construct() {
		parent::__construct( 'dirnet_evaluation', __( 'Evaluation systems', 'directorist-network' ), array( 'description' => __( 'PGS and social balance of the entity, from Solidaros.', 'directorist-network' ) ) );
	}
	protected function title_default() {
		return __( 'Evaluation systems', 'directorist-network' );
	}
	protected function body( $post_id ) {
		return (string) apply_filters( 'solidaros_evaluation_block', '', SolidarosBridge::company_id( $post_id ) );
	}
}

class Company_Widget extends Entity_Widget {
	public function __construct() {
		parent::__construct( 'dirnet_company', __( 'Entity profile', 'directorist-network' ), array( 'description' => __( 'Link to the entity profile questionnaire.', 'directorist-network' ) ) );
	}
	protected function title_default() {
		return __( 'Entity profile', 'directorist-network' );
	}
	protected function body( $post_id ) {
		$h = Sheets::html_part( $post_id, 'company' );
		return '' === $h ? '' : '<div class="dirnet-sheets-wrap">' . $h . '</div>';
	}
}

class Products_Widget extends Entity_Widget {
	public function __construct() {
		parent::__construct( 'dirnet_products', __( 'Product profiles', 'directorist-network' ), array( 'description' => __( 'Links to the product profile questionnaires.', 'directorist-network' ) ) );
	}
	protected function title_default() {
		return __( 'Product profiles', 'directorist-network' );
	}
	protected function body( $post_id ) {
		$h = Sheets::html_part( $post_id, 'products' );
		return '' === $h ? '' : '<div class="dirnet-sheets-wrap">' . $h . '</div>';
	}
}

class Relations_Widget extends Entity_Widget {
	public function __construct() {
		parent::__construct( 'dirnet_relations_w', __( 'Relations of the entity', 'directorist-network' ), array( 'description' => __( 'Network relations: members, suppliers, projects.', 'directorist-network' ) ) );
	}
	protected function title_default() {
		return __( 'Relations', 'directorist-network' );
	}
	protected function body( $post_id ) {
		$r          = new Relations();
		$r->compact = true;
		return $r->html( $post_id );
	}
}
