<?php

namespace Omniplay\Docker\Tests;

use Omniplay\Docker\DockerFactory;
use Omniplay\Docker\DockerHosting;
use Omniplay\Exception\InvalidConfigException;
use Omniplay\Exception\NotSupportedException;
use Omniplay\Exception\ProviderException;
use Omniplay\Fleet;
use Omniplay\Model\Health;
use Omniplay\Model\SeatRequest;
use Omniplay\Model\Server;
use Omniplay\Model\Service;
use Omniplay\Registry;
use Omniplay\Seating;
use Omniplay\Secret\FixedSecrets;
use Omniplay\Storage\InMemoryStore;
use Omniplay\Testing\RecordedHttpClient;
use Omniplay\Tickets;
use PHPUnit\Framework\TestCase;

/**
 * The Docker Engine's answers written by hand after its API's reference
 * (v1.44: containers/create, start, json, stop, delete), with no engine; the
 * live test (group live) runs them against one.
 */
final class DockerHostingTest extends TestCase
{
    private const SECRET = 'not-a-real-secret';
    private const ID = 'f00dfeedcafe0123456789abcdef0123456789abcdef0123456789abcdef0123';

    public function testAServerIsACreatedStartedContainerOnThePortDockerPublished(): void
    {
        $http = self::engine();
        $server = self::hosting($http)->start(self::service(), self::SECRET, null, ['ranked']);

        self::assertMatchesRegularExpression('~^omniplay-game-[0-9a-f]{8}$~', $server->id);
        self::assertSame(['game', 'play.example.org:49153', 8, Health::Starting, 'local', ['ranked'], 'godot', 'docker', self::ID], [$server->service, $server->address, $server->capacity, $server->health, $server->region, $server->tags, $server->engine, $server->provider, $server->instance]);
        self::assertSame(['POST http://docker/v1.44/containers/create', 'POST http://docker/v1.44/containers/'.self::ID.'/start', 'GET http://docker/v1.44/containers/'.self::ID.'/json'], array_map(static fn (array $r) => $r['method'].' '.$r['url'], $http->requests));
        self::assertSame('/var/run/docker.sock', $http->requests[0]['options']['bindto'] ?? null, 'the unix socket, through curl');
        self::assertSame(['name' => $server->id], $http->requests[0]['options']['query'] ?? null);

        $body = $http->requests[0]['options']['json'] ?? null;
        self::assertIsArray($body);
        self::assertSame('example/game-server:1.4', $body['Image']);
        self::assertSame(['./server', '-batchmode'], $body['Cmd']);
        self::assertSame([
            'OMNIPLAY_SERVER_ID='.$server->id,
            'OMNIPLAY_ROOM='.$server->id,
            'OMNIPLAY_SERVICE=game',
            'OMNIPLAY_CAPACITY=8',
            'OMNIPLAY_PORT=7777',
            'GAME_TICKET_SECRET='.self::SECRET,
            'OMNIPLAY_ENGINE=godot',
            'OMNIPLAY_HEARTBEAT_URL=https://www.example.org/omniplay/heartbeat',
            'MAP=harbour',
        ], $body['Env']);
        self::assertSame(['omniplay.server' => $server->id, 'omniplay.service' => 'game', 'omniplay.capacity' => '8', 'omniplay.region' => 'local', 'omniplay.tags' => 'ranked', 'omniplay.engine' => 'godot', 'omniplay.port' => '7777/udp'], $body['Labels']);
        self::assertSame(['PortBindings' => ['7777/udp' => [['HostPort' => '']]], 'AutoRemove' => true, 'Memory' => 536870912, 'NanoCpus' => 500000000], $body['HostConfig']);
        self::assertSame('{"7777/udp":{}}', json_encode($body['ExposedPorts'], \JSON_UNESCAPED_SLASHES));
        self::assertStringNotContainsString(self::SECRET, (string) json_encode($server->toArray()), 'the secret is in the container only');
    }

    public function testAnImageDockerDoesNotHaveIsSaid(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Docker has no image "example/game-server:1.4" for the "game" service: pull it first (docker pull example/game-server:1.4).');
        self::hosting(RecordedHttpClient::routes([]))->start(self::service(), self::SECRET);
    }

