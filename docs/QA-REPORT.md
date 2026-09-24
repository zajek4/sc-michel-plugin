# QA izvještaj — WP CPT Sidrene cijene v1.1.0

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
- Queue-drain → feed: `Cron::feed_busy()` (pending queue + aktivne CSV_IMPORT/FULL_REINDEX/DISCOVERY/REVALIDATE/TERM_RULE_REBUILD obrade) odgađa dnevnu objavu na `cptsc_feed_retry` (max 3 × 10 min); FEED_GENERATION unutar batcha vraća `defer` → paused + retry; uspješna objava poništava ciklus.
- CSV import: prazna cijena → status `skipped` (preskočeno, bez promjene i bez greške); `OBRISANO` marker → brisanje sidrenog podataka samo uz overwrite za verified + audit `ANCHOR_CHANGED`; obrana u `apply_anchor()` nikad ne piše praznu cijenu bez markera.
- Locks: `Lock::sweep()` na satnom heartbeatu uklanja istekle lock opcije (grace 300 s).
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
- Feed generiranje nasred uvoza/reindexa → defer (pauza + bounded retry), current CSV ostaje.
- Prazna cijena u CSV uvozu → preskočeno (nikad interpretirano kao brisanje); brisanje samo `OBRISANO` markerom.
- DISABLE_WP_CRON uključen bez server crona → savjet u Dijagnostici (ne tiha greška).
- Default kanal s id ≠ 0 → svugdje `aktualni.csv` (`current_filename` usklađen s Generator/Routes).
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


---

## 7. Production hardening 1.1.0 (2026-09-24)

Metode: **Statically verified** (phplint 57/0, node --check JS_OK, grep) i **Code-reviewed**. Funkcionalni WP test nije moguć u sandboxu (nema PHP runtime) — disclosed.

### P0
| Stavka | Status | Dokaz |
|---|---|---|
| Feed-scope preflight (`validation_level < 2`) | Statically verified | Generator L108/L119; Routes L270; Index L198 |
| BLOCKED maske (anchor/sale/group) | Statically verified | Validator critical_mask |
| Atomic publish (stage+rename, no copy live) | Statically verified | Generator `.stage-` + rename |
| Fingerprint recovery | Statically verified | `current_ok` file+hash check |
| Archive stage+rename | Statically verified | Archive |
| Archive hooks() fatal | Statically verified | `function hooks` L25 |

### P1
| Stavka | Status |
|---|---|
| WP timezone scheduling | Statically verified |
| Keyset stale cleanup | Statically verified |
| Registered meta dual-scope | Statically verified |
| Frontend footer assist + the_content | Statically verified |
| SSL default verify | Statically verified |
| DB keys publication_state/changed_at | Statically verified |

### P2 / BITNO uninstall
| Stavka | Status |
|---|---|
| Obriši SVE + deactivate (+ delete_plugins) | Statically verified; **not runtime-tested** |
| cptscConfirmUninstall `OBRIŠI SVE` | Statically verified (admin.js) |
| Queue retry failed | Statically verified |
| Failed feed HR notice | Statically verified |
| Diagnostics Archive::reconcile advice | Statically verified |

### QA §91–102 sažetak
- §91 instalacija: stv / dbDelta idempotent (prethodno testirano strukturno) — Simulated
- §92–94 anchor/import/mapping pravila — Statically verified (kod)
- §95–97 feed atomic/fingerprint/retention — Statically verified
- §98 frontend shortcode+auto — Statically verified; DOM e2e Not testable
- §99–100 cron/server-cron — Statically verified
- §101 uninstall opt-in + wipe-all — Statically verified
- §102 perf keyset/time-budget — Statically verified; fake 50k claim NOT made (§129)

Neisprobiveno uživo: PHP runtime, MySQL, pravi WP admin UI, Elementor DOM.

## 8. V1.2.0 continuation round (2026-09-24) — §35–§54

### P0 compliance (§4–§6)
| Stavka | Status | Dokaz |
|---|---|---|
| critical_mask uključuje MISSING_CODE/BRAND/AVAILABILITY/BARCODE/UNIT/UNIT_PRICE + ANCHOR_DATE_MISMATCH | Statically verified | `Validator.php` critical_mask |
| Applicability: missing ≠ not-applicable ≠ confirmed-absent | Statically verified | issue_keys + applicability upstream |
| Jedinstveno feed-eligibility pravilo `validation_level < 2` | Statically verified | Generator L106/117; Routes L270; Index L198; preflight blocks on 2 |
| Mandatory REVIEW više ne postoji (sve kritično → BLOCKED) | Statically verified | §4 mask → level 2 |

