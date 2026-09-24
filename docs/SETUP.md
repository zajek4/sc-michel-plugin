# Postavljanje (setup proces)

## Čarobnjak (12 koraka)

Čarobnjak se automatski otvara nakon aktivacije i **nastavlja se gdje je stao** (stanje se sprema u bazu, ne u sesiju).

| # | Korak | Što administrator radi |
|---|---|---|
| 1 | Dobrodošli | Kratki uvod |
| 2 | Vrsta sadržaja | Proizvodi ili usluge |
| 3 | Odabir CPT-ova | Jedan ili više tipova sadržaja |
| 4 | Discovery | Pokreće se analiza uzorka (meta, ACF, taxonomije) |
| 5 | Mapiranje cijene | Potvrda predloženog izvora (s primjerima vrijednosti) ili ručni odabir |
| 6 | Ostali podaci | Šifra, marka, jedinica, barkod, posebni oblici prodaje, dostupnost, primjenjivost polja, povijesna polja |
| 7 | Taxonomije i skupine | Termini → sidrena skupina ("uključi podkategorije"), zadana skupina |
| 8 | Sidrene cijene | Pregled + link na CSV uvoz / ručni unos; potvrda |
| 9 | Frontend prikaz | Inspector pronalazi mjesto cijene → potvrda selektora; ili isključenje (shortcode) |
| 10 | Podaci za cjenik | Više lokacija? (default NE), podaci glavnog kanala, uključi javnu objavu |
| 11 | Preflight | SPREMNO / ZA PROVJERU / BLOKIRANO s klikovima na problematične stavke |
| 12 | Aktivacija | Checklist → Aktiviraj |

Nakon aktivacije: `setup_state = ACTIVE`, pokreće se početni indeks, zakazana je dnevna objava cjenika (radnim danima do 8:00 za trgovce; kod usluga pri promjeni).

## Nakon postavljanja

- **Pregled** — status sustava, brojke, zadnji cjenik, queue.
- **Proizvodi/Usluge** — tablični katalog (filtr + pretraga + serversko straničenje).
- **Digitalni cjenik** — uključi/isključi objavu, "Objavi odmah", kanali.
- **Uvoz** — CSV sidrenih podataka (`object_id, sifra, sidrena_cijena, sidreni_datum, sidrena_grupa`). Prazna `sidrena_cijena` = preskoči redak (ništa se ne mijenja). Za namjerno brisanje sidrenog podatka u stupac cijene upišite `OBRISANO` (uz potvrdu prepisivanja ako je podatak već potvrđen).
- **Arhiva** — objavljene verzije + ručno čišćenje (retention ≥30 dana se poštuje).
- **Postavke** — opće, lokacije (kanali), deinstalacija, održavanje A–D, testni način.
- **Dijagnostika** — verzije, scheduler, heartbeat, poslovi, server cron naredba, kopiranje izvještaja.

## Unos sidrenih cijena ( dopušteni načini )

1. **CSV uvoz** — masovno; match: object ID → potvrđena jedinstvena šifra (nikad naziv).
2. **Metabox** — ručni unos/izmjena uz potvrdu; sve se auditira.
3. **Povijesno polje** — ako postoji meta/ACF s cijenom i datumom s referentnog datuma (mapira se u koraku 6).
4. **First listing** — automatski zapis kod prijelaza draft→publish dok plugin aktivno prati.

Ako nijedan dokaz ne postoji: `MISSING_ANCHOR_PRICE` — plugin **ne izmišlja** vrijednost.

## Održavanje (Postavke → Održavanje)

| Radnja | Briše sidrene podatke? | Briše audit? |
|---|---|---|
| A) Ponovno analiziraj izvore | NE | NE |
| B) Ponovno izgradi indeks | NE | NE |
| C) Ponovno validiraj katalog | NE | NE |
| D) Potpuni reset (potvrda "RESET") | DA | DA |
| E) Obriši SVE i deinstaliraj (potvrda "OBRIŠI SVE") — tablice, opcije, datoteke, deaktivacija | DA | DA |

## Testni način

Postavke → Testni način: 5 stavki kroz cijeli pipeline (mapiranje, normalizacija, grupe, validacija, frontend HTML, CSV pregled). **Ne** piše u indeks, queue, feed niti mijenja stanje postavljanja.

## Preporuke za produkciju

- Pravi **server cron** (naredba u Dijagnostici) umjesto posjetiteljskog WP-Crona.
- Prije prve javne objave riješite **BLOKIRANO** stavke (nedostaje naziv/cijena).
- Retention arhive držite ≥30 (default 40).
