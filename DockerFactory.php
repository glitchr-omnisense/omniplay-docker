<?php

namespace Omniplay\Docker;

use Omniplay\Config;
use Omniplay\Exception\InvalidConfigException;
use Omniplay\Http\JsonClient;
use Omniplay\ProviderFactory;
use Omniplay\ProviderInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * "docker": options services (by service name: image, port, env, command,
 * address, network, labels, host_port, memory, cpus - see Template), host
 * ("unix:///var/run/docker.sock", "tcp://10.0.0.5:2375",
 * "https://10.0.0.5:2376"), api_version ("v1.44"), public_host (where
 * players reach the published ports), region ("local"), secret_env
 * ("OMNIPLAY_TICKET_SECRET"), name_prefix ("omniplay-"), auto_remove (true),
 * stop_timeout (10 seconds), tls (the HTTP client's cafile, local_cert,
 * local_pk for a TLS engine), heartbeat_url (the application's heartbeat
 * route, handed to each server as OMNIPLAY_HEARTBEAT_URL), user_agent.
 */
final class DockerFactory extends ProviderFactory
{
    public function __construct(private readonly ?HttpClientInterface $http = null)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omniplay.factory_name' => 'docker',
            'omniplay.factory_title' => 'Docker',
            'omniplay.required_options' => ['services'],
            'services' => [],
            'host' => 'unix:///var/run/docker.sock',
            'api_version' => 'v1.44',
            'public_host' => '127.0.0.1',
            'region' => 'local',
            'secret_env' => 'OMNIPLAY_TICKET_SECRET',
            'name_prefix' => 'omniplay-',
            'auto_remove' => true,
            'stop_timeout' => 10,
            'tls' => [],
            'heartbeat_url' => null,
            'user_agent' => 'omniplay/1.0 (+https://github.com/glitchr-omnisense/omniplay)',
        ]);
    }

    protected function build(Config $c): ProviderInterface
    {
        $host = (string) $c->string('host');
        $options = [];
        if (str_starts_with($host, 'unix://')) {
            // Symfony's CurlHttpClient reaches a unix socket through "bindto"; the URL's host is then only a name.
            $options['bindto'] = substr($host, \strlen('unix://'));
            $base = 'http://docker';
        } elseif (1 === preg_match('~^(tcp|http|https)://([^/]+)/?$~', $host, $match)) {
            $tls = $c->get('tls');
            $tls = \is_array($tls) ? array_intersect_key($tls, array_flip(['cafile', 'capath', 'local_cert', 'local_pk', 'passphrase', 'verify_peer', 'verify_host'])) : [];
            $base = ('tcp' === $match[1] ? ([] !== $tls ? 'https' : 'http') : $match[1]).'://'.$match[2];
            $options += $tls;
        } else {
            throw new InvalidConfigException(\sprintf('The "docker" provider\'s host is unix:///path/to/docker.sock, tcp://host:port or http(s)://host:port, not "%s".', $host));
        }
        $version = trim((string) $c->string('api_version'), '/');

        $templates = [];
        $services = $c->get('services');
        foreach (\is_array($services) ? $services : [] as $name => $values) {
            $templates[(string) $name] = Template::fromArray((string) $name, \is_array($values) ? $values : ['image' => $values]);
        }

        return new DockerHosting(
            new JsonClient($this->http ?? HttpClient::create(['timeout' => 30]), 'Docker', ['Accept' => 'application/json', 'User-Agent' => (string) $c->string('user_agent')], $c->backoff()),
            $base.('' !== $version ? '/'.$version : ''),
            $templates,
            (string) $c->string('public_host'),
            (string) $c->string('region'),
            (string) $c->string('secret_env'),
            (string) $c->string('name_prefix'),
            $c->bool('auto_remove'),
            $c->int('stop_timeout', 10),
            $options,
            'Docker Engine at '.$host,
            $c->string('heartbeat_url'),
        );
    }
}
