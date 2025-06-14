<?php

namespace MCP\Server\Tests\Capability;

use MCP\Server\Capability\ToolsCapability;
use MCP\Server\Message\JsonRpcMessage;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;
use MCP\Server\Tool\Attribute\ToolAnnotations;
use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Content;
use PHPUnit\Framework\TestCase;
// MockTool and FailingMockTool are now in separate files.
use MCP\Server\Tests\Capability\MockTool;
use MCP\Server\Tests\Capability\FailingMockTool;
use MCP\Server\Tests\Capability\InvalidSuggestionsTool;

class ToolsCapabilityTest extends TestCase
{
    private ToolsCapability $capability;
    private MockTool $mockToolInstance; // To access its methods if needed, or just for setup

    protected function setUp(): void
    {
        $this->capability = new ToolsCapability();
        $this->mockToolInstance = new MockTool();
        $this->capability->addTool($this->mockToolInstance);
    }

    public function testGetCapabilities(): void
    {
        $caps = $this->capability->getCapabilities();
        $this->assertArrayHasKey('tools', $caps);
        $this->assertArrayHasKey('listChanged', $caps['tools']);
        // As per current ToolsCapability, no other capabilities are announced by default
        $this->assertCount(1, $caps['tools'], "Only listChanged should be in tools capability by default");
    }

