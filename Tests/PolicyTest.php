<?php

namespace Omniplay\Docker\Tests;

use Omniplay\Docker\DockerFactory;
use Omniplay\Docker\DockerHosting;
use Omniplay\Exception\OmniplayException;
use Omniplay\Exception\UsageLimitReachedException;
use Omniplay\Registry;
use Omniplay\Testing\RecordedHttpClient;
use Omnisense\Contracts\Policy\Permission;
use Omnisense\Contracts\Policy\RateLimit;
use Omnisense\Contracts\Policy\UsagePolicy;
use PHPUnit\Framework\TestCase;

/** Docker's usage policy, as policy.json declares it, held through omniplay's registry. */
final class PolicyTest extends TestCase
{
    public function testThePolicyIsTheOneDeclared(): void
    {
        $policy = self::registry(self::answering())->policy('docker');

        self::assertSame('docker', $policy->provider);
        self::assertSame([], null === $policy->rateLimits ? null : array_map(static fn (RateLimit $limit) => $limit->id(), $policy->rateLimits));
        self::assertSame(Permission::Allowed, $policy->rights->commercial);
        self::assertSame(false, $policy->rights->attribution->required);
        self::assertSame(false, $policy->requirements->key);
        self::assertSame('2026-11-10', $policy->provenance->verifiedAt);
        self::assertSame(UsagePolicy::fromFile(DockerHosting::POLICY)->toArray(), $policy->toArray(), 'the registry runs the policy the package declares');
        self::assertSame($policy->toArray(), self::registry(self::answering())->get('docker')->capabilities()->policy()->toArray(), 'its Capabilities carry it too');
    }

    public function testASitesLimitIsCountedAndTheCallOverItIsNeverMade(): void
    {
        $http = self::answering();
        $registry = self::registry($http, ['rateLimits' => [['max' => 1, 'window' => 'minute', 'operations' => ['running']]]]);

        $registry->hosting('docker')->running('game');
        $made = \count($http->requests);
        try {
            $registry->hosting('docker')->running('game');
            self::fail('The call over the site\'s limit was made.');
        } catch (UsageLimitReachedException $e) {
            self::assertInstanceOf(OmniplayException::class, $e, 'the family\'s catch holds it');
            self::assertSame('1/minute/application/running', $e->limit?->id());
        }
        self::assertCount($made, $http->requests, 'the refused call reached nothing');
    }

    public function testA429AndItsRetryAfterHoldEveryCallUntilTheWaitIsOver(): void
    {
        $http = RecordedHttpClient::routes(['~~' => [429, '', ['retry-after' => ['30']]]]);
        $registry = self::registry($http);

        try {
            $registry->hosting('docker')->running('game');
            self::fail('A 429 was read as an answer.');
        } catch (UsageLimitReachedException $e) {
            self::assertSame(30, $e->retryAfter);
        }
        $made = \count($http->requests);
        try {
            $registry->hosting('docker')->running('game');
            self::fail('A call went out while Docker asked to wait.');
        } catch (UsageLimitReachedException $e) {
            self::assertGreaterThanOrEqual(29, $e->retryAfter);
        }
        self::assertCount($made, $http->requests, 'the wait held the next request back');
    }

    /** @param array<string, mixed> $policy */
    private static function registry(RecordedHttpClient $http, array $policy = []): Registry
    {
        return new Registry([new DockerFactory($http)], ['docker' => ['factory' => 'docker', 'options' => ['host' => 'http://docker', 'services' => ['game' => ['image' => 'example/game-server:1.4', 'port' => '7777/udp']]]] + ([] === $policy ? [] : ['policy' => $policy])]);
    }

    private static function answering(): RecordedHttpClient
    {
        return RecordedHttpClient::routes(['~^GET http://docker/v1\\.44/containers/json~' => [200, '[]']]);
    }
}
