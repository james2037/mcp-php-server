<?php

declare(strict_types=1);

namespace MCP\Server\Tests\Registry\DiscoveryTestFiles;

// This file declares a namespace but does not define any class.
// This should cause `getClassFromFile` in Registry.php to return a string
// that won't pass the `class_exists` check in `Registry::discover`.

// No class definition below.