    public function testHandleList(): void
    {
        $request = new JsonRpcMessage('tools/list', [], '1');
        $response = $this->capability->handleMessage($request);

        $this->assertNotNull($response); // Ensure response is not null before proceeding
        $this->assertNull($response->error, "tools/list should not produce an error directly.");
        $this->assertNotNull($response->result, "Result should not be null for tools/list"); // Added
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

    public function testHandleListWithNullIdIsNotification(): void
    {
        $request = new JsonRpcMessage('tools/list', [], null); // ID is null
        $capability = new ToolsCapability(); // Fresh instance, no tools needed for this test
        $response = $capability->handleMessage($request);

        $this->assertNull($response, "handleMessage should return null for a notification (null id)");
    }

    public function testHandleCall(): void
    {
        $request = new JsonRpcMessage(
            'tools/call',
            ['name' => 'test', 'arguments' => ['data' => 'test input']],
            '1'
        );
        $response = $this->capability->handleMessage($request);

        $this->assertNotNull($response); // Ensure response is not null
        $this->assertNull($response->error, "tools/call with valid tool should not produce a top-level error.");
        $this->assertNotNull($response->result, "Result should not be null for successful tools/call"); // Added
        $this->assertFalse($response->result['isError']);
        $this->assertIsArray($response->result['content']);
        $this->assertCount(1, $response->result['content']);
        // Asserting the structure created by createTextContent(...)->toArray()
        $this->assertEquals(['type' => 'text', 'text' => 'Result: test input'], $response->result['content'][0]);
    }

    public function testHandleCallWithNullIdIsNotification(): void
    {
        $request = new JsonRpcMessage(
            'tools/call',
            ['name' => 'test', 'arguments' => ['data' => 'test input']],
            null // ID is null
        );
        // Use the capability instance from setUp, which has MockTool registered
        $response = $this->capability->handleMessage($request);

        $this->assertNull($response, "handleMessage should return null for a tools/call notification (null id)");
    }

    public function testHandleCallWithUnknownTool(): void
    {
        $request = new JsonRpcMessage(
            'tools/call',
            ['name' => 'unknown', 'arguments' => []],
            '1'
        );
        $response = $this->capability->handleMessage($request);
        $response = $this->capability->handleMessage($request);

        $this->assertNotNull($response); // Ensure response is not null
        $this->assertNull($response->error); // Error is within the result for tools/call
        $this->assertNotNull($response->result, "Result should not be null for tools/call (unknown tool)."); // Added
        $this->assertTrue($response->result['isError']);
        $this->assertIsArray($response->result['content']);
        $this->assertCount(1, $response->result['content']);
        $this->assertEquals('text', $response->result['content'][0]['type']);
        $this->assertStringContainsString('Tool not found: unknown', $response->result['content'][0]['text']);
    }

    public function testHandleCallWithFailingTool(): void
    {
        $this->capability->addTool(new FailingMockTool());
        $request = new JsonRpcMessage(
            'tools/call',
            ['name' => 'failing', 'arguments' => []],
            '1'
        );
        $response = $this->capability->handleMessage($request);
        $response = $this->capability->handleMessage($request);

        $this->assertNotNull($response); // Ensure response is not null
        $this->assertNull($response->error); // Error is within the result for tools/call
        $this->assertNotNull($response->result, "Result should not be null for tools/call (failing tool)."); // Added
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
        $response = $this->capability->handleMessage($request);

        $this->assertNotNull($response); // Ensure response is not null
        $this->assertNull($response->error);
        $this->assertNotNull($response->result, "Result should not be null for tools/call (missing arg)."); // Added
        $this->assertTrue($response->result['isError']);
        $this->assertIsArray($response->result['content']);
        $this->assertCount(1, $response->result['content']);
        $this->assertEquals('text', $response->result['content'][0]['type']);
        // Tool::validateArguments should throw InvalidArgumentException for unknown arguments
        $this->assertStringContainsString("Unknown argument: unexpected_arg", $response->result['content'][0]['text']);
    }

    public function testHandleCallWithInvalidToolNameType(): void
    {
        $invalidToolNames = [null, 123, ['array']];

        foreach ($invalidToolNames as $toolName) {
            $request = new JsonRpcMessage(
                'tools/call',
                ['name' => $toolName, 'arguments' => ['data' => 'test input']],
                '1'
            );
            $response = $this->capability->handleMessage($request);

            $this->assertNotNull($response); // Ensure response is not null
            $this->assertNull($response->error);
            $this->assertNotNull($response->result, "Result should not be null for tools/call (invalid tool name type)."); // Added
            $this->assertTrue($response->result['isError']);
            $this->assertIsArray($response->result['content']);
            $this->assertCount(1, $response->result['content']);
            $this->assertEquals('text', $response->result['content'][0]['type']);
            $this->assertStringContainsString('Invalid or missing tool name.', $response->result['content'][0]['text']);
        }
    }

    public function testHandleCallWithEmptyToolName(): void
    {
        $request = new JsonRpcMessage(
            'tools/call',
            ['name' => '', 'arguments' => ['data' => 'test input']],
            '1'
        );
        $response = $this->capability->handleMessage($request);

        $this->assertNotNull($response); // Ensure response is not null
        $this->assertNull($response->error);
        $this->assertNotNull($response->result, "Result should not be null for tools/call (empty tool name)."); // Added
        $this->assertTrue($response->result['isError']);
        $this->assertIsArray($response->result['content']);
        $this->assertCount(1, $response->result['content']);
        $this->assertEquals('text', $response->result['content'][0]['type']);
            $this->assertStringContainsString('Invalid or missing tool name.', $response->result['content'][0]['text']);
    }

    public function testHandleCallWithInvalidToolArgumentsType(): void
    {
        $invalidArguments = ['not an array', 123];

        foreach ($invalidArguments as $arguments) {
            $request = new JsonRpcMessage(
                'tools/call',
                ['name' => 'test', 'arguments' => $arguments],
                '1'
            );
            $response = $this->capability->handleMessage($request);

            $this->assertNotNull($response); // Ensure response is not null
            $this->assertNull($response->error);
            $this->assertNotNull($response->result, "Result should not be null for tools/call (invalid arg type)."); // Added
            $this->assertTrue($response->result['isError']);
            $this->assertIsArray($response->result['content']);
            $this->assertCount(1, $response->result['content']);
            $this->assertEquals('text', $response->result['content'][0]['type']);
            $this->assertStringContainsString('Invalid arguments format: arguments must be an object/map.', $response->result['content'][0]['text']);
        }
    }

    public function testToolInitializationAndShutdown(): void
    {
        $initCount = 0;
        $shutdownCount = 0;

        $testTool = new #[ToolAttribute('lifecycleTool', 'Lifecycle Test Tool')] class ($initCount, $shutdownCount) extends Tool {
            // Using references to modify outer scope variables
            public function __construct(private int &$initCountRef, private int &$shutdownCountRef)
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
            /**
             * @return Content\ContentItemInterface
             */
            protected function doExecute(array $arguments): \MCP\Server\Tool\Content\ContentItemInterface
            {
                return $this->text('done');
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
            'completion/complete',
            [
            'ref' => ['type' => 'ref/prompt', 'name' => 'test'],
            'argument' => ['name' => 'data', 'value' => 'ap']
            ],
            'comp1'
        );
        $response = $this->capability->handleMessage($request);

        $this->assertNotNull($response); // Ensure response is not null
        $this->assertNull($response->error);
        $this->assertNotNull($response->result, "Result should not be null for completion/complete."); // Added
        $this->assertArrayHasKey('completion', $response->result);
        self::assertIsArray($response->result['completion']); // Added
        $expectedSuggestions = ['apple', 'apricot'];
        $this->assertEquals($expectedSuggestions, $response->result['completion']['values']);
        $this->assertEquals(count($expectedSuggestions), $response->result['completion']['total']);
        $this->assertFalse($response->result['completion']['hasMore']);
    }

    public function testHandleCompleteWithNullIdIsNotification(): void
    {
        $request = new JsonRpcMessage(
            'completion/complete',
            [
                'ref' => ['type' => 'ref/prompt', 'name' => 'test'],
                'argument' => ['name' => 'data', 'value' => 'ap']
            ],
            null // ID is null
        );
        // Use the capability instance from setUp, which has MockTool registered
        $response = $this->capability->handleMessage($request);

        $this->assertNull($response, "handleMessage should return null for a completion/complete notification (null id)");
    }

    public function testHandleCompleteDefaultSuggestions(): void
    {
        $basicTool = new #[ToolAttribute('basic', 'Basic Tool')] class extends Tool {
            /**
             * @return Content\ContentItemInterface
             */
            protected function doExecute(array $arguments): \MCP\Server\Tool\Content\ContentItemInterface
            {
                return $this->text('done');
            }
        };
        $this->capability->addTool($basicTool); // Add to the existing capability instance

        $request = new JsonRpcMessage(
            'completion/complete',
            [
            'ref' => ['type' => 'ref/prompt', 'name' => 'basic'],
            'argument' => ['name' => 'some_arg', 'value' => 'any']
            ],
            'comp2'
        );
        $response = $this->capability->handleMessage($request);

        $this->assertNotNull($response); // Ensure response is not null
        $this->assertNull($response->error);
        $this->assertNotNull($response->result, "Result should not be null for completion/complete (default)."); // Added
        $this->assertArrayHasKey('completion', $response->result);
        $this->assertEquals(['values' => [], 'total' => 0, 'hasMore' => false], $response->result['completion']);
    }

