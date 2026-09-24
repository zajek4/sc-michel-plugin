# QA izvještaj — WP CPT Sidrene cijene v1.0.0

Datum: 24.9.2026. · Environment: statička analiza (nema PHP runtimea ni WordPress instancije u sandboxu)

---

## 1. Syntax

| Provjera | Rezultat |
|---|---|
| PHP syntax (57 datoteka, php-parser AST parse — ekvivalent lint) | **PASS** — 0 grešaka |
| JS syntax (`node --check assets/js/admin.js`) | **PASS** |
| CSS (ručni pregled, sve klase s prefiksom `cptsc-`) | **PASS** |
| Direct-access guard (`ABSPATH` / `WP_UNINSTALL_PLUGIN`) u svim PHP datotekama osim main fajla | **PASS** — 0 missing |

## 2. Funkcionalno (što je implementirano i pregledano)

- Discovery: sample keyset upit (LIMIT, nikad `-1`), meta agregacija iz uzorka, ACF siguran no-op, taxonomije s učestalošću, heuristički FieldScorer (regex + numeric consistency, bez AI/vanjskih API-ja).
- Mapping: resolvery core/meta/acf/taxonomy/constant/manual/adapter + `cptsc_resolve_field` filter; potvrđeni mapping se ne mijenja sam.
- Indeks: upsert po `(source_id, object_id)`, DECIMAL(20,6), issue_mask, deterministički row_hash (bez vremenskih oznaka), feed_dirty na promjeni hasha.
- Anchor: verified podatak zaštićen u `resolve_anchor()`; nikad se ne prepisuje na save_post/promjenu cijene/taxonomije; ANCHOR_DATE_MISMATCH i ANCHOR_GROUP_CONFLICT → review; prazan anchor → `MISSING_ANCHOR_PRICE` (nikad se ne izmišlja).
- First listing: immutable nakon prvog zapisivanja; `captured` (aktivan nadzor) vs `requires_confirmation` (naknadna instalacija) — cijena se NE tvrdi bez dokaza.
- Queue: request buffer + `ON DUPLICATE KEY UPDATE` dedup, unique `(source_id, object_id)`, batch + time budget + lock, pokušaji do 3 pa `failed`.
- Jobs: cursor/heartbeat/total/processed/failed, lock po poslu, continuation scheduling, watchdog → `stalled` + UX poruka „Obrada je prekinuta. Možete sigurno nastaviti."
- Feed: temp → fputcsv stream → preflight (header, kolone, blocked count) → SHA-256 → arhivska kopija (regulativni naziv) → atomic rename; greška = current ostaje netaknut; identičan fingerprint = skip.
- Arhiva: retention max(30, postavka), nikad ne briše aktualni, javni serve samo za registrirane `published` filenameove s whitelist regexom (bez `..`, `/`, `\`).
- Frontend: jedan Renderer za shortcode i automatic; labela „Cijena na 10.9.2026.:"; datum bez vodećih nula; selector verified-only; fail-silent; bez duplikata (marker provjera).
- Cron: WP-Cron (min interval + daily 07:00 feed) + server-cron s 48-znakovnim tokenom i `hash_equals`; DISABLE_WP_CRON prikazan u dijagnostici.
- Multilingvno: canonical resolucija (WPML `wpml_object_id` / Polylang `pll_default_post`), feed i indeks drže JEDAN kanonski redak.
- Uninstall: default čuva sve; brisanje samo uz eksplicitnu postavku.
- Test mode: dry-run (memory CSV, bez writeova u indeks/queue/feed/setup state).
- Reset A/B/C/D odvojeni; D traži upis „RESET".

## 3. Security

| Provjera | Rezultat |
|---|---|
| Capability (`manage_options`) na admin mutacijama | PASS (14 provjera u admin sloju) |
| Nonce provjere (form + AJAX) | PASS (17 provjera) |
| Pripremljeni SQL (`$wpdb->prepare` / table iz Database klasa) | PASS (grubi grep: svi dinamički upiti s placeholderima; `IN (...)` liste int-castane) |
| Escaping na izlazu (`esc_html/esc_attr/esc_url`, `wp_kses` za paginate) | PASS (grep: nema `echo $var` bez esc) |
| Upload: ekstenzija `.csv`, MIME whitelist, ≤32 MB, temp storage, bez izvršavanja sadržaja | PASS |
| Javna file ruta: basename + regex whitelist + realpath unutar channel dir + registracija u `feed_versions` | PASS |
| Cron token: 48 chars, `hash_equals`, prikaz samo u admin Dijagnostici, redacted u „Kopiraj dijagnostiku" | PASS |
| Ne izlažem stack trace / SQL / serverske putanja javnosti | PASS (javne rute: 404/403 bez detalja) |
| XSS: nazivi stavki, CSV sadržaj, selector provjera (`is_safe_selector`) | PASS (escape na svim izlazima; DOM ubacivanje kroz DOMDocument import) |

## 4. Performance

- Frontend: max 1–2 indeksna upita po single zahtjevu; nema kataloškog skena, nema discoveryja/feeda na frontendu.
- Svi masovni poslovi: keyset pagination (`WHERE id > cursor … LIMIT`), batch 50–500 (filterable), time budget 3–25 s (default 10).
- Admin katalog: serversko straničenje (LIMIT/OFFSET po stranici od 20 — dopušteno; zabranjeni veliki OFFSET za rebuild se ne koriste).
- CSV: stream `fputcsv` u temp datoteku — nikad cijeli katalog u memoriju.
- Options: samo settings/discovery/health/token (nikad katalog).
- Nema `posts_per_page => -1` (provjereno grepom).

## 5. Edge cases (pokriveni u kodu)

- 0 redaka u feedu → header-only datoteka + warning.
- Prazan CPT / bez ACF / bez WPML-Polylang → no-op, bez fatala.
- Hajmervorm: ACF absent → `ACFResolver` fallback na post meta; WPML absent → canonical = self.
- Promjena taxonomije nakon potvrde anchora → ANCHOR_DATE_MISMATCH, bez automatske izmjene.
- Dvije taxonomije s konfliktnim grupama → ANCHOR_GROUP_CONFLICT (ne pogađa).
- publish → draft → publish → first listing se ne mijenja.
- Posebni oblik prodaje `true` bez naziva → SPECIAL_SALE_NAME_MISSING.
- Stale lock → takeover nakon TTL; stalled job → watchdog + „Nastavi".
- Retention ispod 30 → suzdržan na 30.
- Red s mismatch broja kolona u feedu → preflight FAIL, current ostaje.
- Brisanje posta → redak se uklanja (`deleted_post` + purge u reindexu).
- Prijevodi (WPML/Polylang) → jedan fizički redak u cjeniku.

## 6. Nije bilo moguće realno izvršiti u dostupnom okruženju

Sandbox nema **PHP runtime ni WordPress instanciju** (nije bilo moguće instalirati php-cli — bez root pristupa). Stoga:

- [ ] Stvarna instalacija ZIP-a u WordPress (aktivacija, dbDelta izvedba na konkretnoj MySQL verziji)
- [ ] Pokretanje wizarda kroz preglednik (AJAX tokovi, inspector wp_remote_get prema vlastitom frontu)
- [ ] Integracijski test s pravim ACF/WPML/Polylang
- [ ] Load test s 50.000 pravih zapisa (projektno pokriveno arhitekturom: keyset + batchevi)
- [ ] E2E CSV: fputcsv izvedba, BOM, Windows Excel otvaranje
- [ ] WP-Cron stvarni ticking + server cron poziv
- [ ] XSS/SQL penetration test alatima (ručna statička provjena izvedena)

Preporuka: prije produkcije pokrenuti QA matricu iz specifikacije (§71) na staging WordPressu s bar jednim ACF i jednim čistim meta CPT-om.

## 7. Poznata ograničenja v1

- Automatski frontend radi unutar `the_content` (cijena mora biti u sadržaju); za cijenu koju generira tema koristi se shortcode — namjerno, radi fail-safety.
- Listing/loop integracija: shortcode u templateu (namjerno — bez nestabilne auto-detekecije).
- XML cjenik nije u v1 (CSV prema specifikaciji; arhitektura kanala ga dopušta kasnije).
- Wizardove poruke u JS dijelu su na hrvatskom (bez vanjskog prijevoda JS stringova).
