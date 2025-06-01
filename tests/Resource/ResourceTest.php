<?php

namespace MCP\Server\Tests\Resource;

use MCP\Server\Resource\Resource;
use MCP\Server\Resource\ResourceContents;
use MCP\Server\Resource\TextResourceContents;
use MCP\Server\Resource\BlobResourceContents;
use MCP\Server\Resource\Attribute\ResourceUri;
use PHPUnit\Framework\TestCase;

// TestResource and DynamicResource are now in separate files.

class ResourceTest extends TestCase
{
    public function testResourceMetadata(): void
    {
        $resource = new TestResource("test_static_resource");

        $this->assertEquals('test://static', $resource->getUri());
        $this->assertNull($resource->getDescription()); // No description provided
    }

    public function testTextContentCreation(): void
    {
        $resource = new TestResource("test_static_resource_content");
        $result = $resource->read();

        $this->assertInstanceOf(TextResourceContents::class, $result);
        $this->assertEquals('test://static', $result->uri);
        $this->assertEquals('Static content', $result->text);
    }

    public function testParameterizedResource(): void
    {
        $resource = new DynamicResource("test_dynamic_resource_param");
        $result = $resource->read(['userId' => '123']);

        $this->assertInstanceOf(TextResourceContents::class, $result);
        $this->assertEquals('test://users/123/profile', $result->uri);
        $this->assertEquals('Profile for user 123', $result->text);
    }

    public function testBlobContentCreation(): void
    {
        $resource = new #[ResourceUri('test://image')] class ("test_blob_resource_anon") extends Resource {
            public function read(array $parameters = []): ResourceContents
            {
                return $this->blob('binary data', 'image/png');
            }
        };

        $result = $resource->read();
        $this->assertInstanceOf(BlobResourceContents::class, $result);
        $this->assertEquals('image/png', $result->mimeType);
        $this->assertEquals('binary data', $result->blob);
    }
}
