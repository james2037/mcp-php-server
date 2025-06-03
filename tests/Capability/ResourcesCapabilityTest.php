<?php

namespace MCP\Server\Tests\Capability;

use MCP\Server\Capability\ResourcesCapability;
use MCP\Server\Message\JsonRpcMessage;
use MCP\Server\Resource\Resource;
use MCP\Server\Resource\ResourceContents;
use MCP\Server\Resource\Attribute\ResourceUri;
use MCP\Server\Resource\TextResourceContents;
use MCP\Server\Tool\Content\Annotations;
use PHPUnit\Framework\TestCase;
// Helper classes moved to separate files
use MCP\Server\Tests\Capability\ResourcesCapabilityMockResource;
use MCP\Server\Tests\Capability\ResourcesCapabilityParameterizedResource;
use MCP\Server\Tests\Capability\ResourcesCapabilityErrorOnReadResource;

class ResourcesCapabilityTest extends TestCase
{
    private ResourcesCapability $capability;

    protected function setUp(): void
    {
        $this->capability = new ResourcesCapability();
        $staticAnnotations = new Annotations(audience: ['user'], priority: 0.7);
        // Pass name, mimeType, size, annotations to ResourcesCapabilityMockResource constructor
        $this->capability->addResource(
            new ResourcesCapabilityMockResource('Static Test Resource', 'text/plain', 123, $staticAnnotations)
        );
    }

    public function testGetCapabilities(): void
    {
        $caps = $this->capability->getCapabilities();
        $this->assertArrayHasKey('resources', $caps);
        $this->assertArrayHasKey('subscribe', $caps['resources']);
        $this->assertArrayHasKey('listChanged', $caps['resources']);
    }

    public function testHandleList(): void
    {
        $request = new JsonRpcMessage('resources/list', [], '1');
        $response = $this->capability->handleMessage($request);

        $this->assertNotNull($response);
        $this->assertNull($response->error);
        self::assertIsArray($response->result); // Ensure result is an array when error is null
        $this->assertIsArray($response->result['resources']); // This was the target for "Offset 'resources' does not exist on array|null" if $response->result was not confirmed as array
        $this->assertCount(1, $response->result['resources']);
        $resourceData = $response->result['resources'][0];

        $this->assertEquals('test://static', $resourceData['uri']);
        $this->assertEquals('Static Test Resource', $resourceData['name']);
        $this->assertEquals('A static mock resource', $resourceData['description']);
        $this->assertEquals('text/plain', $resourceData['mimeType']);
        $this->assertEquals(123, $resourceData['size']);
        $this->assertArrayHasKey('annotations', $resourceData);
        $this->assertEquals(['user'], $resourceData['annotations']['audience']);
        $this->assertEquals(0.7, $resourceData['annotations']['priority']);
    }

    public function testHandleRead(): void
    {
        $request = new JsonRpcMessage(
            'resources/read',
            ['uri' => 'test://static'],
            '1'
        );
        $response = $this->capability->handleMessage($request);

        $this->assertNotNull($response);
        $this->assertNull($response->error);
        self::assertIsArray($response->result); // Ensure result is an array when error is null (Corrected: remove duplicate)
        $this->assertArrayHasKey('contents', $response->result); // Target for "Parameter #2 $array of method PHPUnit\Framework\Assert::assertArrayHasKey() expects array|ArrayAccess, array|null given."
        $this->assertIsArray($response->result['contents']);
        $this->assertCount(1, $response->result['contents']);

        $contentItem = $response->result['contents'][0];
        $this->assertEquals('test://static', $contentItem['uri']);
        $this->assertEquals('text/plain', $contentItem['mimeType']);
        $this->assertEquals('Static content', $contentItem['text']);
    }

