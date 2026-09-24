# WP CPT Sidrene cijene

Produkcijski WordPress plugin za prikaz **dodatne (sidrene) cijene** i objavu **digitalnog cjenika** na web stranicama koje proizvode ili usluge vode kroz jedan ili više Custom Post Typeova — **bez ovisnosti o WooCommerceu**.

Regulatorni okvir: NN 101/2026 (Odluka o isticanju dodatne cijene, Odluka o objavi cjenika) + pojašnjenja Ministarstva gospodarstva od 22.9.2026.

## Što plugin radi

- **Discovery** — analizira odabrane CPT-ove (sample, nikad cijeli katalog) i predlaže izvore cijena (ACF, meta, taxonomije, konstante).
- **Mapiranje** — administrator potvrđuje mapping; potvrđeni mapping se nikad sam ne mijenja.
- **Normalizirani indeks** — vlastita tablica `cptsc_items` (DECIMAL(20,6), issue bitmask, row hash).
- **Sidrene grupe** — `legacy_fmcg` (2.5.2025.) i `general_2026` (10.9.2026.) + term pravila s uključenjem podkategorija; konflikti idu u review.
- **Zaštićen povijesni podatak** — sidrena cijena se nikad ne izmišlja ni ne mijenja bez potvrde; audit trag.
- **First listing** — bilježi prvo uvrštenje (immutable) za nove proizvode/usluge.
- **Queue + Jobs** — deduplicirani queue, batch obrada, lock/heartbeat/watchdog, nastavak nakon prekida.
- **Frontend** — automatski prikaz uz **potvrđeni** CSS selector (fail-silent) + shortcode `[sidrena_cijena]`.
- **Digitalni cjenik** — atomicna CSV generacija (temp → validacija → SHA-256 → arhiva → rename), stabilan URL `/cjenik/aktualni.csv`, javna arhiva ≥30 dana (default 40).
- **Javni HTML cjenik** — `/cjenik/` s pretragom i straničenjem.
- **CSV import sidrenih podataka** — dry-run preview, batch posao, match po object ID pa jedinstvenoj šifri.
- **WPML / Polylang** — canonical identitet (jedan redak u cjeniku za prijevode).
- **Test mode** — dry-run bez ikakvog utjecaja na produkcijske podatke.
- **Dijagnostika** — scheduler, heartbeat, queue, poslovi, "Kopiraj dijagnostiku".

## Instalacija

1. `wp-cpt-sidrene-cijene-1.0.0.zip` → Dodaci → Dodaj novi → Prenesi.
2. Aktivirajte.
3. Slijedite čarobnjak za postavljanje (12 koraka, nastavlja se gdje ste stali).
4. Unesite sidrene cijene (CSV uvoz / ručno / povijesno polje / first listing).
5. Potvrdite mjesto cijene na stranici (ili uključite shortcode) i aktivirajte.

## Javni URL-ovi

| URL | Sadržaj |
|---|---|
| `/cjenik/` | HTML cjenik (pretraga, straničenje) |
| `/cjenik/aktualni.csv` | aktualni CSV (bez autentikacije, bez JS) |
| `/cjenik/arhiva/` | javna arhiva objavljenih verzija |
| `/cjenik/arhiva/{datoteka}.csv` | pojedina arhivska datoteka |

Za više lokacija: `/cjenik/aktualni-{id}.csv`.

## Shortcode

```
[sidrena_cijena]           → trenutni post u loopu
[sidrena_cijena id="123"]  → eksplicitni ID
```

Radi u Elementor shortcode widgetu, Gutenberg shortcode bloku, klasičnom sadržaju i `do_shortcode()`.

## Zahtjevi

- WordPress 6.0+
- PHP 7.4+
- Ext: `dom` (DOMDocument) za automatski prikaz — shortcode radi i bez njega
- Nije potreban WooCommerce

## Dokumentacija

- [Postavke i proces postavljanja](docs/SETUP.md)
- [Javni hookovi i filteri](docs/HOOKS.md)
- [QA izvještaj](docs/QA-REPORT.md)
- [Changelog](CHANGELOG.md)

## Sigurnost

- capability `manage_options` + nonce na svim mutacijama
- pripremljeni SQL upiti
- whitelist validacija filenameova javnih ruta (bez path traversala)
- cron token: 48 znakova, `hash_equals` usporedba, vidljiv samo u Dijagnostici
- upload: ekstenzija/MIME/veličina provjera, privremena mapa, batch obrada
- bez izlaganja stack traceova, SQL grešaka ili serverskih putanja javnosti

## Licenca

GPL-2.0-or-later