    public function testHandleCompleteToolNotFound(): void
    {
        $request = new JsonRpcMessage(
            'completion/complete',
            [
            'ref' => ['type' => 'ref/prompt', 'name' => 'unknownToolForCompletion'],
            'argument' => ['name' => 'arg', 'value' => 'val']
            ],
            'comp3'
        );
        $response = $this->capability->handleMessage($request);

        $this->assertNotNull($response); // Ensure response is not null
        $this->assertNotNull($response->error); // The $response object itself should have its error property set
        $this->assertNull($response->result);   // For an error response, result should be null
        // $response->error is now known non-null array
        $this->assertEquals(JsonRpcMessage::METHOD_NOT_FOUND, $response->error['code']);
        self::assertIsString($response->error['message']); // Ensure message is a string
        $this->assertStringContainsString('Tool not found for completion: unknownToolForCompletion', $response->error['message']);
    }

    /**
     * @return array<string, array<mixed>>
     */
    public static function provideHandleCompleteInvalidParamsCases(): array
    {
        $baseValidRef = ['type' => 'ref/prompt', 'name' => 'test'];
        $baseValidArgument = ['name' => 'data', 'value' => 'ap'];

        return [
            // ref parameter
            'missing ref' => [['argument' => $baseValidArgument], JsonRpcMessage::INVALID_PARAMS, 'Missing or invalid "ref" parameter for completion/complete'],
            'ref not an array' => [['ref' => 'not-an-array', 'argument' => $baseValidArgument], JsonRpcMessage::INVALID_PARAMS, 'Missing or invalid "ref" parameter for completion/complete'],
            // argument parameter
            'missing argument' => [['ref' => $baseValidRef], JsonRpcMessage::INVALID_PARAMS, 'Missing or invalid "argument" parameter for completion/complete'],
            'argument not an array' => [['ref' => $baseValidRef, 'argument' => 'not-an-array'], JsonRpcMessage::INVALID_PARAMS, 'Missing or invalid "argument" parameter for completion/complete'],
            // ref.type
            'ref missing type' => [['ref' => ['name' => 'test'], 'argument' => $baseValidArgument], JsonRpcMessage::INVALID_PARAMS, 'Invalid "ref.type" for completion/complete'],
            'ref type not a string' => [['ref' => ['type' => 123, 'name' => 'test'], 'argument' => $baseValidArgument], JsonRpcMessage::INVALID_PARAMS, 'Invalid "ref.type" for completion/complete'],
            'ref type invalid' => [['ref' => ['type' => 'invalid/type', 'name' => 'test'], 'argument' => $baseValidArgument], JsonRpcMessage::INVALID_PARAMS, 'Unsupported "ref.type" for tool completion: invalid/type'],
            // ref.name
            'ref missing name' => [['ref' => ['type' => 'ref/prompt'], 'argument' => $baseValidArgument], JsonRpcMessage::INVALID_PARAMS, 'Missing or invalid "ref.name" (tool name) for completion/complete'],
            'ref name not a string' => [['ref' => ['type' => 'ref/prompt', 'name' => 123], 'argument' => $baseValidArgument], JsonRpcMessage::INVALID_PARAMS, 'Missing or invalid "ref.name" (tool name) for completion/complete'],
            // argument.name
            'argument missing name' => [['ref' => $baseValidRef, 'argument' => ['value' => 'ap']], JsonRpcMessage::INVALID_PARAMS, 'Missing or invalid "argument.name" for completion/complete'],
            'argument name not a string' => [['ref' => $baseValidRef, 'argument' => ['name' => 123, 'value' => 'ap']], JsonRpcMessage::INVALID_PARAMS, 'Missing or invalid "argument.name" for completion/complete'],
        ];
    }

