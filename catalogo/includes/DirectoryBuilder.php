<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the JSON that Directorist's Directory Builder stores for one
 * directory type, starting from Directorist's own sample files
 * (assets/sample-data/directory.json and listing-form-fields.json) — the same
 * recipe Directorist's own setup wizard uses (class-ai-setup-wizard.php).
 */
class DirectoryBuilder {

	const CUSTOM_TYPES = array( 'text', 'textarea', 'number', 'url', 'date', 'time', 'select', 'checkbox', 'radio', 'file' );

	private $assets_dir;

	public function __construct( $assets_dir ) {
		$this->assets_dir = trailingslashit( $assets_dir );
	}

	private function read( $rel ) {
		$file = $this->assets_dir . $rel;
		$data = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
		return is_array( $data ) ? $data : null;
	}

	public static function slug( $s ) {
		return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $s ) ), '-' );
	}

	/**
	 * @return array|\WP_Error|null Directory config, or null when Directorist's sample files are missing.
	 */
	public function build( Profile $profile, $type_slug ) {
		$types = $profile->types();
		if ( empty( $types[ $type_slug ] ) ) {
			return null;
		}
		$def       = $types[ $type_slug ];
		$config    = $this->read( 'sample-data/directory/directory.json' );
		$templates = $this->read( 'sample-data/listing-form-fields.json' );
		if ( ! $config || ! $templates ) {
			return null;
		}

		$presets = isset( $def['presets'] ) ? $def['presets'] : self::default_presets();
		$fields  = array();
		$groups  = array();
		$order   = array(); // group label => list of field keys

		foreach ( $presets as $p ) {
			$name = $p['name'];
			if ( empty( $templates[ $name ] ) || isset( $fields[ $name ] ) ) {
				continue;
			}
			$f = $templates[ $name ];
			if ( ! empty( $p['label'] ) ) {
				$f['label'] = $profile->t( $p['label'] );
			}
			if ( ! empty( $p['required'] ) ) {
				$f['required'] = true;
			}
			if ( 'terms_privacy' === $name ) {
				$f['text'] = self::consent_text();
			}
			$fields[ $name ] = $f;
			$order[ $this->group_label( $profile, $p ) ][] = $name;
		}

		$custom = array();
		$def_fields = isset( $def['fields'] ) ? $def['fields'] : array();
		$have_keys  = array();
		foreach ( $def_fields as $x ) {
			$have_keys[] = isset( $x['key'] ) ? str_replace( '-', '_', (string) $x['key'] ) : '';
		}
		foreach ( array( 'email2' => 'Second e-mail', 'phone2' => 'Second phone' ) as $xk => $xl ) { // every subject has two e-mails and two phones
			if ( ! in_array( $xk, $have_keys, true ) ) {
				$def_fields[] = array( 'key' => $xk, 'type' => 'text', 'label' => $xl, 'group' => 'Contact' );
			}
		}
		$hide = array( 'contact_person', 'sol_entity_type' ); // the contact person is the "Referente" group; the entity type of Solidaros is technical
		if ( 'producer' !== $type_slug ) { // the entity and product questionnaires belong to producers; every legal person has the social balance
			$hide = array_merge( $hide, array( 'company_sheet', 'product_sheets' ) );
		}
		if ( get_role( 'sm_subject' ) ) { // Solidaros active: the questionnaires come from Solidaros, the manual links are not needed
			$hide = array_merge( $hide, array( 'social_report', 'company_sheet', 'product_sheets' ) );
		}
		$def_fields = array_values( array_filter( $def_fields, function ( $x ) use ( $hide ) {
			return ! isset( $x['key'] ) || ! in_array( str_replace( '-', '_', (string) $x['key'] ), $hide, true );
		} ) );
		foreach ( $def_fields as $cf ) {
			$type = isset( $cf['type'] ) ? $cf['type'] : 'text';
			$key  = isset( $cf['key'] ) ? self::slug( str_replace( '_', '-', $cf['key'] ) ) : '';
			if ( '' === $key || ! in_array( $type, self::CUSTOM_TYPES, true ) || empty( $templates[ $type ] ) ) {
				continue;
			}
			$widget_key = str_replace( '-', '_', $key );
			if ( isset( $fields[ $widget_key ] ) ) {
				continue;
			}
			$f                = $templates[ $type ];
			$f['label']       = $profile->t( isset( $cf['label'] ) ? $cf['label'] : $cf['key'] );
			$f['field_key']   = 'custom-' . $key;
			$f['widget_key']  = $widget_key;
			$f['required']    = ! empty( $cf['required'] );
			if ( ! empty( $cf['description'] ) ) {
				$f['description'] = $profile->t( $cf['description'] );
			}
			if ( in_array( $type, array( 'select', 'radio', 'checkbox' ), true ) ) {
				$opts = array();
				foreach ( isset( $cf['options'] ) ? $cf['options'] : array() as $val => $lab ) {
					$opts[] = array( 'option_value' => (string) $val, 'option_label' => $profile->t( $lab ) );
				}
				$f['options'] = $opts;
			}
			$fields[ $widget_key ]                                 = $f;
			$custom[ $widget_key ]                                 = $f;
			$order[ $this->group_label( $profile, $cf ) ][]        = $widget_key;
		}

		if ( ! empty( $templates['select'] ) ) { // relations: one selector per outgoing relation, in the form (backend and frontend)
			foreach ( Relations::defs( $profile ) as $rk => $rd ) {
				if ( ! in_array( $type_slug, $rd['from'], true ) ) {
					continue;
				}
				$wk                = 'rel_' . str_replace( '-', '_', self::slug( $rk ) );
				$f                 = $templates['select'];
				$f['label']        = $rd['label'];
				$f['field_key']    = 'custom-rel-' . $rk;
				$f['widget_key']   = $wk;
				$f['required']     = false;
				$f['options']      = array();
				$fields[ $wk ]     = $f;
				$order[ $profile->t( 'Relations' ) ][] = $wk;
			}
		}

		foreach ( $order as $label => $keys ) { // the second e-mail / phone sit right after the first
			foreach ( array( 'email2' => 'email', 'phone2' => 'phone' ) as $second => $first ) {
				$i = array_search( $second, $keys, true );
				$j = array_search( $first, $keys, true );
				if ( false !== $i && false !== $j ) {
					array_splice( $keys, (int) $i, 1 );
					$j = array_search( $first, $keys, true );
					array_splice( $keys, (int) $j + 1, 0, array( $second ) );
				}
			}
			$order[ $label ] = $keys;
		}
		foreach ( $order as $label => $keys ) {
			$groups[] = array(
				'type'                          => 'general_group',
				'label'                         => $label,
				'fields'                        => $keys,
				'defaultGroupLabel'             => 'Section',
				'disableTrashIfGroupHasWidgets' => array( array( 'widget_name' => 'title', 'widget_group' => 'preset' ) ),
				'icon'                          => 'las la-pen-nib',
			);
		}

		$config['submission_form_fields'] = array( 'fields' => $fields, 'groups' => $groups );

		// Single listing page: every field except those Directorist shows elsewhere.
		$skip   = array( 'title', 'tagline', 'excerpt', 'image_upload', 'location', 'category', 'pricing', 'terms_privacy', 'logo', 'map', 'address', 'phone', 'phone2', 'email', 'email2', 'contact_person', 'website', 'legal_form', 'legal_form_2', 'social_report', 'company_sheet', 'product_sheets', 'sessions' ); // drawn by SocialReport / Sheets / Sessions
		$sf     = array();
		$sg     = array();
		$secid  = 0;
		foreach ( isset( $def['fields'] ) ? $def['fields'] : array() as $cf ) { // consent / commitment boxes belong to the form only
			if ( ! empty( $cf['form_only'] ) && ! empty( $cf['key'] ) ) {
				$skip[] = str_replace( '-', '_', self::slug( str_replace( '_', '-', $cf['key'] ) ) );
			}
		}
		foreach ( $fields as $k => $f ) {
			if ( in_array( $k, $skip, true ) || 0 === strpos( $k, 'rel_' ) ) { // relations are listed by the plugin
				continue;
			}
			$sf[ $k ] = array(
				'icon'                => 'las la-tag',
				'widget_group'        => 'preset_widgets',
				'widget_name'         => $f['widget_name'],
				'original_widget_key' => $k,
				'widget_key'          => $k,
			);
		}
		foreach ( $groups as $g ) {
			$keys = array_values( array_filter( array_diff( $g['fields'], $skip ), function ( $x ) { return 0 !== strpos( $x, 'rel_' ); } ) );
			if ( $keys ) {
				$sg[] = array( 'type' => 'general_group', 'label' => $g['label'], 'fields' => $keys, 'section_id' => ++$secid, 'icon' => 'las la-tag' );
			}
		}
		// Map, entity data and the contact form live in the sidebar (see Sidebar), not in the body.
		$config['single_listings_contents'] = array( 'fields' => $sf, 'groups' => $sg );

		// Search form: title + the filters listed by the profile.
		$search = $config['search_form_fields'];
		$basic  = array( 'title' );
		$adv    = array();
		foreach ( isset( $def['filters'] ) ? $def['filters'] : array() as $fk ) {
			$fk = str_replace( '-', '_', $fk );
			if ( empty( $fields[ $fk ] ) ) {
				continue;
			}
			$wn = $fields[ $fk ]['widget_name'];
			$sfld = array(
				'label'               => $fields[ $fk ]['label'],
				'placeholder'         => $fields[ $fk ]['label'],
				'required'            => false,
				'widget_name'         => $wn,
				'widget_group'        => 'available_widgets',
				'original_widget_key' => $fk,
				'widget_key'          => $fk,
			);
			if ( 'category' === $wn ) {
				$sfld['label'] = '';
				$sfld['placeholder'] = 'Select a category';
			}
			if ( 'location' === $wn ) {
				$sfld['label'] = '';
				$sfld['placeholder'] = 'Select a location';
				$sfld['location_source'] = 'from_listing_location';
			}
			$search['fields'][ $fk ] = $sfld;
			$adv[] = $fk;
		}
		$adv[] = 'radius_search';
		unset( $search['fields']['review'] );
		$search['groups'] = array(
			array( 'label' => 'Basic', 'lock' => '1', 'draggable' => '', 'fields' => $basic, 'type' => 'general_group' ),
			array( 'label' => 'Advanced', 'lock' => '1', 'draggable' => '', 'fields' => $adv, 'type' => 'general_group' ),
		);
		$config['search_form_fields'] = $search;

		// Cards: no price / posted date / rating / view count / author avatar; website next to the phone.
		$website = array(
			'type'        => 'list-item',
			'label'       => 'Website',
			'hook'        => 'atbdp_listings_website',
			'widget_name' => 'website',
			'widget_key'  => 'website',
			'icon'        => 'la la-globe',
		);
		foreach ( array( 'listings_card_grid_view', 'listings_card_list_view' ) as $ck ) {
			if ( isset( $config[ $ck ] ) ) {
				$config[ $ck ] = self::strip_widgets( $config[ $ck ], array( 'pricing', 'posted_date', 'rating', 'view_count' ) );
				if ( isset( $fields['website'] ) ) {
					$config[ $ck ] = self::insert_after( $config[ $ck ], 'phone', $website );
				}
			}
		}
		// Short description under the card title.
		if ( isset( $fields['excerpt'] ) ) {
			$exc = array( 'type' => 'excerpt', 'label' => 'Excerpt', 'hook' => 'atbdp_listings_excerpt', 'widget_name' => 'excerpt', 'widget_key' => 'excerpt', 'excerpt_length' => '18', 'show_readmore' => '', 'readmore_text' => '' );
			$put = function ( &$node ) use ( &$put, $exc ) {
				if ( ! is_array( $node ) ) {
					return;
				}
				foreach ( $node as $k => &$v ) {
					if ( 'excerpt' === $k && is_array( $v ) && ! $v ) {
						$v = array( $exc );
					} elseif ( is_array( $v ) ) {
						$put( $v );
					}
				}
			};
			foreach ( array( 'listings_card_grid_view', 'listings_card_list_view' ) as $ck ) {
				if ( isset( $config[ $ck ] ) ) {
					$put( $config[ $ck ] );
				}
			}
		}
		// The entity's logo (see Logo) takes the publisher-avatar slot of the thumbnail cards.
		$avatar = array(
			'type'        => 'avatar',
			'label'       => 'User Avatar',
			'hook'        => 'atbdp_user_avatar',
			'can_move'    => '',
			'widget_name' => 'user_avatar',
			'widget_key'  => 'user_avatar',
			'align'       => 'right',
		);
		if ( isset( $fields['logo'] ) ) {
			if ( isset( $config['listings_card_grid_view']['template_data']['grid_view_with_thumbnail']['thumbnail'] ) ) {
				$config['listings_card_grid_view']['template_data']['grid_view_with_thumbnail']['thumbnail']['avatar'] = array( $avatar );
			}
			if ( isset( $config['listings_card_list_view']['template_data']['list_view_with_thumbnail']['footer'] ) ) {
				$config['listings_card_list_view']['template_data']['list_view_with_thumbnail']['footer']['right'] = array( $avatar );
			}
		}
		$config['icon']          = isset( $def['icon'] ) ? $def['icon'] : $config['icon'];
		$config['enable_review'] = '0';

		// Image shown on cards and pages of listings without one: the Mercato Sociale logo.
		if ( defined( 'DIRNET_FILE' ) && function_exists( 'plugins_url' ) ) {
			$config['preview_image'] = Placeholder::url();
		}

		return $config;
	}

	/** "I accept the Privacy Policy, the Cookie Policy and the Terms and Conditions", linked to the pages set in Network setup. */
	public static function consent_text() {
		// The pages are the WordPress ones (Settings > Privacy) or ordinary pages found by their usual slugs; no setting to fill in.
		$find = function ( array $slugs ) {
			if ( ! function_exists( 'get_page_by_path' ) ) {
				return '';
			}
			foreach ( $slugs as $slug ) {
				$pg = get_page_by_path( $slug );
				if ( $pg && 'publish' === $pg->post_status ) {
					return (string) get_permalink( $pg );
				}
			}
			return '';
		};
		$urls = array(
			'dirnet_privacy_url' => function_exists( 'get_privacy_policy_url' ) ? (string) get_privacy_policy_url() : '',
			'dirnet_cookie_url'  => $find( array( 'cookie-policy', 'politica-sui-cookie', 'politica-cookie', 'cookie' ) ),
			'dirnet_terms_url'   => $find( array( 'termini-e-condizioni', 'termini-e-condizioni-di-utilizzo', 'termini-di-servizio', 'terms-and-conditions', 'terms' ) ),
		);
		$link = function ( $label, $opt ) use ( $urls ) {
			$u = function_exists( 'apply_filters' ) ? (string) apply_filters( 'dirnet_consent_url', $urls[ $opt ], $opt ) : $urls[ $opt ];
			return '' !== $u ? '<a href="' . esc_url( $u ) . '" target="_blank" rel="noopener">' . $label . '</a>' : $label;
		};
		$text = sprintf(
			/* translators: 1: Privacy Policy, 2: Cookie Policy, 3: Terms and Conditions (each a link when its page is set) */
			__( 'I accept the %1$s, the %2$s and the %3$s', 'directorist-network' ),
			$link( __( 'Privacy Policy', 'directorist-network' ), 'dirnet_privacy_url' ),
			$link( __( 'Cookie Policy', 'directorist-network' ), 'dirnet_cookie_url' ),
			$link( __( 'Terms and Conditions', 'directorist-network' ), 'dirnet_terms_url' )
		);
		return $text;
	}

	private function group_label( Profile $profile, array $item ) {
		return $profile->t( isset( $item['group'] ) ? $item['group'] : 'General information' );
	}

	public static function default_presets() {
		return array(
			array( 'name' => 'title', 'required' => true ),
			array( 'name' => 'description' ),
			array( 'name' => 'category' ),
			array( 'name' => 'tag' ),
			array( 'name' => 'location', 'group' => 'Location' ),
			array( 'name' => 'address', 'group' => 'Location' ),
			array( 'name' => 'map', 'group' => 'Location' ),
			array( 'name' => 'email', 'group' => 'Contact' ),
			array( 'name' => 'phone', 'group' => 'Contact' ),
			array( 'name' => 'website', 'group' => 'Contact' ),
			array( 'name' => 'image_upload', 'group' => 'Media', 'label' => 'Logo / images' ),
		);
	}

	/** Recursively insert $entry right after each widget named $after (skipping lists that already hold it). */
	public static function insert_after( $node, $after, array $entry ) {
		if ( ! is_array( $node ) ) {
			return $node;
		}
		$is_list = $node && array_keys( $node ) === range( 0, count( $node ) - 1 );
		if ( $is_list ) {
			$names = array();
			foreach ( $node as $v ) {
				if ( is_array( $v ) && isset( $v['widget_name'] ) ) {
					$names[] = $v['widget_name'];
				}
			}
			$out = array();
			foreach ( $node as $v ) {
				$out[] = self::insert_after( $v, $after, $entry );
				if ( is_array( $v ) && isset( $v['widget_name'] ) && $after === $v['widget_name'] && ! in_array( $entry['widget_name'], $names, true ) ) {
					$out[] = $entry;
				}
			}
			return $out;
		}
		foreach ( $node as $k => $v ) {
			$node[ $k ] = self::insert_after( $v, $after, $entry );
		}
		return $node;
	}

	/** Recursively drop widgets whose widget_name is in $names. */
	public static function strip_widgets( $node, array $names ) {
		if ( ! is_array( $node ) ) {
			return $node;
		}
		$is_list = array_keys( $node ) === range( 0, count( $node ) - 1 );
		$out     = array();
		foreach ( $node as $k => $v ) {
			if ( is_array( $v ) && isset( $v['widget_name'] ) && in_array( $v['widget_name'], $names, true ) ) {
				continue;
			}
			$out[ $k ] = self::strip_widgets( $v, $names );
		}
		return $is_list ? array_values( $out ) : $out;
	}
}
