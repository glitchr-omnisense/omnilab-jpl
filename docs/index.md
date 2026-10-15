# omnilab/jpl

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

## Verified, and not

Against the real service on 2026-10-15: 433 Eros by its number, its name and its SPK-ID; 101955
Bennu (a PHA followed by Sentry: its summary checked against `sentry.api`); 99942 Apophis by
`2004 MN4`, `99942` and `Apophis` (no longer on Sentry's list); 3200 Phaethon; 1P/Halley and
C/2020 F3 (NEOWISE), comets; "Halley" (code 300, two bodies); an unknown name; the searches for
"eros" (13 bodies, offsets 0 and 3) and an unknown text; `cad.api` (to decide against a series);
the Collector's walk above. The fixtures in `Tests/Fixtures/` are its answers.

Not verified: JPL's answer to a client that calls too fast (no 429 met; the source calls one at a
time, 0.2 s apart); a body asked by a planet's satellite designation.
