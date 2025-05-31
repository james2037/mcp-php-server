<?php

namespace MCP\Server\Tests\Resource;

use MCP\Server\Resource\ResourceRegistry;
use MCP\Server\Resource\Resource;
use MCP\Server\Resource\ResourceContents;
use MCP\Server\Resource\Attribute\ResourceUri;
use PHPUnit\Framework\TestCase;

// MockResource and OtherMockResource are now in separate files.

class ResourceRegistryTest extends TestCase
{
    private ResourceRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ResourceRegistry();
    }

    public function testRegister(): void
    {
        $resource = new MockResource("mock_resource_name_test_register");
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
                // Ensure constructor matches parent or is defined if custom logic is needed
                public function __construct(string \$name, ?string \$mimeType = null, ?int \$size = null, ?\MCP\Server\Tool\Content\Annotations \$annotations = null, ?array \$config = null) {
                    parent::__construct(\$name, \$mimeType, \$size, \$annotations, \$config);
                }

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
            // Ensure DiscoveredResource.php is deleted even if the test fails
            if (file_exists($tempDir . '/DiscoveredResource.php')) {
                unlink($tempDir . '/DiscoveredResource.php');
            }
            rmdir($tempDir);
        }
    }
}
