<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Which CSV columns a profile type understands, and how each one is stored.
 * Column names accepted: the English key (e.g. "status"), or the field label in
 * English or in the site language ("Status", "Stato"); case, spaces, "_" and
 * "-" are ignored.
 */
class ColumnMap {

	/** preset => [meta key or null, label, kind] */
	const PRESETS = array(
		'title'       => array( null, 'Title', 'title' ),
		'excerpt'     => array( null, 'Short description', 'content' ),
		'description' => array( null, 'Extended description', 'content' ),
		'category'    => array( null, 'Category', 'tax_category' ),
		'tag'         => array( null, 'Tags', 'tax_tag' ),
		'location'    => array( null, 'Location', 'tax_location' ),
		'region'      => array( null, 'Region', 'geo' ),
		'province'    => array( null, 'Province', 'geo' ),
		'city'        => array( null, 'City', 'geo' ),
		'district'    => array( null, 'District', 'geo' ),
		'address'     => array( 'address', 'Address', 'meta' ),
		'zip'         => array( 'zip', 'Postcode', 'meta' ),
		'email'       => array( 'email', 'Email', 'meta' ),
		'phone'       => array( 'phone', 'Phone', 'meta' ),
		'email2'      => array( 'custom-email2', 'Second email', 'meta' ),
		'phone2'      => array( 'custom-phone2', 'Second phone', 'meta' ),
		'email2'      => array( 'custom-email2', 'Second email', 'meta' ),
		'phone2'      => array( 'custom-phone2', 'Second phone', 'meta' ),
		'website'     => array( 'website', 'Website', 'meta' ),
		'latitude'    => array( 'manual_lat', 'Latitude', 'meta' ),
		'longitude'   => array( 'manual_lng', 'Longitude', 'meta' ),
		'image'       => array( null, 'Image URL', 'image' ),
		'functions'   => array( null, 'Roles', 'tax_function' ),
	);

	/** Extra header spellings accepted for the built-in columns (English + Italian). */
	const ALIASES = array(
		'title'       => array( 'name', 'nome', 'titolo', 'ragione sociale', 'ragione_sociale' ),
		'excerpt'     => array( 'descrizione breve', 'short description', 'breve', 'sintesi', 'estratto' ),
		'description' => array( 'descrizione', 'descrizione estesa', 'extended description', 'about' ),
		'category'    => array( 'categories', 'categoria', 'categorie', 'settore' ),
		'tag'         => array( 'tags', 'etichette', 'group type', 'group tags' ),
		'location'    => array( 'locations', 'localita', 'località', 'territorio', 'full location' ),
		'region'      => array( 'regione' ),
		'province'    => array( 'provincia' ),
		'city'        => array( 'citta', 'città', 'comune', 'town' ),
		'district'    => array( 'quartiere', 'municipio', 'neighbourhood', 'neighborhood', 'zona' ),
		'address'     => array( 'indirizzo', 'via', 'street' ),
		'zip'         => array( 'cap', 'postcode', 'postal code' ),
		'email'       => array( 'e mail', 'mail', 'pec' ),
		'phone'       => array( 'telefono', 'tel' ),
		'email2'      => array( 'seconda email', 'seconda mail', 'email 2', 'mail 2' ),
		'phone2'      => array( 'secondo telefono', 'telefono 2', 'tel 2', 'cellulare' ),
		'email2'      => array( 'seconda email', 'seconda mail', 'email 2', 'mail 2' ),
		'phone2'      => array( 'secondo telefono', 'telefono 2', 'tel 2', 'cellulare' ),
		'website'     => array( 'sito', 'sito web', 'sito internet', 'url', 'web' ),
		'latitude'    => array( 'lat' ),
		'longitude'   => array( 'lng', 'lon', 'long' ),
		'image'       => array( 'image', 'logo', 'immagine', 'foto', 'image url', 'logo url' ),
		'functions'   => array( 'funzioni', 'funzione', 'function', 'ruoli', 'ruolo' ),
	);

	/**
	 * @return array<string,array{key:string,kind:string,meta:?string,label:string,options:array}> keyed by canonical column key
	 */
	public static function for_type( Profile $profile, $slug ) {
		$types = $profile->types();
		$def   = isset( $types[ $slug ] ) ? $types[ $slug ] : array();
		$have  = array();
		foreach ( isset( $def['presets'] ) ? $def['presets'] : DirectoryBuilder::default_presets() as $p ) {
			$have[ $p['name'] ] = true;
		}
		$cols = array();
		foreach ( self::PRESETS as $key => $spec ) {
			$present = isset( $have[ $key ] ) || 'functions' === $key
				|| ( in_array( $key, array( 'region', 'province', 'city', 'district' ), true ) && isset( $have['location'] ) )
				|| ( in_array( $key, array( 'latitude', 'longitude' ), true ) && isset( $have['map'] ) )
				|| ( 'image' === $key && isset( $have['image_upload'] ) );
			if ( ! $present ) {
				continue;
			}
			$cols[ $key ] = array( 'key' => $key, 'kind' => $spec[2], 'meta' => $spec[0], 'label' => $profile->t( $spec[1] ), 'options' => array() );
		}
		foreach ( isset( $def['fields'] ) ? $def['fields'] : array() as $cf ) {
			if ( empty( $cf['key'] ) || ! empty( $cf['form_only'] ) ) {
				continue;
			}
			$fk   = 'custom-' . DirectoryBuilder::slug( str_replace( '_', '-', $cf['key'] ) );
			$opts = array();
			foreach ( isset( $cf['options'] ) ? $cf['options'] : array() as $v => $l ) {
				$opts[ (string) $v ] = $profile->t( $l );
			}
			$cols[ $cf['key'] ] = array(
				'key'     => $cf['key'],
				'kind'    => in_array( isset( $cf['type'] ) ? $cf['type'] : 'text', array( 'select', 'radio', 'checkbox' ), true ) ? 'choice' : 'meta',
				'meta'    => $fk,
				'label'   => $profile->t( isset( $cf['label'] ) ? $cf['label'] : $cf['key'] ),
				'options' => $opts,
				'multi'   => ( isset( $cf['type'] ) && 'checkbox' === $cf['type'] ),
			);
		}
		$cols = self::live_fields( $profile, $slug, $cols );
		foreach ( Relations::defs( $profile ) as $rk => $rd ) {
			if ( in_array( $slug, $rd['from'], true ) && ! isset( $cols[ $rk ] ) ) {
				$cols[ $rk ] = array( 'key' => $rk, 'kind' => 'relation', 'meta' => null, 'label' => $rd['label'], 'options' => array() );
			}
		}
		return $cols;
	}

