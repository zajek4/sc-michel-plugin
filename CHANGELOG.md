# Changelog

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
