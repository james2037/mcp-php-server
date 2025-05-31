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

class ResourcesCapabilityTest extends TestCase
{
    private ResourcesCapability $_capability;

    protected function setUp(): void
    {
        $this->_capability = new ResourcesCapability();
        $staticAnnotations = new Annotations(audience: ['user'], priority: 0.7);
        // Pass name, mimeType, size, annotations to ResourcesCapabilityMockResource constructor
        $this->_capability->addResource(
            new ResourcesCapabilityMockResource('Static Test Resource', 'text/plain', 123, $staticAnnotations)
        );
    }

    public function testGetCapabilities(): void
    {
        $caps = $this->_capability->getCapabilities();
        $this->assertArrayHasKey('resources', $caps);
        $this->assertArrayHasKey('subscribe', $caps['resources']);
        $this->assertArrayHasKey('listChanged', $caps['resources']);
    }

    public function testHandleList(): void
    {
        $request = new JsonRpcMessage('resources/list', [], '1');
        $response = $this->_capability->handleMessage($request);

        $this->assertNotNull($response);
        $this->assertNull($response->error);
        $this->assertIsArray($response->result['resources']);
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
        $response = $this->_capability->handleMessage($request);

        $this->assertNotNull($response);
        $this->assertNull($response->error);
        $this->assertArrayHasKey('contents', $response->result);
        $this->assertIsArray($response->result['contents']);
        $this->assertCount(1, $response->result['contents']);

        $contentItem = $response->result['contents'][0];
        $this->assertEquals('test://static', $contentItem['uri']);
        $this->assertEquals('text/plain', $contentItem['mimeType']);
        $this->assertEquals('Static content', $contentItem['text']);
    }

    public function testHandleReadWithParameters(): void
    {
        $this->_capability->addResource(
            new ResourcesCapabilityParameterizedResource("User Data", "application/json")
        );
        $request = new JsonRpcMessage(
            'resources/read',
            ['uri' => 'test://users/123'],
            '1'
        );
        $response = $this->_capability->handleMessage($request);

        $this->assertNotNull($response);
        $this->assertNull($response->error);
        $this->assertArrayHasKey('contents', $response->result);
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
        $response = $this->_capability->handleMessage($request);

        $this->assertNotNull($response);
        $this->assertNotNull($response->error, "Response should be an error object.");
        $this->assertEquals(JsonRpcMessage::INVALID_PARAMS, $response->error['code']); // Or a more specific not_found
        $this->assertStringContainsString('Resource not found', $response->error['message']);
    }

    public function testHandleMissingUri(): void
    {
        $request = new JsonRpcMessage('resources/read', [], '1');
        $response = $this->_capability->handleMessage($request);

        $this->assertNotNull($response);
        $this->assertNotNull($response->error, "Response should be an error object.");
        $this->assertEquals(JsonRpcMessage::INVALID_PARAMS, $response->error['code']);
        $this->assertStringContainsString('Missing uri parameter', $response->error['message']);
    }
}
