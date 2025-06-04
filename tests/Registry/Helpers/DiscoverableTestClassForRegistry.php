<?php

declare(strict_types=1);

namespace MCP\Server\Tests\Registry\Helpers;

class DiscoverableTestClassForRegistry
{
    // This class is intentionally simple for discovery testing.
    // It can be instantiated without constructor arguments.
    public function __construct()
    {
        // Constructor can be empty or perform simple initializations.
    }

    public function getMessage(): string
    {
        return "Hello from DiscoverableTestClassForRegistry!";
    }
}