    /**
     * @dataProvider provideHandleCompleteInvalidParamsCases
     * @param array<string, mixed> $params
     */
    public function testHandleCompleteWithInvalidParams(array $params, int $expectedErrorCode, string $expectedErrorMessageSubstring): void
    {
        $request = new JsonRpcMessage('completion/complete', $params, 'comp_invalid');
        $response = $this->capability->handleMessage($request);

        $this->assertNotNull($response); // Ensure response is not null
        $this->assertNotNull($response->error, "Expected an error response for params: " . json_encode($params));
        $this->assertNull($response->result);
        // $response->error is now known non-null array
        $this->assertEquals($expectedErrorCode, $response->error['code']);
        self::assertIsString($response->error['message']); // Ensure message is a string
        $this->assertStringContainsString($expectedErrorMessageSubstring, $response->error['message']);
    }

    public function testHandleCompleteWithToolReturningInvalidSuggestionsStructure(): void
    {
        // Case: Tool's getSuggestions returns something that is not an array (e.g. null or string)
        // Note: The Tool abstract class typehints getSuggestions to return array.
        // This test is more about what happens if the array structure is not as expected.
        // Test when 'values' is not an array.
        $invalidSuggestionsTool = new InvalidSuggestionsTool(['values' => 'not-an-array']);
        $this->capability->addTool($invalidSuggestionsTool);

        $request = new JsonRpcMessage(
            'completion/complete',
            [
                'ref' => ['type' => 'ref/tool', 'name' => 'invalidSuggestionsTool'],
                'argument' => ['name' => 'someArg', 'value' => 'a']
            ],
            'comp_invalid_structure'
        );
        $response = $this->capability->handleMessage($request);

        $this->assertNotNull($response); // Ensure response is not null
        $this->assertNotNull($response->error);
        $this->assertNull($response->result);
        // $response->error is now known non-null array
        $this->assertEquals(JsonRpcMessage::INTERNAL_ERROR, $response->error['code']);
        self::assertIsString($response->error['message']); // Ensure message is a string
        $this->assertStringContainsString("Tool 'invalidSuggestionsTool' returned suggestions with invalid structure. 'values' key is missing or not an array.", $response->error['message']);
    }

