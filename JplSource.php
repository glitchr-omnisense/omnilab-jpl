<?php

namespace Omnilab\Jpl;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Model\Domain;
use Omnilab\Model\Entity;
use Omnilab\Model\Identifier;
use Omnilab\Model\Identifiers;
use Omnilab\Model\Property;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\Page;
use Omnilab\Source\Query;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * JPL's Small-Body Database (ssd-api.jpl.nasa.gov): an asteroid or a comet
 * by its designation, its number or its name (433, Eros, 101955 Bennu,
 * 1P, 2004 MN4); its orbit - the elements at their epoch with their
 * uncertainties, the period, the MOIDs -, its physical parameters
 * (absolute magnitude, diameter, albedo, rotation period, density,
 * spectral types), whether it is a near-Earth object and a potentially
 * hazardous one, its discovery and the IAU citation of its name, its close
 * approaches to the Earth and, when Sentry follows it, its impact risk;
 * a search by name or designation (the SBDB Query API).
 *
 * No key. One call per entity (sbdb.api answers everything at once), one
 * per search page. JPL's fair use policy: one request at a time, an
 * application-specific User-Agent with a contact (set "mailto"); the calls
 * are spaced by 0.2 s by default.
 */
final class JplSource extends HttpSource
{
    public const BASE_URI = 'https://ssd-api.jpl.nasa.gov/';

    /** The most objects one search page is asked for. */
    public const MAX_PER_PAGE = 100;

    /** sbdb.api's orbital elements => key, label. */
    private const ELEMENTS = [
        'e' => ['eccentricity', 'Eccentricity'],
        'a' => ['semi_major_axis', 'Semi-major axis'],
        'q' => ['perihelion_distance', 'Perihelion distance'],
        'ad' => ['aphelion_distance', 'Aphelion distance'],
        'i' => ['inclination', 'Inclination (to the ecliptic)'],
        'om' => ['ascending_node', 'Longitude of the ascending node'],
        'w' => ['argument_of_perihelion', 'Argument of perihelion'],
        'ma' => ['mean_anomaly', 'Mean anomaly'],
        'per' => ['orbital_period', 'Sidereal orbital period'],
        'n' => ['mean_motion', 'Mean motion'],
    ];

    /** sbdb.api's physical parameters => key, label, unit when the SBDB gives none (the others are "physical_" and their name, lower-cased, with their title). */
    private const PHYSICAL = [
        'H' => ['absolute_magnitude', 'Absolute magnitude (H)', 'mag'],
        'G' => ['slope_parameter', 'Magnitude slope parameter (G)', null],
        'diameter' => ['diameter', 'Diameter', null],
        'extent' => ['extent', 'Extent (bi- or tri-axial)', null],
        'GM' => ['gm', 'Standard gravitational parameter (GM)', null],
        'density' => ['density', 'Bulk density', null],
        'rot_per' => ['rotation_period', 'Rotation period', null],
        'pole' => ['pole', 'Pole direction (RA/Dec, deg)', null],
        'albedo' => ['albedo', 'Geometric albedo', null],
        'BV' => ['color_b_v', 'Colour index B-V', 'mag'],
        'UB' => ['color_u_b', 'Colour index U-B', 'mag'],
        'IR' => ['color_i_r', 'Colour index I-R', 'mag'],
        'spec_T' => ['spectral_type_tholen', 'Spectral type (Tholen)', null],
        'spec_B' => ['spectral_type_smass', 'Spectral type (SMASSII)', null],
        'M1' => ['comet_total_magnitude', 'Comet total magnitude parameter (M1)', 'mag'],
        'K1' => ['comet_total_magnitude_slope', 'Comet total magnitude slope (K1)', null],
        'M2' => ['comet_nuclear_magnitude', 'Comet nuclear magnitude parameter (M2)', 'mag'],
        'K2' => ['comet_nuclear_magnitude_slope', 'Comet nuclear magnitude slope (K2)', null],
        'PC' => ['comet_phase_coefficient', 'Comet nuclear magnitude phase coefficient (PC)', null],
        'I' => ['thermal_inertia', 'Thermal inertia', null],
        'rot_acc' => ['rotation_acceleration', 'Rotation acceleration (YORP)', null],
    ];

