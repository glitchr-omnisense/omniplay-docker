---
title: omniplay/docker
order: 10
---

# omniplay/docker

Game servers as containers of a Docker Engine. The provider implements omnisense/omniplay's
`HostingInterface`: `start()`, `stop()`, `running()`.

## The engine

| `host` | Reached as |
|---|---|
| `unix:///var/run/docker.sock` (default) | `http://docker/<api_version>/...` with the HTTP client's `bindto` set to the socket: Symfony's `CurlHttpClient` only (ext-curl); the native client refuses unix sockets |
| `tcp://10.0.0.5:2375` | `http://10.0.0.5:2375/...`; with `tls` options, `https://` |
| `https://10.0.0.5:2376` | as written, with `tls`: `cafile`, `capath`, `local_cert`, `local_pk`, `passphrase`, `verify_peer`, `verify_host` |

`api_version` is `v1.44` (Docker Engine 25 or later; engine 29 accepts no older one); an empty
string calls the engine's own.

## A service's template

`services` maps a `Service`'s name to its template:

| Key | Default | |
|---|---|---|
| `image` | required | on the engine already |
| `port` | `7777/udp` | the port the server listens on in its container: `7777/udp`, `7777/tcp`, `7777` (tcp) |
| `command` | the image's | a list, or a string |
| `env` | `[]` | the server's own variables, set after omniplay's and never replacing them |
| `address` | `{host}:{port}` | what players are given: `{host}` is `public_host`, `{port}` the published host port (`wss://{host}:{port}/play`) |
| `network` | Docker's default | `HostConfig.NetworkMode` |
| `labels` | `[]` | besides omniplay's |
| `host_port` | a free one | a fixed host port: one server at a time |
| `memory`, `cpus` | none | bytes; CPUs (0.5 is `NanoCpus` 500000000) |

## The other options

| Option | Default | |
|---|---|---|
| `public_host` | `127.0.0.1` | the host players reach the published ports on |
| `region` | `local` | the one region this engine runs servers in |
| `secret_env` | `OMNIPLAY_TICKET_SECRET` | the variable the ticket secret is handed in |
| `name_prefix` | `omniplay-` | a container's name is the prefix, the service, a dash and 8 hex digits: the server's id |
| `auto_remove` | true | Docker removes a stopped container; false: `stop()` removes it |
| `stop_timeout` | 10 | seconds Docker waits before killing |
| `heartbeat_url` | none | handed to each server as `OMNIPLAY_HEARTBEAT_URL` |
| `user_agent` | `omniplay/1.0 (+...)` | |

## The labels

`omniplay.server`, `omniplay.service`, `omniplay.capacity`, `omniplay.region`, `omniplay.tags`
(comma-separated), `omniplay.engine`, `omniplay.port`: `running()` reads them back, a running
container as Healthy, another as Starting, its players unknown (0) until its heartbeat.

## Errors

- `NotSupportedException`: a service without a template, a region other than this engine's.
- `ProviderException`: `Docker has no image "example/game-server:1.4" for the "game" service: pull
  it first (docker pull example/game-server:1.4).`; any error the engine answers (`Docker answered
  500 for POST /v1.44/containers/<id>/start. {"message": "...port is already allocated"}`), the
  container then removed; `Docker published no host port for 7777/udp of the container <id>.`
- `UnreachableException`: the socket or the host could not be reached.
- `InvalidConfigException`: no `services`, a template without its image, a port that is not one, a
  `host` that is not unix, tcp, http or https.

## Tests

`Tests/DockerHostingTest.php` on answers written after the Engine API's reference, no engine;
`Tests/LiveTest.php` (group `live`) against a real engine:

```sh
cd ../core/docker   # omnisense/omniplay's harness, OMNIPLAY_PLUGINS=../.. in its .env
docker compose run --rm omniplay test
docker compose run --rm -v /var/run/docker.sock:/var/run/docker.sock omniplay live
```
