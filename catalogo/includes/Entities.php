<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [dirnet_entities] — every entity of every type on one page, with a filter bar:
 * text search, type, category, location and the choice fields of the selected type.
 * Results are queried and drawn here (not by Directorist's listing shortcode) so any filter can be combined.
 *
 * Attributes: per_page="10", types="slug,slug" (directory-type term slugs; default all generated types).
 */
class Entities {

	const PAGE_OPTION = 'dirnet_entities_page';

	public function boot() {
		add_shortcode( 'dirnet_entities', array( $this, 'shortcode' ) );
		add_shortcode( 'dirnet_map', array( $this, 'map_shortcode' ) );
		add_action( 'template_redirect', array( $this, 'maybe_export' ) );
	}

	/** Export format being served (xls, csv, txt) or ''. */
	private static $export = '';

	/** Runs the page's shortcode with the current filters and streams all results as a file. */
	public function maybe_export() {
		$f = isset( $_GET['dirnet_export'] ) ? sanitize_key( wp_unslash( $_GET['dirnet_export'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! in_array( $f, array( 'xls', 'csv', 'txt' ), true ) || ! is_singular() ) {
			return;
		}
		if ( ! is_user_logged_in() ) { // Excel, CSV e Copia sono riservati agli iscritti
			$to = Pages::id( 'login' ) ? get_permalink( Pages::id( 'login' ) ) : wp_login_url( get_permalink() );
			wp_safe_redirect( add_query_arg( 'redirect_to', rawurlencode( remove_query_arg( 'dirnet_export' ) ), $to ) );
			exit;
		}
		$post = get_queried_object();
		if ( ! $post || ! has_shortcode( (string) $post->post_content, 'dirnet_entities' ) ) {
			return;
		}
		self::$export = $f;
		do_shortcode( $post->post_content ); // the shortcode sends the file and exits
		self::$export = '';
	}

	/** array( column index, 'asc'|'desc' ) from ?ord=&dir=, or array() for the default title order. */
	private static function order() {
		$o = self::req( 'ord' );
		if ( '' === $o || ! ctype_digit( $o ) || (int) $o > 10 ) {
			return array();
		}
		return array( (int) $o, 'desc' === self::req( 'dir' ) ? 'desc' : 'asc' );
	}

	/** Link that sorts the list by column $i (a second click reverses the direction). */
	private static function sort_url( $i ) {
		$o   = self::order();
		$dir = ( $o && $o[0] === $i && 'asc' === $o[1] ) ? 'desc' : 'asc';
		return add_query_arg( array( 'ord' => $i, 'dir' => $dir ), remove_query_arg( 'pg' ) ) . '#dirnet-bar';
	}

	private static function sort_arrow( $i ) {
		$o = self::order();
		return ( $o && $o[0] === $i ) ? ( 'asc' === $o[1] ? ' &uarr;' : ' &darr;' ) : '';
	}

	/** Columns shown (and sortable by a click) in the current view; the dropdown keeps only the others. */
	private static function shown_cols() {
		$v = self::view();
		if ( 'list' === $v ) {
			return array( 0, 1, 2, 3, 4, 5, 8, 9 );
		}
		if ( 'grid' === $v ) {
			return array( 3, 4, 8 );
		}
		return array();
	}

	/** Names of the functions of one entity, for sorting. */
	private static function fn_names( $id ) {
		if ( ! taxonomy_exists( Functions::TAX ) ) {
			return '';
		}
		$t = wp_get_post_terms( $id, Functions::TAX, array( 'fields' => 'names' ) );
		return is_wp_error( $t ) ? '' : implode( ', ', $t );
	}

	/** Posts sorted by one of the list columns. */
	private static function sorted( array $posts, array $by_id, array $ord ) {
		if ( ! $ord ) {
			return $posts;
		}
		$rows = self::plain_rows( $posts, $by_id );
		array_shift( $rows );
		$keys = array();
		foreach ( $posts as $i => $p ) {
			$keys[ $i ] = (string) $rows[ $i ][ $ord[0] ];
		}
		$idx = array_keys( $posts );
		usort( $idx, function ( $a, $b ) use ( $keys, $ord ) {
			$c = strnatcasecmp( remove_accents( $keys[ $a ] ), remove_accents( $keys[ $b ] ) );
			return 'desc' === $ord[1] ? -$c : $c;
		} );
		$out = array();
		foreach ( $idx as $i ) {
			$out[] = $posts[ $i ];
		}
		return $out;
	}

	/** Plain-text header + rows, as in the list view. */
	private static function plain_rows( array $posts, array $by_id ) {
		$rows = array( self::labels() );
		foreach ( $posts as $post ) {
			$id   = (int) $post->ID;
			$tid  = (int) get_post_meta( $id, '_directory_type', true );
			$type = isset( $by_id[ $tid ] ) ? $by_id[ $tid ] : null;
			$geo  = self::geo_names( $id );
			$cat  = array();
			$sub  = array();
			$terms = wp_get_post_terms( $id, ATBDP_CATEGORY );
			foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) {
				if ( $t->parent ) {
					$sub[] = $t->name;
				} else {
					$cat[] = $t->name;
				}
			}
			$sr     = SocialReport::url( $id );
			$rows[] = array(
				html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ), $geo[0], $geo[1], $geo[2],
				implode( ', ', $cat ), implode( ', ', $sub ),
				$type ? self::choice_label( $id, $type, 'legal_form' ) : '',
				$type ? self::choice_label( $id, $type, 'legal_form_2' ) : '',
				SingleHero::roles_line( $id ),
				(string) get_post_meta( $id, '_website', true ),
				'' !== $sr ? __( 'Yes', 'directorist-network' ) : __( 'No', 'directorist-network' ),
			);
		}
		return $rows;
	}

	private static function send_export( array $rows, $format ) {
		$name = sanitize_file_name( 'entities-' . gmdate( 'Y-m-d' ) );
		nocache_headers();
		if ( 'txt' === $format ) {
			header( 'Content-Type: text/plain; charset=utf-8' );
			foreach ( $rows as $r ) {
				echo esc_html( implode( "\t", array_map( function ( $c ) {
					return str_replace( array( "\t", "\r", "\n" ), ' ', (string) $c );
				}, $r ) ) ) . "\n";
			}
		} elseif ( 'csv' === $format ) {
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename=' . $name . '.csv' );
			echo "\xEF\xBB\xBF"; // phpcs:ignore WordPress.Security.EscapeOutput
			$o = fopen( 'php://output', 'w' );
			foreach ( $rows as $r ) {
				fputcsv( $o, $r, ';' );
			}
			fclose( $o ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		} else {
			header( 'Content-Type: application/vnd.ms-excel; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename=' . $name . '.xls' );
			echo '<html><head><meta charset="utf-8"></head><body><table border="1">';
			foreach ( $rows as $i => $r ) {
				echo '<tr>';
				foreach ( $r as $c ) {
					echo ( $i ? '<td>' : '<th>' ) . esc_html( (string) $c ) . ( $i ? '</td>' : '</th>' );
				}
				echo '</tr>';
			}
			echo '</table></body></html>';
		}
		exit;
	}

	/** Dropdown: Excel, CSV, Copy (everything matching the current filters, not just this page). */
	public static function export_menu() {
		$login = is_user_logged_in() ? '' : ( Pages::id( 'login' ) ? get_permalink( Pages::id( 'login' ) ) : wp_login_url( get_permalink() ) );
		$u = function ( $f ) use ( $login ) {
			return '' === $login ? esc_url( add_query_arg( 'dirnet_export', $f ) ) : esc_url( add_query_arg( 'redirect_to', rawurlencode( remove_query_arg( 'dirnet_export' ) ), $login ) ); // Esporta è riservato agli iscritti: gli altri vanno alla pagina di accesso
		};
		return '<select class="dirnet-export" aria-label="' . esc_attr__( 'Export', 'directorist-network' ) . '" onchange="var v=this.value;this.selectedIndex=0;if(!v)return;var p=v.split(\'|\');if(p[0]===\'txt\'){var s=this;fetch(p[1],{credentials:\'same-origin\'}).then(function(r){return r.text()}).then(function(t){return navigator.clipboard.writeText(t)}).then(function(){s.options[0].text=s.dataset.done;setTimeout(function(){s.options[0].text=s.dataset.label},1800)})}else{location.href=p[1]}" data-label="' . esc_attr__( 'Export', 'directorist-network' ) . '" data-done="' . esc_attr__( 'Copied', 'directorist-network' ) . '">'
			. '<option value="">' . esc_html__( 'Export', 'directorist-network' ) . '</option>'
			. '<option value="xls|' . $u( 'xls' ) . '">Excel</option>'
			. '<option value="csv|' . $u( 'csv' ) . '">CSV</option>'
			. '<option value="' . ( '' === $login ? 'txt' : 'go' ) . '|' . $u( 'txt' ) . '">' . esc_html__( 'Copy', 'directorist-network' ) . '</option></select>';
	}

	/** CSS of the entity card and of its grid (also used by the category, territory and type tiles). */
	public static function card_css() {
		return '.dirnet-entities{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin:12px 0 20px}'
			. '.dirnet-entity{position:relative;display:block;margin:0;padding:0;border:1px solid rgba(128,128,128,.35);border-radius:10px;overflow:hidden;background:rgba(128,128,128,.06)}'
			. '.dirnet-entity-img{display:block;height:130px;background-color:rgba(128,128,128,.2);background-size:cover;background-position:center}'
			. '.dirnet-entity-logo{position:absolute;left:14px;top:96px;line-height:0}.dirnet-entity-logo .dirnet-logo{width:56px;height:56px}'
			. '.dirnet-entity-body{padding:36px 14px 12px}.dirnet-entity-body h3{margin:0 0 4px;font-size:1.1em}.dirnet-entity-body h3 a{text-decoration:none}'
			. '.dirnet-entity-line{font-size:.9em;opacity:.85;margin-top:2px;word-break:break-word}.dirnet-entity-exc{font-size:.9em;margin:6px 0}.dirnet-entity-cat{font-weight:600}';
	}

	/** CSS of the coloured tiles: the same on the entities page (types, functions), the categories and the territories. */
	public static function tile_css() {
		return '.dirnet-tiles,.dirnet-cats,.dirnet-terrs,.dirnet-type-cards{display:grid;grid-template-columns:repeat(auto-fill,220px);gap:14px;margin:12px 0 18px}.dirnet-tile{display:flex;flex-direction:column;justify-content:center;box-sizing:border-box;width:220px;height:112px;overflow:hidden;padding:14px 22px;border-radius:12px;color:#fff!important;text-decoration:none!important;box-shadow:0 1px 4px rgba(0,0,0,.2);transition:transform .15s}.dirnet-tile:hover{transform:translateY(-2px)}.dirnet-tile.is-on{outline:3px solid rgba(0,0,0,.35);outline-offset:2px}.dirnet-tile b{display:block;font-size:2em;line-height:1.1}.dirnet-tile span{font-size:1.1em;font-weight:600;line-height:1.2;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}.dirnet-tiles,.dirnet-cats,.dirnet-type-cards{grid-template-columns:repeat(5,minmax(0,1fr))}.dirnet-tiles .dirnet-tile,.dirnet-cats .dirnet-tile,.dirnet-type-cards .dirnet-tile{width:auto}@media(max-width:900px){.dirnet-tiles,.dirnet-cats,.dirnet-type-cards{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:560px){.dirnet-tiles,.dirnet-cats,.dirnet-type-cards{grid-template-columns:repeat(2,minmax(0,1fr))}}';
	}

	/** One coloured tile: big number (the count), then the name. $big, when given, is the number shown instead of $sub. */
	public static function tile( $url, $icon_html, $color, $title, $sub = '', $class = '', $active = false, $big = '' ) {
		return '<a class="dirnet-tile ' . esc_attr( $class ) . ( $active ? ' is-on' : '' ) . '" style="background:' . esc_attr( $color ) . '" href="' . esc_url( $url ) . '">' . ( '' !== ( '' !== $big ? $big : $sub ) ? '<b>' . esc_html( '' !== $big ? $big : $sub ) . '</b>' : '' ) . '<span>' . esc_html( $title ) . '</span></a>';
	}

	/** Two icons: infinite scroll / page numbers. */
	public static function paging_switch( $mode ) {
		$inf = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M11 3h2v12.2l4.6-4.6L19 12l-7 7-7-7 1.4-1.4 4.6 4.6zM5 20h14v2H5z"/></svg>';
		$num = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><text x="12" y="16.5" text-anchor="middle" font-size="13" font-weight="700" fill="currentColor" font-family="sans-serif">1 2</text></svg>';
		$btn = function ( $m, $icon, $title ) use ( $mode ) {
			return '<a href="' . esc_url( add_query_arg( 'paging', $m, remove_query_arg( 'pg' ) ) ) . '" class="' . ( $m === $mode ? 'is-active' : '' ) . '" title="' . esc_attr( $title ) . '" aria-label="' . esc_attr( $title ) . '">' . $icon . '</a>';
		};
		return '<span class="dirnet-viewswitch dirnet-pagingswitch">'
			. $btn( 'scroll', $inf, __( 'Infinite scroll', 'directorist-network' ) )
			. $btn( 'pages', $num, __( 'Page numbers', 'directorist-network' ) ) . '</span>';
	}

	/** Bar on every list. Row 1: view switch, count, paging switch (+ rows per page when paging by numbers). Row 2: Search, Export, Sort by (cards view only), Filters. */
	public static function toolbar( $found, $q = '', $n = '10', $mode = 'scroll', $panel = '', $open = false ) {
		$hidden = '';
		foreach ( $_GET as $k => $v ) { // phpcs:ignore WordPress.Security.NonceVerification
			if ( is_scalar( $v ) && ! in_array( $k, array( 'q', 'n', 'pg', 'dirnet_export', 'paging' ), true ) ) {
				$hidden .= '<input type="hidden" name="' . esc_attr( sanitize_key( $k ) ) . '" value="' . esc_attr( wp_unslash( (string) $v ) ) . '" />';
			}
		}
		$hidden .= '<input type="hidden" name="paging" value="' . esc_attr( $mode ) . '" />';
		$opts = '';
		foreach ( array( '1', '5', '10', '25', '50', 'all' ) as $o ) {
			$opts .= '<option value="' . $o . '"' . selected( (string) $n, $o, false ) . '>' . ( 'all' === $o ? esc_html__( 'All', 'directorist-network' ) : $o ) . '</option>';
		}
		$o    = self::order();
		$none = ! $o;
		$o    = $o ? $o : array( 0, 'asc' ); // default: by name, A to Z
		$sort_hidden = '';
		foreach ( $_GET as $k => $v ) { // phpcs:ignore WordPress.Security.NonceVerification
			if ( is_scalar( $v ) && ! in_array( $k, array( 'ord', 'dir', 'pg', 'dirnet_export' ), true ) ) {
				$sort_hidden .= '<input type="hidden" name="' . esc_attr( sanitize_key( $k ) ) . '" value="' . esc_attr( wp_unslash( (string) $v ) ) . '" />';
			}
		}
		$sort = '<form class="dirnet-sortbox" method="get" action="#dirnet-bar">' . $sort_hidden . '<input type="hidden" name="dir" value="' . esc_attr( $o[1] ) . '" /><label>' . esc_html__( 'Sort by', 'directorist-network' ) . ' <select class="dirnet-sort" name="ord" aria-label="' . esc_attr__( 'Sort by', 'directorist-network' ) . '">';
		$sort .= '<option value="" disabled' . ( $none ? ' selected' : '' ) . '>&mdash;</option>';
		$sort_labels = self::labels();
		$shown = self::shown_cols();
		foreach ( $sort_labels as $i => $label ) {
			if ( in_array( $i, $shown, true ) ) {
				continue;
			}
			$sort .= '<option value="' . $i . '"' . ( ! $none && $o[0] === $i ? ' selected' : '' ) . '>' . esc_html( $label ) . '</option>';
		}
		$flip  = 'asc' === $o[1] ? 'desc' : 'asc';
		$sort .= '</select></label> <button type="submit">' . esc_html__( 'Send', 'directorist-network' ) . '</button><a class="dirnet-sortdir" href="' . esc_url( add_query_arg( array( 'ord' => $o[0], 'dir' => $flip ), remove_query_arg( 'pg' ) ) . '#dirnet-bar' ) . '" title="' . esc_attr( 'asc' === $o[1] ? __( 'Ascending: click for descending', 'directorist-network' ) : __( 'Descending: click for ascending', 'directorist-network' ) ) . '">' . ( 'asc' === $o[1] ? '&uarr;' : '&darr;' ) . '</a></form>';

		$filter_btn = '';
		$panel_html = '';
		if ( '' !== $panel ) {
			$filter_btn = '<button type="button" class="dirnet-filterbtn" aria-expanded="' . ( $open ? 'true' : 'false' ) . '" onclick="var p=this.closest(\'.dirnet-bar\').querySelector(\'.dirnet-filterpanel\');var h=p.hasAttribute(\'hidden\');if(h){p.removeAttribute(\'hidden\')}else{p.setAttribute(\'hidden\',\'\')}this.setAttribute(\'aria-expanded\',h?\'true\':\'false\')">' . esc_html__( 'Filter', 'directorist-network' ) . '</button>';
			$panel_html = '<div class="dirnet-filterpanel"' . ( $open ? '' : ' hidden' ) . '>' . $panel . '</div>';
		}
		$rows = '';
		if ( 'pages' === $mode ) {
			$rows = '<form class="dirnet-rows" method="get" action="">' . $hidden . '<label>' . esc_html__( 'Show', 'directorist-network' ) . ' <select name="n" onchange="this.form.submit()">' . $opts . '</select></label></form>';
		}
		return '<div class="dirnet-bar" id="dirnet-bar">'
			. '<div class="dirnet-row1">' . self::view_switch()
			. '<p class="dirnet-count">' . esc_html( sprintf( /* translators: %d: number of entities */ __( 'Entities found: %d', 'directorist-network' ), (int) $found ) ) . '</p>'
			. '<span class="dirnet-pagingbox">' . self::paging_switch( $mode ) . $rows . '</span></div>'
			. '<div class="dirnet-row2">'
			. '<form class="dirnet-tools" method="get" action="#dirnet-bar">' . $hidden
			. '<label>' . esc_html__( 'Search', 'directorist-network' ) . ' <input type="search" name="q" value="' . esc_attr( $q ) . '" placeholder="' . esc_attr__( 'Search by name…', 'directorist-network' ) . '" /></label> <button type="submit">' . esc_html__( 'Send', 'directorist-network' ) . '</button></form>'
			. self::export_menu() . ( 'grid' === self::view() ? $sort : '' ) . $filter_btn . '</div>'
			. $panel_html . '</div>';
	}

	private static function labels() {
		return array(
			__( 'Name', 'directorist-network' ), __( 'Region', 'directorist-network' ), __( 'Province', 'directorist-network' ), __( 'City', 'directorist-network' ),
			__( 'Category', 'directorist-network' ), __( 'Subcategories', 'directorist-network' ), __( 'Legal form', 'directorist-network' ),
			__( 'Secondary legal form', 'directorist-network' ), __( 'Role', 'directorist-network' ), __( 'Website', 'directorist-network' ), __( 'Social report', 'directorist-network' ),
		);
	}

	/** term slug => array( name, profile, type_slug, term_id ) for generated types. */
	public static function types( $with_campaigns = false ) {
		$out = array();
		foreach ( Relations::registry() as $term_id => $info ) {
			if ( ! $with_campaigns && in_array( $info[1], (array) apply_filters( 'dirnet_hidden_entity_types', array( 'campaign' ) ), true ) ) { // these types have their own pages (Services), not the Entities list
				continue;
			}
			$t = get_term( $term_id, 'atbdp_listing_types' );
			if ( $t && ! is_wp_error( $t ) ) {
				$out[ $t->slug ] = array( 'name' => $t->name, 'profile' => $info[0], 'type' => $info[1], 'term_id' => (int) $term_id );
			}
		}
		return $out;
	}

	/** Functions that have at least one published entity: slug => name. */
	private static function fn_options() {
		$out = array();
		foreach ( self::fn_filled() as $row ) {
			$out[ $row[0]->slug ] = $row[0]->name;
		}
		return $out;
	}

	/** @return array<int,array{0:\WP_Term,1:int}> */
	private static function fn_filled() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$cache = array();
		if ( ! taxonomy_exists( Functions::TAX ) ) {
			return $cache;
		}
		$terms = get_terms( array( 'taxonomy' => Functions::TAX, 'hide_empty' => false ) );
		foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) {
			$q = new \WP_Query( array(
				'post_type'      => ATBDP_POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'tax_query'      => array( array( 'taxonomy' => Functions::TAX, 'field' => 'term_id', 'terms' => $t->term_id ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
			) );
			if ( $q->found_posts > 0 ) {
				$cache[] = array( $t, (int) $q->found_posts );
			}
		}
		return $cache;
	}

	/** Coloured cards: main roles only; the active main role opens a second row with its sub-roles. A second click on the active card goes one level up. */
	private static function fn_tiles( $current ) {
		$rows = self::fn_filled();
		if ( ! $rows ) {
			return '';
		}
		$base = remove_query_arg( array( 'dt', 'fn', 'cat', 'reg', 'prov', 'city', 'dist', 'sr', 'pg', 'q' ) );
		$cur  = null;
		foreach ( $rows as $row ) {
			if ( $current === $row[0]->slug ) {
				$cur = $row[0];
			}
		}
		$top = 0; // active main role
		if ( $cur ) {
			$top = $cur->parent ? (int) $cur->parent : (int) $cur->term_id;
		}
		$card = function ( $t, $n, $on, $up ) use ( $base ) {
			return '<a class="dirnet-tile' . ( $on ? ' is-on' : '' ) . '" style="background:' . esc_attr( Functions::color( $t ) ) . '" href="' . esc_url( $on ? $up : add_query_arg( 'fn', $t->slug, $base ) ) . '"><b>' . (int) $n . '</b><span>' . esc_html( $t->name ) . '</span></a>';
		};
		$cnt = array();
		foreach ( $rows as $row ) {
			$cnt[ (int) $row[0]->term_id ] = (int) $row[1];
		}
		$hd  = 'margin:14px 0 0;font-size:1em;font-weight:700;opacity:.8';
		$h   = '<style>' . self::tile_css() . '</style><h4 class="dirnet-tiles-h" style="' . $hd . '">' . esc_html__( 'Roles', 'directorist-network' ) . '</h4><div class="dirnet-tiles">';
		foreach ( $rows as $row ) {
			list( $t, $n ) = $row;
			if ( ! $t->parent ) {
				$h .= $card( $t, $n, (int) $t->term_id === $top, $base );
			}
		}
		$h .= '</div>';
		if ( $top ) {
			$kids = get_terms( array( 'taxonomy' => Functions::TAX, 'parent' => $top, 'hide_empty' => false, 'orderby' => 'name' ) );
			$pt   = get_term( $top, Functions::TAX );
			$up   = $pt && ! is_wp_error( $pt ) ? add_query_arg( 'fn', $pt->slug, $base ) : $base;
			$sub  = '';
			foreach ( is_wp_error( $kids ) ? array() : $kids as $k ) {
				$sub .= $card( $k, isset( $cnt[ (int) $k->term_id ] ) ? $cnt[ (int) $k->term_id ] : 0, $current === $k->slug, $up );
			}
			if ( '' !== $sub ) {
				$h .= '<h4 class="dirnet-tiles-h" style="' . $hd . '">' . esc_html__( 'Sub-roles', 'directorist-network' ) . '</h4><div class="dirnet-tiles dirnet-subtiles">' . $sub . '</div>';
			}
		}
		return $h;
	}

	public static function tiles( array $types, $current = '' ) {
		$map = array(
			'producer' => array( __( 'Producers', 'directorist-network' ), '#2e7d32' ),
			'gas'      => array( __( 'GAS', 'directorist-network' ), '#ef6c00' ),
			'network'  => array( __( 'Networks', 'directorist-network' ), '#1565c0' ),
			'distributor' => array( __( 'Distributors', 'directorist-network' ), '#00695c' ),
		);
		$spare = array( '#6a1b9a', '#00838f', '#ad1457', '#4e342e' );
		$base  = remove_query_arg( array( 'dt', 'fn', 'cat', 'reg', 'prov', 'city', 'dist', 'sr', 'pg', 'q' ) );
		$h     = '<style>' . self::tile_css() . '</style><div class="dirnet-tiles">';
		$i     = 0;
		foreach ( $types as $slug => $t ) {
			$q = new \WP_Query( array(
				'post_type'      => ATBDP_POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'tax_query'      => array( array( 'taxonomy' => 'atbdp_listing_types', 'field' => 'term_id', 'terms' => $t['term_id'] ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
			) );
			$def   = isset( $map[ $t['type'] ] ) ? $map[ $t['type'] ] : array( $t['name'], $spare[ $i++ % count( $spare ) ] );
			$on    = $current === $slug;
			$h    .= '<a class="dirnet-tile' . ( $on ? ' is-on' : '' ) . '" style="background:' . esc_attr( $def[1] ) . '" href="' . esc_url( $on ? $base : add_query_arg( 'dt', $slug, $base ) ) . '"><b>' . (int) $q->found_posts . '</b><span>' . esc_html( $def[0] ) . '</span></a>';
		}
		return $h . '</div>';
	}

	private static function req( $key ) {
		return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	}

	private static $block_n = 0;
	private static $js_done = false;

	/** Infinite scroll: when the bottom sentinel comes into view, fetch the next page and append its rows or cards. Without JavaScript the page numbers stay. */
	private static function scroll_js() {
		if ( self::$js_done ) {
			return '';
		}
		self::$js_done = true;
		$js = <<<'JS'
(function(){
function init(w){
 var max=+w.dataset.max,page=+w.dataset.page,busy=false,s=w.querySelector(".dirnet-sentinel"),pg=w.querySelector(".dirnet-pager");
 if(pg){pg.style.display="none"}
 function more(){
  if(busy||page>=max){return}
  busy=true;
  var u=new URL(location.href);u.searchParams.set("pg",page+1);
  fetch(u.toString(),{credentials:"same-origin"}).then(function(r){return r.text()}).then(function(t){
   var d=new DOMParser().parseFromString(t,"text/html"),n=d.querySelector('.dirnet-listwrap[data-idx="'+w.dataset.idx+'"]');
   if(!n){throw 0}
   var a=w.querySelector("tbody"),b=n.querySelector("tbody");
   if(!a||!b){a=w.querySelector(".dirnet-entities");b=n.querySelector(".dirnet-entities")}
   if(!a||!b){throw 0}
   Array.prototype.slice.call(b.children).forEach(function(c){a.appendChild(document.importNode(c,true))});
   page++;busy=false;
   if(page>=max){s.remove();io&&io.disconnect()}
  }).catch(function(){busy=false;if(pg){pg.style.display=""}if(io){io.disconnect()}});
 }
 var io=null;
 if("IntersectionObserver" in window){io=new IntersectionObserver(function(e){if(e[0].isIntersecting){more()}},{rootMargin:"400px"});io.observe(s)}
 else if(pg){pg.style.display=""}
}
function go(){Array.prototype.forEach.call(document.querySelectorAll(".dirnet-listwrap"),init)}
if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",go)}else{go()}
})();
JS;
		return '<script>' . $js . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	

	

	

	/** Map of every entity linked to this one (outgoing and incoming relations), for all sheet types. */
	public static function relations_map( $post_id ) {
		$ids = self::by_ids( $post_id );
		foreach ( (array) get_post_meta( $post_id ) as $k => $vals ) {
			if ( 0 === strpos( $k, '_dirnet_rel_' ) ) {
				foreach ( (array) $vals as $v ) {
					foreach ( (array) maybe_unserialize( $v ) as $i ) {
						$ids[] = (int) $i;
					}
				}
			}
		}
		$ids = array_values( array_diff( array_unique( array_filter( $ids ) ), array( (int) $post_id ) ) );
		if ( ! $ids ) {
			return '';
		}
		$posts = get_posts( array( 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'publish', 'post__in' => $ids, 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		if ( ! $posts ) {
			return '';
		}
		$old              = self::$map_height;
		self::$map_height = 360;
		$h                = self::map( $posts );
		self::$map_height = $old;
		if ( '' === trim( $h ) ) {
			return '';
		}
		return '<style>' . self::tile_css() . '</style><h3>' . esc_html__( 'Map of the relations', 'directorist-network' ) . '</h3>' . $h;
	}

	/** Ids of the entities linked to this one by any relation (the key is named by the profile: project, campaign...). */
	private static function by_ids( $post_id ) {
		$ids = array();
		foreach ( (array) get_post_meta( $post_id ) as $k => $vals ) {
			if ( 0 === strpos( $k, '_dirnet_by_' ) ) {
				foreach ( (array) $vals as $v ) {
					foreach ( (array) maybe_unserialize( $v ) as $i ) {
						$ids[] = (int) $i;
					}
				}
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	

	

	

	

	

	

	

	private static $force_view = '';
	private static $map_height = 560;

	/** [dirnet_map]: only the map of all entities (or of the chosen types / category / place). No filters or toolbar unless asked. */
	public function map_shortcode( $atts ) {
		$a = shortcode_atts( array( 'types' => '', 'sr' => '', 'location' => '', 'category' => '', 'filters' => '0', 'bar' => '0', 'height' => '560', 'tiles' => '0' ), $atts, 'dirnet_map' ); // only the map: the function / type tiles of the entities page are not part of it
		self::$force_view = 'map';
		self::$map_height = max( 200, min( 1600, (int) $a['height'] ) );
		unset( $a['height'] );
		$out              = $this->shortcode( $a );
		self::$force_view = '';
		self::$map_height = 560;
		return $out;
	}

	/** Shortcode entry: an error inside the plugin must not take the whole page down; admins see what failed. */
	public function shortcode( $atts ) {
		try {
			$h = $this->render( $atts );
			return '' === trim( (string) $h ) ? '' : '<div class="dirnet-block">' . $h . '</div>'; // a plain wrapper, like the territory page's: the theme then lays it out as one block
		} catch ( \Throwable $e ) {
			error_log( '[directorist-network] dirnet_entities: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return current_user_can( 'manage_options' ) ? '<pre style="white-space:pre-wrap;background:#fff3f3;border:1px solid #c00;padding:10px;font-size:12px">' . esc_html( 'Directorist Network [dirnet_entities]: ' . $e->getMessage() . ' — ' . basename( $e->getFile() ) . ':' . $e->getLine() ) . '</pre>' : '';
		}
	}

	private function render( $atts ) {
		$idx = ++self::$block_n; // same order on every request: a block finds itself in the next page
		if ( ! Generator::directorist_ready() ) {
			return '';
		}
		$a     = shortcode_atts( array( 'per_page' => 10, 'types' => '', 'sr' => '', 'location' => '', 'category' => '', 'filters' => '0', 'bar' => '1', 'tiles' => '', 'children' => '1', 'paging' => 'scroll' ), $atts, 'dirnet_entities' );
		$types = self::types( false ); // campaigns no longer exist; the filter below stays for old data
		if ( $a['types'] ) {
			$want  = array_flip( array_map( 'sanitize_title', explode( ',', $a['types'] ) ) );
			$types = array_intersect_key( self::types( true ), $want );
			$types = array_filter( $types, function ( $t, $slug ) use ( $want ) {
				return 'campaign' !== $t['type'] || isset( $want[ $slug ] );
			}, ARRAY_FILTER_USE_BOTH );
		}
		if ( ! $types ) {
			return '';
		}

		$q    = self::req( 'q' );
		$dt   = sanitize_title( self::req( 'dt' ) );
		$dt   = isset( $types[ $dt ] ) ? $dt : '';
		$fn   = taxonomy_exists( Functions::TAX ) ? sanitize_title( self::req( 'fn' ) ) : '';
		$cat  = sanitize_title( self::req( 'cat' ) );
		$reg  = sanitize_title( self::req( 'reg' ) );
		$prov = $reg ? sanitize_title( self::req( 'prov' ) ) : '';
		$city = $prov ? sanitize_title( self::req( 'city' ) ) : '';
		$dist = $city ? sanitize_title( self::req( 'dist' ) ) : '';
		$loc  = $dist ? $dist : ( $city ? $city : ( $prov ? $prov : $reg ) ); // deepest level chosen
		if ( $a['location'] ) { // fixed by the page (e.g. a territory page)
			$loc = sanitize_title( $a['location'] );
		}
		if ( $a['category'] ) {
			$cat = sanitize_title( $a['category'] );
		}
		$show_filters = '0' !== (string) $a['filters'];
		$is_map       = 'map' === self::view();
		$n            = strtolower( self::req( 'n' ) );
		$per_page     = max( 1, (int) $a['per_page'] );
		$map_mode     = 'map' === self::view();
		if ( $map_mode || 'all' === $n ) {
			$per_page = -1;
		} elseif ( in_array( $n, array( '1', '5', '10', '25', '50' ), true ) ) {
			$per_page = (int) $n;
		}
		$page = max( 1, (int) self::req( 'pg' ) );

		// Choice fields of the selected type become extra filters.
		$extra = array();
		if ( $dt ) {
			$profile = Profile::get( $types[ $dt ]['profile'] );
			if ( $profile ) {
				$defs = $profile->types();
				$only = isset( $defs[ $types[ $dt ]['type'] ]['filters'] ) ? array_map( function ( $k ) {
					return str_replace( '-', '_', $k );
				}, $defs[ $types[ $dt ]['type'] ]['filters'] ) : array();
				foreach ( ColumnMap::for_type( $profile, $types[ $dt ]['type'] ) as $key => $col ) {
					if ( 'choice' === $col['kind'] && $col['options'] && ( ! $only || in_array( str_replace( '-', '_', $key ), $only, true ) ) ) {
						$extra[ $key ] = $col;
					}
				}
			}
		}

		$tax  = array();
		$meta = array();
		if ( $dt ) {
			$tax[] = array( 'taxonomy' => 'atbdp_listing_types', 'field' => 'term_id', 'terms' => $types[ $dt ]['term_id'] );
		} else {
			$tax[] = array( 'taxonomy' => 'atbdp_listing_types', 'field' => 'term_id', 'terms' => wp_list_pluck( $types, 'term_id' ) );
		}
		if ( $cat ) {
			$tax[] = array( 'taxonomy' => ATBDP_CATEGORY, 'field' => 'slug', 'terms' => $cat, 'include_children' => '0' !== (string) $a['children'] );
		}
		if ( $fn ) {
			$tax[] = array( 'taxonomy' => Functions::TAX, 'field' => 'slug', 'terms' => $fn );
		}
		if ( $loc ) {
			$tax[] = array( 'taxonomy' => ATBDP_LOCATION, 'field' => 'slug', 'terms' => $loc, 'include_children' => true );
		}
		$sr = self::req( 'sr' );
		if ( '' === $sr && in_array( (string) $a['sr'], array( '0', '1' ), true ) ) {
			$sr = (string) $a['sr']; // preset by the shortcode, e.g. the Social Report page
		}
		if ( '1' === $sr ) {
			$meta[] = array( 'key' => SocialReport::META, 'value' => '', 'compare' => '!=' );
		} elseif ( '0' === $sr ) {
			$meta[] = array( 'relation' => 'OR', array( 'key' => SocialReport::META, 'compare' => 'NOT EXISTS' ), array( 'key' => SocialReport::META, 'value' => '', 'compare' => '=' ) );
		} else {
			$sr = '';
		}
		$chosen = array();
		foreach ( $extra as $key => $col ) {
			$v = sanitize_text_field( self::req( 'f_' . $key ) );
			if ( '' !== $v && isset( $col['options'][ $v ] ) ) {
				$chosen[ $key ] = $v;
				$meta[]         = array( 'key' => '_' . $col['meta'], 'value' => $v, 'compare' => empty( $col['multi'] ) ? '=' : 'LIKE' );
			}
		}

		$args = array(
			'post_type'      => ATBDP_POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'tax_query'      => $tax, // phpcs:ignore WordPress.DB.SlowDBQuery
		);
		if ( $q ) {
			$args['s'] = $q;
		}
		if ( $meta ) {
			$args['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		if ( self::$export ) {
			$args['posts_per_page'] = -1;
			$args['paged']          = 1;
			$by = array();
			foreach ( $types as $t ) {
				$by[ $t['term_id'] ] = $t;
			}
			$all   = new \WP_Query( $args );
			$posts = self::sorted( $all->posts, $by, self::order() );
			self::send_export( self::plain_rows( $posts, $by ), self::$export );
		}
		$ord   = self::order();
		$by_id = array();
		foreach ( $types as $slug => $t ) {
			$by_id[ $t['term_id'] ] = $t;
		}
		if ( $ord ) { // sorting by a computed column: sort the whole result, then cut the page
			$args['posts_per_page'] = -1;
			$args['paged']          = 1;
			$query                  = new \WP_Query( $args );
			$sorted                 = self::sorted( $query->posts, $by_id, $ord );
			$total                  = count( $sorted );
			$query->found_posts     = $total;
			$query->max_num_pages   = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;
			$query->posts           = $per_page > 0 ? array_slice( $sorted, ( $page - 1 ) * $per_page, $per_page ) : $sorted;
		} else {
			$query = new \WP_Query( $args );
		}

		$mode = self::req( 'paging' );
		$mode = in_array( $mode, array( 'scroll', 'pages' ), true ) ? $mode : ( 'pages' === $a['paging'] ? 'pages' : 'scroll' );
		$out  = self::css();
		$show_tiles = '' === (string) $a['tiles'] ? ( ! $a['types'] && ! $a['location'] && ! $a['category'] ) : '1' === (string) $a['tiles'];
		if ( $show_tiles ) {
			$ft   = self::fn_tiles( $fn );
			$out .= '' !== $ft ? $ft : self::tiles( $types, $dt );
		}
		$fh = '';
		if ( $show_filters ) {
			$fh .= '<form class="dirnet-filters" method="get" action="">';
			$fh .= '<input type="hidden" name="paging" value="' . esc_attr( $mode ) . '" />';
			if ( 'grid' === self::view() ) {
				$fh .= '<input type="hidden" name="vista" value="grid" />';
			}
			foreach ( array( 'q', 'n' ) as $keep ) {
				if ( '' !== self::req( $keep ) ) {
					$fh .= '<input type="hidden" name="' . $keep . '" value="' . esc_attr( self::req( $keep ) ) . '" />';
				}
			}
			$fopts = self::fn_options();
			if ( $fopts ) {
				$fh .= self::select( 'fn', __( 'All roles', 'directorist-network' ), $fopts, $fn, true );
			} else {
				$fh .= self::select( 'dt', __( 'All types', 'directorist-network' ), wp_list_pluck( $types, 'name' ), $dt, true );
			}
			$fh .= self::term_select( 'cat', ATBDP_CATEGORY, __( 'All categories', 'directorist-network' ), $cat );
			$fh .= self::geo_selects( $reg, $prov, $city, $dist );
			foreach ( $extra as $key => $col ) {
				$fh .= self::select( 'f_' . $key, $col['label'], $col['options'], isset( $chosen[ $key ] ) ? $chosen[ $key ] : '', false );
			}
			$fh .= self::select( 'sr', __( 'Social report: any', 'directorist-network' ), array( '1' => __( 'Social report available', 'directorist-network' ), '0' => __( 'Social report not available', 'directorist-network' ) ), $sr, false );
			$fh .= '<button type="submit">' . esc_html__( 'Filter', 'directorist-network' ) . '</button>';
			if ( $dt || $fn || $cat || $reg || '' !== $sr ) {
				$fh .= ' <a href="' . esc_url( remove_query_arg( array( 'dt', 'fn', 'cat', 'reg', 'prov', 'city', 'dist', 'sr', 'pg' ) ) ) . '">' . esc_html__( 'Reset', 'directorist-network' ) . '</a>';
			}
			$fh .= '</form>';
		}
		$filters_open = $dt || $fn || $cat || $reg || '' !== $sr || $chosen;

		if ( '0' !== (string) $a['bar'] ) {
			$bar = self::toolbar( (int) $query->found_posts, $q, $n ? $n : (string) $per_page, $mode, 'only' === $a['bar'] ? '' : $fh, (bool) $filters_open );
			if ( 'only' === $a['bar'] ) {
				return self::css() . $bar;
			}
			$out .= $bar;
			$fh   = ''; // the filters live in the bar now
		}
		$out .= $fh;

		if ( empty( $query->posts ) ) {
			return $out . '<p>' . esc_html__( 'No entities match these filters.', 'directorist-network' ) . '</p>';
		}
		$more = $query->max_num_pages > 1 && 'map' !== self::view() && ! self::$export;
		if ( $more && 'scroll' === $mode ) {
			$out .= '<div class="dirnet-listwrap" data-idx="' . (int) $idx . '" data-page="' . (int) $page . '" data-max="' . (int) $query->max_num_pages . '">';
		}
		if ( 'map' === self::view() ) {
			$out .= self::map( $query->posts );
		} elseif ( 'list' === self::view() ) {
			$out .= self::table( $query->posts, $by_id );
		} else {
			$out .= '<div class="dirnet-entities dirnet-view-grid">';
			foreach ( $query->posts as $post ) {
				$out .= self::card( $post, $by_id );
			}
			$out .= '</div>';
		}

		if ( $query->max_num_pages > 1 ) {
			$out .= '<nav class="dirnet-pager">' . wp_kses_post( paginate_links( array(
				'base'      => add_query_arg( 'pg', '%#%' ),
				'format'    => '',
				'current'   => $page,
				'total'     => (int) $query->max_num_pages,
				'prev_text' => '&lsaquo; ' . esc_html__( 'Previous', 'directorist-network' ),
				'next_text' => esc_html__( 'Next', 'directorist-network' ) . ' &rsaquo;',
			) ) ) . '</nav>';
		}
		if ( $more && 'scroll' === $mode ) {
			$out .= '<div class="dirnet-sentinel" aria-hidden="true" style="height:1px"></div></div>' . self::scroll_js();
		}
		wp_reset_postdata();
		return $out;
	}

	private static function card( $post, array $by_id ) {
		$id    = (int) $post->ID;
		$tid   = (int) get_post_meta( $id, '_directory_type', true );
		$type  = isset( $by_id[ $tid ] ) ? $by_id[ $tid ] : null;
		$img   = '';
		$att   = (int) get_post_meta( $id, '_listing_prv_img', true );
		if ( $att ) {
			$img = (string) wp_get_attachment_image_url( $att, 'medium' );
		}
		if ( ! $img && defined( 'DIRNET_FILE' ) ) {
			$img = Placeholder::url();
		}
		$cats = wp_get_post_terms( $id, ATBDP_CATEGORY, array( 'fields' => 'names' ) );
		$link = get_permalink( $id );
		$city = self::city_name( $id );
		$exc  = trim( (string) $post->post_excerpt );

		$h  = '<div class="dirnet-entity">';
		$h .= '<a class="dirnet-entity-img" href="' . esc_url( $link ) . '" style="background-image:url(' . esc_url( $img ) . ')" aria-label="' . esc_attr( get_the_title( $post ) ) . '"></a>';
		$h .= '<div class="dirnet-entity-logo">' . Logo::img( $id, 56 ) . '</div>';
		$h .= '<div class="dirnet-entity-body">';
		$h .= '<h3><a href="' . esc_url( $link ) . '">' . esc_html( get_the_title( $post ) ) . '</a></h3>';
		if ( '' !== $city ) {
			$h .= '<div class="dirnet-entity-line dirnet-entity-city"><a class="dirnet-sorth" href="' . esc_url( self::sort_url( 3 ) ) . '" title="' . esc_attr__( 'Sort by', 'directorist-network' ) . '">' . esc_html( $city ) . '</a></div>';
		}
		if ( '' !== $exc ) {
			$h .= '<p class="dirnet-entity-exc">' . esc_html( wp_trim_words( $exc, 22 ) ) . '</p>';
		}
		if ( ! is_wp_error( $cats ) && $cats ) {
			$h .= '<div class="dirnet-entity-line dirnet-entity-cat"><a class="dirnet-sorth" href="' . esc_url( self::sort_url( 4 ) ) . '" title="' . esc_attr__( 'Sort by', 'directorist-network' ) . '">' . esc_html( implode( ', ', array_slice( $cats, 0, 3 ) ) ) . '</a></div>';
		}
		$roles = SingleHero::roles_line( $id );
		if ( '' !== $roles ) {
			$h .= '<div class="dirnet-entity-line dirnet-entity-role"><a class="dirnet-sorth" href="' . esc_url( self::sort_url( 8 ) ) . '" title="' . esc_attr__( 'Sort by', 'directorist-network' ) . '">' . esc_html( $roles ) . '</a></div>';
		}
		return $h . '</div></div>';
	}

	/** Region, province, city names of an entity (empty ones left out). */
	public static function place_parts( $id ) {
		return array_values( array_filter( array_map( 'strval', self::geo_names( (int) $id ) ) ) );
	}

	/** Identity data of one entity for the sidebar: array of label => html (already escaped). */
	public static function identity_rows( $id ) {
		$id   = (int) $id;
		$tid  = (int) get_post_meta( $id, '_directory_type', true );
		$type = null;
		foreach ( self::types( true ) as $t ) {
			if ( $t['term_id'] === $tid ) {
				$type = $t;
				break;
			}
		}
		$rows = array();
		if ( $type ) {
			$rows[ __( 'Type', 'directorist-network' ) ] = esc_html( $type['name'] );
			foreach ( array( 'legal_form' => __( 'Legal form', 'directorist-network' ), 'legal_form_2' => __( 'Secondary legal form', 'directorist-network' ) ) as $k => $l ) {
				$v = self::choice_label( $id, $type, $k );
				if ( '' !== $v ) {
					$rows[ $l ] = esc_html( $v );
				}
			}
		}
		$geo  = self::geo_names( $id );
		$addr = trim( (string) get_post_meta( $id, '_address', true ) );
		$place = array_filter( array( $addr, $geo[2], $geo[1], $geo[0] ) );
		if ( $place ) {
			$rows[ __( 'Where', 'directorist-network' ) ] = esc_html( implode( ', ', array_unique( $place ) ) );
		}
		$tel = trim( (string) get_post_meta( $id, '_phone', true ) );
		if ( '' !== $tel ) {
			$rows[ __( 'Phone', 'directorist-network' ) ] = '<a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $tel ) ) . '">' . esc_html( $tel ) . '</a>';
		}
		$tel2 = trim( (string) get_post_meta( $id, '_custom-phone2', true ) );
		if ( '' !== $tel2 ) {
			$rows[ __( 'Second phone', 'directorist-network' ) ] = '<a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $tel2 ) ) . '">' . esc_html( $tel2 ) . '</a>';
		}
		foreach ( array( '_email' => __( 'E-mail', 'directorist-network' ), '_custom-email2' => __( 'Second e-mail', 'directorist-network' ) ) as $mk => $ml ) {
			$em = trim( (string) get_post_meta( $id, $mk, true ) );
			if ( '' !== $em && is_email( $em ) ) {
				$rows[ $ml ] = '<a href="mailto:' . esc_attr( $em ) . '">' . esc_html( $em ) . '</a>';
			}
		}
		$web = trim( (string) get_post_meta( $id, '_website', true ) );
		if ( '' !== $web ) {
			$rows[ __( 'Website', 'directorist-network' ) ] = '<a href="' . esc_url( $web ) . '" rel="noopener" target="_blank">' . esc_html( preg_replace( '#^https?://(www\.)?#', '', rtrim( $web, '/' ) ) ) . '</a>';
		}
		$ref = trim( (string) get_post_meta( $id, '_custom-contact-person', true ) );
		if ( '' === $ref ) {
			$ref = trim( (string) get_post_meta( $id, '_custom-sol-referent-name', true ) ); // the name in the Referente group
		}
		if ( '' !== $ref ) {
			$rows[ __( 'Contact person', 'directorist-network' ) ] = esc_html( $ref );
		}
		return $rows;
	}

	/** Legal form label of a listing, '' if none. */
	public static function legal_label( $id ) {
		$tid = (int) get_post_meta( $id, '_directory_type', true );
		foreach ( self::types( true ) as $t ) {
			if ( $t['term_id'] === $tid ) {
				return self::choice_label( $id, $t, 'legal_form' );
			}
		}
		return '';
	}

	/** URL of the Entities page. */
	public static function page_url() {
		$p = (int) get_option( self::PAGE_OPTION );
		return $p ? get_permalink( $p ) : home_url( '/' );
	}

	/** Label of a choice field value of a listing (e.g. legal form), '' if unset. */
	private static function choice_label( $id, array $type, $key ) {
		static $cols = array();
		$ck = $type['profile'] . '/' . $type['type'];
		if ( ! isset( $cols[ $ck ] ) ) {
			$profile      = Profile::get( $type['profile'] );
			$cols[ $ck ] = $profile ? ColumnMap::for_type( $profile, $type['type'] ) : array();
		}
		if ( empty( $cols[ $ck ][ $key ] ) ) {
			return '';
		}
		$v = (string) get_post_meta( $id, '_' . $cols[ $ck ][ $key ]['meta'], true );
		return isset( $cols[ $ck ][ $key ]['options'][ $v ] ) ? $cols[ $ck ][ $key ]['options'][ $v ] : $v;
	}

	/** Region / province / city names of an entity, from its deepest location term. */
	private static function geo_names( $id ) {
		$out   = array( '', '', '' );
		$terms = wp_get_post_terms( $id, ATBDP_LOCATION );
		if ( is_wp_error( $terms ) || ! $terms ) {
			return $out;
		}
		$best = null;
		$bd   = -1;
		foreach ( $terms as $t ) {
			$d = count( get_ancestors( $t->term_id, ATBDP_LOCATION, 'taxonomy' ) );
			if ( $d > $bd ) {
				$bd   = $d;
				$best = $t;
			}
		}
		$chain = array_reverse( get_ancestors( $best->term_id, ATBDP_LOCATION, 'taxonomy' ) );
		$chain[] = $best->term_id;
		foreach ( array_slice( $chain, 0, 3 ) as $i => $tid ) {
			$t = get_term( $tid, ATBDP_LOCATION );
			if ( $t && ! is_wp_error( $t ) ) {
				$out[ $i ] = $t->name;
			}
		}
		return $out;
	}

	/** List view: per entity, a line of field names with the values right below (stacked label: value on small screens). */
	/** Map of the entries matching the current filters (all of them, not one page): Leaflet + OpenStreetMap. */
	private static function map( array $posts ) {
		$pts  = array();
		$miss = 0;
		foreach ( $posts as $p ) {
			$lat = (float) get_post_meta( $p->ID, '_manual_lat', true );
			$lng = (float) get_post_meta( $p->ID, '_manual_lng', true );
			if ( ! $lat && ! $lng ) {
				$miss++;
				continue;
			}
			$cat   = '';
			$color = '#3d85c6';
			$terms = wp_get_post_terms( $p->ID, ATBDP_CATEGORY );
			foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) {
				$c = (string) get_term_meta( $t->term_id, CategoryIcons::COLOR, true );
				if ( ! $cat ) {
					$cat = $t->name;
				}
				if ( preg_match( '/^#[0-9a-f]{6}$/i', $c ) ) {
					$color = $c;
					$cat   = $t->name;
					break;
				}
			}
			$tid  = (int) get_post_meta( $p->ID, '_directory_type', true );
			$tt   = $tid ? get_term( $tid, 'atbdp_listing_types' ) : null;
			$pts[] = array( 'lat' => $lat, 'lng' => $lng, 'title' => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ), 'url' => get_permalink( $p ), 'cat' => $cat, 'color' => $color, 'layer' => ( $tt && ! is_wp_error( $tt ) ) ? html_entity_decode( $tt->name, ENT_QUOTES, 'UTF-8' ) : __( 'Entities', 'directorist-network' ) );
		}

		/**
		 * Extra layers, e.g. from a maps add-on. Each item: name; type = geojson (url or data) | tile (url, attribution)
		 * | wms (url, layers); style (Leaflet path options, geojson); on (bool, shown at start, default true).
		 */
		$extra = array();
		foreach ( (array) apply_filters( 'dirnet_map_layers', array(), $posts ) as $l ) {
			if ( ! is_array( $l ) || empty( $l['name'] ) || empty( $l['type'] ) ) {
				continue;
			}
			$extra[] = array(
				'name'  => wp_strip_all_tags( (string) $l['name'] ),
				'type'  => sanitize_key( $l['type'] ),
				'url'   => isset( $l['url'] ) ? esc_url_raw( $l['url'] ) : '',
				'data'  => isset( $l['data'] ) ? $l['data'] : null,
				'attr'  => isset( $l['attribution'] ) ? wp_strip_all_tags( (string) $l['attribution'] ) : '',
				'wms'   => isset( $l['layers'] ) ? sanitize_text_field( $l['layers'] ) : '',
				'style' => isset( $l['style'] ) && is_array( $l['style'] ) ? $l['style'] : new \stdClass(),
				'on'    => ! isset( $l['on'] ) || $l['on'],
			);
		}

		$id = 'dirnet-map-' . wp_rand( 1000, 9999 );
		$h  = '<div id="' . $id . '" class="dirnet-map" style="height:' . (int) self::$map_height . 'px;margin:12px 0;border-radius:8px;position:relative;z-index:0;isolation:isolate;overflow:hidden"></div>';
		if ( $miss ) {
			$h .= '<p class="dirnet-map-note">' . esc_html( sprintf( /* translators: %d: number of entities */ ( 1 === $miss ? __( '%d entity has no coordinates and is not on the map.', 'directorist-network' ) : __( '%d entities have no coordinates and are not on the map.', 'directorist-network' ) ), $miss ) ) . '</p>';
		}
		$js = <<<'JS'
function(){
var L=window.L,m=L.map("__ID__"),P=__PTS__,X=__EXTRA__,b=[],over={},clu=!!L.markerClusterGroup;
var osm=L.tileLayer("https://tile.openstreetmap.org/{z}/{x}/{y}.png",{maxZoom:19,attribution:"&copy; OpenStreetMap"}).addTo(m);
var base={"OpenStreetMap":osm,"__SAT__":L.tileLayer("https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}",{maxZoom:19,attribution:"Tiles &copy; Esri"}),"__LIGHT__":L.tileLayer("https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png",{maxZoom:19,subdomains:"abcd",attribution:"&copy; OpenStreetMap &copy; CARTO"})};
P.forEach(function(p){
 var i=L.divIcon({className:"",iconSize:[26,26],iconAnchor:[13,26],html:"<span style=\"display:block;width:26px;height:26px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);background:"+p.color+";border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.4)\"></span>"});
 var a=document.createElement("a");a.href=p.url;a.textContent=p.title;var d=document.createElement("div");d.appendChild(a);
 if(p.cat){d.appendChild(document.createElement("br"));d.appendChild(document.createTextNode(p.cat))}
 var g=over[p.layer]||(over[p.layer]=clu?L.markerClusterGroup({showCoverageOnHover:false,maxClusterRadius:45}):L.layerGroup());
 g.addLayer(L.marker([p.lat,p.lng],{icon:i}).bindPopup(d));b.push([p.lat,p.lng]);
});
Object.keys(over).forEach(function(k){over[k].addTo(m)});
X.forEach(function(x){
 var l=null;
 if(x.type==="tile"&&x.url){l=L.tileLayer(x.url,{attribution:x.attr||""})}
 else if(x.type==="wms"&&x.url){l=L.tileLayer.wms(x.url,{layers:x.wms,format:"image/png",transparent:true,attribution:x.attr||""})}
 else if(x.type==="geojson"){
  l=L.geoJSON(null,{style:function(){return x.style},onEachFeature:function(f,y){var n=f.properties&&(f.properties.name||f.properties.title||f.properties.nome);if(n){y.bindPopup(document.createTextNode(n).textContent)}}});
  if(x.data){l.addData(x.data)}else if(x.url){fetch(x.url).then(function(r){return r.json()}).then(function(j){l.addData(j)}).catch(function(){})}
 }
 if(l){over[x.name]=l;if(x.on)l.addTo(m)}
});
L.control.layers(base,over,{collapsed:true}).addTo(m);
var it=b.filter(function(q){return q[0]>35.3&&q[0]<47.2&&q[1]>6.4&&q[1]<18.8});
if(it.length){m.fitBounds(it,{padding:[30,30],maxZoom:14})}else if(b.length){m.fitBounds(b,{padding:[30,30],maxZoom:14})}else{m.setView([42.5,12.5],6)}
}
JS;
		$js = str_replace(
			array( '__ID__', '__PTS__', '__EXTRA__', '__SAT__', '__LIGHT__' ),
			array( $id, wp_json_encode( $pts ), wp_json_encode( $extra ), esc_js( __( 'Satellite', 'directorist-network' ) ), esc_js( __( 'Light', 'directorist-network' ) ) ),
			$js
		);
		return $h . '<script>(function(){var go=' . $js . ';function css(u){var c=document.createElement("link");c.rel="stylesheet";c.href=u;document.head.appendChild(c)}function js(u,f){var s=document.createElement("script");s.src=u;s.onload=f;s.onerror=f;document.head.appendChild(s)}'
			. 'function load(){if(window.L&&window.L.markerClusterGroup){go();return}'
			. 'var more=function(){css("https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css");css("https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css");js("https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js",go)};'
			. 'if(window.L){more();return}css("https://unpkg.com/leaflet@1.9.4/dist/leaflet.css");js("https://unpkg.com/leaflet@1.9.4/dist/leaflet.js",more)}'
			. 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",load)}else{load()}})();</script>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private static function table( array $posts, array $by_id ) {
		$skip   = array( 6, 7, 10 ); // legal forms: left out of the table view (still in exports)
		$labels = array_diff_key( self::labels(), array_flip( $skip ) );

		$h = '<div class="dirnet-table-wrap"><table class="dirnet-table"><thead><tr>';
		foreach ( $labels as $i => $label ) {
			$h .= '<th><a class="dirnet-sorth" href="' . esc_url( self::sort_url( (int) $i ) ) . '" title="' . esc_attr__( 'Sort by', 'directorist-network' ) . '">' . esc_html( $label ) . self::sort_arrow( (int) $i ) . '</a></th>';
		}
		$h .= '</tr></thead><tbody>';
		foreach ( $posts as $post ) {
			$id   = (int) $post->ID;
			$tid  = (int) get_post_meta( $id, '_directory_type', true );
			$type = isset( $by_id[ $tid ] ) ? $by_id[ $tid ] : null;
			$geo  = self::geo_names( $id );
			$cat  = array();
			$sub  = array();
			$terms = wp_get_post_terms( $id, ATBDP_CATEGORY );
			foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) {
				if ( $t->parent ) {
					$sub[] = $t->name;
				} else {
					$cat[] = $t->name;
				}
			}
			$web = (string) get_post_meta( $id, '_website', true );
			$sr  = SocialReport::url( $id );
			$vals = array(
				Logo::img( $id, 28 ) . ' <a href="' . esc_url( get_permalink( $id ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a>',
				esc_html( $geo[0] ), esc_html( $geo[1] ), esc_html( $geo[2] ),
				esc_html( implode( ', ', $cat ) ), esc_html( implode( ', ', $sub ) ),
				esc_html( $type ? self::choice_label( $id, $type, 'legal_form' ) : '' ),
				esc_html( $type ? self::choice_label( $id, $type, 'legal_form_2' ) : '' ),
				esc_html( SingleHero::roles_line( $id ) ),
				$web ? '<a href="' . esc_url( $web ) . '" rel="noopener" target="_blank" title="' . esc_attr( $web ) . '">' . esc_html__( 'link', 'directorist-network' ) . '</a>' : '',
				'' !== $sr ? '<a class="dirnet-sr dirnet-sr-yes" href="' . esc_url( $sr ) . '" rel="noopener" target="_blank">' . esc_html__( 'Yes', 'directorist-network' ) . '</a>' : '<span class="dirnet-sr dirnet-sr-no">' . esc_html__( 'No', 'directorist-network' ) . '</span>',
			);
			$h .= '<tr>';
			foreach ( $labels as $i => $label ) {
				$h .= '<td data-label="' . esc_attr( $label ) . '">' . ( '' === $vals[ $i ] ? '&ndash;' : $vals[ $i ] ) . '</td>';
			}
			$h .= '</tr>';
		}
		return $h . '</tbody></table></div>';
	}

	/** City of an entity: the location term at depth 3 if there is one, else its deepest term. */
	private static function city_name( $id ) {
		$terms = wp_get_post_terms( $id, ATBDP_LOCATION );
		if ( is_wp_error( $terms ) || ! $terms ) {
			return '';
		}
		$best = '';
		$bd   = -1;
		foreach ( $terms as $t ) {
			$d = count( get_ancestors( $t->term_id, ATBDP_LOCATION, 'taxonomy' ) ) + 1;
			if ( 3 === $d ) {
				return $t->name;
			}
			if ( $d > $bd ) {
				$bd   = $d;
				$best = $t->name;
			}
		}
		return $best;
	}

	/** Current list style: grid (cards) or list (rows), from ?vista=. */
	public static function view() {
		if ( '' !== self::$force_view ) {
			return self::$force_view;
		}
		$v = self::req( 'vista' );
		return in_array( $v, array( 'grid', 'map' ), true ) ? $v : 'list'; // table by default, cards and map as alternatives
	}

	/** Two icon buttons that switch between card view and list view. */
	public static function view_switch( $param = 'vista', $cur = null, $anchor = '' ) {
		$cur = null === $cur ? self::view() : $cur;
		$g   = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M3 3h8v8H3zm10 0h8v8h-8zM3 13h8v8H3zm10 0h8v8h-8z"/></svg>';
		$l   = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M3 4h18v4H3zm0 6h18v4H3zm0 6h18v4H3z"/></svg>';
		$m   = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z"/></svg>';
		$btn = function ( $view, $icon, $label, $title ) use ( $cur, $param, $anchor ) {
			return '<a href="' . esc_url( add_query_arg( $param, $view ) . $anchor ) . '" class="' . ( $view === $cur ? 'is-active' : '' ) . '" title="' . esc_attr( $title ) . '">' . $icon . ' <span>' . esc_html( $label ) . '</span></a>';
		};
		return '<span class="dirnet-viewswitch">'
			. $btn( 'list', $l, __( 'List', 'directorist-network' ), __( 'List view', 'directorist-network' ) )
			. $btn( 'grid', $g, __( 'Cards', 'directorist-network' ), __( 'Card view', 'directorist-network' ) )
			. $btn( 'map', $m, __( 'Map', 'directorist-network' ), __( 'View on map', 'directorist-network' ) ) . '</span>';
	}

	private static function select( $name, $placeholder, array $options, $current, $submit ) {
		$h = '<select name="' . esc_attr( $name ) . '"' . ( $submit ? ' onchange="this.form.submit()"' : '' ) . '><option value="">' . esc_html( $placeholder ) . '</option>';
		foreach ( $options as $v => $l ) {
			$h .= '<option value="' . esc_attr( $v ) . '"' . selected( (string) $current, (string) $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		return $h . '</select>';
	}

	/** Region → province → city, each level listed only once its parent is chosen (location hierarchy: 1 / 2 / 3 / 4 = region / province / city / district). */
	private static function geo_selects( $reg, $prov, $city, $dist ) {
		if ( ! taxonomy_exists( ATBDP_LOCATION ) ) {
			return '';
		}
		$level = function ( $parent_slug, $name, $label, $current, $submit ) {
			$parent = 0;
			if ( null !== $parent_slug ) {
				$pt = get_term_by( 'slug', $parent_slug, ATBDP_LOCATION );
				if ( ! $pt ) {
					return '';
				}
				$parent = (int) $pt->term_id;
			}
			$terms = get_terms( array( 'taxonomy' => ATBDP_LOCATION, 'parent' => $parent, 'hide_empty' => true, 'orderby' => 'name' ) );
			if ( is_wp_error( $terms ) || ! $terms ) {
				return '';
			}
			$opts = array();
			foreach ( null === $parent_slug ? Territory::national_first( $terms ) : $terms as $t ) {
				$opts[ $t->slug ] = $t->name;
			}
			return self::select( $name, $label, $opts, $current, $submit );
		};
		$h = $level( null, 'reg', __( 'Region', 'directorist-network' ), $reg, true );
		if ( $reg ) {
			$h .= $level( $reg, 'prov', __( 'Province', 'directorist-network' ), $prov, true );
		}
		if ( $prov ) {
			$h .= $level( $prov, 'city', __( 'City', 'directorist-network' ), $city, true );
		}
		if ( $city ) {
			$h .= $level( $city, 'dist', __( 'District', 'directorist-network' ), $dist, false );
		}
		return $h;
	}

	private static function term_select( $name, $taxonomy, $placeholder, $current ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return '';
		}
		$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => true ) );
		if ( is_wp_error( $terms ) || ! $terms ) {
			return '';
		}
		$by_parent = array();
		foreach ( $terms as $t ) {
			$by_parent[ (int) $t->parent ][] = $t;
		}
		$opts = array();
		$walk = function ( $parent, $depth ) use ( &$walk, &$opts, $by_parent ) {
			foreach ( isset( $by_parent[ $parent ] ) ? $by_parent[ $parent ] : array() as $t ) {
				$opts[ $t->slug ] = str_repeat( '— ', $depth ) . $t->name;
				$walk( (int) $t->term_id, $depth + 1 );
			}
		};
		$walk( 0, 0 );
		return self::select( $name, $placeholder, $opts, $current, false );
	}

	public static function switch_css() {
		return '.dirnet-bar{scroll-margin-top:90px;display:flex;flex-direction:column;gap:10px;margin:8px 0;padding:10px 14px;background:rgba(128,128,128,.18);border:1px solid rgba(128,128,128,.25);border-radius:8px}.dirnet-row1,.dirnet-row2{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.dirnet-row1 .dirnet-count{margin:0 auto 0 8px;flex:1 1 auto}.dirnet-pagingbox{display:inline-flex;align-items:center;gap:10px;margin-left:auto}.dirnet-filterbtn{padding:5px 14px;border:1px solid rgba(128,128,128,.4);border-radius:6px;background:transparent;color:inherit;cursor:pointer}.dirnet-filterbtn[aria-expanded=true]{background:rgba(128,128,128,.15)}.dirnet-filterpanel[hidden]{display:none}.dirnet-filterpanel .dirnet-filters{margin:0}.dirnet-rows{margin:0}.dirnet-rows label{display:flex;gap:6px;align-items:center;margin:0}.dirnet-rows select{width:auto!important}.dirnet-row2 .dirnet-export,.dirnet-row2 .dirnet-sort{width:auto!important;min-width:0;flex:0 0 auto}.dirnet-tools{display:flex;flex:1 1 260px;margin:0}.dirnet-tools label{display:flex;gap:8px;align-items:center;margin:0;width:100%}.dirnet-tools input[type=search]{flex:1 1 auto;width:100%!important;min-width:0}.dirnet-viewswitch a{align-items:center;gap:6px;line-height:1.2!important;text-decoration:none}@media(max-width:560px){.dirnet-viewswitch a span{display:none}}.dirnet-sortbox{display:inline-flex;gap:6px;align-items:center;margin:0}.dirnet-sortbox label{display:inline-flex;gap:6px;align-items:center}.dirnet-sortdir{padding:4px 9px;border:1px solid rgba(128,128,128,.4);border-radius:6px;color:inherit;text-decoration:none}.dirnet-sort,.dirnet-export{padding:5px 8px;border:1px solid rgba(128,128,128,.4);border-radius:6px;background:transparent;color:inherit}'
			. '.dirnet-viewswitch{display:inline-flex;gap:4px}.dirnet-viewswitch a{display:inline-flex;padding:6px;border:1px solid rgba(128,128,128,.4);border-radius:6px;color:inherit;opacity:.6;line-height:0}.dirnet-viewswitch a.is-active{opacity:1;background:rgba(128,128,128,.15)}';
	}

	private static function css() {
		return '<style>' . self::switch_css()
			. '.dirnet-filters{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 12px}.dirnet-filters input,.dirnet-filters select,.dirnet-filters button{padding:8px 10px;max-width:100%}'
			. self::card_css()
			. '.dirnet-sorth{color:inherit!important;text-decoration:none!important}.dirnet-sorth:hover{text-decoration:underline!important}.dirnet-table-wrap{width:100%;max-width:100%;box-sizing:border-box;margin:12px 0;padding:0;overflow-x:auto}.dirnet-table{width:100%;max-width:100%;border-collapse:collapse;margin:0!important;font-size:.95em;box-sizing:border-box}.dirnet-table th a{color:inherit;text-decoration:none}.dirnet-table th{text-align:left;font-size:.78em;text-transform:uppercase;letter-spacing:.03em;background:#000;color:#fff;padding:8px 10px;border-bottom:2px solid #000}.dirnet-table th a,.dirnet-table th .dirnet-sorth{color:#fff!important}.dirnet-table td{padding:9px 10px;border-bottom:1px solid rgba(128,128,128,.25);vertical-align:top;word-break:break-word}.dirnet-table tbody tr>td{background:transparent!important}.dirnet-table tbody tr:nth-child(odd)>td{background:var(--dirnet-row-a,rgba(128,128,128,.05))!important}.dirnet-table tbody tr:nth-child(even)>td{background:var(--dirnet-row-b,rgba(128,128,128,.17))!important}.dirnet-table tbody tr:hover>td{background:var(--dirnet-row-hover,rgba(128,128,128,.32))!important}.dirnet-table th:first-child,.dirnet-table td:first-child{width:280px;min-width:280px;max-width:280px;word-break:normal;overflow-wrap:anywhere}.dirnet-table .dirnet-logo{width:28px;height:28px;vertical-align:middle;margin-right:4px}'
			. '@media(max-width:900px){.dirnet-table,.dirnet-table tbody,.dirnet-table tr,.dirnet-table td{display:block}.dirnet-table thead{display:none}.dirnet-table tr{border:1px solid rgba(128,128,128,.35);border-radius:8px;margin-bottom:10px;padding:6px 8px}.dirnet-table td{display:flex;justify-content:space-between;gap:10px;border:0;padding:4px 2px}.dirnet-table td::before{content:attr(data-label);flex:0 0 42%;font-size:.75em;text-transform:uppercase;opacity:.65}.dirnet-table td:first-child{font-weight:600;width:auto;min-width:0;max-width:none}.dirnet-table td:first-child::before{display:none}}'
			. '.dirnet-sheets{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:6px 0}.dirnet-sheet{display:inline-block;padding:3px 10px;border-radius:6px;font-size:.85em;text-decoration:none;border:1px solid currentColor}.dirnet-products{font-size:.85em;padding:3px 6px;max-width:100%}.dirnet-pager{margin:16px 0}.dirnet-pager .page-numbers{display:inline-block;padding:6px 10px;margin-right:4px;border:1px solid rgba(128,128,128,.4);border-radius:4px;text-decoration:none}.dirnet-pager .current{font-weight:700}</style>';
	}

	public static function ensure_page() {
		$id = (int) get_option( self::PAGE_OPTION );
		if ( $id && 'trash' !== get_post_status( $id ) && get_post( $id ) ) {
			return $id;
		}
		return 0; // pages are never created here: the site assigns them in System pages
	}
}