    public function testHandleCompleteWithToolReturningInvalidSuggestionsValuesType(): void
    {
        // Case: Tool's getSuggestions returns ['values' => 'not-an-array']
        // To make this pass the constructor type hint, we need 'values' to be an array,
        // but the test is for when the *content* of 'values' is wrong *inside* the tool's logic.
        // However, the constructor now strictly type-checks.
        // This test might need rethinking or the InvalidSuggestionsTool needs to be more flexible
        // for testing invalid internal states vs. invalid construction.
        // For now, let's make it constructible and assume the tool *internally* produces a bad 'values' type.
        // This specific error is now caught by the constructor type hint.
        // So, we can't directly test the runtime check in ToolsCapability for this exact scenario via constructor.
        // Let's adjust the test to reflect what can be passed to constructor,
        // and accept that this particular internal error path in ToolsCapability might be hard to reach
        // if constructor validation is robust.
        $customBadTool = new #[ToolAttribute('customBadValuesTool', 'Tool with bad values type')] class extends Tool
        {
            /**
             * @return array<Content\ContentItemInterface>
             */
            protected function doExecute(array $arguments): array
            {
                return [];
            }
            public function getCompletionSuggestions(string $argumentName, mixed $currentValue, array $allCurrentArguments = []): array
            {
                // @phpstan-ignore-next-line Incorrect return type (array<int> for values) is intended for testing robustness of ToolsCapability.
                return ['values' => [123]]; // Directly return the problematic structure
            }
        };
        $this->capability->addTool($customBadTool);

        $request = new JsonRpcMessage(
            'completion/complete',
            [
                'ref' => ['type' => 'ref/tool', 'name' => 'customBadValuesTool'],
                'argument' => ['name' => 'someArg', 'value' => 'a']
            ],
            'comp_invalid_values_type'
        );
        $response = $this->capability->handleMessage($request);

        $this->assertNotNull($response); // Ensure response is not null
        $this->assertNotNull($response->error);
        $this->assertNull($response->result);
        // $response->error is now known non-null array
        $this->assertEquals(JsonRpcMessage::INTERNAL_ERROR, $response->error['code']);
        self::assertIsString($response->error['message']); // Ensure message is a string
        $this->assertStringContainsString("Tool 'customBadValuesTool' returned suggestions where 'values' contains non-string elements.", $response->error['message']);
    }

    public function testHandleCompleteWithToolReturningNonArraySuggestions(): void
    {
        $nonArraySuggestionsTool = new #[ToolAttribute('nonArraySuggestionsTool', 'Tool returning non-array suggestions')] class extends Tool {
            /**
             * @return array<Content\ContentItemInterface>
             */
            protected function doExecute(array $arguments): array
            {
                return []; // Not called in this test path
            }

            // Declare as returning array to satisfy PHP's inheritance rules,
            // but actually return a string to test the error handling in ToolsCapability.
            public function getCompletionSuggestions(string $argumentName, mixed $currentValue, array $allCurrentArguments = []): array
            {
                // @phpstan-ignore-next-line Deliberately returning a non-array to test error handling
                return "invalid_suggestions_not_an_array"; // @phpstan-ignore-line
            }
        };

        $capability = new ToolsCapability();
        $capability->addTool($nonArraySuggestionsTool);

        $request = new JsonRpcMessage(
            'completion/complete',
            [
                'ref' => ['type' => 'ref/tool', 'name' => 'nonArraySuggestionsTool'],
                'argument' => ['name' => 'someArg', 'value' => 'a']
            ],
            'comp_non_array_sugg'
        );

        $response = $capability->handleMessage($request);

        $this->assertNotNull($response, "Response should not be null for a request with an ID.");
        $this->assertNotNull($response->error, "Error should be populated when suggestions are not an array.");
        $this->assertNull($response->result, "Result should be null when an error occurs.");

        $this->assertEquals(JsonRpcMessage::INTERNAL_ERROR, $response->error['code']);
        $this->assertStringContainsString(
            "Tool 'nonArraySuggestionsTool' returned suggestions that is not an array.",
            $response->error['message']
        );
    }

    public function testCanHandleMessageWithValidMethods(): void
    {
        $validMethods = [
            'tools/list',
            'tools/call',
            'completion/complete',
        ];

        foreach ($validMethods as $method) {
            $request = new JsonRpcMessage($method, [], '1');
            $this->assertTrue($this->capability->canHandleMessage($request), "Failed for method: {$method}");
        }
    }

    public function testCanHandleMessageWithInvalidMethod(): void
    {
        $request = new JsonRpcMessage('tools/invalid_method', [], '1');
        $this->assertFalse($this->capability->canHandleMessage($request));
    }
}
