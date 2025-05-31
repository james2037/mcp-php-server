<?php

namespace MCP\Server\Tests\Capability;

use MCP\Server\Capability\ToolsCapability;
use MCP\Server\Message\JsonRpcMessage;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;
use MCP\Server\Tool\Attribute\ToolAnnotations; // Added
use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Content; // Added
use PHPUnit\Framework\TestCase;

#[ToolAnnotations(title: 'Mock Test Tool', readOnlyHint: true)] // Added annotation
#[ToolAttribute('test', 'Test Tool')]
class MockTool extends Tool
{
    protected function doExecute(
        #[ParameterAttribute('data', type: 'string', description: 'Input data')] // Added description
        array $arguments
    ): array { // Return type hint for clarity, still array of ContentItemInterface
        // Use the helper method from Tool.php
        return [$this->createTextContent('Result: ' . $arguments['data'])];
    }

    // Override for completion test
    public function getCompletionSuggestions(string $argumentName, mixed $currentValue, array $allArguments = []): array
    {
        if ($argumentName === 'data') {
            $allValues = ['apple', 'apricot', 'banana', 'blueberry'];
            $filteredValues = array_filter($allValues, fn($v) => str_starts_with($v, (string)$currentValue));
            return ['values' => array_values($filteredValues), 'total' => count($filteredValues), 'hasMore' => false];
        }
        return parent::getCompletionSuggestions($argumentName, $currentValue, $allArguments);
    }
}

#[ToolAttribute('failing', 'Failing Tool')]
class FailingMockTool extends Tool
{
    protected function doExecute(array $arguments): array
    {
        throw new \RuntimeException('Tool execution failed');
    }
    // No change to return type needed here as it throws before returning
}

class ToolsCapabilityTest extends TestCase
{
    private ToolsCapability $_capability;
    private MockTool $_mockToolInstance; // To access its methods if needed, or just for setup

    protected function setUp(): void
    {
        $this->_capability = new ToolsCapability();
        $this->_mockToolInstance = new MockTool();
        $this->_capability->addTool($this->_mockToolInstance);
    }

    public function testGetCapabilities(): void
    {
        $caps = $this->_capability->getCapabilities();
        $this->assertArrayHasKey('tools', $caps);
        $this->assertArrayHasKey('listChanged', $caps['tools']);
        // As per current ToolsCapability, no other capabilities are announced by default
        $this->assertCount(1, $caps['tools'], "Only listChanged should be in tools capability by default");
    }

    public function testHandleList(): void
    {
        $request = new JsonRpcMessage('tools/list', [], '1');
        $response = $this->_capability->handleMessage($request);

        $this->assertNotNull($response);
        $this->assertNull($response->error, "tools/list should not produce an error directly.");
        $this->assertIsArray($response->result['tools']);
        $this->assertCount(1, $response->result['tools']);

        $toolData = $response->result['tools'][0]; // Confirmed to be an array by error_log

        $this->assertEquals('test', $toolData['name']);
        $this->assertEquals('Test Tool', $toolData['description']); // From ToolAttribute
        $this->assertArrayHasKey('annotations', $toolData);
        $this->assertEquals('Mock Test Tool', $toolData['annotations']['title']);
        $this->assertTrue($toolData['annotations']['readOnlyHint']);

        $this->assertArrayHasKey('inputSchema', $toolData);
        // inputSchema['properties'] is an object (stdClass) as created by Tool::getInputSchema()
        // So access to its sub-properties should be object access.
        $this->assertArrayHasKey('properties', $toolData['inputSchema']);
        $this->assertObjectHasProperty('data', $toolData['inputSchema']['properties']);
        $this->assertEquals('Input data', $toolData['inputSchema']['properties']->data->description);
    }

    public function testHandleCall(): void
    {
        $request = new JsonRpcMessage(
            'tools/call',
            ['name' => 'test', 'arguments' => ['data' => 'test input']],
            '1'
        );
        $response = $this->_capability->handleMessage($request);

        $this->assertNotNull($response);
        $this->assertNull($response->error, "tools/call with valid tool should not produce a top-level error.");
        $this->assertFalse($response->result['isError']);
        $this->assertIsArray($response->result['content']);
        $this->assertCount(1, $response->result['content']);
        // Asserting the structure created by createTextContent(...)->toArray()
        $this->assertEquals(['type' => 'text', 'text' => 'Result: test input'], $response->result['content'][0]);
    }

    public function testHandleCallWithUnknownTool(): void
    {
        $request = new JsonRpcMessage(
            'tools/call',
            ['name' => 'unknown', 'arguments' => []],
            '1'
        );
        $response = $this->_capability->handleMessage($request);

        $this->assertNotNull($response);
        $this->assertNull($response->error); // Error is within the result for tools/call
        $this->assertTrue($response->result['isError']);
        $this->assertIsArray($response->result['content']);
        $this->assertCount(1, $response->result['content']);
        $this->assertEquals('text', $response->result['content'][0]['type']);
        $this->assertStringContainsString('Tool not found: unknown', $response->result['content'][0]['text']);
    }