    public function testAContainerThatDoesNotStartIsRemoved(): void
    {
        $http = RecordedHttpClient::routes([
            '~^POST http://docker/v1\.44/containers/create~' => [201, '{"Id": "'.self::ID.'", "Warnings": []}'],
            '~^POST http://docker/v1\.44/containers/\w+/start~' => [500, '{"message": "driver failed programming external connectivity: port is already allocated"}'],
            '~^DELETE http://docker/v1\.44/containers/\w+\?force=true~' => [204, ''],
        ]);
        try {
            self::hosting($http)->start(self::service(), self::SECRET);
            self::fail('Started.');
        } catch (ProviderException $e) {
            self::assertSame('Docker answered 500 for POST /v1.44/containers/'.self::ID.'/start. {"message": "driver failed programming external connectivity: port is already allocated"}', $e->getMessage());
        }
        self::assertSame('DELETE', $http->requests[2]['method']);
    }

    public function testAServerIsStoppedAndRemovedUnlessDockerRemovesItItself(): void
    {
        $server = new Server('omniplay-game-1', 'game', 'play.example.org:49153', 8, provider: 'docker', instance: self::ID);
        $http = self::engine();
        self::hosting($http)->stop($server);
        self::hosting($http, ['auto_remove' => false])->stop($server);

        self::assertSame(['POST /stop?t=10', 'POST /stop?t=10', 'DELETE ?force=true'], array_map(static fn (array $r) => $r['method'].' '.str_replace('http://docker/v1.44/containers/'.self::ID, '', $r['url']).'?'.http_build_query(\is_array($r['options']['query'] ?? null) ? $r['options']['query'] : []), $http->requests));
    }

    public function testTheRunningServersAreReadBackFromTheirLabels(): void
    {
        $http = self::engine();
        $servers = self::hosting($http)->running('game');

        self::assertSame([['omniplay-game-1', 'game', 'play.example.org:49153', 8, Health::Healthy, 'local', ['ranked', 'v1.4'], 'godot', self::ID]], array_map(static fn (Server $s) => [$s->id, $s->service, $s->address, $s->capacity, $s->health, $s->region, $s->tags, $s->engine, $s->instance], $servers));
        $query = $http->requests[0]['options']['query'] ?? null;
        self::assertIsArray($query);
        self::assertIsString($query['filters'] ?? null);
        self::assertSame(['label' => ['omniplay.server', 'omniplay.service=game'], 'status' => ['created', 'restarting', 'running']], json_decode($query['filters'], true));
    }

    public function testAServiceItHasNoTemplateForOrAnotherRegionIsRefused(): void
    {
        $hosting = self::hosting(self::engine());

        self::assertSame(['contracts' => ['hosting'], 'services' => ['game'], 'regions' => ['local'], 'where' => 'Docker Engine at unix:///var/run/docker.sock'], $hosting->capabilities()->toArray());
        foreach ([[new Service('relay'), null], [self::service(), 'eu']] as [$service, $region]) {
            try {
                $hosting->start($service, self::SECRET, $region);
                self::fail('Started.');
            } catch (NotSupportedException $e) {
                self::assertStringStartsWith('Docker starts no server of the', $e->getMessage());
            }
        }
    }