    /** The SBDB Query API's fields a search asks, in this order. */
    private const FIELDS = ['spkid', 'full_name', 'pdes', 'name', 'prefix', 'kind', 'neo', 'pha', 'class', 'H', 'diameter', 'albedo', 'rot_per', 'e', 'a', 'q', 'i', 'per', 'moid'];

    /**
     * @param int                     $synonyms how many alternate designations to keep (0: none)
     * @param \DateTimeImmutable|null $now      the moment "next close approach" is reckoned from (null: now)
     */
    public function __construct(
        HttpClientInterface $http,
        private readonly int $synonyms = 20,
        private readonly ?\DateTimeImmutable $now = null,
        string $baseUri = self::BASE_URI,
        array $headers = [],
        float $throttle = 0.2,
    ) {
        parent::__construct($http, $baseUri, $headers, $throttle);
    }

    public function getName(): string
    {
        return 'jpl';
    }

    public function capabilities(): array
    {
        return [Capability::ENTITY, Capability::SEARCH];
    }

    /**
     * A small body by what the SBDB reads (sstr): a number, a designation, a
     * name. A string that matches several bodies ("Halley": 1P/Halley and
     * the asteroid 2688 Halley) is null rather than a guess.
     */
    public function entity(Identifier|string $id): ?Entity
    {
        if ($id instanceof Identifier) {
            $text = Scheme::JPL_SBDB === $id->scheme ? $id->value : throw NotSupportedException::identifier('jpl', (string) $id);
        } else {
            $parsed = Identifier::parse($id);
            if (null !== $parsed && Scheme::JPL_SBDB !== $parsed->scheme) {
                throw NotSupportedException::identifier('jpl', $id);
            }
            $text = $parsed?->value ?? Identifier::tryOf(Scheme::JPL_SBDB, $id)?->value;
            if (null === $text) {
                return null;
            }
        }
        $data = $this->getJson('sbdb.api', [
            'sstr' => $text, 'phys-par' => 1, 'full-prec' => 1, 'alt-des' => 1, 'discovery' => 1,
            'cd-epoch' => 1, 'cd-tp' => 1, 'ca-data' => 1, 'ca-body' => 'Earth', 'vi-data' => 1,
        ]);
        // Not found ("specified object was not found", code 200) or several (code 300, a list): null.
        if (null === $data || !isset($data['object']['des'])) {
            if (isset($data['code']) && !\in_array((string) $data['code'], ['200', '300'], true)) {
                throw new ProviderException('jpl', (string) ($data['message'] ?? 'code '.$data['code']));
            }

            return null;
        }

        return $this->fromRecord($data);
    }

    /**
     * Bodies whose name holds the text (whatever its case), or whose
     * designation is the text, through the SBDB Query API, in the
     * database's order (numbered first, by number).
     */
    public function search(Query $query): Page
    {
        if (null !== $query->domain && Domain::ASTRONOMY !== $query->domain) {
            return new Page();
        }
        $text = trim((string) preg_replace('~\s+~u', ' ', $query->text));
        // What the SBDB's regular expressions accept: letters (each in both cases), digits, spaces, a few signs.
        $pattern = (string) preg_replace_callback('~[a-z]~i', static fn (array $m) => '['.strtoupper($m[0]).strtolower($m[0]).']', (string) preg_replace('~[^\p{L}\d \'.-]~u', '', $text));
        if ('' === trim($pattern)) {
            return new Page();
        }
        $offset = max(0, (int) $query->cursor);
        $limit = max(1, min(self::MAX_PER_PAGE, $query->limit));
        $data = $this->getJson('sbdb_query.api', [
            'fields' => implode(',', self::FIELDS),
            'sb-cdata' => json_encode(['OR' => ['name|RE|'.$pattern, 'pdes|EQ|'.$text]], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
            'full-prec' => 1,
            'limit' => $limit,
            'limit-from' => $offset > 0 ? $offset : null,
        ]) ?? [];
        $fields = $data['fields'] ?? self::FIELDS;
        $items = [];
        foreach ($data['data'] ?? [] as $values) {
            if (\count($values) === \count($fields)) {
                $items[] = $this->fromRow(array_combine($fields, $values));
            }
        }
        $total = isset($data['count']) ? (int) $data['count'] : null;

        return new Page($items, null !== $total && $offset + $limit < $total ? (string) ($offset + $limit) : null, $total);
    }

    /** @param array<string, mixed> $data what sbdb.api answers for one body */
    private function fromRecord(array $data): Entity
    {
        $object = $data['object'];
        $orbit = $data['orbit'] ?? [];
        $properties = [];
        $add = static function (string $key, string $label, mixed $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'jpl');
            }
        };
        $add('neo', 'Near-Earth object', \is_bool($object['neo'] ?? null) ? $object['neo'] : null);
        $add('pha', 'Potentially hazardous asteroid', \is_bool($object['pha'] ?? null) ? $object['pha'] : null);
        $add('orbit_class', 'Orbit class', self::text($object['orbit_class']['name'] ?? null));
        $add('orbit_class_code', 'Orbit class (code)', self::text($object['orbit_class']['code'] ?? null));

