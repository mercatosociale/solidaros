=== SolidarOS ===
Contributors: mercatosociale
Tags: economia solidale, directorist, bilancio sociale, PGS, reti, produttori
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.74
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Strumenti per l'economia solidale. Catalogo basato su Directorist ed Autocertificazione basata su Bilancio sociale, Scheda azienda, Scheda prodotti, Scheda prezzo sorgente e Scheda valutazione partecipativa. I due moduli si attivano separatamente.

== Description ==

SolidarOS riunisce due moduli, ciascuno attivabile e disattivabile da *SolidarOS → Impostazioni*. Possono funzionare insieme o da soli.

= Modulo Catalogo =

Richiede il plugin Directorist. Configura Directorist per una rete di soggetti (tipi di elenco, campi, categorie, ruoli, territori, tag) e aggiunge:

* **Accesso e registrazione** con codice via email, senza link da aprire.
* **Bacheca** personale: Dati personali, La mia scheda e, con Autocertificazione, I miei questionari.
* **Scheda del soggetto** creata con la registrazione e precompilata.
* **Soggetti, Territori, Categorie**, con mappa e filtri.
* **Import/Export** dei dati, strumenti di riparazione (allineamento schede, coordinate mancanti, ripristino email da CSV) e dati di prova.

= Modulo Autocertificazione =

Funziona anche da solo, senza Directorist e senza utenti WordPress (modalità standalone: l'identità è l'email, confermata con un codice).

* **Bilancio sociale** con punteggio automatico.
* **Scheda azienda** (94 campi in sette blocchi) e **Schede prodotto** (28 tipi, senza prezzi).
* **Scheda prezzo sorgente**: il listino del produttore, esposto anche via API REST.
* **Scheda Valutazione Partecipativa** (PGS): i valutatori verificano le schede di azienda e di prodotto.
* **Import/Export** dei questionari e import del listino in WooCommerce (solo se attivo).

I due moduli dialogano solo tramite azioni e filtri di WordPress.

= Menu di amministrazione =

* **Impostazioni**: attivazione dei moduli.
* **Catalogo** e **Autocertificazione**: ciascuno con il proprio menu laterale, dove *Configurazione* è la prima voce.

= Accesso e registrazione =

1. La persona inserisce l'email e riceve un codice a 6 cifre (valido 10 minuti).
2. Se è già registrata entra nella bacheca o nei suoi questionari.
3. Se non lo è, sceglie come registrarsi (produttore, GAS, rete, distributore, valutatore PGS), compila l'anagrafica e accetta la Privacy Policy.
4. Si crea l'account e, per i soggetti, la scheda già pubblicata. Il ruolo è attivo subito; l'amministratore conferma o rifiuta da *Utenti abilitati*. Il rifiuto cancella la scheda e, per il produttore, i questionari e la scheda prezzo sorgente; per un valutatore revoca il ruolo.

Antispam: un codice al minuto per email, 5 richieste all'ora per IP, 300 al giorno per il sito (filtri `solidaros_code_ip_hour`, `solidaros_code_site_day`, `dirnet_code_ip_hour`, `dirnet_code_site_day`). Un controllo giornaliero rimuove, dopo 7 giorni, gli account lasciati a metà.

= Pagine e shortcode =

Il plugin non crea pagine: le crei tu e inserisci lo shortcode. Con Catalogo attivo le pagine si assegnano da *Catalogo → Pagine di sistema*.

Autocertificazione:

* `[so-login]` accesso
* `[so-register]` registrazione
* `[so-mydocs]` i miei questionari
* `[so-socialbalance]` Bilancio sociale
* `[so-entity]` Scheda azienda
* `[so-products]` Schede prodotto
* `[so-sourceprice]` Scheda prezzo sorgente
* `[so-pgs-process]` Scheda Valutazione Partecipativa

Catalogo:

* `[dirnet_access]` accesso
* `[dirnet_registration]` registrazione
* `[dirnet_dashboard]` bacheca (sostituisce `[directorist_user_dashboard]`)
* `[dirnet_entities]` elenco soggetti, `[dirnet_map]` mappa
* `[dirnet_territory]` territori, `[dirnet_categories]` categorie
* `[dirnet_assignment]` assegnazione di una scheda già presente
* `[dirnet_social_report]`

