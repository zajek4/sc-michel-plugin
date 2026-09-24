=== WP CPT Sidrene cijene ===
Contributors: cptsc
Tags: sidrena cijena, dodatna cijena, cjenik, csv, cpt
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Dodatna (sidrena) cijena i digitalni CSV cjenik za WordPress CPT-ove — bez WooCommercea.

== Description ==

WP CPT Sidrene cijene omogućuje prikaz dodatne (sidrene) cijene uz aktualnu cijenu i javnu objavu digitalnog cjenika prema Odlukama NN 101/2026, za web stranice koje proizvode/usluge vode kroz Custom Post Typeove.

Ključne značajke:

* Čarobnjak za postavljanje u 12 koraka
* Discovery i potvrda mapiranja cijena (ACF, meta, taxonomije)
* Zaštićena povijesna sidrena cijena s auditom
* Normalizirani indeks, queue i background poslovi
* Frontend: automatski prikaz (potvrđeni selector) + shortcode `[sidrena_cijena]`
* Javni CSV cjenik (`/cjenik/aktualni.csv`) + HTML cjenik + arhiva ≥30 dana
* CSV import sidrenih podataka
* WPML / Polylang podrška
* Dijagnostika i testni način

== Installation ==

1. Prenesite ZIP kroz Dodaci → Dodaj novi → Prenesi
2. Aktivirajte plugin
3. Slijedite čarobnjak za postavljanje

== Changelog ==

= 1.1.0 =
* Produkcijsko stvrdnjavanje: feed-scope preflight, BLOCKED za obvezne sidrene podatke, atomicna objava, fingerprint recovery, WP timezone, keyset cleanup, registered meta API, SSL, footer selector-assist, Uninstalacija „Obriši SVE", queue retry, novi indeksi baze.

= 1.0.0 =
* Prvo izdanje

== Upgrade Notice ==

= 1.1.0 =
Produkcijsko stvrdnjavanje i usklađenost; preporučena nadogradnja.