    public function testAnEngineOverTcpOrTlsAndWhatTheFactoryRefuses(): void
    {
        $http = self::engine('http://10\.0\.0\.5:2375');
        self::hosting($http, ['host' => 'tcp://10.0.0.5:2375'])->running();
        $tls = self::engine('https://10\.0\.0\.5:2376');
        self::hosting($tls, ['host' => 'tcp://10.0.0.5:2376', 'tls' => ['cafile' => '/certs/ca.pem', 'local_cert' => '/certs/cert.pem', 'local_pk' => '/certs/key.pem', 'other' => 'x']])->running();

        self::assertSame('http://10.0.0.5:2375/v1.44/containers/json', $http->requests[0]['url']);
        self::assertArrayNotHasKey('bindto', $http->requests[0]['options']);
        self::assertSame(['https://10.0.0.5:2376/v1.44/containers/json', '/certs/ca.pem', '/certs/key.pem', false], [$tls->requests[0]['url'], $tls->requests[0]['options']['cafile'] ?? null, $tls->requests[0]['options']['local_pk'] ?? null, isset($tls->requests[0]['options']['other'])]);

        foreach ([
            [['services' => []], 'The "docker" provider needs: services.'],
            [['services' => ['game' => ['port' => '7777']]], 'The "game" service of the "docker" provider names its image.'],
            [['services' => ['game' => ['image' => 'x', 'port' => 'seven']]], 'The "game" service\'s port is "7777/udp", "7777/tcp" or "7777", not "seven".'],
            [['services' => ['game' => 'x'], 'host' => 'ssh://user@host'], 'The "docker" provider\'s host is unix:///path/to/docker.sock, tcp://host:port or http(s)://host:port, not "ssh://user@host".'],
        ] as [$options, $message]) {
            try {
                (new DockerFactory($http))->create($options);
                self::fail($message);
            } catch (InvalidConfigException $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
    }

    public function testSeatingStartsAContainerWhenNoServerHasRoom(): void
    {
        $registry = new Registry([new DockerFactory(self::engine())], ['local' => ['factory' => 'docker', 'options' => self::options()]]);
        $secrets = new FixedSecrets(['GAME_SECRET' => self::SECRET]);
        $fleet = new Fleet(new InMemoryStore());
        $seating = new Seating($fleet, new Tickets($secrets), $registry->hostingFor('game'), $secrets);

        $seat = $seating->seat(self::service(), new SeatRequest('player-42'));

        self::assertNotNull($seat);
        self::assertSame(['play.example.org:49153', true, 1], [$seat->server->address, $seat->started, $fleet->get($seat->server->id)?->players]);
    }

    /** @param array<string, mixed> $options */
    private static function hosting(RecordedHttpClient $http, array $options = []): DockerHosting
    {
        $hosting = (new DockerFactory($http))->create($options + self::options());
        self::assertInstanceOf(DockerHosting::class, $hosting);

        return $hosting;
    }

    /** @return array<string, mixed> */
    private static function options(): array
    {
        return [
            'public_host' => 'play.example.org',
            'secret_env' => 'GAME_TICKET_SECRET',
            'heartbeat_url' => 'https://www.example.org/omniplay/heartbeat',
            'services' => ['game' => ['image' => 'example/game-server:1.4', 'port' => '7777/udp', 'command' => ['./server', '-batchmode'], 'env' => ['MAP' => 'harbour', 'GAME_TICKET_SECRET' => 'never this one'], 'memory' => 536870912, 'cpus' => 0.5]],
        ];
    }

    private static function service(): Service
    {
        return new Service('game', secret: 'GAME_SECRET', capacity: 8, engine: 'godot');
    }

    private static function engine(string $base = 'http://docker'): RecordedHttpClient
    {
        $id = self::ID;

        return RecordedHttpClient::routes([
            '~^POST '.$base.'/v1\.44/containers/create~' => [201, '{"Id": "'.$id.'", "Warnings": []}'],
            '~^POST '.$base.'/v1\.44/containers/\w+/start~' => [204, ''],
            '~^POST '.$base.'/v1\.44/containers/\w+/stop~' => [204, ''],
            '~^DELETE '.$base.'/v1\.44/containers/\w+~' => [204, ''],
            '~^GET '.$base.'/v1\.44/containers/\w+/json~' => [200, '{"Id": "'.$id.'", "State": {"Status": "running"}, "NetworkSettings": {"Ports": {"7777/udp": [{"HostIp": "0.0.0.0", "HostPort": "49153"}, {"HostIp": "::", "HostPort": "49153"}]}}}'],
            '~^GET '.$base.'/v1\.44/containers/json~' => [200, '[{"Id": "'.$id.'", "Names": ["/omniplay-game-1"], "State": "running", "Ports": [{"IP": "0.0.0.0", "PrivatePort": 7777, "PublicPort": 49153, "Type": "udp"}], "Labels": {"omniplay.server": "omniplay-game-1", "omniplay.service": "game", "omniplay.capacity": "8", "omniplay.region": "local", "omniplay.tags": "ranked,v1.4", "omniplay.engine": "godot", "omniplay.port": "7777/udp"}}, {"Id": "other", "Labels": {}}]'],
        ]);
    }
}
