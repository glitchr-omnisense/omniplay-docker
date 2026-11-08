<?php

namespace Omniplay\Docker\Tests;

use Omniplay\Docker\DockerFactory;
use Omniplay\Docker\DockerHosting;
use Omniplay\Http\JsonClient;
use Omniplay\Model\Service;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Against a real Docker Engine on its unix socket (DOCKER_SOCKET, or
 * /var/run/docker.sock): a container of an image the engine already has
 * (OMNIPLAY_LIVE_IMAGE, php:8.4-cli by default, running PHP's own web
 * server on 7777/tcp) started, found running, its environment holding the
 * secret, stopped and removed by Docker.
 */
#[Group('live')]
final class LiveTest extends TestCase
{
    public function testAContainerIsStartedFoundAndStopped(): void
    {
        $socket = getenv('DOCKER_SOCKET') ?: '/var/run/docker.sock';
        if (!file_exists($socket)) {
            self::markTestSkipped('No Docker Engine socket at '.$socket.'.');
        }
        $image = getenv('OMNIPLAY_LIVE_IMAGE') ?: 'php:8.4-cli';
        $hosting = (new DockerFactory())->create([
            'host' => 'unix://'.$socket,
            'name_prefix' => 'omniplay-live-',
            'services' => ['live' => ['image' => $image, 'port' => '7777/tcp', 'command' => ['php', '-S', '0.0.0.0:7777', '-t', '/tmp']]],
        ]);
        self::assertInstanceOf(DockerHosting::class, $hosting);
        $service = new Service('live', capacity: 4, engine: 'custom');

        $server = $hosting->start($service, 'not-a-real-secret-live');
        try {
            self::assertMatchesRegularExpression('~^127\.0\.0\.1:\d+$~', $server->address);
            self::assertSame([$server->id], array_map(static fn ($s) => $s->id, $hosting->running('live')));
            $engine = new JsonClient(HttpClient::create(), 'Docker');
            $env = $engine->request('GET', 'http://docker/v1.44/containers/'.$server->instance.'/json', ['bindto' => $socket])?->data('Config')->strings('Env') ?? [];
            self::assertContains('OMNIPLAY_TICKET_SECRET=not-a-real-secret-live', $env);
            self::assertContains('OMNIPLAY_ROOM='.$server->id, $env);
        } finally {
            $hosting->stop($server);
        }
        for ($i = 0; $i < 50 && [] !== $hosting->running('live'); ++$i) {
            usleep(200000);
        }
        self::assertSame([], $hosting->running('live'), 'stopped, and removed by Docker');
    }
}
