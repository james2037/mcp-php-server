<?php

declare(strict_types=1);

namespace MCP\Server\Tests\Registry\DiscoveryTestFiles;

use MCP\Server\Tool\Tool as BaseTool;
use MCP\Server\Resource\Resource as BaseResource;

// This class is abstract and should not be instantiated or registered directly by 'discover'.
abstract class AbstractTestClass extends BaseTool
{
    // Abstract methods or regular methods can be here.
    // The key is that the class itself is abstract.
    abstract protected function someMethod(): void;
}
