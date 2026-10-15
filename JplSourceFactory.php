<?php

namespace Omnilab\Jpl;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * JPL's Small-Body Database: asteroids and comets, their orbits, physical
 * parameters, close approaches to the Earth and impact risk - no key
 * needed.
 *
 *   options:
 *     mailto: 'support@glitchr.io'     # in the User-Agent: JPL's fair use policy asks an application-specific one with a contact
 *     synonyms: 20                     # how many alternate designations to keep (0: none)
 *     throttle: 0.2                    # seconds between two calls: JPL asks one request at a time
 *     base_uri: 'https://ssd-api.jpl.nasa.gov/'
 */
final class JplSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'jpl',
            'omnilab.required_options' => [],
            'synonyms' => 20,
            'now' => null,
            'throttle' => 0.2,
            'base_uri' => JplSource::BASE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        $now = $c->get('now');

        return new JplSource(
            $this->http ?? HttpClient::create(),
            (int) $c->get('synonyms'),
            null === $now ? null : ($now instanceof \DateTimeImmutable ? $now : new \DateTimeImmutable((string) $now, new \DateTimeZone('UTC'))),
            (string) $c->get('base_uri', JplSource::BASE_URI),
            ['User-Agent' => self::userAgent($c)],
            (float) $c->get('throttle'),
        );
    }
}
