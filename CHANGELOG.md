# Changelog

## Unreleased (1.x)

### Added
- `omniplay/docker`: omnisense/omniplay's `HostingInterface` over the Docker Engine API (v1.44),
  on a unix socket (Symfony's CurlHttpClient, `bindto`) or TCP with or without TLS.
  `DockerFactory` (factory `docker`), `DockerHosting`, `Template` (a service's image, port,
  command, environment, address, network, labels, host port, memory, CPUs). The server's ticket
  secret, id, room, service, capacity, port, engine and heartbeat route in its environment;
  omniplay's labels on the container, from which `running()` rebuilds the servers.
