<?php

namespace Omniplay\Docker;

use Omniplay\Exception\InvalidConfigException;

/**
 * What a server of one service runs, as the configuration gives it: the
 * image, the port the server listens on inside its container ("7777/udp"),
 * its command, its environment, the address players are given ("{host}" the
 * provider's public host, "{port}" the port Docker published), and what
 * Docker limits it to.
 */
final readonly class Template
{
    /**
     * @param array<string, string> $env     the server's own variables (omniplay's are set after them)
     * @param ?list<string>         $command the image's command when null
     * @param array<string, string> $labels  besides omniplay's
     * @param ?int                  $hostPort the host port to publish on (one server at a time); any free one when null
     * @param ?int                  $memory   bytes
     */
    public function __construct(
        public string $image,
        public string $port = '7777/udp',
        public array $env = [],
        public ?array $command = null,
        public string $address = '{host}:{port}',
        public ?string $network = null,
        public array $labels = [],
        public ?int $hostPort = null,
        public ?int $memory = null,
        public ?float $cpus = null,
    ) {
    }

    /**
     * A service's entry of the "services" option.
     *
     * @param array<mixed> $values
     */
    public static function fromArray(string $service, array $values): self
    {
        $image = \is_string($values['image'] ?? null) ? trim($values['image']) : '';
        if ('' === $image) {
            throw new InvalidConfigException(\sprintf('The "%s" service of the "docker" provider names its image.', $service));
        }
        $port = \is_scalar($values['port'] ?? null) ? strtolower(trim((string) $values['port'])) : '7777/udp';
        if (1 === preg_match('~^\d+$~', $port)) {
            $port .= '/tcp';
        }
        if (1 !== preg_match('~^\d{1,5}/(tcp|udp|sctp)$~', $port)) {
            throw new InvalidConfigException(\sprintf('The "%s" service\'s port is "7777/udp", "7777/tcp" or "7777", not "%s".', $service, $port));
        }
        $map = static function (mixed $values): array {
            $map = [];
            foreach (\is_array($values) ? $values : [] as $key => $value) {
                if (\is_scalar($value) || null === $value) {
                    $map[(string) $key] = \is_bool($value) ? ($value ? '1' : '0') : (string) $value;
                }
            }

            return $map;
        };
        $command = \is_array($values['command'] ?? null) ? array_values(array_map('strval', array_filter($values['command'], 'is_scalar'))) : (\is_string($values['command'] ?? null) ? [$values['command']] : null);

        return new self(
            $image,
            $port,
            $map($values['env'] ?? []),
            $command,
            \is_string($values['address'] ?? null) && '' !== $values['address'] ? $values['address'] : '{host}:{port}',
            \is_string($values['network'] ?? null) && '' !== $values['network'] ? $values['network'] : null,
            $map($values['labels'] ?? []),
            is_numeric($values['host_port'] ?? null) ? (int) $values['host_port'] : null,
            is_numeric($values['memory'] ?? null) ? (int) $values['memory'] : null,
            is_numeric($values['cpus'] ?? null) ? (float) $values['cpus'] : null,
        );
    }
}
