<?php

namespace MCP\Server\Tests\Capability;

use MCP\Server\Capability\ToolsCapability;
use MCP\Server\Message\JsonRpcMessage;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;
use MCP\Server\Tool\Tool;
use PHPUnit\Framework\TestCase;

#[ToolAttribute('test', 'Test Tool')]
class MockTool extends Tool
{
    protected function doExecute(
        #[ParameterAttribute('data', type: 'string')]
        array $arguments
    ): array {
        return $this->text('Result: ' . $arguments['data']);
    }
}

#[ToolAttribute('failing', 'Failing Tool')]
class FailingMockTool extends Tool
{
    protected function doExecute(array $arguments): array
    {
        throw new \RuntimeException('Tool execution failed');
    }
}

class ToolsCapabilityTest extends TestCase
{
    private ToolsCapability $capability;

    protected function setUp(): void
    {
        $this->capability = new ToolsCapability();
        $this->capability->addTool(new MockTool());
    }

    public function testGetCapabilities(): void
    {
        $caps = $this->capability->getCapabilities();
        $this->assertArrayHasKey('tools', $caps);
        $this->assertArrayHasKey('listChanged', $caps['tools']);
    }

    public function testHandleList(): void
    {
        $request = new JsonRpcMessage('tools/list', [], '1');
        $response = $this->capability->handleMessage($request);

        $this->assertNotNull($response);
        $this->assertArrayHasKey('tools', $response->result);
        $this->assertCount(1, $response->result['tools']);
        $this->assertEquals('test', $response->result['tools'][0]['name']);
    }

    public function testHandleCall(): void
    {
        $request = new JsonRpcMessage(
            'tools/call',
            [
            'name' => 'test',
            'arguments' => ['data' => 'test input']
            ],
            '1'
        );

        $response = $this->capability->handleMessage($request);

        $this->assertNotNull($response);
        $this->assertArrayHasKey('content', $response->result);
        $this->assertEquals('Result: test input', $response->result['content'][0]['text']);
    }

    public function testHandleCallWithUnknownTool(): void
    {
        $request = new JsonRpcMessage(
            'tools/call',
            [
            'name' => 'unknown',
            'arguments' => []
            ],
            '1'
        );

        $response = $this->capability->handleMessage($request);

        $this->assertNotNull($response);
        $this->assertArrayHasKey('content', $response->result);
        $this->assertTrue($response->result['isError']);
        $this->assertStringContainsString('Tool not found', $response->result['content'][0]['text']);
    }

    public function testHandleCallWithFailingTool(): void
    {
        $this->capability->addTool(new FailingMockTool());

        $request = new JsonRpcMessage(
            'tools/call',
            [
            'name' => 'failing',
            'arguments' => []
            ],
            '1'
        );

        $response = $this->capability->handleMessage($request);

        $this->assertNotNull($response);
        $this->assertArrayHasKey('content', $response->result);
        $this->assertTrue($response->result['isError']);
        $this->assertEquals('Tool execution failed', $response->result['content'][0]['text']);
    }

    public function testHandleCallWithInvalidArguments(): void
    {
        $request = new JsonRpcMessage(
            'tools/call',
            [
            'name' => 'test',
            'arguments' => ['invalid_param' => 'value']
            ],
            '1'
        );

        $response = $this->capability->handleMessage($request);

        $this->assertNotNull($response);
        $this->assertArrayHasKey('content', $response->result);
        $this->assertTrue($response->result['isError']);
        $this->assertStringContainsString('Unknown argument', $response->result['content'][0]['text']);
    }

    public function testToolInitializationAndShutdown(): void
    {
        $initCount = 0;
        $shutdownCount = 0;

        $mockTool = new class ($initCount, $shutdownCount) extends Tool {
            private $initCount;
            private $shutdownCount;

            public function __construct(&$initCount, &$shutdownCount)
            {
                parent::__construct();
                $this->initCount = &$initCount;
                $this->shutdownCount = &$shutdownCount;
            }

            public function initialize(): void
            {
                $this->initCount++;
            }

            public function shutdown(): void
            {
                $this->shutdownCount++;
            }

            protected function doExecute(array $arguments): array
            {
                return $this->text('test');
            }
        };

        $capability = new ToolsCapability();
        $capability->addTool($mockTool);

        $capability->initialize();
        $this->assertEquals(1, $initCount);

        $capability->shutdown();
        $this->assertEquals(1, $shutdownCount);
    }
}