	/**
	 * Custom fields an admin added (or renamed) in the Directory Builder after generation become
	 * importable columns too: read them from the type's live submission form.
	 */
	private static function live_fields( Profile $profile, $slug, array $cols ) {
		$applied = Generator::applied();
		$term_id = isset( $applied[ $profile->id ][ $slug ] ) ? (int) $applied[ $profile->id ][ $slug ] : 0;
		if ( ! $term_id || ! function_exists( 'get_term_meta' ) ) {
			return $cols;
		}
		$form   = get_term_meta( $term_id, 'submission_form_fields', true );
		$live   = array();
		$metas  = array();
		foreach ( $cols as $c ) {
			if ( ! empty( $c['meta'] ) ) {
				$metas[ $c['meta'] ] = true;
			}
		}
		if ( ! is_array( $form ) || empty( $form['fields'] ) ) {
			return $cols;
		}
		foreach ( $form['fields'] as $f ) {
			$fk = isset( $f['field_key'] ) ? (string) $f['field_key'] : '';
			if ( 0 !== strpos( $fk, 'custom-' ) || isset( $metas[ $fk ] ) ) {
				continue;
			}
			$wn   = isset( $f['widget_name'] ) ? $f['widget_name'] : 'text';
			$opts = array();
			foreach ( isset( $f['options'] ) ? (array) $f['options'] : array() as $o ) {
				if ( isset( $o['option_value'] ) ) {
					$opts[ (string) $o['option_value'] ] = isset( $o['option_label'] ) ? (string) $o['option_label'] : (string) $o['option_value'];
				}
			}
			$key  = substr( $fk, 7 );
			$key  = isset( $cols[ $key ] ) ? $fk : $key;
			$cols[ $key ] = array(
				'key'     => $key,
				'kind'    => in_array( $wn, array( 'select', 'radio', 'checkbox' ), true ) ? 'choice' : 'meta',
				'meta'    => $fk,
				'label'   => isset( $f['label'] ) ? (string) $f['label'] : $key,
				'options' => $opts,
				'multi'   => ( 'checkbox' === $wn ),
			);
		}
		return $cols;
	}

	/** Map each raw header cell to a canonical column key (or null when unknown). */
	public static function match_header( array $cols, array $header ) {
		$index = array();
		foreach ( $cols as $key => $c ) {
			$index[ Csv::norm( $key ) ]        = $key;
			$index[ Csv::norm( $c['label'] ) ] = $key;
		}
		foreach ( self::PRESETS as $key => $spec ) { // English label + extra aliases
			if ( isset( $cols[ $key ] ) ) {
				$index[ Csv::norm( $spec[1] ) ] = $key;
				foreach ( self::ALIASES[ $key ] as $a ) {
					$index[ Csv::norm( $a ) ] = $key;
				}
			}
		}
		$out = array();
		foreach ( $header as $i => $h ) {
			$n          = Csv::norm( $h );
			$out[ $i ]  = isset( $index[ $n ] ) ? $index[ $n ] : null;
			if ( null === $out[ $i ] && preg_match( '/^(?:desc|descrizione|description)[ _-]+(.+)$/', strtolower( trim( (string) $h ) ), $m ) ) {
				$out[ $i ] = 'fdesc:' . sanitize_title( $m[1] ); // description of one function, e.g. desc_gas
			}
		}
		return $out;
	}

	/** Turn a CSV cell into the stored value for choice fields (accepts value or label). */
	public static function choice_value( array $col, $raw ) {
		$parts = ! empty( $col['multi'] ) ? preg_split( '/\s*[;|]\s*/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY ) : array( trim( (string) $raw ) );
		$out   = array();
		foreach ( $parts as $p ) {
			if ( '' === $p ) {
				continue;
			}
			$found = null;
			foreach ( $col['options'] as $v => $l ) {
				if ( Csv::norm( $p ) === Csv::norm( $v ) || Csv::norm( $p ) === Csv::norm( $l ) ) {
					$found = (string) $v;
					break;
				}
			}
			$out[] = null === $found ? $p : $found;
		}
		if ( ! empty( $col['multi'] ) ) {
			return $out;
		}
		return $out ? $out[0] : '';
	}

	/** Split a taxonomy cell: terms separated by ";" or "|", hierarchy written "Parent > Child". */
	public static function term_paths( $raw ) {
		$paths = array();
		foreach ( preg_split( '/\s*[;|]\s*/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY ) as $item ) {
			$path = array_values( array_filter( array_map( 'trim', explode( '>', $item ) ), 'strlen' ) );
			if ( $path ) {
				$paths[] = $path;
			}
		}
		return $paths;
	}
}
