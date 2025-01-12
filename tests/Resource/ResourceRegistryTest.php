<?php

namespace MCP\Server\Tests\Resource;

use MCP\Server\Resource\ResourceRegistry;
use MCP\Server\Resource\Resource;
use MCP\Server\Resource\ResourceContents;
use MCP\Server\Resource\Attribute\ResourceUri;
use PHPUnit\Framework\TestCase;

#[ResourceUri('test://one')]
class MockResource extends Resource
{
    public function read(array $parameters = []): ResourceContents
    {
        return $this->text('Resource One');
    }
}

#[ResourceUri('test://two')]
class OtherMockResource extends Resource
{
    public function read(array $parameters = []): ResourceContents
    {
        return $this->text('Resource Two');
    }
}

class ResourceRegistryTest extends TestCase
{
    private ResourceRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ResourceRegistry();
    }

    public function testRegister(): void
    {
        $resource = new MockResource();
        $this->registry->register($resource);

        $resources = $this->registry->getResources();
        $this->assertCount(1, $resources);
        $this->assertArrayHasKey('test://one', $resources);
        $this->assertSame($resource, $resources['test://one']);
    }

    public function testDiscoverResources(): void
    {
        $tempDir = sys_get_temp_dir() . '/resource-test-' . uniqid();
        mkdir($tempDir);

        try {
            $resourceContent = <<<PHP
            <?php
            namespace MCP\\Server\\Tests\\Resource;
            
            use MCP\\Server\\Resource\\Resource;
            use MCP\\Server\\Resource\\ResourceContents;
            use MCP\\Server\\Resource\\Attribute\\ResourceUri;
            
            #[ResourceUri('test://discovered')]
            class DiscoveredResource extends Resource {
                public function read(array \$parameters = []): ResourceContents {
                    return \$this->text('discovered');
                }
            }
            PHP;

            file_put_contents($tempDir . '/DiscoveredResource.php', $resourceContent);

            $this->registry->discover($tempDir);

            $resources = $this->registry->getResources();
            $this->assertCount(1, $resources);
            $this->assertArrayHasKey('test://discovered', $resources);
        } finally {
            unlink($tempDir . '/DiscoveredResource.php');
            rmdir($tempDir);
        }
    }
}
