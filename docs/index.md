# omnilab/jpl

NASA/JPL's Small-Body Database, through the SSD/CNEOS API service (`https://ssd-api.jpl.nasa.gov/`:
`sbdb.api`, `sbdb_query.api`), for [omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab):
asteroids and comets by number, designation, name or SPK-ID - orbit, physical parameters, NEO and
PHA flags, close approaches to the Earth, Sentry's impact risk - as entities; a search. No key.

## Installation

```sh
composer require omnilab/jpl
```

```php
use Omnilab\Jpl\JplSourceFactory;

$jpl = (new JplSourceFactory($httpClient))->create([
    'mailto' => 'support@glitchr.io',  // recommended: JPL asks a User-Agent with a contact
    'synonyms' => 20,                  // optional
    'throttle' => 0.2,                 // optional
]);
```

With the Symfony bundle, the factory is registered as `jpl` when the bundle knows it; otherwise
register `JplSourceFactory` as a service (it is autoconfigured as a `SourceFactoryInterface`).

## Credentials

None: the SSD/CNEOS API service takes no key. JPL's fair use policy asks for an
application-specific User-Agent with a contact (give `mailto`), one request at a time, results
cached; it does not let a web page call it from the browser (CORS): call it from the server.

## Options

The factory's options, with the defaults it applies (`JplSourceFactory`):

| Option | Type | Default | Required | Effect |
|---|---|---|---|---|
| `synonyms` | `int` | `20` | no | how many alternate designations to keep; 0: none |
| `now` | `?string` | null | no | the moment the next close approach is reckoned from (a `DateTimeImmutable` or a date string, UTC), for tests; null: now |
| `mailto` | `?string` | null | no | a contact address, in the User-Agent: JPL's fair use asks an application-specific one |
| `user_agent` | `?string` | null | no | the whole User-Agent, in place of the one made from `mailto` |
| `throttle` | `float` | `0.2` | no | seconds left between two calls: JPL asks one request at a time |
| `base_uri` | `string` | `https://ssd-api.jpl.nasa.gov/` | no | the API the source calls |

## Capabilities

`capabilities()` answers `entity`, `search`; an operation it does not answer throws `NotSupportedException` before any call.

| Capability | Supported | |
|---|---|---|
| `entity` | yes | a number (`433`), a designation (`2004 MN4`, `1P`, `C/2020 F3`), a name, an SPK-ID, `jpl.sbdb:`, an SBDB Lookup page: one call with every section; a name two bodies share is null; another scheme refused before any call |
| `search` | yes | the names that hold the text, whatever its case, or the designation that is it, 100 a page at most, paged by offset, with a `total`; a domain other than astronomy answers an empty page |
| `series` | no | close approaches and Sentry are not series (no place on the Earth): read into the body's properties |
| `products` | no |  |
| `occurrences` | no |  |
| `datasets` | no |  |
| `jobs` | no |  |
| `open` | no |  |
| `structure` | no |  |
| `cross_references` | no | its SPK-ID as a second `jpl.sbdb` identifier; the MPC names the body by the same designation |
| `forecast` | no |  |
| `historical` | no |  |
| `area` | no |  |
| `station` | no |  |
| `cloud_cover` | no |  |

## Which API

The SSD/CNEOS API service (`https://ssd-api.jpl.nasa.gov/`): `sbdb.api` (version 1.3) for one
body - asked with every section it can add (`phys-par`, `full-prec`, `alt-des`, `discovery`,
`cd-epoch`, `cd-tp`, `ca-data` with `ca-body=Earth`, `vi-data`), so one call gives the orbit, the
physical parameters, the discovery, the close approaches and Sentry's virtual impactors - and
`sbdb_query.api` (1.0) for the search. Without `full-prec` the SBDB rounds every value to three
digits (Eros's eccentricity 0.223): it is always asked.

### Close approaches and Sentry: not a `Series`

`cad.api` lists close approaches (date, distance, velocity of each body passing near a planet in
a period) and `sentry.api` the impact risk of the bodies Sentry follows. Neither is a `Series`:
a series is one variable at a place on the Earth over a time (`Series::$place` is a WGS 84
`Point` or `BBox`), and an approach list is a set of bodies, each at its own date, with no place.
So they are not added as series; the SBDB gives the same data for one body, read into its
properties: the close approaches to the Earth (`ca-data`, 1900-2200: their number, the closest,
the next from now) and Sentry's summary computed from its virtual impactors (`vi-data`: their
number, the cumulative impact probability, the cumulative and the highest Palermo scale, the
highest Torino scale - for Bennu 157, 5.717e-4, -1.40 and -1.58, the figures `sentry.api`'s own
summary gave the same day).

## Calls

