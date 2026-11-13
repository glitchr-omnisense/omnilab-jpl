# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable release: it installs with Composer's default minimum-stability (stable).

### Added

- Omnilab JPL: an asteroid or a comet by its number, designation or name, from NASA/JPL's Small-Body
  Database API - its orbit (elements at their epoch with their uncertainties, period, MOID),
  physical parameters (absolute magnitude, diameter, albedo, rotation period, density, spectral
  types), NEO and PHA flags, discovery and IAU citation, close approaches to the Earth and Sentry's
  impact risk - and a search by name or designation (SBDB Query API), no key needed.

### Release

- Requires `omnisense/omnilab` `^1.0`, stable versions, where it required `@dev` ones:
  `composer require omnilab/jpl` installs in a project at Composer's default minimum-stability
  (stable). `extra.branch-alias` names the branch (`dev-1.x`: `1.x-dev`).
- CI: `.github/workflows/ci.yml` (glitchr-studio/ci's php-package.yml, pinned: the package
  installed alone, PHPUnit, PHPStan against its baseline, a consumer install) and `release.yml`
  (that CI on the tag, then glitchr-studio/ci's php-release.yml); `phpunit.xml.dist` runs the
  package's own tests.
- README: the install line and a first call.
