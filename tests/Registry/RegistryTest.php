<?php

declare(strict_types=1);

namespace MCP\Server\Tests\Registry;

use MCP\Server\Registry\Registry;
use PHPUnit\Framework\TestCase;
use MCP\Server\Tests\Registry\Helpers\ConcreteTestRegistry;
use MCP\Server\Tests\Registry\Helpers\DiscoverableTestClassForRegistry;
use MCP\Server\Tests\Registry\Helpers\AnotherDiscoverableTestClassForRegistry;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class RegistryTest extends TestCase
{
    private ConcreteTestRegistry $registry;
    private string $tempTestDir = '';

    protected function setUp(): void
    {
        $this->registry = new ConcreteTestRegistry();
        // Create a temporary directory for discovery tests
        $this->tempTestDir = sys_get_temp_dir() . '/registry_test_' . uniqid();
        if (!mkdir($this->tempTestDir, 0777, true) && !is_dir($this->tempTestDir)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $this->tempTestDir));
        }
    }

    protected function tearDown(): void
    {
        // Clean up the temporary directory
        if (is_dir($this->tempTestDir)) {
            $this->deleteDirectory($this->tempTestDir);
        }
    }

    public function testRegisterAndGetItem(): void
    {
        $item1 = new \stdClass();
        $item1->name = 'item1_key'; // Ensure it has a 'name' for getItemKey

        $this->registry->register($item1);

        $retrievedItem = $this->registry->get('item1_key');
        $this->assertSame($item1, $retrievedItem, "Should retrieve the registered item by its key.");
    }

    public function testGetNonExistentItem(): void
    {
        $retrievedItem = $this->registry->get('non_existent_key');
        $this->assertNull($retrievedItem, "Should return null for a non-existent key.");
    }

    public function testRegisterAndGetAllItems(): void
    {
        $item1 = new \stdClass();
        $item1->name = 'item1';
        $item2 = new \stdClass();
        $item2->name = 'item2';

        $this->registry->register($item1);
        $this->registry->register($item2);

        // Access items through a public method in the test registry if available,
        // or by individual 'get' calls.
        // For this test, we'll use an anonymous class that exposes getItems().
        $registryWithPublicGetItems = new class () extends ConcreteTestRegistry {
            /** @return array<string, object> */
            public function getAllPublic(): array
            {
                return $this->getItems();
            }
        };
        $registryWithPublicGetItems->register($item1);
        $registryWithPublicGetItems->register($item2);

        $allItemsFetched = $registryWithPublicGetItems->getAllPublic();
        $this->assertCount(2, $allItemsFetched, "Should have 2 items registered.");
        $this->assertSame($item1, $allItemsFetched['item1'], "Item1 should be retrievable.");
        $this->assertSame($item2, $allItemsFetched['item2'], "Item2 should be retrievable.");
    }

    public function testRegisterOverwritesExistingItem(): void
    {
        $item1 = new \stdClass();
        $item1->name = 'item_key';
        $item2 = new \stdClass();
        $item2->name = 'item_key'; // Same key

        $this->registry->register($item1);
        $this->assertSame($item1, $this->registry->get('item_key'), "First item should be retrievable.");

        $this->registry->register($item2);
        $this->assertSame($item2, $this->registry->get('item_key'), "Second item should overwrite and be retrievable.");

        // Verify count using the anonymous class trick for getItems()
        $registryWithPublicGetItems = new class () extends ConcreteTestRegistry {
            /** @return array<string, object> */
            public function getAllPublic(): array
            {
                return $this->getItems();
            }
        };
        $registryWithPublicGetItems->register($item1); // item1
        $registryWithPublicGetItems->register($item2); // item2 overwrites item1 due to same key

        $allItemsFetched = $registryWithPublicGetItems->getAllPublic();
        $this->assertCount(1, $allItemsFetched, "Should only have 1 item due to overwrite.");
        $this->assertSame($item2, $allItemsFetched['item_key'], "The second item should be the one present.");
    }

    private function createTempFile(string $filename, string $content): string
    {
        $filepath = $this->tempTestDir . DIRECTORY_SEPARATOR . $filename;
        file_put_contents($filepath, $content);
        return $filepath;
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }
        rmdir($dir);
    }

    public function testDiscoverItems(): void
    {
        $classContent1 = <<<PHP
<?php
namespace MCP\\Server\\Tests\\Registry\\Temp; // Use a unique namespace for temp files
use MCP\\Server\\Tests\\Registry\\Helpers\\DiscoverableTestClassForRegistry; // This might be problematic if not careful with paths
class TestDiscover1 extends \MCP\Server\Tests\Registry\Helpers\DiscoverableTestClassForRegistry {}
PHP;
        $this->createTempFile('TestDiscover1.php', $classContent1);

        $classContent2 = <<<PHP
<?php
namespace MCP\\Server\\Tests\\Registry\\Temp;
use MCP\Server\\Tests\\Registry\\Helpers\\AnotherDiscoverableTestClassForRegistry;
class TestDiscover2 extends \MCP\Server\Tests\Registry\Helpers\AnotherDiscoverableTestClassForRegistry {}
PHP;
        $this->createTempFile('TestDiscover2.php', $classContent2);

        // Create a non-PHP file to be ignored
        $this->createTempFile('NonPHPFile.txt', 'This is not a PHP file.');

        // Create a PHP file with no class
        $this->createTempFile('NoClass.php', '<?php echo "No class here";');

        // Create a PHP file with an abstract class (should be ignored by ConcreteTestRegistry::createFromReflection)
        $abstractClassContent = <<<PHP
<?php
namespace MCP\\Server\\Tests\\Registry\\Temp;
abstract class AbstractDiscoverableClass { public function __construct() {} }
PHP;
        $this->createTempFile('AbstractDiscoverable.php', $abstractClassContent);


        $this->registry->discover($this->tempTestDir);

        // Check the original $this->registry for discovered items
        $discoveredItem1 = $this->registry->get('MCP\\Server\\Tests\\Registry\\Temp\\TestDiscover1');
        $discoveredItem2 = $this->registry->get('MCP\\Server\\Tests\\Registry\\Temp\\TestDiscover2');
        $nonExistentItem = $this->registry->get('MCP\\Server\\Tests\\Registry\\Temp\\AbstractDiscoverableClass');

        $this->assertInstanceOf(DiscoverableTestClassForRegistry::class, $discoveredItem1);
        $this->assertInstanceOf(AnotherDiscoverableTestClassForRegistry::class, $discoveredItem2);
        $this->assertNull($nonExistentItem, "Abstract classes should not be registered by the test createFromReflection.");

        // A more direct count test using an anonymous class to expose count
        $countingRegistry = new class () extends ConcreteTestRegistry {
            /** @return array<string, object> */
            public function getAllPublic(): array // Adding this to satisfy potential checks if getItems was public
            {
                return $this->getItems();
            }
            public function countItems(): int
            {
                return count($this->getItems());
            }
        };
        $countingRegistry->discover($this->tempTestDir); // discover into this new instance
        $this->assertEquals(2, $countingRegistry->countItems(), "Should discover and register exactly 2 items.");
    }

    public function testDiscoverWithEmptyDirectory(): void
    {
        $this->registry->discover($this->tempTestDir); // Discover in the original registry
        // Use an anonymous class to count items in the original registry after discovery
        $registryWithPublicGetItems = new class () extends ConcreteTestRegistry {
             /** @var array<string, object> */
            private array $internalItems = []; // Simulate holding items

            // Simulate register to populate internalItems for getItems to work
            public function register(object $item): void
            {
                $this->internalItems[$this->getItemKey($item)] = $item;
            }
            // Simulate discover by copying items from the actual registry
            public function syncItems(Registry $sourceRegistry): void
            {
                // This is a bit of a hack. Ideally, ConcreteTestRegistry would have a public getItems or count.
                // Reflecting to get items or making getItems public in ConcreteTestRegistry for test is another way.
                // For now, let's assume we test count by re-discovering into a counting-specific instance.
            }
            /** @return array<string, object> */
            protected function getItems(): array // Make it work like the parent
            {
                return $this->internalItems;
            }
            public function countItems(): int
            {
                return count($this->getItems());
            }
        };
        // To test the count on $this->registry, we'd need $this->registry->getItems() to be accessible.
        // Re-discovering into a new instance for counting is a valid approach.
        $countingRegistry = new class () extends ConcreteTestRegistry {
            public function countItems(): int
            {
                return count($this->getItems());
            }
        };
        $countingRegistry->discover($this->tempTestDir);
        $this->assertEquals(0, $countingRegistry->countItems(), "Should discover 0 items in an empty directory.");
    }

    public function testDiscoverWithNonExistentDirectory(): void
    {
        // PHP's RecursiveDirectoryIterator throws a RuntimeException if the directory doesn't exist.
        // The discover method itself doesn't catch this, so the test should expect it.
        $this->expectException(\RuntimeException::class);
        $this->registry->discover($this->tempTestDir . '/non_existent_subdir');
    }
}