| Method | Reads | JPL |
|---|---|---|
| `entity()` | a number (`433`), a designation (`2004 MN4`, `1P`, `C/2020 F3`), a name (`Eros`), an SPK-ID (`20000433`), `jpl.sbdb:433`, an SBDB Lookup page | `sbdb.api?sstr=` with the sections above |
| `search()` | `Query::$text`: the names that hold it, whatever its case, or the designation that is it; a domain other than astronomy answers nothing | `sbdb_query.api?fields=...&sb-cdata={"OR":["name\|RE\|[Ee][Rr][Oo][Ss]","pdes\|EQ\|eros"]}&limit=&limit-from=` |

`sstr` that matches several bodies answers a list (code 300: "Halley" is 1P/Halley and the
asteroid 2688 Halley): `entity()` gives `null` rather than a guess - ask `1P` or `2688`. An
unknown body is answered 200 with "specified object was not found": `null`. An identifier of
another base is refused before any call. The search's regular expression is case-sensitive at
JPL: each letter is asked in both cases; it takes 4 to 7 s, and pages by `limit-from`
(`Page::$next` is the next offset, `total` the SBDB's count), in the SBDB's order (numbered
bodies first, by number).

## What an entity holds

`id` (its primary designation: `jpl.sbdb:433`, `jpl.sbdb:1P`, `jpl.sbdb:C/2020 F3`), `label` (the
SBDB's short name: `433 Eros`, `1P/Halley`), `domain` astronomy, `kind` `asteroid` or `comet`,
`description` (the IAU citation of its name, when it has one: Bennu's), `synonyms` (its full name,
then its alternate designations), `identifiers` (its SPK-ID as a second `jpl.sbdb` identifier,
`20000433`), `url` (its SBDB Lookup page), and these properties, numbers with their unit and
1-sigma uncertainty as the SBDB gives them:

| Key | SBDB | Value |
|---|---|---|
| `neo`, `pha` | object.neo, object.pha | boolean |
| `orbit_class`, `orbit_class_code` | object.orbit_class | text: `Amor`, `AMO` |
| `epoch`, `epoch_jd` | orbit.epoch_cd, orbit.epoch | text (TDB), Julian date |
| `eccentricity`, `semi_major_axis`, `perihelion_distance`, `aphelion_distance` | e, a, q, ad | number, au |
| `inclination`, `ascending_node`, `argument_of_perihelion`, `mean_anomaly` | i, om, w, ma | deg |
| `orbital_period`, `mean_motion` | per, n | d, deg/d |
| `perihelion_time`, `perihelion_time_jd` | tp_cd, tp | text (TDB), Julian date |
| `moid`, `moid_jupiter`, `tisserand_jupiter` | orbit.moid, moid_jup, t_jup | au, au, number |
| `condition_code`, `data_arc`, `observations`, `first_observation`, `last_observation` | the orbit's quality and data | number, d, number, date, date |
| `absolute_magnitude`, `slope_parameter` | H, G | mag, number |
| `diameter`, `extent`, `gm`, `density` | diameter, extent, GM, density | km, text, km³/s², g/cm³ |
| `rotation_period`, `rotation_acceleration`, `pole` | rot_per, rot_acc, pole | h, rad/d², text |
| `albedo`, `thermal_inertia` | albedo, I | number, J/m²/s^0.5/K |
| `color_b_v`, `color_u_b`, `color_i_r` | BV, UB, IR | mag |
| `spectral_type_tholen`, `spectral_type_smass` | spec_T, spec_B | text |
| `comet_total_magnitude`, `comet_nuclear_magnitude` and their slopes, `comet_phase_coefficient` | M1, K1, M2, K2, PC | mag, number |
| `discovery_date`, `discovered_by`, `discovery_site` | discovery | text |
| `earth_approaches`; `closest_earth_approach`, `next_earth_approach` and their `_distance`, `_velocity` | ca_data (Earth) | number; text (TDB), au, km/s |
| `sentry_impacts`, `sentry_impact_probability`, `sentry_palermo_cumulative`, `sentry_palermo_max`, `sentry_torino_max` | vi_data | number |

Another physical parameter the SBDB adds is kept as `physical_` and its name. A search result
holds the flags, the class code, the main elements, the MOID, H, the diameter, the albedo and the
rotation period: `entity()` reads the rest.

## The Collector: Eros from Wikidata

Wikidata's JPL Small-Body Database id (P716) is the SPK-ID, which the SBDB reads as it reads a
designation. Against the real services on 2026-10-15:

```php
$collector->collect('wikidata', 'Q16711', 1, ['jpl']);
```

2 calls in 1 s - `wbgetentities` for Q16711, then `sbdb.api?sstr=20000433` - and `sources` =
wikidata, jpl:

```
diameter          16.84 ± 0.06 km [jpl]
orbital_period    643.1151986547 ± 0.000000104 d [wikidata]  643.1963890928 ± 0.0000001005 d [jpl]
rotation_period   5.27 h [jpl]
albedo            0.25 ± 0.06 [jpl]
absolute_magnitude 10.4 mag [jpl]
neo               yes [jpl]
```

The two periods differ in their sixth digit: Wikidata's comes from an older orbit solution.

## What the SBDB does not give here

The covariance, the non-gravitational parameters (`model_pars`: kept by the SBDB, not read), the
radar astrometry, the satellites, the approaches to other bodies than the Earth, each virtual
impactor's date and energy, the planets and moons (not small bodies), the Horizons ephemerides
and the other SSD/CNEOS APIs (Scout, NHATS, fireballs, `cad.api` and `sentry.api` as lists: see
above) are not read.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\Jpl\JplSourceFactory;
use Omnilab\Source\Query;
use Symfony\Component\HttpClient\HttpClient;

$jpl = (new JplSourceFactory(HttpClient::create()))->create(['mailto' => 'you@example.org', 'synonyms' => 3]);

$bennu = $jpl->entity('Bennu');
echo $bennu->label, ' (', $bennu->id, '): NEO ', $bennu->property('neo'), ', PHA ', $bennu->property('pha'), ', diameter ', $bennu->property('diameter'), "\n";
echo '  closest Earth approach ', $bennu->value('closest_earth_approach'), ' at ', $bennu->property('closest_earth_approach_distance'), "\n";
echo '  Sentry: ', $bennu->value('sentry_impacts'), ' virtual impactors, probability ', $bennu->value('sentry_impact_probability'), ', Palermo max ', $bennu->value('sentry_palermo_max'), "\n";
var_dump($jpl->entity('Halley'));   // two bodies: neither

$page = $jpl->search(new Query('eros', limit: 3));
printf("%d bodies hold \"eros\"; the first %d: %s\n", $page->total, count($page), implode(', ', array_map(static fn ($b) => $b->label, $page->items)));
```

```
101955 Bennu (jpl.sbdb:101955): NEO yes, PHA yes, diameter 0.48444 ± 0.0003 km
  closest Earth approach 2135-Sep-25 07:49 at 0.0013639272 au
  Sentry: 157 virtual impactors, probability 0.0005717, Palermo max -1.58
NULL
13 bodies hold "eros"; the first 3: 433 Eros, 947 Monterosa, 1943 Anteros
```

(as answered on 2026-10-27: three calls, 0.2 s apart; "Halley" names two bodies, the SBDB's code
300: null)

```yaml
# config/packages/omnilab.yaml
omnilab:
    sources:
        jpl: { factory: jpl, options: { mailto: '%env(OMNILAB_MAILTO)%', synonyms: 20 } }
```

## Errors

Every call goes through `HttpSource`: a 404 or a 410 (and a 204) is null - an empty page for a list -; a 429 is a `RateLimitedException` (`retryAfter` from `Retry-After`); a 5xx, a time-out or a host that does not answer an `UnavailableException`; any other 4xx a `ProviderException` with its `status` and what the service said; an answer that is not JSON a `ProviderException`, a web page answered for JSON an `UnavailableException`.

The SBDB's own: "specified object was not found" (code 200) and several matches (code 300, a list)
are null; any other code with a message a `ProviderException` with it. Before any call: an
identifier of another scheme (`NotSupportedException`).

## Verified, and not

Against the real service on 2026-10-15: 433 Eros by its number, its name and its SPK-ID; 101955
Bennu (a PHA followed by Sentry: its summary checked against `sentry.api`); 99942 Apophis by
`2004 MN4`, `99942` and `Apophis` (no longer on Sentry's list); 3200 Phaethon; 1P/Halley and
C/2020 F3 (NEOWISE), comets; "Halley" (code 300, two bodies); an unknown name; the searches for
"eros" (13 bodies, offsets 0 and 3) and an unknown text; `cad.api` (to decide against a series);
the Collector's walk above. The fixtures in `Tests/Fixtures/` are its answers.

Again on 2026-10-27: Bennu (its closest Earth approach in 2135, Sentry's 157 virtual impactors),
"Halley" null, the search for "eros" (13 bodies; the example above).

Not verified: JPL's answer to a client that calls too fast (no 429 met; the source calls one at a
time, 0.2 s apart); a body asked by a planet's satellite designation.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by NASA, JPL or Caltech. The SSD/CNEOS API service is free under JPL's
[fair use policy](https://ssd-api.jpl.nasa.gov/): only the requests a use needs, one at a time,
results cached, an application-specific User-Agent with a contact, as the README's
[Terms of use](https://github.com/glitchr-omnisense/omnilab-jpl#terms-of-use) says. Whoever runs
the package is responsible for complying with them and for any use made of what it returns: see
the README's [Responsibility](https://github.com/glitchr-omnisense/omnilab-jpl#responsibility)
section.