L'elenco completo con gli attributi è in *Catalogo → Shortcode*.

= Integrazioni =

* Chiave dell'entità: l'ID della scheda Directorist (meta utente `sm_entity_id`); in standalone `SU-<id>`.
* Azioni: `solidaros_request_role`, `solidaros_grant_role`, `solidaros_grant_role_provisional`, `solidaros_role_granted`, `solidaros_role_rejected`.
* Filtri: `solidaros_role_status`, `solidaros_pages_registry`, `solidaros_page_url`, `solidaros_pgs_status`, `solidaros_user_profile_field`.
* API REST: `GET /wp-json/solidaros/v1/pricelist` (intestazione `Authorization: Bearer` o `X-Solidaros-Key`; parametri `entity`, `updated_since`, `active`, `page`, `per_page`).

== Installation ==

1. Carica la cartella `solidaros` in `wp-content/plugins/` e attiva il plugin.
2. In *SolidarOS → Impostazioni* scegli i moduli da usare.
3. Catalogo: installa e attiva Directorist, esegui la configurazione guidata da *Catalogo → Configurazione* e assegna le pagine da *Pagine di sistema*. Imposta la pagina Privacy Policy in *Impostazioni → Privacy*.
4. Autocertificazione: da *Autocertificazione → Configurazione* scegli la modalità (utenti WordPress o standalone) e i tipi di prodotto attivi.

= Migrazione dai vecchi plugin =

Se usavi Directorist Network e Solidaros: disattiva i due plugin, carica SolidarOS e attivalo. Le impostazioni e i dati restano. Non eliminare i vecchi plugin da wp-admin (la disinstallazione cancella dati): rimuovi le cartelle via FTP. Per tornare indietro, ricarica i due plugin vecchi via FTP.

Per aggiornare in futuro basta sostituire il plugin: lo schema del database si aggiorna da solo.

== Frequently Asked Questions ==

= Posso usare un modulo senza l'altro? =
Sì. Autocertificazione funziona anche senza Directorist; Catalogo senza Autocertificazione perde solo questionari e conferme di ruolo.

= Servono utenti WordPress? =
No, per Autocertificazione: in modalità standalone l'identità è l'email confermata con il codice.

= Devo tenere le pagine di Directorist? =
Sì: Aggiungi inserzione (serve per modificare la scheda), Bacheca, Accesso/Registrazione (reindirizzano al Catalogo) e gli archivi di categoria, tag, luogo e autore.

= I ruoli (funzioni) si ricreano a ogni aggiornamento? =
No. L'elenco iniziale viene creato una sola volta, solo se vuoto.

= Perché non vedo tutti i tipi di scheda prodotto? =
Sono attivi i tipi scelti nelle impostazioni; di default sono tutti attivi.

= Cosa succede se rifiuto un produttore? =
Il ruolo è revocato e vengono cancellati i suoi questionari e la scheda prezzo sorgente. La cancellazione è definitiva.

= Cosa succede disattivando un modulo? =
I dati restano. Disattivando Catalogo vengono ripristinate le impostazioni di Directorist modificate dal modulo.

== Changelog ==

= Dopo 1.0.0 =
* Il questionario Bilancio sociale esiste in due versioni separate, XES 2024 e XES 2025, da scegliere in Impostazioni (predefinito: 2024). La versione 2025 è allineata alla guida e al foglio punteggi XES 2025; le domande adattate all'Italia sono segnalate nel modulo.

= 1.0.0 =
* Unione di Directorist Network (3.0.0) e Solidaros (4.0.0) in un unico plugin con i moduli Catalogo e Autocertificazione, attivabili separatamente.
* Menu: Impostazioni, Catalogo, Autocertificazione, con Configurazione come prima voce.
* Strumenti di riparazione: ripristino email da CSV, coordinate mancanti, elenco comuni da GitHub.
* Traduzioni italiane complete.
