<?php
/**
 * Plugin Name: SolidarOS
 * Plugin URI:  https://github.com/mercatosociale/solidaros
 * Description: Strumenti per l'economia solidale. Catalogo basato su Directorist ed Autocertificazione basata su Bilancio sociale, Scheda azienda, Scheda prodotti, Scheda prezzo sorgente e Scheda valutazione partecipativa. I due moduli si attivano separatamente.
 * Version:           1.0.74
 * Requires PHP: 7.4
 * Author:      Mercato Sociale
 * License:     GPLv3
 * Text Domain: solidaros
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SOLIDAROS_VERSION', '1.0.74' );
define( 'SOLIDAROS_FILE', __FILE__ );
define( 'SOLIDAROS_PATH', plugin_dir_path( __FILE__ ) );
define( 'SOLIDAROS_URL', plugin_dir_url( __FILE__ ) );

/**
 * Autoloader PSR-4 locale. Nessun vendor/, nessuna dipendenza Composer.
 */
spl_autoload_register( function ( $class ) {
    $prefix = 'Solidaros\\';
    if ( strpos( $class, $prefix ) !== 0 ) {
        return;
    }
    $relative = substr( $class, strlen( $prefix ) );
    $file     = SOLIDAROS_PATH . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

// Due moduli, ciascuno spegnibile da Impostazioni › Solidaros: Catalogo (ex Directorist Network) e Autocertificazione.
// Il Catalogo si carica subito (priorità 1) per definire le sue costanti prima dell'avvio dell'Autocertificazione;
// con il Catalogo spento o Directorist assente l'Autocertificazione lavora da sola, e viceversa.
add_action( 'plugins_loaded', function () {
    if ( Solidaros\Core\Modules::catalogRuns() ) {
        require_once SOLIDAROS_PATH . 'catalogo/catalogo.php';
    }
}, 1 );
register_deactivation_hook( __FILE__, function () {
    if ( class_exists( '\\DirectoristNetwork\\Member' ) ) {
        \DirectoristNetwork\Member::restore();
    }
} );

register_activation_hook( __FILE__, array( 'Solidaros\\Storage\\Database', 'activate' ) );
register_activation_hook( __FILE__, array( 'Solidaros\\Security\\Capabilities', 'install' ) );
register_deactivation_hook( __FILE__, array( 'Solidaros\\Security\\Capabilities', 'uninstall' ) );

// L'URL pubblico entita/<slug-nome>/ e il suo redirect al PDF del
// questionario (entita/<nome>/questionario/<slug>.pdf) sono di proprietà del
// plugin SoliCat dallo split del 18/09/2026 — nulla da registrare qui.

add_action( 'plugins_loaded', function () {
    load_plugin_textdomain( 'solidaros', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

    // Aggiornamenti via ZIP sovrascrivono i file ma non rilanciano
    // register_activation_hook: senza questo controllo, chi aggiorna il plugin
    // senza disattivarlo/riattivarlo si ritroverebbe con colonne DB mancanti.
    if ( get_option( 'solidaros_schema_version' ) !== Solidaros\Storage\Database::SCHEMA_VERSION ) {
        Solidaros\Storage\Database::activate();
    }

    // Stessa logica per la mappa ruoli→capability: un aggiornamento via ZIP non
    // rilancia register_activation_hook, quindi il ritiro del ruolo reviewer e
    // l'assegnazione della funzione di amministrazione ad 'administrator'/'editor'
    // vanno riallineati anche sugli aggiornamenti in place.
    if ( get_option( 'solidaros_caps_version' ) !== Solidaros\Security\Capabilities::CAPS_VERSION ) {
        Solidaros\Security\Capabilities::install();
    }

    // Pagine degli shortcode: generate all'attivazione; su un aggiornamento in
    // place (nessuna attivazione) le crea al primo caricamento.
    if ( false === get_option( Solidaros\Core\Pages::OPTION ) ) {
        add_action( 'init', array( 'Solidaros\\Core\\Pages', 'ensure' ) );
    }

    Solidaros\Core\Modules::boot();
    ( new Solidaros\Core\Faq() )->register(); // FAQ dell'intero sito: indipendenti dai due moduli
    if ( Solidaros\Core\Modules::enabled( Solidaros\Core\Modules::SELFCERT ) ) {
        ( new Solidaros\Plugin() )->boot();
    }
} );