    public function testHandleReadWithParameters(): void
    {
        $this->capability->addResource(
            new ResourcesCapabilityParameterizedResource("User Data", "application/json")
        );
        $request = new JsonRpcMessage(
            'resources/read',
            ['uri' => 'test://users/123'],
            '1'
        );
        $response = $this->capability->handleMessage($request);

        $this->assertNotNull($response);
        $this->assertNull($response->error);
        self::assertIsArray($response->result); // Ensure result is an array when error is null
        $this->assertArrayHasKey('contents', $response->result); // Target for "Parameter #2 $array of method PHPUnit\Framework\Assert::assertArrayHasKey() expects array|ArrayAccess, array|null given."
        $this->assertIsArray($response->result['contents']);
        $this->assertCount(1, $response->result['contents']);

        $contentItem = $response->result['contents'][0];
        // The URI in TextResourceContents is resolved by Resource::text/blob using the *template* and parameters
        // So it will be the template 'test://users/{userId}' not the fully resolved 'test://users/123'
        // This might be a point of discussion for schema vs implementation detail.
        // For now, assuming the URI in ResourceContents is the *template* URI.
        // If it should be the *resolved* URI, then Resource::text/blob helpers need adjustment.
        // The current Resource::text/blob implementation does pass the resolved URI.
        $this->assertEquals('test://users/123', $contentItem['uri']);
        $this->assertEquals('application/json', $contentItem['mimeType']);
        $this->assertEquals('User 123', $contentItem['text']);
    }

    public function testHandleReadUnknownResource(): void
    {
        $request = new JsonRpcMessage(
            'resources/read',
            ['uri' => 'test://unknown'],
            '1'
        );
        $response = $this->capability->handleMessage($request);

        $this->assertNotNull($response);
        $this->assertNotNull($response->error, "Response should be an error object.");
        self::assertIsArray($response->error);
        self::assertArrayHasKey('message', $response->error);
        self::assertIsString($response->error['message']); // Target for "Parameter #2 $haystack of method PHPUnit\Framework\Assert::assertStringContainsString() expects string, mixed given."
        $this->assertEquals(JsonRpcMessage::INVALID_PARAMS, $response->error['code']); // Or a more specific not_found
        $this->assertStringContainsString('Resource not found', $response->error['message']);
    }

    public function testHandleMissingUri(): void
    {
        $request = new JsonRpcMessage('resources/read', [], '1');
        $response = $this->capability->handleMessage($request);

        $this->assertNotNull($response);
        $this->assertNotNull($response->error, "Response should be an error object.");
        self::assertIsArray($response->error);
        self::assertArrayHasKey('message', $response->error);
        self::assertIsString($response->error['message']); // Target for "Parameter #2 $haystack of method PHPUnit\Framework\Assert::assertStringContainsString() expects string, mixed given."
        $this->assertEquals(JsonRpcMessage::INVALID_PARAMS, $response->error['code']);
        $this->assertStringContainsString('Missing uri parameter', $response->error['message']);
    }

    public function testCanHandleMessageWithListMethod(): void
    {
        $request = new JsonRpcMessage('resources/list', [], '1');
        $this->assertTrue($this->capability->canHandleMessage($request));
    }

    public function testCanHandleMessageWithReadMethod(): void
    {
        $request = new JsonRpcMessage('resources/read', ['uri' => 'test://static'], '1');
        $this->assertTrue($this->capability->canHandleMessage($request));
    }

    public function testCanHandleMessageWithUnsupportedMethod(): void
    {
        $request = new JsonRpcMessage('unsupported/method', [], '1');
        $this->assertFalse($this->capability->canHandleMessage($request));
    }

    public function testInitializeRunsWithoutExceptions(): void
    {
        $exception = null;
        try {
            $this->capability->initialize();
        } catch (\Throwable $exception) {
            // Exception is captured
        }
        $this->assertNull($exception, "ResourcesCapability::initialize() should not throw an exception.");
    }

    public function testShutdownRunsWithoutExceptions(): void
    {
        $exception = null;
        try {
            $this->capability->shutdown();
        } catch (\Throwable $exception) {
            // Exception is captured
        }
        $this->assertNull($exception, "ResourcesCapability::shutdown() should not throw an exception.");
    }

