# Changelog

## 1.2.0

### Compliance & feed (P0)
- Critical mandatory-data issues (MISSING_CODE/BRAND/AVAILABILITY/BARCODE/UNIT/UNIT_PRICE + ANCHOR_DATE_MISMATCH) now block feed generation via validation level 2; applicability still distinguishes missing / not-applicable / confirmed-absent.
- Single feed-eligibility rule remains `validation_level < 2` (READY + non-mandatory REVIEW only).

### Queue, jobs, locking
- `recover_stale()`: stuck `processing` rows recovered with UTC lock comparisons; attempts ≥ 3 → `failed` with Croatian error message.
- Unique per-worker lock tokens; continuation releases lock between batches (persisted cursor).
- Watchdog and heartbeats use UTC machine time consistently; progress() only heartbeats owned token.
- Retry limit 3 for queue items.

### Cron & DST
- Feed publish uses chained `wp_schedule_single_event` (DST-safe 07:00); legacy daily events converted; workdays Mon–Fri.

### Selector & frontend
- Selector verification against rendered page (HTTP fetch + DOM) with content fallback; explicit `frontend.strategy` stored (content|selector).
- Fail-safe unchanged: missing selector → no injection; shortcode `[sidrena_cijena]` full fallback.

### Activation & channels
- Server-side activation checklist enforcement (nonce+cap insufficient); feed metadata validated (object kind/code/address; no site-domain address).
- Multi-location UI hidden until real per-channel data.

### Publish & reconciliation
- `$wpdb->insert` return checked; DB drift flagged (`feed_db_drift`), no clean-success on insert failure.
- `Archive::reconcile()` extended: file-without-DB, missing file for DB, drift flag.

### Admin UI (Croatian)
- Queue → Red obrade; Diagnostics: Na čekanju / U obradi / Neuspjelo / Zaglavljeno; Aktivni / Zaglavljeni / Neuspjeli poslovi; Zadnji heartbeat.
- Structured code + Croatian messages for forbidden/nonce/post_type/rules/job errors.

### Versions
- Plugin 1.2.0 / DB 1.2.0 (schema unchanged from 1.1.0; version bump for migration consistency).


## 1.1.0 — 2026-09-24

Produkcijsko stvrdnjavanje (production hardening) i usklađenost.

### Ispravljeno (P0)
- **Raspon validacije cjenika**: preflight više ne koristi globalne brojače — gleda samo objavljene+kanonske stavke. Draft/private/trash stavke više ne mogu blokirati nevezani feed.
- **Blokiranje zakonski obveznih podataka**: `MISSING_ANCHOR_PRICE`, `SPECIAL_SALE_NAME_MISSING`, `ANCHOR_GROUP_CONFLICT` sada su BLOCKED (ne REVIEW). Feed streama samo `validation_level < 2` (READY+REVIEW) — blokirani redci nikad ne ulaze u CSV.
- **Atomicna objava aktualnog CSV-a**: stage u istom direktoriju + `rename` — **nikad** `copy()` preko live datoteke; na neuspjehu prethodna verzija ostaje netaknuta.
- **Atomicna arhiva**: stage `*.tmp` → rename u finalno regulativno ime; neuspjeh ne bilježi uspješnu verziju.
- **Fingerprint recovery**: skip samo ako *diskovna* aktualna datoteka postoji i hash odgovara zadnjoj objavi; nestala/korumpirana datoteka se restaurira.
- **`Archive::hooks()`** — nedostajala metoda koju je `Plugin::boot()` zvao (fatal).
- **Reconciliacija feeda** (`Archive::reconcile()`): nedostajuća datoteka, fingerprint mismatch, orfan tmp — savjeti u Dijagnostici.

### Ispravljeno (P1)
- **WordPress timezone**: dnevna objava 07:00 i provjera radnog dana koriste `wp_timezone()`/`current_datetime()` (Europe/Zagreb, DST) umjesto PHP server vremena (`strtotime('today')`, `date('N')`).
- **Stale index cleanup**: keyset paginacija preko cijelog indeksa (bez `LIMIT 5000`) — skalira na 10k/50k/100k.
- **Registered meta discovery**: ispravan API `get_registered_meta_keys('post')` + `get_registered_meta_keys('post', $post_type)`.
- **Frontend automatic**: the_content strategija + footer selector-assist za Elementor/Bricks/ACF template (isti Renderer, potvrđeni selector, fail-silent; isključivo kad je AUTO_VERIFIED).
- **SSL**: inspector više ne šalje `sslverify => false` (filter `cptsc_inspector_sslverify` samo za staging).
- **Archive cleanup**: cutoff usklađen s `current_time` satnicom; nikad ne briše aktualni file po kanalu.
- **HR format datuma**: `Dates::format_hr_date` koristi WP timezone komponente.

