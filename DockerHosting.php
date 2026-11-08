<?php

namespace Omniplay\Docker;

use Omniplay\Capabilities;
use Omniplay\Exception\NotSupportedException;
use Omniplay\Exception\OmniplayException;
use Omniplay\Exception\ProviderException;
use Omniplay\HostingInterface;
use Omniplay\Http\Data;
use Omniplay\Http\JsonClient;
use Omniplay\Model\Health;
use Omniplay\Model\Server;
use Omniplay\Model\Service;
use Omniplay\ProviderInterface;

/**
 * Game servers as containers of a Docker Engine, through its HTTP API: a
 * server is created from its service's template (POST /containers/create),
 * started, and inspected for the host port Docker published; it is stopped
 * (and removed, unless Docker removes it itself) by its container's id.
 *
 * The server's environment holds its ticket secret (OMNIPLAY_TICKET_SECRET
 * unless configured otherwise), its id - which is its tickets' room -
 * (OMNIPLAY_SERVER_ID, OMNIPLAY_ROOM), its service, capacity, port,
 * declared engine and the application's heartbeat route
 * (OMNIPLAY_HEARTBEAT_URL, when configured). The secret goes into the container and nowhere else:
 * not into the Server returned, not into its labels. Whoever can read the
 * engine's API (docker inspect) reads it: the engine's socket is root's.
 *
 * Its labels (omniplay.server, .service, .capacity, .region, .tags,
 * .engine, .port) are what running() rebuilds the servers from.
 */
final class DockerHosting implements ProviderInterface, HostingInterface
{
    public const LABEL = 'omniplay.';

    /**
     * @param array<string, Template> $templates by service
     * @param array<string, mixed>    $options   the HTTP client's, on every call (a unix socket's "bindto")
     */
    public function __construct(
        private readonly JsonClient $json,
        private readonly string $base,
        private readonly array $templates,
        private readonly string $publicHost = '127.0.0.1',
        private readonly string $region = 'local',
        private readonly string $secretEnv = 'OMNIPLAY_TICKET_SECRET',
        private readonly string $prefix = 'omniplay-',
        private readonly bool $autoRemove = true,
        private readonly int $stopTimeout = 10,
        private readonly array $options = [],
        private readonly string $where = 'a Docker Engine',
        private readonly ?string $heartbeatUrl = null,
    ) {
    }

    public function getName(): string
    {
        return 'docker';
    }

    public function getTitle(): string
    {
        return 'Docker';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(hosting: true, services: array_keys($this->templates), regions: [$this->region], where: $this->where);
    }

    public function start(Service $service, string $secret, ?string $region = null, array $tags = []): Server
    {
        $template = $this->templates[$service->name] ?? null;
        if (null === $template || (null !== $region && $region !== $this->region)) {
            throw NotSupportedException::service('Docker', $service->name, $region);
        }
        $id = $this->prefix.trim((string) preg_replace('~[^a-zA-Z0-9_.-]+~', '-', $service->name), '-').'-'.bin2hex(random_bytes(4));
        $created = $this->call('POST', '/containers/create', ['query' => ['name' => $id], 'json' => $this->container($id, $service, $template, $secret, $tags)])
            ?? throw new ProviderException(\sprintf('Docker has no image "%s" for the "%s" service: pull it first (docker pull %s).', $template->image, $service->name, $template->image));
        $container = $created->string('Id') ?? $id;
        try {
            $this->call('POST', '/containers/'.rawurlencode($container).'/start');
            $port = $template->hostPort ?? $this->published($container, $template->port);
        } catch (OmniplayException $e) {
            $this->call('DELETE', '/containers/'.rawurlencode($container), ['query' => ['force' => 'true']]);

            throw $e;
        }

        return new Server($id, $service->name, $this->address($template, $port), $service->capacity, 0, Health::Starting, null, $this->region, $tags, $service->engine, $this->getName(), $container);
    }

    public function stop(Server $server): void
    {
        $container = rawurlencode($server->instance ?? $server->id);
        // 204 stopped, 304 stopped already, 404 gone: each is fine.
        $this->call('POST', '/containers/'.$container.'/stop', ['query' => ['t' => $this->stopTimeout]]);
        if (!$this->autoRemove) {
            $this->call('DELETE', '/containers/'.$container, ['query' => ['force' => 'true']]);
        }
    }

    public function running(?string $service = null): array
    {
        $labels = [self::LABEL.'server'];
        if (null !== $service) {
            $labels[] = self::LABEL.'service='.$service;
        }
        $servers = [];
        foreach ($this->call('GET', '/containers/json', ['query' => ['filters' => (string) json_encode(['label' => $labels, 'status' => ['created', 'restarting', 'running']])]])?->items() ?? [] as $container) {
            $server = $this->server($container);
            if (null !== $server) {
                $servers[] = $server;
            }
        }

        return $servers;
    }