    public function testHandleReadThrowsException(): void
    {
        $this->capability->addResource(
            new ResourcesCapabilityErrorOnReadResource('Error Resource', 'text/plain')
        );

        $request = new JsonRpcMessage(
            'resources/read',
            ['uri' => 'test://erroronread'],
            '1'
        );
        $response = $this->capability->handleMessage($request);

        $this->assertNotNull($response);
        $this->assertNotNull($response->error, "Response should be an error object.");
        self::assertIsArray($response->error);
        self::assertArrayHasKey('message', $response->error);
        self::assertIsString($response->error['message']); // Target for "Parameter #2 $haystack of method PHPUnit\Framework\Assert::assertStringContainsString() expects string, mixed given."
        $this->assertEquals(JsonRpcMessage::INTERNAL_ERROR, $response->error['code']);
        $this->assertStringContainsString('Error reading resource test://erroronread: Mock error reading resource', $response->error['message']);
    }

    public function testHandleListWithNullIdReturnsError(): void
    {
        $request = new JsonRpcMessage("resources/list", [], null);
        $response = $this->capability->handleMessage($request);

        $this->assertNotNull($response);
        $this->assertNotNull($response->error, "Response should be an error object.");
        self::assertIsArray($response->error);
        self::assertArrayHasKey('message', $response->error);
        self::assertIsString($response->error['message']); // Target for "Parameter #2 $haystack of method PHPUnit\Framework\Assert::assertStringContainsStringIgnoringCase() expects string, mixed given."
        $this->assertEquals(JsonRpcMessage::INTERNAL_ERROR, $response->error["code"]);
        $this->assertStringContainsStringIgnoringCase("request id is missing", $response->error["message"]);
        $this->assertNull($response->id);
    }

    public function testHandleReadWithNullIdReturnsError(): void
    {
        $request = new JsonRpcMessage(
            "resources/read",
            ["uri" => "test://static"],
            null
        );
        $response = $this->capability->handleMessage($request);

        $this->assertNotNull($response);
        $this->assertNotNull($response->error, "Response should be an error object.");
        self::assertIsArray($response->error);
        self::assertArrayHasKey('message', $response->error);
        self::assertIsString($response->error['message']); // Target for "Parameter #2 $haystack of method PHPUnit\Framework\Assert::assertStringContainsStringIgnoringCase() expects string, mixed given."
        $this->assertEquals(JsonRpcMessage::INTERNAL_ERROR, $response->error["code"]);
        $this->assertStringContainsStringIgnoringCase("request id is missing", $response->error["message"]);
        $this->assertNull($response->id);
    }

    public function testHandleReadWithNonStringUriReturnsError(): void
    {
        $request = new JsonRpcMessage(
            "resources/read",
            ["uri" => 12345],
            "id_non_string_uri"
        );
        $response = $this->capability->handleMessage($request);

        $this->assertNotNull($response);
        $this->assertNotNull($response->error, "Response should be an error object for non-string URI.");
        self::assertIsArray($response->error);
        self::assertArrayHasKey('message', $response->error);
        self::assertIsString($response->error['message']); // Target for "Parameter #2 $haystack of method PHPUnit\Framework\Assert::assertStringContainsString() expects string, mixed given."
        $this->assertEquals(JsonRpcMessage::INVALID_PARAMS, $response->error["code"]);
        $this->assertStringContainsString("URI parameter must be a string", $response->error["message"]);
    }

    public function testHandleReadWithArrayUriReturnsError(): void
    {
        $request = new JsonRpcMessage(
            "resources/read",
            ["uri" => ["should_be_string"]],
            "id_array_uri"
        );
        $response = $this->capability->handleMessage($request);

        $this->assertNotNull($response);
        $this->assertNotNull($response->error, "Response should be an error object for array URI.");
        self::assertIsArray($response->error);
        self::assertArrayHasKey('message', $response->error);
        self::assertIsString($response->error['message']); // Target for "Parameter #2 $haystack of method PHPUnit\Framework\Assert::assertStringContainsString() expects string, mixed given."
        $this->assertEquals(JsonRpcMessage::INVALID_PARAMS, $response->error["code"]);
        $this->assertStringContainsString("URI parameter must be a string", $response->error["message"]);
    }
}