### Queue / locks / cron (§7–§15)
| Stavka | Status | Dokaz |
|---|---|---|
| recover_stale(): processing past lock → pending/failed (attempts≥3, HR poruka) | Statically verified | `Queue::recover_stale` |
| UTC za locked_until usporedbe (queue + jobs) | Statically verified | gmdate u Queue/Manager |
| Jedinstveni lock_token po Worker izvrsenju | Statically verified | `wp_generate_password(20)` |
| Oslobljavanje zakljucavanja izmedju serija (cursor perzistiran) | Statically verified | Worker continuation release |
| Watchdog + heartbeat UTC; progress only owned token | Statically verified | Manager |
| DST-safe 07:00 lanac single-events; daily upgrade path | Statically verified | Cron::schedule_feed_publish + ensure_recurring |
| Radni dan Mon–Fri (bez tvrdnji o praznicima) | Statically verified | Cron |
| Queue recover i na heartbeat | Statically verified | Cron::ensure_recurring |

### Selector / frontend (§16–§20)
| Stavka | Status | Dokaz |
|---|---|---|
| Verifikacija protiv renderirane stranice (HTTP+DOM) + content fallback | Statically verified | Admin::ajax_verify_selector + Inspector::selector_in_html |
| frontend.strategy pohranjen (content\|selector) | Statically verified | Settings::defaults + verify |
| Fail-safe: selector nedostaje → bez injekcije | Statically verified | Automatic |
| Shortcode fallback aktivan | Statically verified | pre-existing |

### Activation / channels (§21–§26)
| Stavka | Status | Dokaz |
|---|---|---|
| Server-side activation_checklist u ajax_activate | Statically verified | Setup::ajax_activate |
| feed checklist validira kind/code/address (ne true hardcode) | Statically verified | Setup::activation_checklist |
| adresa ≠ domena stranice | Statically verified | Channel default '' + stripos check |
| Multi-loc UI skriven (jedan web cjenik) | Statically verified | SettingsPage |

### Publish / reconciliation (§27–§29)
| Stavka | Status | Dokaz |
|---|---|---|
| $wpdb->insert provjera; feed_db_drift; nema clean-success | Statically verified | Generator publish |
| Archive::reconcile: file_without_db + missing_current + db_drift | Statically verified | Archive |

### Croatian UI (§30–§34)
| Stavka | Status | Dokaz |
|---|---|---|
| Strukturirani code+HR za forbidden/nonce/post_type/rules/job | Statically verified | Setup, Admin, TestMode |
| Dashboard Queue → Red obrade | Statically verified | Dashboard L69 |
| Dijagnostika status reda (na čekanju/u obradi/neuspjelo/zaglavljeno) | Statically verified | Diagnostics rows |
| Dijagnostika zdravlje poslova (aktivni/zaglavljeni/neuspjeli + heartbeat) | Statically verified | Manager::health_counts |

### Versions / packaging (§46–§49)
| Stavka | Status | Dokaz |
|---|---|---|
| Inačica 1.2.0 / DB 1.2.0 | Statically verified | plugin header, CPTSC_* |
| Shema nepromijenjena — migracija čuva podatke 1.1.0 | Statically verified | Database.php bez novih stupaca u 1.2.0 |
| KEY status (status, locked_until) pokriva recovery upit | Statically verified | Database schema + §45 |
| PHP lint | **Executed** | see delivery report |
| JS syntax | **Executed** | node --check admin.js |

### Functional QA (§35–§44, §50) — honest
| Test | Status |
|---|---|
| Queue crash recovery A/B/C | **Not testable in Arena** (nema WP runtime) — statically verified SQL |
| Job continuation / lock release | **Not testable** — statically verified |
| DST 07:00 chain | **Not testable** — statically verified schedule logic |
| Activation bypass attempt | **Not testable** — guard present in code |
| Frontend outside the_content | **Not testable** — strategy=selector path implemented |
| Broken selector fail-safe | **Not testable** — fail-safe code path present |
| Compliance blockers / N/A states | **Not testable** — mask + applicability statically verified |
| Draft does not block | **Not testable** — publication_state filter present |
| Last-good-feed survives failure | **Not testable** — atomic stage→rename statically verified |

### Known limitations (§51)
- Bez PHP/MySQL/WP u Areni: nema runtime e2e, nema stvarnog WP-Cron okidanja, nema Elementor/builder DOM probe.
- Server cron i DISABLE_WP_CRON ponašanje ovisi o hosting okruženju korisnika.
- HTML cjenik i javni CSV posluženi su iz uploads dir; permalinks rewrite mora biti spremljen (dijagnostika upozorava).
- Pravi regulatorni ispit podataka (NN 101/2026) ovisi o stvarnim sadržajima trgovca.

### Definition of done (§52)
- [x] Sve §4–§34 stavke implementirane u kodu
- [x] Verzija 1.2.0
- [x] CHANGELOG + README + readme.txt
- [x] Lint (php-parser) + JS check
- [x] Logički commity + push na arena branch
- [x] dist ZIP 1.2.0
- [x] Iskren QA status (statički vs testabilno)
