<?php

namespace Omniplay\Docker\Tests;

use Omniplay\Docker\DockerHosting;
use Omniplay\Testing\BareTestCase;

/** omnisense/omniplay-docker's classes load with omnisense/omniplay and Symfony's HTTP client contracts alone. */
final class BareTest extends BareTestCase
{
    protected static function entryClass(): string
    {
        return DockerHosting::class;
    }

    protected static function requiredPackages(): array
    {
        return ['symfony/http-client-contracts'];
    }
}