        // The orbit: its elements at the epoch, with their 1-sigma uncertainties.
        $add('epoch', 'Epoch of the osculating elements (TDB)', self::text($orbit['epoch_cd'] ?? null));
        $add('epoch_jd', 'Epoch of the osculating elements (Julian date, TDB)', self::number($orbit['epoch'] ?? null));
        foreach ($orbit['elements'] ?? [] as $element) {
            $name = (string) ($element['name'] ?? '');
            if ('tp_cd' === $name) {
                $add('perihelion_time', 'Time of perihelion passage (TDB)', self::text($element['value'] ?? null));
            } elseif ('tp' === $name) {
                $add('perihelion_time_jd', 'Time of perihelion passage (Julian date, TDB)', self::number($element['value'] ?? null));
            } elseif (isset(self::ELEMENTS[$name])) {
                [$key, $label] = self::ELEMENTS[$name];
                $add($key, $label, self::measure($element['value'] ?? null, $element['units'] ?? null, $element['sigma'] ?? null));
            }
        }
        $add('moid', 'Earth minimum orbit intersection distance (MOID)', self::measure($orbit['moid'] ?? null, 'au'));
        $add('moid_jupiter', 'Jupiter MOID', self::measure($orbit['moid_jup'] ?? null, 'au'));
        $add('tisserand_jupiter', 'Tisserand parameter with respect to Jupiter', self::number($orbit['t_jup'] ?? null));
        $add('condition_code', 'Orbit condition code (0: best, 9: worst)', self::number($orbit['condition_code'] ?? null));
        $add('data_arc', 'Data arc', self::measure($orbit['data_arc'] ?? null, 'd'));
        $add('observations', 'Observations used', self::number($orbit['n_obs_used'] ?? null));
        $add('first_observation', 'First observation used', self::text($orbit['first_obs'] ?? null));
        $add('last_observation', 'Last observation used', self::text($orbit['last_obs'] ?? null));

        foreach ($data['phys_par'] ?? [] as $parameter) {
            $name = (string) ($parameter['name'] ?? '');
            [$key, $label, $unit] = self::PHYSICAL[$name] ?? ['physical_'.strtolower($name), ucfirst((string) ($parameter['title'] ?? $name)), null];
            $value = $parameter['value'] ?? null;
            $unit ??= self::text($parameter['units'] ?? null);
            // A value that is not one number ("34.4x11.2x11.2", a pole "11.37/17.22", a type "S") is text, its unit after it.
            $add($key, $label, is_numeric($value) ? self::measure($value, $unit, $parameter['sigma'] ?? null) : (null === ($text = self::text($value)) ? null : $text.(null === $unit ? '' : ' '.$unit)));
        }

        $discovery = $data['discovery'] ?? [];
        $add('discovery_date', 'Discovered', self::text($discovery['date'] ?? null));
        $add('discovered_by', 'Discovered by', self::text($discovery['who'] ?? null));
        $add('discovery_site', 'Discovery site', self::text($discovery['location'] ?? null));

        array_push($properties, ...$this->approaches($data['ca_data'] ?? []), ...self::sentry($data['vi_data'] ?? []));

