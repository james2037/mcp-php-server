<?php

declare(strict_types=1);

namespace MCP\Server\Tests\Registry\DiscoveryTestFiles;

class SyntaxErrorFile
{
    public function __construct()
    {
        echo "This file will have a syntax error introduced dynamically in tests";
    }
}
