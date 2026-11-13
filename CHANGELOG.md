# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable release.

### Release

- Requires `omnisense/contracts: ^1.0`, `omnisense/omniplay: ^1.0`: stable versions, so `composer
  require omniplay/docker` installs in a project at Composer's default minimum-stability.
  `extra.branch-alias` names 1.x (`1.x-dev`); the CI (`.github/workflows/ci.yml`, glitchr-studio/ci's
  php-package.yml) is callable, and `release.yml` runs it on a tag before glitchr-studio/ci's
  php-release.yml.

### Added
- 2026-11-10: the usage policy declared as data, in `policy.json` (omnisense/contracts' schema, named in `composer.json`'s `extra.omnisense.policy`). The provider implements `DeclaresUsagePolicyInterface` and gives the policy to its Capabilities. The factory hands the provider's Backoff to its JsonClient, so that a 429 and its Retry-After hold every call. `Tests/PolicyTest.php`.
- `omniplay/docker`: omnisense/omniplay's `HostingInterface` over the Docker Engine API (v1.44),
  on a unix socket (Symfony's CurlHttpClient, `bindto`) or TCP with or without TLS.
  `DockerFactory` (factory `docker`), `DockerHosting`, `Template` (a service's image, port,
  command, environment, address, network, labels, host port, memory, CPUs). The server's ticket
  secret, id, room, service, capacity, port, engine and heartbeat route in its environment;
  omniplay's labels on the container, from which `running()` rebuilds the servers.
