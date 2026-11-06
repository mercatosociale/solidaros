<?php
namespace DirectoristNetwork;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cambia la base degli indirizzi delle schede (es. /directory/reti/ashoka/ → /catalogo/reti/ashoka/).
 * Directorist legge la base dall'opzione "atbdp_listing_slug" (Impostazioni → Single Listing → Listing Slug); da qui si
 * scrive quell'opzione, si fa rigenerare le regole degli indirizzi e i vecchi indirizzi vengono rimandati ai nuovi (301).
 */
class ListingBase {

	const ACTION = 'dirnet_listing_base';
	const OLD    = 'dirnet_listing_old_bases';

	public function boot() {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'save' ) );
		add_action( 'template_redirect', array( $this, 'redirect_old' ), 1 );
	}

	/** @return string base attuale ('directory' se Directorist non ne ha una). */
	public static function current() {
		$o = (array) get_option( 'atbdp_option', array() );
		$s = isset( $o['atbdp_listing_slug'] ) ? (string) $o['atbdp_listing_slug'] : '';
		return '' !== $s ? $s : 'directory';
	}

	/** Pannello in Riparazione dati. */
	public static function panel() {
		$cur = self::current();
		$ex  = '';
		if ( defined( 'ATBDP_POST_TYPE' ) ) {
			$p = get_posts( array( 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids' ) );
			$ex = $p ? (string) get_permalink( $p[0] ) : '';
		}
		?>
		<hr />
		<h2><?php esc_html_e( 'Address of the entries', 'directorist-network' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Changes the first part of the address of every entry (for example /directory/networks/name/ becomes /catalogo/networks/name/). The old addresses keep working: they are redirected to the new ones.', 'directorist-network' ); ?></p>
		<?php if ( '' !== $ex ) : ?>
			<p><?php echo esc_html( sprintf( /* translators: %s: address */ __( 'Example now: %s', 'directorist-network' ), $ex ) ); ?></p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
			<p><label><?php esc_html_e( 'Base of the address', 'directorist-network' ); ?> <input type="text" name="base" value="<?php echo esc_attr( $cur ); ?>" pattern="[a-z0-9\-]+" required /></label>
			<?php submit_button( __( 'Change the address', 'directorist-network' ), 'secondary', 'submit', false ); ?></p>
		</form>
		<?php
	}

	public function save() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::ACTION ) ) {
			wp_safe_redirect( admin_url() );
			exit;
		}
		$new = isset( $_POST['base'] ) ? sanitize_title( wp_unslash( $_POST['base'] ) ) : '';
		$old = self::current();
		$log = array();
		if ( '' === $new || $new === $old ) {
			$log[] = __( 'Address not changed.', 'directorist-network' );
		} else {
			$o = (array) get_option( 'atbdp_option', array() );
			$o['atbdp_listing_slug'] = $new;
			update_option( 'atbdp_option', $o );
			$olds = array_values( array_unique( array_filter( array_merge( (array) get_option( self::OLD, array() ), array( $old ) ), function ( $s ) use ( $new ) {
				return $s !== $new;
			} ) ) );
			update_option( self::OLD, $olds, false );
			update_option( 'dirnet_rewrite_ver', '', false ); // the rules are rebuilt at the next page load
			$log[] = sprintf( /* translators: 1: old base, 2: new base */ __( 'Address changed: /%1$s/ → /%2$s/. The old addresses are redirected.', 'directorist-network' ), $old, $new );
			$log[] = __( 'If an entry does not open, save Settings → Permalinks once.', 'directorist-network' );
		}
		set_transient( 'dirnet_log_' . get_current_user_id(), $log, 300 );
		wp_safe_redirect( admin_url( 'edit.php?post_type=at_biz_dir&page=' . Repairs::SLUG ) );
		exit;
	}

	/** An old address (/directory/…) that no longer exists goes to the same path under the new base. */
	public function redirect_old() {
		if ( ! is_404() ) {
			return;
		}
		$olds = (array) get_option( self::OLD, array() );
		if ( ! $olds ) {
			return;
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$rel  = ltrim( substr( $path, strlen( rtrim( $home, '/' ) ) ), '/' );
		foreach ( $olds as $b ) {
			if ( 0 === strpos( $rel, $b . '/' ) ) {
				$to = home_url( '/' . self::current() . substr( $rel, strlen( $b ) ) );
				$q  = (string) wp_parse_url( $uri, PHP_URL_QUERY );
				wp_safe_redirect( '' !== $q ? $to . '?' . $q : $to, 301 );
				exit;
			}
		}
	}
}
