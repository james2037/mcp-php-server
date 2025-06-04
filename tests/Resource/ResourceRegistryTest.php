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

    public function testDiscoverSkipsProblematicFiles(): void
    {
        // Directory containing files that should not be registered as resources
        $testFilesDir = __DIR__ . '/../Registry/DiscoveryTestFiles';

        // Attempt to discover resources in the directory with problematic files
        $this->registry->discover($testFilesDir);

        // Assert that no resources were registered from these files
        $resources = $this->registry->getResources();
        $this->assertCount(0, $resources, "Registry should be empty after discovering problematic files.");
    }

    public function testDiscoverSkipsResourceWithoutUriAttribute(): void
    {
        // Define the directory where NoAttributeResource.php is located
        $testFilesDir = __DIR__ . '/DiscoveryTestFiles'; // Adjusted path

        // Ensure the directory exists for the test context (it should, as NoAttributeResource.php was created there)
        if (!is_dir($testFilesDir)) {
            mkdir($testFilesDir, 0777, true); // Create if it doesn't exist, though it should
        }

        // Attempt to discover resources in the directory
        $this->registry->discover($testFilesDir);

        // Assert that no resources were registered because NoAttributeResource lacks the ResourceUri attribute
        $resources = $this->registry->getResources();
        $this->assertCount(0, $resources, "Registry should be empty as NoAttributeResource lacks ResourceUri attribute.");
    }

    public function testGetItemKeyThrowsOnInvalidItemType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Item must be an instance of ' . Resource::class);

        $registry = new ResourceRegistry(); // Use a fresh instance for this specific test
        $method = new \ReflectionMethod(ResourceRegistry::class, 'getItemKey');
        $method->setAccessible(true);

        $invalidItem = new \stdClass();
        $method->invoke($registry, $invalidItem);
    }
}
