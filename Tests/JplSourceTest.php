<?php

namespace Omnilab\Jpl\Tests;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Exception\RateLimitedException;
use Omnilab\Exception\UnavailableException;
use Omnilab\Jpl\JplSourceFactory;
use Omnilab\Model\Domain;
use Omnilab\Model\Identifier;
use Omnilab\Model\Point;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\Query;
use Omnilab\Source\SeriesQuery;
use Omnilab\Source\SourceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from ssd-api.jpl.nasa.gov on 2026-10-15 (sbdb.api 1.3,
 * sbdb_query.api 1.0): 433 Eros, 101955 Bennu (81 close approaches, 157
 * virtual impactors) and 1P/Halley with their physical parameters,
 * discovery, close approaches and Sentry data; "Halley" (two bodies, code
 * 300); an unknown name ("not found", code 200); the search for "eros"
 * (offsets 0 and 3, three a page) and for an unknown text.
 */
final class JplSourceTest extends TestCase
{
    /** @var list<array<string, string>> the queries asked */
    private array $calls = [];

    private function source(?MockResponse $response = null, array $options = []): SourceInterface
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($response): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $path = (string) parse_url($url, \PHP_URL_PATH);
            $this->calls[] = ['path' => $path] + $query;
            if ($response) {
                return $response;
            }
            $fixture = match (true) {
                '/sbdb.api' === $path => match ($query['sstr']) {
                    '433', 'Eros' => 'sbdb-433.json',
                    '101955', 'Bennu' => 'sbdb-101955.json',
                    '1P' => 'sbdb-1P.json',
                    'Halley' => 'sbdb-halley.json',
                    default => 'sbdb-notfound.json',
                },
                str_contains($query['sb-cdata'], '[Ee][Rr][Oo][Ss]') => 'query-eros-'.($query['limit-from'] ?? 0).'.json',
                default => 'query-notfound.json',
            };

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture));
        });

        return (new JplSourceFactory($http))->create(['throttle' => 0, 'now' => '2026-10-15'] + $options);
    }

    public function testErosInOneCall(): void
    {
        $eros = $this->source()->entity('433');

        self::assertSame('jpl.sbdb:433', $eros->id->key());
        self::assertSame('433 Eros', $eros->label);
        self::assertSame(Domain::ASTRONOMY, $eros->domain);
        self::assertSame('asteroid', $eros->kind);
        self::assertSame(['433 Eros (A898 PA)', '1956 PC', 'A898 PA'], $eros->synonyms);
        self::assertTrue($eros->value('neo'));
        self::assertFalse($eros->value('pha'));
        self::assertSame('Amor', $eros->value('orbit_class'));
        self::assertSame('https://ssd.jpl.nasa.gov/tools/sbdb_lookup.html#/?sstr=433', $eros->url);
        self::assertSame(['jpl.sbdb:433', 'jpl.sbdb:20000433'], $eros->identifiers()->keys(), 'its SPK-ID too, as Wikidata gives it');
        self::assertCount(1, $this->calls);
        self::assertSame(['path' => '/sbdb.api', 'sstr' => '433', 'phys-par' => '1', 'full-prec' => '1', 'alt-des' => '1', 'discovery' => '1', 'cd-epoch' => '1', 'cd-tp' => '1', 'ca-data' => '1', 'ca-body' => 'Earth', 'vi-data' => '1'], $this->calls[0]);
    }

    public function testItsOrbit(): void
    {
        $eros = $this->source()->entity('Eros');

        self::assertSame('2026-Jun-09.0', $eros->value('epoch'));
        self::assertSame(2461200.5, $eros->value('epoch_jd'));
        self::assertEquals(new Quantity(0.2228779627700761, null, 9.3902e-09), $eros->value('eccentricity'));
        self::assertEquals(new Quantity(1.458243716760167, 'au', 1.5185e-10), $eros->value('semi_major_axis'));
        self::assertSame('deg', $eros->property('inclination')->unit());
        self::assertSame(10.82854410314273, $eros->property('inclination')->number());
        self::assertSame('643.1963890928 ± 0.0000001005 d', (string) $eros->property('orbital_period'));
        self::assertSame('2026-Feb-17.31349404', $eros->value('perihelion_time'));
        self::assertEquals(new Quantity(0.148798, 'au'), $eros->value('moid'));
        self::assertSame(4.582, $eros->value('tisserand_jupiter'));
        self::assertSame(9130, $eros->value('observations'));
    }

    public function testItsPhysicalParametersAndDiscovery(): void
    {
        $eros = $this->source()->entity('433');

        self::assertEquals(new Quantity(10.4, 'mag'), $eros->value('absolute_magnitude'));
        self::assertEquals(new Quantity(16.84, 'km', 0.06), $eros->value('diameter'));
        self::assertEquals(new Quantity(5.27, 'h'), $eros->value('rotation_period'));
        self::assertEquals(new Quantity(0.25, null, 0.06), $eros->value('albedo'));
        self::assertEquals(new Quantity(2.67, 'g/cm³', 0.03), $eros->value('density'));
        self::assertSame('34.4x11.2x11.2 km', $eros->value('extent'));
        self::assertSame('S', $eros->value('spectral_type_tholen'));
        self::assertSame(['1898-Aug-13', 'Witt, G.', 'Berlin'], [$eros->value('discovery_date'), $eros->value('discovered_by'), $eros->value('discovery_site')]);
    }

    public function testItsCloseApproachesToTheEarth(): void
    {
        $eros = $this->source()->entity('433');

        self::assertSame(34, $eros->value('earth_approaches'));
        self::assertSame('2137-Jan-25 14:12', $eros->value('closest_earth_approach'));
        self::assertSame('2042-Apr-06 19:02', $eros->value('next_earth_approach'), 'the first after the clock');
        self::assertSame('au', $eros->property('next_earth_approach_distance')->unit());
        self::assertSame('km/s', $eros->property('next_earth_approach_velocity')->unit());
        self::assertNull($eros->property('sentry_impacts'), 'Sentry does not follow Eros');
    }

    public function testBennuIsHazardousAndSentryFollowsIt(): void
    {
        $bennu = $this->source()->entity(Identifier::of(Scheme::JPL_SBDB, '101955'));

        self::assertSame('101955 Bennu', $bennu->label);
        self::assertTrue($bennu->value('pha'));
        self::assertStringStartsWith('Bennu was an Egyptian mythological figure', $bennu->description, 'the IAU citation of its name');
        self::assertSame(157, $bennu->value('sentry_impacts'));
        self::assertEqualsWithDelta(5.717e-4, $bennu->value('sentry_impact_probability'), 1e-10, 'as sentry.api sums it');
        self::assertSame(-1.4, $bennu->value('sentry_palermo_cumulative'), 'as sentry.api gives it');
        self::assertSame(-1.58, $bennu->value('sentry_palermo_max'));
        self::assertNull($bennu->property('sentry_torino_max'), 'no Torino value among its impactors');
        self::assertSame('2135-Sep-25 07:49', $bennu->value('closest_earth_approach'));
        self::assertSame('2030-Jun-21 20:22', $bennu->value('next_earth_approach'));
        self::assertNotNull($bennu->property('thermal_inertia'));
        self::assertNotNull($bennu->property('rotation_acceleration'));
        self::assertNull($bennu->property('i'), 'thermal inertia ("I") is not the inclination');
    }

    public function testAComet(): void
    {
        $halley = $this->source()->entity('1P');

        self::assertSame('1P/Halley', $halley->label);
        self::assertSame('comet', $halley->kind);
        self::assertSame('Halley-type Comet*', $halley->value('orbit_class'));
        self::assertEquals(new Quantity(5.5, 'mag'), $halley->value('comet_total_magnitude'));
        self::assertEquals(new Quantity(27728.04608790421, 'd', 2.3103e-05), $halley->value('orbital_period'));
        self::assertContains('1P/1758 Y1', $halley->synonyms);
        self::assertCount(20, $halley->synonyms, 'cut to the option');
        self::assertCount(62, $this->source(null, ['synonyms' => 100])->entity('1P')->synonyms);
    }

    public function testSeveralOrNoneIsNull(): void
    {
        self::assertNull($this->source()->entity('Halley'), '1P/Halley and the asteroid 2688 Halley: no guess');
        self::assertNull($this->source()->entity('nonexistentbodyxyz'));
        self::assertNull($this->source()->entity(''));
    }

    public function testAnIdentifierOfAnotherBaseIsRefusedBeforeAnyCall(): void
    {
        foreach ([Identifier::wikidata('Q16194'), 'Q16194', Identifier::of(Scheme::SIMBAD, 'M 31'), 'simbad:M 31', Identifier::cid(2519)] as $id) {
            try {
                $this->source()->entity($id);
                self::fail('not read: '.$id);
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->calls);
    }

    public function testSearchPageByPage(): void
    {
        $source = $this->source();
        $page = $source->search(new Query('eros', limit: 3));

        self::assertSame(['433 Eros', '947 Monterosa', '1943 Anteros'], array_map(static fn ($e) => $e->label, $page->items));
        self::assertSame(13, $page->total);
        self::assertSame('3', $page->next);
        self::assertSame(['433 Eros (A898 PA)'], $page->items[0]->synonyms);
        self::assertTrue($page->items[0]->value('neo'));
        self::assertEquals(new Quantity(16.84, 'km'), $page->items[0]->value('diameter'));
        self::assertSame('{"OR":["name|RE|[Ee][Rr][Oo][Ss]","pdes|EQ|eros"]}', $this->calls[0]['sb-cdata']);
        self::assertSame(['3', null], [$this->calls[0]['limit'], $this->calls[0]['limit-from'] ?? null]);

        $second = $source->search(new Query('eros', limit: 3, cursor: $page->next));
        self::assertSame(['4643 Cisneros', '5185 Alerossi', '6435 Daveross'], array_map(static fn ($e) => $e->label, $second->items));
        self::assertSame('3', $this->calls[1]['limit-from']);

        self::assertSame([], $source->search(new Query('eros', Domain::CHEMISTRY))->items, 'nothing of another domain');
        self::assertCount(2, $this->calls, 'and no call for it');
        $none = $source->search(new Query('nonexistentbodyxyz'));
        self::assertSame([0, true], [$none->total, $none->isLast()]);
    }

    public function testErrorsMapTheSameWayAsEverySource(): void
    {
        try {
            $this->source(new MockResponse('{"code":"400","message":"one of sstr, spk, or des must be specified"}', ['http_code' => 400]))->entity('433');
            self::fail('a 400 is a ProviderException');
        } catch (ProviderException $e) {
            self::assertStringContainsString('one of sstr, spk, or des must be specified', $e->getMessage());
        }
        try {
            $this->source(new MockResponse('', ['http_code' => 503]))->entity('433');
            self::fail('a 503 is an UnavailableException');
        } catch (UnavailableException $e) {
            self::assertSame(503, $e->status);
        }
        $this->expectException(RateLimitedException::class);
        $this->source(new MockResponse('', ['http_code' => 429]))->entity('433');
    }

    public function testWhatItCanDo(): void
    {
        self::assertSame([Capability::ENTITY, Capability::SEARCH], $this->source()->capabilities());
        $this->expectException(NotSupportedException::class);
        $this->source()->series(new SeriesQuery(['temperature_2m'], new Point(48.57, 7.75)));
    }
}
