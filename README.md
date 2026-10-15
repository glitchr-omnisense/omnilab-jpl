# omnilab/jpl

NASA/JPL's Small-Body Database for [glitchr/omnilab](https://github.com/glitchr-studio/omnilab):
an asteroid or a comet by its number, its designation or its name (`433`, `Eros`, `2004 MN4`,
`1P`, `C/2020 F3`); its orbit - the osculating elements at their epoch with their uncertainties,
the period, the MOIDs -, its physical parameters (absolute magnitude, diameter, albedo, rotation
period, density, spectral types), whether it is a near-Earth object and a potentially hazardous
asteroid, its discovery and the IAU citation of its name, its close approaches to the Earth and,
when Sentry follows it, its impact risk; a search by name or designation. No key.

```php
$jpl = (new JplSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
$bennu = $jpl->entity('Bennu');
$bennu->id;                                         // jpl.sbdb:101955
$bennu->value('pha');                               // true
$bennu->property('diameter');                       // 0.48444 ± 0.0003 km
$bennu->property('semi_major_axis');                // 1.1263910259 ± 0.0000000002 au
$bennu->value('next_earth_approach');               // 2030-Jun-21 20:22 (TDB), 0.346 au
$bennu->value('sentry_impact_probability');         // 0.0005717 (157 virtual impactors)
$jpl->search(new Query('eros', limit: 10));         // 433 Eros, 947 Monterosa, 1943 Anteros...
```

```yaml
omnilab:
    sources:
        jpl:
            factory: jpl
            options:
                mailto: 'support@glitchr.io'   # in the User-Agent, as JPL's fair use policy asks
                synonyms: 20                   # how many alternate designations to keep; 0: none
                throttle: 0.2                  # seconds between two calls
```

With Wikidata configured too, the `Collector` follows a small body from one to the other:
Wikidata's JPL Small-Body Database id (P716, the SPK-ID: 20000433 for Eros) leads to the SBDB.

## Terms of use

The SSD/CNEOS API service is JPL's, free and without a key, under its
[fair use policy](https://ssd-api.jpl.nasa.gov/): only the requests a use needs, one at a time,
results cached, errors and rate limits met by backing off, and an application-specific
User-Agent with the product, its version and a contact - set `mailto`. JPL may throttle or block
what loads the service unreasonably, and does not let a web page call it from the browser (CORS):
call it from the server. The source makes one call per entity and per search page, 0.2 s apart.

See [docs/](docs/index.md) for what is read, how it maps, and what the SBDB does not give here.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by NASA JPL (Solar System Dynamics). It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms above, for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or
a citation, a rate limit to respect), and for any use made of what it returns. The authors
publish it free of charge, make no commercial use of it, and accept no liability for how it is
used (see [LICENSE](LICENSE)).

License: MIT.