    /**
     * The body of POST /containers/create.
     *
     * @param list<string> $tags
     *
     * @return array<string, mixed>
     */
    private function container(string $id, Service $service, Template $template, string $secret, array $tags): array
    {
        $env = [
            'OMNIPLAY_SERVER_ID' => $id,
            'OMNIPLAY_ROOM' => $id,
            'OMNIPLAY_SERVICE' => $service->name,
            'OMNIPLAY_CAPACITY' => (string) $service->capacity,
            'OMNIPLAY_PORT' => explode('/', $template->port)[0],
            $this->secretEnv => $secret,
        ] + (null !== $service->engine ? ['OMNIPLAY_ENGINE' => $service->engine] : []) + (null !== $this->heartbeatUrl ? ['OMNIPLAY_HEARTBEAT_URL' => $this->heartbeatUrl] : []) + $template->env;
        $labels = [
            self::LABEL.'server' => $id,
            self::LABEL.'service' => $service->name,
            self::LABEL.'capacity' => (string) $service->capacity,
            self::LABEL.'region' => $this->region,
            self::LABEL.'tags' => implode(',', $tags),
            self::LABEL.'engine' => (string) $service->engine,
            self::LABEL.'port' => $template->port,
        ] + $template->labels;
        $host = array_filter([
            'PortBindings' => [$template->port => [['HostPort' => null !== $template->hostPort ? (string) $template->hostPort : '']]],
            'AutoRemove' => $this->autoRemove,
            'NetworkMode' => $template->network,
            'Memory' => $template->memory,
            'NanoCpus' => null !== $template->cpus ? (int) round($template->cpus * 1e9) : null,
        ], static fn (mixed $value) => null !== $value);

        return array_filter([
            'Image' => $template->image,
            'Cmd' => $template->command,
            'Env' => array_map(static fn (string $name, string $value) => $name.'='.$value, array_keys($env), $env),
            'Labels' => $labels,
            'ExposedPorts' => [$template->port => new \stdClass()],
            'HostConfig' => $host,
        ], static fn (mixed $value) => null !== $value);
    }

    /** The host port Docker published for the server's port. */
    private function published(string $container, string $port): int
    {
        $inspected = $this->call('GET', '/containers/'.rawurlencode($container).'/json');
        foreach ($inspected?->data('NetworkSettings')->data('Ports')->list($port) ?? [] as $binding) {
            if (null !== $hostPort = $binding->int('HostPort')) {
                return $hostPort;
            }
        }

        throw new ProviderException(\sprintf('Docker published no host port for %s of the container %s.', $port, substr($container, 0, 12)));
    }

    /** A server of the list GET /containers/json answers, from its labels; null when they do not make one. */
    private function server(Data $container): ?Server
    {
        $labels = $container->data('Labels');
        $id = $labels->string(self::LABEL.'server');
        $service = $labels->string(self::LABEL.'service');
        $template = null !== $service ? $this->templates[$service] ?? null : null;
        if (null === $id || null === $service) {
            return null;
        }
        $port = $labels->string(self::LABEL.'port') ?? $template?->port;
        $hostPort = $template?->hostPort;
        foreach ($container->list('Ports') as $binding) {
            if (null !== $port && $port === $binding->string('PrivatePort').'/'.$binding->string('Type') && null !== $binding->int('PublicPort')) {
                $hostPort = $binding->int('PublicPort');
            }
        }

        return new Server(
            $id,
            $service,
            null !== $template && null !== $hostPort ? $this->address($template, $hostPort) : $this->publicHost.(null !== $hostPort ? ':'.$hostPort : ''),
            max(1, $labels->int(self::LABEL.'capacity') ?? 1),
            0,
            'running' === $container->string('State') ? Health::Healthy : Health::Starting,
            null,
            $labels->string(self::LABEL.'region'),
            array_values(array_filter(explode(',', (string) $labels->string(self::LABEL.'tags')), static fn (string $tag) => '' !== $tag)),
            $labels->string(self::LABEL.'engine'),
            $this->getName(),
            $container->string('Id'),
        );
    }

    private function address(Template $template, int $port): string
    {
        return strtr($template->address, ['{host}' => $this->publicHost, '{port}' => (string) $port]);
    }

    /** @param array<string, mixed> $options */
    private function call(string $method, string $path, array $options = []): ?Data
    {
        return $this->json->request($method, $this->base.$path, $options + $this->options);
    }
}
