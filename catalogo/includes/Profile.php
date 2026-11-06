<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A profile = folder profiles/<id>/ with profile.json and optional
 * i18n/<locale>.json (English source string => translation).
 * Profiles can also be dropped into wp-content/uploads/directorist-network/profiles/
 * (survives plugin updates) and override the bundled one with the same id.
 */
class Profile {

	public $id;
	public $data;
	private $dict = array();

	public function __construct( $id, array $data, array $dict = array() ) {
		$this->id   = $id;
		$this->data = $data;
		$this->dict = $dict;
	}

	/** Translate a profile string (English source) using the site locale. */
	public function t( $s ) {
		return ( is_string( $s ) && isset( $this->dict[ $s ] ) ) ? $this->dict[ $s ] : $s;
	}

	public function label() {
		return $this->t( isset( $this->data['label'] ) ? $this->data['label'] : $this->id );
	}

	public function description() {
		return $this->t( isset( $this->data['description'] ) ? $this->data['description'] : '' );
	}

	/** Fields every type shares (profiles/_common/common.json); a type opts out with "common": false. */
	public static function common() {
		static $c = null;
		if ( null === $c ) {
			$c = json_decode( (string) @file_get_contents( DIRNET_PATH . 'profiles/_common/common.json' ), true );
			$c = is_array( $c ) ? $c : array( 'presets' => array(), 'fields' => array() );
		}
		return $c;
	}

	public function types() {
		$types = isset( $this->data['types'] ) && is_array( $this->data['types'] ) ? $this->data['types'] : array();
		$c     = self::common();
		foreach ( $types as $k => $t ) {
			if ( isset( $t['common'] ) && ! $t['common'] ) {
				continue;
			}
			$have   = array();
			foreach ( $c['fields'] as $f ) {
				$have[] = $f['key'];
			}
			$own = array();
			foreach ( isset( $t['fields'] ) ? $t['fields'] : array() as $f ) {
				if ( ! in_array( $f['key'], $have, true ) ) {
					$own[] = $f;
				}
			}
			$names = array();
			foreach ( isset( $t['presets'] ) ? $t['presets'] : DirectoryBuilder::default_presets() as $p ) {
				$names[] = $p['name'];
			}
			$presets = isset( $t['presets'] ) ? $t['presets'] : DirectoryBuilder::default_presets();
			foreach ( $c['presets'] as $p ) {
				if ( ! in_array( $p['name'], $names, true ) ) {
					$presets[] = $p;
				}
			}
			// Short + extended description instead of a single description.
			$fixed = array();
			foreach ( $presets as $p ) {
				if ( 'excerpt' === $p['name'] ) {
					continue;
				}
				if ( 'description' === $p['name'] ) {
					$fixed[] = array( 'name' => 'excerpt', 'label' => 'Short description' );
					$p['label'] = 'Extended description';
				}
				$fixed[] = $p;
			}
			$types[ $k ]['presets'] = $fixed;
			$types[ $k ]['fields']  = array_merge( $c['fields'], $own );
		}
		return $types;
	}

	public static function dirs() {
		$dirs = array( DIRNET_PATH . 'profiles/' );
		$up   = wp_upload_dir( null, false );
		if ( ! empty( $up['basedir'] ) ) {
			$dirs[] = trailingslashit( $up['basedir'] ) . 'directorist-network/profiles/';
		}
		return $dirs;
	}

	/** Folder for the profiles uploaded from the admin (survives plugin updates); '' if the uploads folder is not available. */
	public static function custom_base() {
		$up = wp_upload_dir( null, false );
		return empty( $up['basedir'] ) ? '' : trailingslashit( $up['basedir'] ) . 'directorist-network/profiles/';
	}

	/** True when the profile has an uploaded copy. */
	public static function is_custom( $id ) {
		$b = self::custom_base();
		return '' !== $b && is_readable( $b . sanitize_key( $id ) . '/profile.json' );
	}

	const DELETED = 'dirnet_deleted_profiles';

	/** @return string[] Ids of the profiles deleted from the list. */
	public static function deleted() {
		return array_values( array_filter( array_map( 'sanitize_key', (array) get_option( self::DELETED, array() ) ) ) );
	}

	public static function delete_listed( $id ) {
		$d   = self::deleted();
		$d[] = sanitize_key( $id );
		update_option( self::DELETED, array_values( array_unique( $d ) ), false );
	}

	public static function undelete( $id ) {
		update_option( self::DELETED, array_values( array_diff( self::deleted(), array( sanitize_key( $id ) ) ) ), false );
	}

	/** @return Profile[] keyed by id */
	public static function all() {
		$out  = array();
		$gone = self::deleted();
		foreach ( self::dirs() as $base ) {
			foreach ( (array) glob( $base . '*/profile.json' ) as $file ) {
				$id   = basename( dirname( $file ) );
				if ( in_array( $id, $gone, true ) ) {
					continue;
				}
				$data = json_decode( (string) file_get_contents( $file ), true );
				if ( ! is_array( $data ) || empty( $data['types'] ) ) {
					continue;
				}
				$dict   = array();
				$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
				foreach ( array( DIRNET_PATH . 'profiles/_common/' . $locale . '.json', dirname( $file ) . '/i18n/' . $locale . '.json' ) as $tr ) {
					if ( is_readable( $tr ) ) {
						$d = json_decode( (string) file_get_contents( $tr ), true );
						if ( is_array( $d ) ) {
							$dict = array_merge( $dict, $d );
						}
					}
				}
				$data = (array) apply_filters( 'dirnet_profile_data', $data, $id ); // add-ons may add types
				$dict = (array) apply_filters( 'dirnet_profile_dict', $dict, $id, $locale );
				$out[ $id ] = new self( $id, $data, $dict );
			}
		}
		return $out;
	}

	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}
}
