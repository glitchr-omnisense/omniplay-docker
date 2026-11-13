# omniplay/docker

[omnisense/omniplay](https://github.com/glitchr-omnisense/omniplay)'s Docker hosting, under any PHP
host: a game server is a container of a Docker Engine, created, started and stopped through the
engine's HTTP API (`/var/run/docker.sock`, or TCP with or without TLS).

- **start**: `POST /containers/create` from the service's template (image, command, environment,
  the port the server listens on, published on a free host port or a fixed one, labels, memory,
  CPUs), `POST /containers/{id}/start`, `GET /containers/{id}/json` for the host port Docker
  published; the server returned Starting at `public_host:port` (or the template's `address`).
  A container that does not start is removed.
- **stop**: `POST /containers/{id}/stop`, then `DELETE` unless Docker removes it itself
  (`auto_remove`, the default).
- **running**: `GET /containers/json` filtered on omniplay's labels, the servers rebuilt from them.

```php
$docker = (new DockerFactory())->create([
    'host' => 'unix:///var/run/docker.sock',
    'public_host' => 'play.example.org',
    'heartbeat_url' => 'https://www.example.org/omniplay/heartbeat',
    'services' => ['game' => ['image' => 'example/game-server:1.4', 'port' => '7777/udp', 'command' => ['./server', '-batchmode'], 'env' => ['MAP' => 'harbour']]],
]);
$server = $docker->start(new Service('game', secret: 'GAME_TICKET_SECRET', engine: 'unity-mirror'), $secret);
```

The server's environment: its ticket secret (`OMNIPLAY_TICKET_SECRET`, or `secret_env`),
`OMNIPLAY_SERVER_ID` and `OMNIPLAY_ROOM` (its id, the room its tickets name), `OMNIPLAY_SERVICE`,
`OMNIPLAY_CAPACITY`, `OMNIPLAY_PORT`, `OMNIPLAY_ENGINE` (when the service declares one),
`OMNIPLAY_HEARTBEAT_URL` (when configured), then the template's `env` (which cannot replace
omniplay's). The secret is in the container only: not in the `Server` returned, not in its labels.
Whoever can read the engine's API reads it (`docker inspect`): the socket is root's.

Options: `services` (required; see [docs/index.md](docs/index.md)), `host`, `api_version`
(`v1.44`: Docker Engine 25 or later), `public_host`, `region`, `secret_env`, `name_prefix`,
`auto_remove`, `stop_timeout`, `tls`, `heartbeat_url`, `user_agent`. The image must be on the
engine already: an image it does not have is a `ProviderException` saying to pull it.

## Install

```sh
composer require omniplay/docker
```

It requires `omnisense/omniplay` `^1.0`, installed with it. A real call needs: no account: a Docker
Engine's socket (a unix socket, or TCP with TLS) on the host the game servers run on. With no
account, its tests run on recorded answers (a `MockHttpClient`), and
[omnisense/omniplay](https://github.com/glitchr-omnisense/omniplay#install-and-a-first-call)'s
README has a first call that runs without one.

## Verified, and not

- Verified (omnisense/omniplay's harness, PHP 8.4, 2026-11-08): create, start, inspect, a
  container removed when it does not start, stop and remove, running, TCP and TLS addresses, the
  factory's refusals, seating through the core starting a container - on answers written after
  the Engine API's reference (v1.44); `BareTest`; PHPStan max.
- Verified live (Docker Desktop, engine 29.8.2, the socket mounted in the harness): a `php:8.4-cli`
  container started, listed by `running()`, its environment holding the secret and its room,
  stopped and removed by Docker.
- Not verified: an engine over TCP or TLS; a game server's image.

## Responsibility

An independent client of the Docker Engine API, affiliated with neither Docker, Inc. nor any
engine's vendor. The MIT licence covers this code only. Whoever runs it is responsible for the
containers it starts and the secrets it hands them. The authors publish it free of charge and
accept no liability for its use.

## Usage policy

The package declares its usage policy as data, in [policy.json](policy.json) (omnisense/contracts' schema). The site's own Docker Engine: no rate limit, everything allowed (Docker Engine is under Apache-2.0). Docker Desktop's subscription terms, for businesses over 250 employees or US$10 million in revenue, are in the notes. With nothing to count, omniplay's registry gives the provider itself; a site's own limit (`'policy' => ['rateLimits' => [['max' => 10, 'window' => 'minute', 'operations' => ['start']]]]`) puts it behind its guard.

## Licence

MIT.
