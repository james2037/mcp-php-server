<?php

declare(strict_types=1);

namespace MCP\Server\Tests\Registry\DiscoveryTestFiles;

// The class name 'ActualClassNameInsideFile' is different from the file name 'DifferentNameClass.php'.
// phpcs:ignore Squiz.Classes.ClassFileName.NoMatch
class ActualClassNameInsideFile
{
    // This could be a valid Tool or Resource class structure,
    // but the discovery mechanism might rely on filename matching the class name.
}
