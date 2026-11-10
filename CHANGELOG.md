# Changelog

## Unreleased (1.x)

### Added
- 2026-11-10: the usage policy declared as data, in `policy.json` (omnisense/contracts' schema, named in `composer.json`'s `extra.omnisense.policy`). The provider implements `DeclaresUsagePolicyInterface` and gives the policy to its Capabilities. The factory hands the provider's Backoff to its JsonClient, so that a 429 and its Retry-After hold every call. `Tests/PolicyTest.php`.
- `omniplay/docker`: omnisense/omniplay's `HostingInterface` over the Docker Engine API (v1.44),
  on a unix socket (Symfony's CurlHttpClient, `bindto`) or TCP with or without TLS.
  `DockerFactory` (factory `docker`), `DockerHosting`, `Template` (a service's image, port,
  command, environment, address, network, labels, host port, memory, CPUs). The server's ticket
  secret, id, room, service, capacity, port, engine and heartbeat route in its environment;
  omniplay's labels on the container, from which `running()` rebuilds the servers.
