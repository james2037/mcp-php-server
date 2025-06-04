<?php

declare(strict_types=1);

namespace MCP\Server\Tests\Registry\Helpers;

class AnotherDiscoverableTestClassForRegistry
{
    // This class is also simple for discovery testing.
    public function __construct()
    {
    }

    public function getAnotherMessage(): string
    {
        return "Hello from AnotherDiscoverableTestClassForRegistry!";
    }
}