    public function testHandleCallWithFailingTool(): void
    {
        $this->_capability->addTool(new FailingMockTool());
        $request = new JsonRpcMessage(
            'tools/call',
            ['name' => 'failing', 'arguments' => []],
            '1'
        );
        $response = $this->_capability->handleMessage($request);

        $this->assertNotNull($response);
        $this->assertNull($response->error); // Error is within the result for tools/call
        $this->assertTrue($response->result['isError']);
        $this->assertIsArray($response->result['content']);
        $this->assertCount(1, $response->result['content']);
        $this->assertEquals('text', $response->result['content'][0]['type']);
        $this->assertStringContainsString("Error executing tool 'failing': Tool execution failed", $response->result['content'][0]['text']);
    }

    public function testHandleCallWithMissingRequiredArgument(): void
    {
        // Current Tool::validateArguments throws InvalidArgumentException for unknown args,
        // but not yet for missing required args or type mismatches.
        // The error here will come from MockTool trying to access $arguments['data'] which won't exist.
        $request = new JsonRpcMessage(
            'tools/call',
            ['name' => 'test', 'arguments' => ['unexpected_arg' => 'value']], // 'data' is missing
            '1'
        );
        $response = $this->_capability->handleMessage($request);

        $this->assertNotNull($response);
        $this->assertNull($response->error);
        $this->assertTrue($response->result['isError']);
        $this->assertIsArray($response->result['content']);
        $this->assertCount(1, $response->result['content']);
        $this->assertEquals('text', $response->result['content'][0]['type']);
        // Tool::validateArguments should throw InvalidArgumentException for unknown arguments
        $this->assertStringContainsString("Unknown argument: unexpected_arg", $response->result['content'][0]['text']);
    }

    public function testToolInitializationAndShutdown(): void
    {
        $initCount = 0;
        $shutdownCount = 0;

        $testTool = new #[ToolAttribute('lifecycleTool', 'Lifecycle Test Tool')] class ($initCount, $shutdownCount) extends Tool {
            // Using references to modify outer scope variables
            public function __construct(private &$initCountRef, private &$shutdownCountRef)
            {
                parent::__construct(); // Important to call parent constructor
            }

            public function initialize(): void
            {
                $this->initCountRef++;
            }
            public function shutdown(): void
            {
                $this->shutdownCountRef++;
            }
            protected function doExecute(array $arguments): array
            {
                return [$this->createTextContent('done')];
            }
        };

        $capabilityWithLifecycleTool = new ToolsCapability();
        $capabilityWithLifecycleTool->addTool($testTool);

        $capabilityWithLifecycleTool->initialize();
        $this->assertEquals(1, $initCount, "Tool's initialize method should have been called.");

        $capabilityWithLifecycleTool->shutdown();
        $this->assertEquals(1, $shutdownCount, "Tool's shutdown method should have been called.");
    }

    // --- New Completion Tests ---
    public function testHandleCompleteSuggestions(): void
    {
        $request = new JsonRpcMessage(
            'completion/complete', [
            'ref' => ['type' => 'ref/prompt', 'name' => 'test'],
            'argument' => ['name' => 'data', 'value' => 'ap']
            ], 'comp1'
        );
        $response = $this->_capability->handleMessage($request);

        $this->assertNotNull($response);
        $this->assertNull($response->error);
        $this->assertArrayHasKey('completion', $response->result);
        $expectedSuggestions = ['apple', 'apricot'];
        $this->assertEquals($expectedSuggestions, $response->result['completion']['values']);
        $this->assertEquals(count($expectedSuggestions), $response->result['completion']['total']);
        $this->assertFalse($response->result['completion']['hasMore']);
    }

    public function testHandleCompleteDefaultSuggestions(): void
    {
        $basicTool = new #[ToolAttribute('basic', 'Basic Tool')] class extends Tool {
            protected function doExecute(array $arguments): array
            {
                return [$this->createTextContent('done')];
            }
        };
        $this->_capability->addTool($basicTool); // Add to the existing capability instance

        $request = new JsonRpcMessage(
            'completion/complete', [
            'ref' => ['type' => 'ref/prompt', 'name' => 'basic'],
            'argument' => ['name' => 'some_arg', 'value' => 'any']
            ], 'comp2'
        );
        $response = $this->_capability->handleMessage($request);

        $this->assertNotNull($response);
        $this->assertNull($response->error);
        $this->assertArrayHasKey('completion', $response->result);
        $this->assertEquals(['values' => [], 'total' => 0, 'hasMore' => false], $response->result['completion']);
    }

    public function testHandleCompleteToolNotFound(): void
    {
        $request = new JsonRpcMessage(
            'completion/complete', [
            'ref' => ['type' => 'ref/prompt', 'name' => 'unknownToolForCompletion'],
            'argument' => ['name' => 'arg', 'value' => 'val']
            ], 'comp3'
        );
        $response = $this->_capability->handleMessage($request);

        $this->assertNotNull($response);
        $this->assertNotNull($response->error); // The $response object itself should have its error property set
        $this->assertNull($response->result);   // For an error response, result should be null

        $this->assertEquals(JsonRpcMessage::METHOD_NOT_FOUND, $response->error['code']);
        $this->assertStringContainsString('Tool not found for completion: unknownToolForCompletion', $response->error['message']);
    }
}
