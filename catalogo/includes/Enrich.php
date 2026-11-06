<?php
/**
 * Complete missing data of entries: OpenStreetMap (address, coordinates), Brave Search
 * (optional key, finds the website) and the entry's own website (email, phone).
 * Nothing is saved without review: proposals are shown and applied one entry at a time.
 *
 * @package DirectoristNetwork
 */
namespace DirectoristNetwork;

defined( 'ABSPATH' ) || exit;

class Enrich {

	const SLUG = 'dirnet-enrich';

	/** meta key => label */
	const FIELDS = array(
		'_website' => 'Sito web',
		'_email'   => 'Email',
		'_phone'   => 'Telefono',
		'_address' => 'Indirizzo',
	);

	public function boot() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_head', function () {
			echo '<style>#adminmenu a[href$="page=' . esc_attr( self::SLUG ) . '"]{display:none}</style>';
		} );
		add_action( 'admin_post_dirnet_enrich_search', array( $this, 'search' ) );
		add_action( 'admin_post_dirnet_enrich_apply', array( $this, 'apply' ) );
		add_action( 'admin_post_dirnet_enrich_key', array( $this, 'save_key' ) );
		add_action( 'admin_post_dirnet_enrich_bulk_start', array( $this, 'bulk_start' ) );
		add_action( 'admin_post_dirnet_enrich_bulk_apply', array( $this, 'bulk_apply' ) );
		add_action( 'wp_ajax_dirnet_enrich_step', array( $this, 'bulk_step' ) );
	}

	public function menu() {
		add_submenu_page( 'edit.php?post_type=at_biz_dir', 'Completa dati', 'Completa dati', 'manage_options', self::SLUG, array( $this, 'page' ) );
	}

	private function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'post_type' => 'at_biz_dir', 'page' => self::SLUG ), $args ), admin_url( 'edit.php' ) );
	}

	private function missing( $id ) {
		$out = array();
		foreach ( self::FIELDS as $k => $l ) {
			if ( '' === trim( (string) get_post_meta( $id, $k, true ) ) ) {
				$out[] = $k;
			}
		}
		return $out;
	}

	public function save_key() {
		check_admin_referer( 'dirnet_enrich_key' );
		current_user_can( 'manage_options' ) || wp_die( 'No' );
		$eng = sanitize_key( wp_unslash( $_POST['engine'] ?? 'none' ) );
		update_option( 'dirnet_search_engine', in_array( $eng, array( 'none', 'brave', 'serper', 'searxng' ), true ) ? $eng : 'none', false );
		update_option( 'dirnet_search_key', sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) ), false );
		update_option( 'dirnet_searxng_url', esc_url_raw( wp_unslash( $_POST['searxng'] ?? '' ) ), false );
		wp_safe_redirect( $this->url() );
		exit;
	}

	public function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$review = isset( $_GET['review'] ) ? absint( $_GET['review'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="wrap"><h1>Completa dati dei soggetti</h1>';
		echo '<p class="description">Fonti: OpenStreetMap (indirizzo, coordinate), un motore di ricerca a scelta (sito, se lo configuri) e il sito del soggetto (email, telefono). Nulla viene salvato senza la tua conferma e i campi già compilati non vengono toccati.</p>';
		if ( $review ) {
			$this->review( $review );
		} elseif ( ! empty( $_GET['bulk'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$this->bulk_page();
		} else {
			$this->lista();
		}
		echo '</div>';
	}

	private function lista() {
		$eng = $this->engine();
		$key = $this->search_key();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:12px 0">
			<?php wp_nonce_field( 'dirnet_enrich_key' ); ?>
			<input type="hidden" name="action" value="dirnet_enrich_key" />
			<p><label>Motore per trovare il sito
				<select name="engine">
					<option value="none" <?php selected( $eng, 'none' ); ?>>Nessuno (solo OpenStreetMap e sito già noto)</option>
					<option value="brave" <?php selected( $eng, 'brave' ); ?>>Brave Search (chiave API)</option>
					<option value="serper" <?php selected( $eng, 'serper' ); ?>>Serper.dev, risultati Google (chiave API)</option>
					<option value="searxng" <?php selected( $eng, 'searxng' ); ?>>SearXNG (tuo server, gratuito)</option>
				</select></label></p>
			<p><label>Chiave API (Brave o Serper) <input type="text" name="key" value="<?php echo esc_attr( $key ); ?>" class="regular-text" /></label></p>
			<p><label>Indirizzo SearXNG, es. https://search.miodominio.it <input type="text" name="searxng" value="<?php echo esc_attr( get_option( 'dirnet_searxng_url', '' ) ); ?>" class="regular-text" /></label><br />
			<span class="description">SearXNG deve avere il formato JSON abilitato (search.formats: json) e deve essere raggiungibile pubblicamente: WordPress non contatta indirizzi locali.</span></p>
			<?php submit_button( 'Salva', 'secondary', 'submit', false ); ?>
		</form>
		<?php
		$res = (array) get_option( 'dirnet_enrich_res', array() );
		$q   = (array) get_option( 'dirnet_enrich_q', array() );
		echo '<div style="background:#fff;border:1px solid #c3c4c7;padding:10px 14px;max-width:900px;margin:12px 0"><h2 style="margin-top:0">Ricerca massiva</h2><p>Cerca i dati mancanti di <strong>tutti</strong> i soggetti, uno dopo l\'altro, senza salvare nulla. Alla fine controlli la tabella e applichi in blocco.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		wp_nonce_field( 'dirnet_enrich_bulk_start' );
		echo '<input type="hidden" name="action" value="dirnet_enrich_bulk_start" />';
		submit_button( 'Avvia ricerca su tutti', 'primary', 'submit', false );
		echo '</form> ';
		if ( $q || $res ) {
			echo '<a class="button" href="' . esc_url( $this->url( array( 'bulk' => 1 ) ) ) . '">Riprendi / vedi risultati (' . count( $res ) . ' trovati, ' . count( $q ) . ' da cercare)</a>';
		}
		echo '</div>';
		$paged = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$ids   = get_posts( array( 'post_type' => 'at_biz_dir', 'post_status' => array( 'publish', 'pending', 'draft' ), 'numberposts' => 400, 'offset' => ( $paged - 1 ) * 400, 'fields' => 'ids', 'orderby' => 'ID' ) );
		$rows  = array();
		foreach ( $ids as $id ) {
			$m = $this->missing( $id );
			if ( $m ) {
				$rows[ $id ] = $m;
			}
		}
		echo '<p>Soggetti con dati mancanti in questo blocco di 400: <strong>' . count( $rows ) . '</strong></p>';
		echo '<table class="widefat striped"><thead><tr><th>Soggetto</th><th>Mancano</th><th></th></tr></thead><tbody>';
		foreach ( array_slice( $rows, 0, 100, true ) as $id => $m ) {
			$labels = array_map( function ( $k ) { return self::FIELDS[ $k ]; }, $m );
			$link   = wp_nonce_url( admin_url( 'admin-post.php?action=dirnet_enrich_search&id=' . $id ), 'dirnet_enrich_' . $id );
			echo '<tr><td>' . esc_html( get_the_title( $id ) ) . ' <small>#' . (int) $id . '</small></td><td>' . esc_html( implode( ', ', $labels ) ) . '</td><td><a class="button" href="' . esc_url( $link ) . '">Cerca dati</a></td></tr>';
		}
		echo '</tbody></table>';
		if ( 400 === count( $ids ) ) {
			echo '<p><a href="' . esc_url( $this->url( array( 'paged' => $paged + 1 ) ) ) . '">Blocco successivo &rarr;</a></p>';
		}
		if ( count( $rows ) > 100 ) {
			echo '<p class="description">Mostro i primi 100 del blocco; completati questi, ricarica la pagina.</p>';
		}
	}

	private function engine() {
		$e = get_option( 'dirnet_search_engine', '' );
		if ( '' === $e ) {
			return get_option( 'dirnet_brave_key', '' ) ? 'brave' : 'none';
		}
		return $e;
	}

	private function search_key() {
		$k = get_option( 'dirnet_search_key', '' );
		return '' !== $k ? $k : get_option( 'dirnet_brave_key', '' );
	}

	/** @return array<int,array{url:string,title:string}> candidate websites */
	private function web_search( $q ) {
		$eng  = $this->engine();
		$key  = $this->search_key();
		$raw  = array();
		if ( 'brave' === $eng && $key ) {
			$j = json_decode( $this->http( add_query_arg( array( 'q' => $q, 'count' => 8, 'country' => 'IT', 'search_lang' => 'it' ), 'https://api.search.brave.com/res/v1/web/search' ), array( 'headers' => array( 'Accept' => 'application/json', 'X-Subscription-Token' => $key ) ) ), true );
			foreach ( (array) ( $j['web']['results'] ?? array() ) as $r ) {
				$raw[] = array( $r['url'] ?? '', $r['title'] ?? '' );
			}
		} elseif ( 'serper' === $eng && $key ) {
			$res = wp_safe_remote_post( 'https://google.serper.dev/search', array( 'timeout' => 10, 'headers' => array( 'X-API-KEY' => $key, 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( array( 'q' => $q, 'gl' => 'it', 'hl' => 'it', 'num' => 8 ) ) ) );
			if ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) ) {
				$j = json_decode( wp_remote_retrieve_body( $res ), true );
				foreach ( (array) ( $j['organic'] ?? array() ) as $r ) {
					$raw[] = array( $r['link'] ?? '', $r['title'] ?? '' );
				}
			}
		} elseif ( 'searxng' === $eng ) {
			$base = untrailingslashit( (string) get_option( 'dirnet_searxng_url', '' ) );
			if ( $base ) {
				$j = json_decode( $this->http( add_query_arg( array( 'q' => $q, 'format' => 'json', 'language' => 'it-IT' ), $base . '/search' ) ), true );
				foreach ( (array) ( $j['results'] ?? array() ) as $r ) {
					$raw[] = array( $r['url'] ?? '', $r['title'] ?? '' );
				}
			}
		}
		$skip = '/(facebook|instagram|linkedin|twitter|x\.com|youtube|tiktok|paginegialle|tripadvisor|wikipedia|google|pinterest|yelp|volontariato\.gov|registro)/i';
		$out  = array();
		foreach ( $raw as $r ) {
			if ( $r[0] && ! preg_match( $skip, $r[0] ) ) {
				$out[] = array( 'url' => esc_url_raw( $r[0] ), 'title' => wp_strip_all_tags( $r[1] ) );
			}
		}
		return $out;
	}

	private function http( $url, $args = array() ) {
		$res = wp_safe_remote_get( $url, array_merge( array( 'timeout' => 10, 'redirection' => 3, 'user-agent' => 'DirectoristNetwork/' . DIRNET_VERSION . ' (WordPress; contatto sito)' ), $args ) );
		if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
			return '';
		}
		return (string) wp_remote_retrieve_body( $res );
	}

	private function allowed( $url ) {
		$p = wp_parse_url( $url );
		if ( empty( $p['host'] ) ) {
			return false;
		}
		$rb = $this->http( ( $p['scheme'] ?? 'https' ) . '://' . $p['host'] . '/robots.txt' );
		if ( $rb && preg_match( '/User-agent:\s*\*\s*(.*?)(?:\n\s*\n|$)/is', $rb, $m ) && preg_match( '/^\s*Disallow:\s*\/\s*$/mi', $m[1] ) ) {
			return false;
		}
		return true;
	}

	/** Emails and phones found in a web page. */
	private function scrape( $url ) {
		$out = array( 'email' => array(), 'phone' => array(), 'contact' => '' );
		if ( ! $this->allowed( $url ) ) {
			return $out;
		}
		$pages = array( $url );
		$home  = $this->http( $url );
		if ( $home && preg_match_all( '/href=["\']([^"\']*(?:contatt|contact|chi-siamo|dove-siamo)[^"\']*)["\']/i', $home, $mm ) ) {
			$c = $mm[1][0];
			if ( 0 === strpos( $c, '//' ) ) {
				$c = 'https:' . $c;
			} elseif ( 0 === strpos( $c, '/' ) ) {
				$p = wp_parse_url( $url );
				$c = $p['scheme'] . '://' . $p['host'] . $c;
			}
			if ( 0 === strpos( $c, 'http' ) && $c !== $url ) {
				$pages[]        = $c;
				$out['contact'] = $c;
			}
		}
		foreach ( $pages as $i => $pg ) {
			$html = 0 === $i ? $home : $this->http( $pg );
			if ( ! $html ) {
				continue;
			}
			if ( preg_match_all( '/mailto:([A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})/', $html, $m ) ) {
				$out['email'] = array_merge( $out['email'], $m[1] );
			}
			if ( preg_match_all( '/tel:([+0-9][0-9 .\-]{6,})/', $html, $m ) ) {
				$out['phone'] = array_merge( $out['phone'], array_map( 'trim', $m[1] ) );
			}
			if ( ! $out['phone'] && preg_match_all( '/(?:tel|telefono|cell|mobile)[^0-9+]{0,15}((?:\+39\s?)?[03][0-9][0-9 .\-]{6,12})/i', wp_strip_all_tags( $html ), $m ) ) {
				$out['phone'] = array_merge( $out['phone'], array_map( 'trim', $m[1] ) );
			}
		}
		$out['email'] = array_slice( array_values( array_unique( array_map( 'strtolower', $out['email'] ) ) ), 0, 3 );
		$out['phone'] = array_slice( array_values( array_unique( $out['phone'] ) ), 0, 3 );
		return $out;
	}

	private function place( $id ) {
		$loc = wp_get_post_terms( $id, 'atbdp_listing_location', array( 'fields' => 'names' ) );
		return is_wp_error( $loc ) ? '' : implode( ', ', array_reverse( $loc ) );
	}

	/** Look for the missing data of one entry; returns the proposals (nothing is saved). */
	private function compute( $id ) {
		$title = get_the_title( $id );
		$place = $this->place( $id );
		$miss  = $this->missing( $id );
		$prop  = array( 'cand' => array(), 'osm' => array(), 'site' => '', 'email' => array(), 'phone' => array() );
		$site  = trim( (string) get_post_meta( $id, '_website', true ) );

		if ( ! $site && 'none' !== $this->engine() ) {
			foreach ( $this->web_search( trim( $title . ' ' . $place ) ) as $r ) {
				if ( count( $prop['cand'] ) < 3 ) {
					$prop['cand'][] = $r;
				}
			}
			if ( $prop['cand'] ) {
				$site = $prop['cand'][0]['url'];
			}
		}
		if ( $site ) {
			$s             = $this->scrape( $site );
			$prop['site']  = $site;
			$prop['email'] = $s['email'];
			$prop['phone'] = $s['phone'];
		}
		if ( in_array( '_address', $miss, true ) ) {
			usleep( 1100000 );
			$body = $this->http( add_query_arg( array( 'format' => 'json', 'limit' => 1, 'addressdetails' => 1, 'q' => trim( $title . ', ' . $place ) ), 'https://nominatim.openstreetmap.org/search' ) );
			$j    = json_decode( $body, true );
			if ( ! empty( $j[0]['lat'] ) && ! empty( $j[0]['address']['road'] ) ) {
				$a            = $j[0]['address'];
				$prop['osm'] = array(
					'address' => trim( $a['road'] . ' ' . ( $a['house_number'] ?? '' ) . ', ' . ( $a['postcode'] ?? '' ) . ' ' . ( $a['city'] ?? $a['town'] ?? $a['village'] ?? '' ) ),
					'lat'     => (string) $j[0]['lat'],
					'lng'     => (string) $j[0]['lon'],
					'name'    => $j[0]['display_name'] ?? '',
				);
			}
		}
		return $prop;
	}

	public function search() {
		$id = absint( $_GET['id'] ?? 0 );
		check_admin_referer( 'dirnet_enrich_' . $id );
		current_user_can( 'manage_options' ) || wp_die( 'No' );
		set_transient( 'dirnet_enrich_' . $id, $this->compute( $id ), HOUR_IN_SECONDS );
		wp_safe_redirect( $this->url( array( 'review' => $id ) ) );
		exit;
	}

	private function review( $id ) {
		$prop = get_transient( 'dirnet_enrich_' . $id );
		echo '<p><a href="' . esc_url( $this->url() ) . '">&larr; Elenco</a></p><h2>' . esc_html( get_the_title( $id ) ) . '</h2>';
		if ( ! $prop ) {
			echo '<p>Proposte scadute: rilancia la ricerca.</p>';
			return;
		}
		$miss = $this->missing( $id );
		$any  = false;
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'dirnet_enrich_apply_' . $id ); ?>
			<input type="hidden" name="action" value="dirnet_enrich_apply" /><input type="hidden" name="id" value="<?php echo (int) $id; ?>" />
			<table class="widefat striped" style="max-width:900px"><tbody>
			<?php
			if ( in_array( '_website', $miss, true ) && $prop['cand'] ) {
				$any = true;
				echo '<tr><th>Sito web</th><td>';
				foreach ( $prop['cand'] as $i => $c ) {
					echo '<label><input type="radio" name="site" value="' . esc_attr( $c['url'] ) . '"' . checked( 0, $i, false ) . ' /> <a href="' . esc_url( $c['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $c['title'] ?: $c['url'] ) . '</a></label><br>';
				}
				echo '<label><input type="radio" name="site" value="" /> nessuno</label><br><small>Email e telefono sotto sono letti dal primo sito.</small></td></tr>';
			}
			foreach ( array( '_email' => 'email', '_phone' => 'phone' ) as $meta => $k ) {
				if ( in_array( $meta, $miss, true ) && $prop[ $k ] ) {
					$any = true;
					echo '<tr><th>' . esc_html( self::FIELDS[ $meta ] ) . '</th><td>';
					foreach ( $prop[ $k ] as $i => $v ) {
						echo '<label><input type="radio" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '"' . checked( 0, $i, false ) . ' /> ' . esc_html( $v ) . '</label><br>';
					}
					echo '<label><input type="radio" name="' . esc_attr( $k ) . '" value="" /> nessuno</label></td></tr>';
				}
			}
			if ( in_array( '_address', $miss, true ) && $prop['osm'] ) {
				$any = true;
				echo '<tr><th>Indirizzo</th><td><label><input type="checkbox" name="addr" value="1" checked /> ' . esc_html( $prop['osm']['address'] ) . '</label><br><small>OpenStreetMap: ' . esc_html( $prop['osm']['name'] ) . ' (' . esc_html( $prop['osm']['lat'] . ', ' . $prop['osm']['lng'] ) . ')</small></td></tr>';
			}
			?>
			</tbody></table>
			<?php if ( $any ) : submit_button( 'Applica selezionati' ); else : ?>
				<p>Nessun dato trovato<?php echo 'none' !== $this->engine() ? '' : ' (senza un motore di ricerca non cerco il sito se la scheda non ne ha uno)'; ?>.</p>
			<?php endif; ?>
		</form>
		<?php
	}

	public function bulk_start() {
		check_admin_referer( 'dirnet_enrich_bulk_start' );
		current_user_can( 'manage_options' ) || wp_die( 'No' );
		$ids = get_posts( array( 'post_type' => 'at_biz_dir', 'post_status' => array( 'publish', 'pending', 'draft' ), 'numberposts' => -1, 'fields' => 'ids', 'orderby' => 'ID' ) );
		$q   = array();
		foreach ( $ids as $id ) {
			if ( $this->missing( $id ) ) {
				$q[] = (int) $id;
			}
		}
		update_option( 'dirnet_enrich_q', $q, false );
		update_option( 'dirnet_enrich_res', array(), false );
		wp_safe_redirect( $this->url( array( 'bulk' => 1, 'go' => 1 ) ) );
		exit;
	}

	/** Ajax: search the next entry of the queue. */
	public function bulk_step() {
		check_ajax_referer( 'dirnet_enrich_step', 'n' );
		current_user_can( 'manage_options' ) || wp_send_json_error();
		@set_time_limit( 90 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$q = (array) get_option( 'dirnet_enrich_q', array() );
		if ( ! $q ) {
			wp_send_json_success( array( 'left' => 0, 'found' => count( (array) get_option( 'dirnet_enrich_res', array() ) ) ) );
		}
		$id   = (int) array_shift( $q );
		$prop = $this->compute( $id );
		$res  = (array) get_option( 'dirnet_enrich_res', array() );
		if ( $prop['site'] || $prop['email'] || $prop['phone'] || $prop['osm'] ) {
			$res[ $id ] = $prop;
			update_option( 'dirnet_enrich_res', $res, false );
		}
		update_option( 'dirnet_enrich_q', $q, false );
		wp_send_json_success( array( 'left' => count( $q ), 'found' => count( $res ), 'title' => get_the_title( $id ) ) );
	}

	private function bulk_page() {
		$q   = (array) get_option( 'dirnet_enrich_q', array() );
		$res = (array) get_option( 'dirnet_enrich_res', array() );
		echo '<p><a href="' . esc_url( $this->url() ) . '">&larr; Elenco</a></p><h2>Ricerca massiva</h2>';
		$log = get_transient( 'dirnet_log_' . get_current_user_id() );
		if ( $log ) {
			delete_transient( 'dirnet_log_' . get_current_user_id() );
			echo '<div class="notice notice-success"><p>' . esc_html( implode( ' ', (array) $log ) ) . '</p></div>';
		}
		$nonce = wp_create_nonce( 'dirnet_enrich_step' );
		?>
		<div id="dn-bulk" style="max-width:900px">
			<p><span id="dn-left"><?php echo (int) count( $q ); ?></span> soggetti da cercare, <span id="dn-found"><?php echo (int) count( $res ); ?></span> con proposte. <span id="dn-now" class="description"></span></p>
			<p><button type="button" class="button button-primary" id="dn-go">Avvia / riprendi</button> <button type="button" class="button" id="dn-stop">Pausa</button>
			<span class="description"> Lascia aperta questa pagina: la ricerca va avanti finché non arriva a zero. Se la chiudi, riprendi da dove eri.</span></p>
		</div>
		<script>
		(function(){
			var run=false, url=<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, n=<?php echo wp_json_encode( $nonce ); ?>;
			function step(){
				if(!run){return;}
				fetch(url,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=dirnet_enrich_step&n='+n})
				.then(function(r){return r.json();}).then(function(j){
					if(!j.success){run=false;document.getElementById('dn-now').textContent='Errore: ricarica la pagina.';return;}
					document.getElementById('dn-left').textContent=j.data.left;
					document.getElementById('dn-found').textContent=j.data.found;
					document.getElementById('dn-now').textContent=j.data.title||'';
					if(j.data.left>0){step();}else{run=false;location.href=location.pathname+location.search.replace(/&go=1/,'');}
				}).catch(function(){setTimeout(step,3000);});
			}
			document.getElementById('dn-go').onclick=function(){if(!run){run=true;step();}};
			document.getElementById('dn-stop').onclick=function(){run=false;};
			<?php if ( ! empty( $_GET['go'] ) && $q ) : // phpcs:ignore ?>document.getElementById('dn-go').click();<?php endif; ?>
		})();
		</script>
		<?php
		if ( ! $res ) {
			echo '<p>Nessun risultato ancora.</p>';
			return;
		}
		?>
		<h3>Risultati da controllare</h3>
		<p class="description">Il sito trovato con la ricerca va controllato: apri il link. Email e telefono sono quelli letti da quel sito. Spunta solo ciò che vuoi salvare; i campi già compilati non vengono toccati.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'dirnet_enrich_bulk_apply' ); ?>
			<input type="hidden" name="action" value="dirnet_enrich_bulk_apply" />
			<table class="widefat striped"><thead><tr><th>Soggetto</th><th>Sito</th><th>Email</th><th>Telefono</th><th>Indirizzo</th></tr></thead><tbody>
			<?php
			foreach ( $res as $id => $p ) {
				$miss = $this->missing( $id );
				$cell = function ( $meta, $key, $label, $show ) use ( $id, $miss ) {
					if ( ! in_array( $meta, $miss, true ) || '' === $show ) {
						return '<td>&mdash;</td>';
					}
					return '<td><label><input type="checkbox" name="rows[' . (int) $id . '][' . $key . ']" value="1" checked /> ' . $label . '</label></td>';
				};
				$site = ! empty( $p['cand'][0]['url'] ) ? $p['cand'][0]['url'] : '';
				echo '<tr><td>' . esc_html( get_the_title( $id ) ) . ' <small>#' . (int) $id . '</small></td>';
				echo $cell( '_website', 'site', '<a href="' . esc_url( $site ) . '" target="_blank" rel="noopener">' . esc_html( preg_replace( '#^https?://(www\.)?#', '', $site ) ) . '</a>', $site ); // phpcs:ignore WordPress.Security.EscapeOutput
				echo $cell( '_email', 'email', esc_html( $p['email'][0] ?? '' ), $p['email'][0] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput
				echo $cell( '_phone', 'phone', esc_html( $p['phone'][0] ?? '' ), $p['phone'][0] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput
				echo $cell( '_address', 'addr', esc_html( $p['osm']['address'] ?? '' ), $p['osm']['address'] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput
				echo '</tr>';
			}
			?>
			</tbody></table>
			<p><button type="button" class="button" onclick="document.querySelectorAll('#wpbody input[type=checkbox]').forEach(function(c){c.checked=true})">Seleziona tutto</button>
			<button type="button" class="button" onclick="document.querySelectorAll('#wpbody input[type=checkbox]').forEach(function(c){c.checked=false})">Deseleziona tutto</button></p>
			<?php submit_button( 'Applica i selezionati' ); ?>
		</form>
		<?php
	}

	public function bulk_apply() {
		check_admin_referer( 'dirnet_enrich_bulk_apply' );
		current_user_can( 'manage_options' ) || wp_die( 'No' );
		$res  = (array) get_option( 'dirnet_enrich_res', array() );
		$rows = isset( $_POST['rows'] ) && is_array( $_POST['rows'] ) ? wp_unslash( $_POST['rows'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$n    = 0;
		foreach ( $rows as $id => $f ) {
			$id = (int) $id;
			if ( empty( $res[ $id ] ) || ! is_array( $f ) ) {
				continue;
			}
			$p   = $res[ $id ];
			$set = function ( $k, $v ) use ( $id, &$n ) {
				if ( '' !== $v && '' === trim( (string) get_post_meta( $id, $k, true ) ) ) {
					update_post_meta( $id, $k, $v );
					$n++;
				}
			};
			if ( ! empty( $f['site'] ) && ! empty( $p['cand'][0]['url'] ) ) {
				$set( '_website', esc_url_raw( $p['cand'][0]['url'] ) );
			}
			if ( ! empty( $f['email'] ) && ! empty( $p['email'][0] ) ) {
				$set( '_email', sanitize_email( $p['email'][0] ) );
			}
			if ( ! empty( $f['phone'] ) && ! empty( $p['phone'][0] ) ) {
				$set( '_phone', sanitize_text_field( $p['phone'][0] ) );
			}
			if ( ! empty( $f['addr'] ) && ! empty( $p['osm'] ) ) {
				$set( '_address', sanitize_text_field( $p['osm']['address'] ) );
				$set( '_manual_lat', $p['osm']['lat'] );
				$set( '_manual_lng', $p['osm']['lng'] );
			}
			unset( $res[ $id ] );
		}
		update_option( 'dirnet_enrich_res', $res, false );
		set_transient( 'dirnet_log_' . get_current_user_id(), array( sprintf( 'Completa dati: %d campi salvati.', $n ) ), 60 );
		wp_safe_redirect( $this->url( array( 'bulk' => 1 ) ) );
		exit;
	}

	public function apply() {
		$id = absint( $_POST['id'] ?? 0 );
		check_admin_referer( 'dirnet_enrich_apply_' . $id );
		current_user_can( 'manage_options' ) || wp_die( 'No' );
		$prop = get_transient( 'dirnet_enrich_' . $id );
		$set  = function ( $k, $v ) use ( $id ) {
			if ( '' !== $v && '' === trim( (string) get_post_meta( $id, $k, true ) ) ) {
				update_post_meta( $id, $k, $v );
			}
		};
		$set( '_website', esc_url_raw( wp_unslash( $_POST['site'] ?? '' ) ) );
		$set( '_email', sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ) );
		$set( '_phone', sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ) );
		if ( ! empty( $_POST['addr'] ) && ! empty( $prop['osm'] ) ) {
			$set( '_address', sanitize_text_field( $prop['osm']['address'] ) );
			$set( '_manual_lat', $prop['osm']['lat'] );
			$set( '_manual_lng', $prop['osm']['lng'] );
		}
		delete_transient( 'dirnet_enrich_' . $id );
		wp_safe_redirect( $this->url() );
		exit;
	}
}
