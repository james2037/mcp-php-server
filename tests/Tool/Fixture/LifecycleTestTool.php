<?php

declare(strict_types=1);

namespace MCP\Server\Tests\Tool\Fixture;

use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Content\ContentItemInterface;

class LifecycleTestTool extends Tool
{
    public bool $initialized = false;
    public bool $shutdown = false;
    /** @var string[] */
    public array $log = [];

    public function __construct(?array $config = null)
    {
        $this->log[] = "Before parent constructor";
        parent::__construct($config);
        $this->log[] = "After parent constructor";
    }

    public function initialize(): void
    {
        parent::initialize(); // Good practice to call parent
        $this->initialized = true;
        $this->log[] = "initialize called";
    }

    public function shutdown(): void
    {
        parent::shutdown(); // Good practice to call parent
        $this->shutdown = true;
        $this->log[] = "shutdown called";
    }

    protected function doExecute(array $arguments): array|ContentItemInterface
    {
        return $this->text("executed");
    }

    // Helper to get the log for assertions
    /** @return string[] */
    public function getLog(): array
    {
        return $this->log;
    }

    public function clearLog(): void
    {
        $this->log = [];
    }
}