### Poboljšano
- **Uninstalacija E)**: jedan klik „Obriši SVE i deinstaliraj" — DROP tablice, sve opcije, cron, `uploads/cptsc`, deaktivacija (+ brisanje datoteka ako `delete_plugins`).
- **Queue retry**: „Pokušaj ponovno" za neuspješne stavke u Dijagnostici.
- **Failed feed UX**: hrvatska poruka da prethodna ispravna verzija ostaje dostupna + grupirane greške.
- **Indeksi baze**: `publication_state`, `changed_at` na `cptsc_items` (dbDelta, idempotentno).
- Cron schedule display na hrvatskom; AJAX poruke na hrvatskom.

### Baza
- `1.0.0` → `1.1.0` (novi indeksi; bez promjena kolona; postojeći podaci ostaju netaknuti).

---

## 1.0.0 — 2026-09-24

Prvo izdanje.

### Poboljšanja po referentnoj arhitekturi (WooCommerce Sidrene Cijene v2.8.18, evaluacija 2026-09-24)
- **Queue-drain → feed zaštita**: dnevna objava i retry čekaju da se indeks smiri (queue prazan + nema aktivnih obrada) prije generiranja CSV-a; FEED_GENERATION job se pauzira (`defer`) ako katalog još radi.
- **Feed retry**: jednokratni `cptsc_feed_retry` događaj (10 min, max 3 pokušaja po ciklusu; ciklus se poništi na dnevnom terminu i na uspješnoj objavi) — neuspjela/odgođena objava ne čeka sljedeći dan.
- **CSV import semantika**: prazna sidrena cijena = preskočeno (nikad brisanje, nikad greška); eksplicitni marker `OBRISANO` briše sidreni podatak uz potvrdu prepisivanja za potvrđene podatke + audit `ANCHOR_CHANGED`; pregled prikazuje preskočene i retke za brisanje.
- **Lock sweep**: satni heartbeat uklanja istekle lock opcije (reconciliacija nakon fatala/timeouta).
- **Imenovane FMCG skupine** (NN 101/2026): `fmcg_hrana`, `fmcg_pice`, `fmcg_kozmetika`, `fmcg_ciscenje`, `fmcg_toaleta`, `fmcg_kucanstvo` — sve na 2.5.2025.; uz postojeće `legacy_fmcg` i `general_2026`. Dodatne su opcionalne, nikad se ne dodjeljuju automatski.
- **Dijagnostika**: provjera frontend selector stanja, shortcode dimenzija, kanonskog ID-ja, javne rute i datoteke na disku; savjeti (DISABLE_WP_CRON bez server crona, neispravan selector pri AUTO_VERIFIED, nedostajuća datoteka, neuspješni queue redci); retry status i queue-drain status u tabeli.
- **Ispravak**: `Archive::current_filename()` sada poštuje default kanal (id ≠ 0 → ipak `aktualni.csv`) — usklađeno s Generator/Routes logikom; Dijagnostika koristi istu putanju.

### Regulatorno
- NN 101/2026 — dodatna cijena na 10.9.2026. (FMCG kategorije: 2.5.2025.)
- Javni CSV cjenik (strokovno čitljiv), arhiva ≥30 dana, dnevna objava do 8:00 (trgovci radnim danom)
- Polja CSV-a za proizvode i usluge prema Odluci o objavi cjenika + pojašnjenjima Ministarstva gospodarstva

### Funkcije
- Setup wizard (12 koraka, resumable)
- Discovery engine (meta / ACF / taxonomije / registered meta + heuristički scoring)
- Source profile mapping (core, meta, acf, taxonomy, constant, manual, adapter)
- Normalizirani indeks `cptsc_items` s DECIMAL cijenama, issue bitmaskom i row hashom
- Sidrene grupe + term pravila + ANCHOR_GROUP_CONFLICT / ANCHOR_DATE_MISMATCH review
- Zaštićena sidrena cijena (nikad se ne izmišlja, nikad se ne mijenja bez potvrde) + audit
- First listing capture (immutable, requires_confirmation za naknadno instaliran plugin)
- Deduplicirani queue s bufferom, batch obradom i lockovima
- Job sustav (DISCOVERY, FULL_REINDEX, CSV_IMPORT, FEED_GENERATION, ARCHIVE_CLEANUP, TERM_RULE_REBUILD, REVALIDATE) s cursorom, heartbeatom, watchdogom i nastavkom
- WP-Cron + potpisani server-cron heartbeat
- Atomic CSV feed (temp → preflight → SHA-256 → arhiva → rename), stabilan aktualni URL
- Javni HTML cjenik (pretraga + straničenje) i arhiva
- Frontend: automatski prikaz uz potvrđen selector (fail-silent) + `[sidrena_cijena]`
- Metabox "Sidrena cijena" s ručnim unosom i poviješću
- Centralni katalog s filtrima, pretragom i serverskim straničenjem
- CSV import sidrenih podataka (dry-run, batch, object ID → jedinstvena šifra)
- Test mode (bez utjecaja na produkciju)
- Reset operacije A/B/C/D (odvojene)
- WPML/Polylang canonical logika
- Adapter API (filteri/akcije s prefiksom `cptsc_`)
- Dijagnostika s "Kopiraj dijagnostiku"
- uninstall.php: podaci se ne brišu bez eksplicitne postavke