        $synonyms = [];
        foreach ($object['des_alt'] ?? [] as $alternate) {
            foreach ((array) $alternate as $designation) {
                if (\is_string($designation) && '' !== trim($designation)) {
                    $synonyms[trim($designation)] = trim($designation);
                }
            }
        }
        if (null !== ($fullname = self::text($object['fullname'] ?? null))) {
            $synonyms = [$fullname => $fullname] + $synonyms;
        }
        $label = self::text($object['shortname'] ?? null) ?? $fullname ?? (string) $object['des'];
        unset($synonyms[$label]);
        // An unnumbered comet's designation with its prefix, as written: C/2020 F3.
        $designation = (string) $object['des'];
        if (\is_string($object['prefix'] ?? null) && preg_match('~^\d{4} ~', $designation)) {
            $designation = $object['prefix'].'/'.$designation;
        }
        $id = Identifier::of(Scheme::JPL_SBDB, $designation);

        return new Entity(
            id: $id,
            label: $label,
            domain: Domain::ASTRONOMY,
            kind: self::kind($object['kind'] ?? null),
            synonyms: \array_slice(array_values($synonyms), 0, $this->synonyms),
            description: self::text($discovery['citation'] ?? null),
            properties: $properties,
            // Its SPK-ID too, which the SBDB reads as well and Wikidata gives (P716: 20000433 for 433 Eros).
            identifiers: new Identifiers([Identifier::tryOf(Scheme::JPL_SBDB, (string) ($object['spkid'] ?? ''))]),
            url: $id->url(),
            source: 'jpl',
        );
    }

    /** @param array<string, mixed> $row a row of the SBDB Query API */
    private function fromRow(array $row): Entity
    {
        $properties = [];
        $add = static function (string $key, string $label, mixed $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'jpl');
            }
        };
        $flag = static fn (mixed $v): ?bool => match ($v) { 'Y' => true, 'N' => false, default => null };
        $add('neo', 'Near-Earth object', $flag($row['neo'] ?? null));
        $add('pha', 'Potentially hazardous asteroid', $flag($row['pha'] ?? null));
        $add('orbit_class_code', 'Orbit class (code)', self::text($row['class'] ?? null));
        $add('eccentricity', 'Eccentricity', self::number($row['e'] ?? null));
        $add('semi_major_axis', 'Semi-major axis', self::measure($row['a'] ?? null, 'au'));
        $add('perihelion_distance', 'Perihelion distance', self::measure($row['q'] ?? null, 'au'));
        $add('inclination', 'Inclination (to the ecliptic)', self::measure($row['i'] ?? null, 'deg'));
        $add('orbital_period', 'Sidereal orbital period', self::measure($row['per'] ?? null, 'd'));
        $add('moid', 'Earth minimum orbit intersection distance (MOID)', self::measure($row['moid'] ?? null, 'au'));
        $add('absolute_magnitude', 'Absolute magnitude (H)', self::measure($row['H'] ?? null, 'mag'));
        $add('diameter', 'Diameter', self::measure($row['diameter'] ?? null, 'km'));
        $add('albedo', 'Geometric albedo', self::number($row['albedo'] ?? null));
        $add('rotation_period', 'Rotation period', self::measure($row['rot_per'] ?? null, 'h'));

        $fullname = self::text($row['full_name'] ?? null);
        $designation = (string) $row['pdes'];
        $name = self::text($row['name'] ?? null);
        // "433 Eros (A898 PA)" is shown "433 Eros", as sbdb.api's shortname; a comet "1P/Halley" as it is.
        $label = null !== $fullname ? (string) preg_replace('~\s*\([^)]*\)$~', '', $fullname) : ($name ?? $designation);
        $id = Identifier::of(Scheme::JPL_SBDB, $designation);

        return new Entity(
            id: $id,
            label: $label,
            domain: Domain::ASTRONOMY,
            kind: self::kind($row['kind'] ?? null),
            synonyms: null !== $fullname && $fullname !== $label ? [$fullname] : [],
            properties: $properties,
            url: $id->url(),
            source: 'jpl',
        );
    }

    /**
     * The close approaches to the Earth the SBDB lists (1900 to 2200): how
     * many, the closest, and the next one from now.
     *
     * @param list<array<string, mixed>> $approaches
     *
     * @return list<Property>
     */
    private function approaches(array $approaches): array
    {
        if (!$approaches) {
            return [];
        }
        $closest = null;
        $next = null;
        $now = ($this->now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i');
        foreach ($approaches as $approach) {
            if (!is_numeric($approach['dist'] ?? null) || !\is_string($approach['cd'] ?? null)) {
                continue;
            }
            if (null === $closest || (float) $approach['dist'] < (float) $closest['dist']) {
                $closest = $approach;
            }
            // "2135-Sep-25 07:49": comparable once the month is a number.
            if (null === $next && self::sortable($approach['cd']) >= $now) {
                $next = $approach;
            }
        }
        $properties = [new Property('earth_approaches', 'Close approaches to the Earth listed (1900-2200)', \count($approaches), 'jpl')];
        foreach (['closest' => $closest, 'next' => $next] as $which => $approach) {
            if (null === $approach) {
                continue;
            }
            $properties[] = new Property($which.'_earth_approach', ucfirst($which).' approach to the Earth (TDB)', (string) $approach['cd'], 'jpl');
            $properties[] = new Property($which.'_earth_approach_distance', ucfirst($which).' approach to the Earth: nominal distance', self::measure($approach['dist'], 'au'), 'jpl');
            if (is_numeric($approach['v_rel'] ?? null)) {
                $properties[] = new Property($which.'_earth_approach_velocity', ucfirst($which).' approach to the Earth: relative velocity', self::measure($approach['v_rel'], 'km/s'), 'jpl');
            }
        }

        return $properties;
    }

    /**
     * The impact risk Sentry computes, from its virtual impactors: their
     * number, the cumulative probability, the cumulative and the highest
     * Palermo scale, the highest Torino scale - as Sentry's summary gives
     * them (checked against sentry.api for Bennu).
     *
     * @param list<array<string, mixed>> $impactors
     *
     * @return list<Property>
     */
    private static function sentry(array $impactors): array
    {
        if (!$impactors) {
            return [];
        }
        $probability = 0.0;
        $palermo = 0.0;
        $palermoMax = null;
        $torino = null;
        foreach ($impactors as $impactor) {
            $probability += (float) ($impactor['ip'] ?? 0);
            if (is_numeric($impactor['ps'] ?? null)) {
                $palermo += 10 ** (float) $impactor['ps'];
                $palermoMax = max($palermoMax ?? -INF, (float) $impactor['ps']);
            }
            if (is_numeric($impactor['ts'] ?? null)) {
                $torino = max($torino ?? 0, (int) $impactor['ts']);
            }
        }
        $properties = [
            new Property('sentry_impacts', 'Sentry: potential impacts (virtual impactors)', \count($impactors), 'jpl'),
            new Property('sentry_impact_probability', 'Sentry: cumulative impact probability', $probability, 'jpl'),
        ];
        if ($palermo > 0) {
            $properties[] = new Property('sentry_palermo_cumulative', 'Sentry: cumulative Palermo scale', round(log10($palermo), 2), 'jpl');
            $properties[] = new Property('sentry_palermo_max', 'Sentry: highest Palermo scale', $palermoMax, 'jpl');
        }
        if (null !== $torino) {
            $properties[] = new Property('sentry_torino_max', 'Sentry: highest Torino scale', $torino, 'jpl');
        }

        return $properties;
    }

    private static function kind(mixed $kind): ?string
    {
        return match (\is_string($kind) ? $kind[0] ?? '' : '') {
            'a' => 'asteroid',
            'c' => 'comet',
            default => null,
        };
    }

    /** "2135-Sep-25 07:49" as "2135-09-25 07:49". */
    private static function sortable(string $date): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-M-d H:i', $date, new \DateTimeZone('UTC'));

        return false === $date ? '' : $date->format('Y-m-d H:i');
    }

    private static function number(mixed $value): int|float|null
    {
        if (!is_numeric($value)) {
            return null;
        }

        return preg_match('~^-?\d+$~', (string) $value) ? (int) $value : (float) $value;
    }

    /** A number with its unit (the SBDB's spelling made typographic) and its 1-sigma uncertainty; a pure number without a unit. */
    private static function measure(mixed $value, ?string $unit, mixed $sigma = null): Quantity|int|float|null
    {
        $number = self::number($value);
        if (null === $number) {
            return null;
        }
        $uncertainty = is_numeric($sigma) ? (float) $sigma : null;
        if (null === $unit || '' === $unit) {
            return null === $uncertainty ? $number : new Quantity($number, null, $uncertainty);
        }

        return new Quantity($number, strtr($unit, ['^3' => '³', '^2' => '²']), $uncertainty);
    }
}
