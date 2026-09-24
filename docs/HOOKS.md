# Javni hookovi i filteri (Adapter API)

Svi hookovi su prefiksirani `cptsc_`. Namjena: integracije i prilagodbe bez forkanja plugina.

## Filteri

| Hook | Signatura | Opis |
|---|---|---|
| `cptsc_resolve_field` | `( mixed $value, array $spec, int $post_id )` | Zamjena/izrada vrijednosti bilo kojeg mapiranog polja (radi i za `source = adapter`). |
| `cptsc_resolve_adapter` | `( mixed $value, array $spec, int $post_id )` | Specifičan za `source=adapter` specs. |
| `cptsc_normalized_values` | `( array $values, SourceProfile $profile, int $post_id )` | Transformacija normaliziranih vrijednosti prije pohrane. |
| `cptsc_row_hash_input` | `( array $input, array $row )` | Ulaz u deterministički row hash. |
| `cptsc_validation_issue_keys` | `( string[] $keys, array $row, SourceProfile $profile )` | Dodavanje/uklanjanje issue ključeva. |
| `cptsc_validation_result` | `( array $result, array $row )` | Finalni `{level, mask}`. |
| `cptsc_anchor_groups` | `( array $groups )` | Dodatne sidrene grupe (moraju imati `reference_date`). |
| `cptsc_anchor_label` | `( string $label, array $row )` | Frontend labela (default: „Cijena na 10.9.2026.:"). |
| `cptsc_renderer_output` | `( string $html, array $row, string $context )` | Zamjena kompletnog frontend HTML-a (custom renderer). |
| `cptsc_feed_row` | `( array $row, array $item, array $channel )` | Redak CSV-a prije zapisivanja. |
| `cptsc_feed_header` | `( string[] $header, string $item_type )` | Zaglavlje CSV-a. |
| `cptsc_canonical_post_id` | `( int $canonical, int $post_id )` | Vlastita logika prijevoda/canonical identiteta. |
| `cptsc_relevant_meta_keys` | `( string[] $keys )` | Dodatni relevantni meta ključevi za change detection. |
| `cptsc_batch_size` | `( int $n )` | Veličina batcha (queue/reindex). |
| `cptsc_feed_batch_size` | `( int $n )` | Batch pri generiranju CSV-a. |
| `cptsc_import_batch` | `( int $n )` | Batch CSV uvoza. |
| `cptsc_time_budget` | `( int $seconds )` | Vremenski budget radnika (default 10 s). |
| `cptsc_discovery_sample` | `( int $n )` | Veličina discovery uzorka (default 100). |
| `cptsc_job_types` | `( string[] $types )` | Proširenje tipova poslova. |
| `cptsc_row_hash_input` | vidi gore | — |

## Akcije

| Hook | Signatura | Opis |
|---|---|---|
| `cptsc_booted` | `( Plugin $plugin )` | Nakon potpunog boot-a plugina. |
| `cptsc_activated` | `()` | Nakon aktivacije. |
| `cptsc_deactivated` | `()` | Nakon deaktivacije. |
| `cptsc_activated_production` | `()` | Nakon završetka wizarda (ACTIVE). |
| `cptsc_item_indexed` | `( int $post_id, array $row, bool $changed )` | Nakon indeksiranja stavke. |
| `cptsc_anchor_set` | vidi audit | Pratite `cptsc_audit_logged`. |
| `cptsc_audit_logged` | `( int $object_id, string $event, string $old, string $new, string $source )` | Svaki compliance audit zapis. |
| `cptsc_csv_import_applied` | `( int $object_id, array $row )` | Primijenjen CSV import. |
| `cptsc_feed_published` | `( int $channel_id, array $version )` | Uspješna objava cjenika. |
| `cptsc_archive_cleaned` | `( int $removed )` | Nakon čišćenja arhive. |
| `cptsc_job_finished` | `( int $job_id, string $type, array $job )` | Posao završen. |
| `cptsc_job_failed` | `( int $job_id, string $error )` | Posao pao. |
| `cptsc_migrated` | `( string $from, string $to )` | Nakon migracije baze. |
| `cptsc_full_reset` | `()` | Nakon potpunog resetiranja. |
| `cptsc_wpml_ready` / `cptsc_polylang_ready` | `()` | Integracije aktivne. |

## Registrar resolverya (PHP)

```php
// Registrirajte vlastiti resolver za custom izvor (npr. vanjska tablica):
add_action( 'cptsc_booted', function () {
    \CPTSC\Mapping\Resolver::register(
        'mydb',
        function ( array $spec, $post_id, $post ) {
            // $spec['key'] sadrži ključ iz source profila.
            return my_custom_lookup( $spec['key'], $post_id );
        }
    );
} );
```

Zatim u mapiranju koristite `source = mydb` ili generički:

```php
add_filter( 'cptsc_resolve_adapter', function ( $value, $spec, $post_id ) {
    if ( isset( $spec['resolver'] ) && 'my_lookup' === $spec['resolver'] ) {
        return my_lookup( $post_id );
    }
    return $value;
}, 10, 3 );
```

## Hrvatski format datuma

`\CPTSC\Dates::format_hr_date( '2026-09-10' )` → `10.9.2026.` (bez vodećih nula).
Interni/CSV format: `Y-m-d`.
