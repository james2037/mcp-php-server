<?php

// Autoload dependencies
require_once __DIR__ . '/../vendor/autoload.php';

use MCP\Server\Server;
use MCP\Server\Transport\StdioTransport;
use MCP\Server\Capability\ToolsCapability;
use MCP\Server\Capability\ResourcesCapability;
// Removed: use MCP\Server\Tool\ListFilesTool;
use MCP\Server\Tool\Tool as BaseTool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Resource\Resource as BaseResource;
use MCP\Server\Resource\Attribute\ResourceUri;
use MCP\Server\Resource\ResourceContents;

use MCP\Server\Tool\Attribute\Parameter; // Ensure Parameter is imported

// Definition of ListFilesTool prepended here:
#[ToolAttribute(
    name: 'list_files',
    description: 'Lists files in a specified directory. If no directory is provided, it lists files in the current working directory.',
)]
class ListFilesTool extends BaseTool
{
    protected function doExecute(
        #[Parameter(name: 'directoryPath', type: 'string', description: 'The directory path to list files from.', required: false)]
        array $arguments
    ): array {
        $directoryPath = $arguments['directoryPath'] ?? '.';

        if (!is_dir($directoryPath)) {
            throw new \Exception("Directory not found at '{$directoryPath}'");
        }

        if (!is_readable($directoryPath)) {
            throw new \Exception("Directory not readable at '{$directoryPath}'");
        }

        $files = scandir($directoryPath);

        if ($files === false) {
            throw new \Exception("Could not read directory contents at '{$directoryPath}'");
        }

        $fileList = array_filter($files, fn ($file) => $file !== '.' && $file !== '..');

        if (empty($fileList)) {
            return [$this->createTextContent("No files found in '{$directoryPath}'.")];
        }

        return [$this->createTextContent(implode("\n", $fileList))];
    }
}
// End of prepended ListFilesTool definition

#[ToolAttribute(name: "echo", description: "Echoes back the provided message.")]
class EchoTool extends BaseTool
{
    protected function doExecute(
        #[Parameter(name: 'message', type: 'string', description: 'The message to echo.')]
        array $arguments
    ): array {
        $message = $arguments['message'] ?? 'Default message if not provided';
        return [$this->createTextContent("Echo: " . $message)];
    }
}

#[ResourceUri(uri: "greeting://welcome", description: "Provides a welcome greeting.")]
class GreetingResource extends BaseResource
{
    public function __construct()
    {
        parent::__construct("Welcome Greeting", "text/plain");
    }

    public function read(array $parameters = []): ResourceContents
    {
        return $this->text("Hello from your MCP server!");
    }
}

// 1. Instantiate the Server
$server = new Server('MySimpleServer (STDIO)', '1.0.0');

// 2. Setup Tools Capability
$toolsCapability = new ToolsCapability();
$toolsCapability->addTool(new EchoTool());
$toolsCapability->addTool(new ListFilesTool()); // This should now use the local class
$server->addCapability($toolsCapability);

// 3. Setup Resources Capability
$resourcesCapability = new ResourcesCapability();
$resourcesCapability->addResource(new GreetingResource());
$server->addCapability($resourcesCapability);

// 4. Connect StdioTransport
$transport = new StdioTransport();
$server->connect($transport);

// 5. Run the Server
fwrite(STDERR, "STDIO Server listening...
"); // Message to stderr
$server->run();

?>
