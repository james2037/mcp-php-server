<?php

require_once __DIR__ . '/../vendor/autoload.php';

use MCP\Server\Server;
use MCP\Server\Transport\StdioTransport;
use MCP\Server\Capability\ToolsCapability;
use MCP\Server\Capability\ResourcesCapability;
use MCP\Server\Tool\Tool as BaseTool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Resource\Resource as BaseResource;
use MCP\Server\Resource\Attribute\ResourceUri;
use MCP\Server\Resource\ResourceContents;
use MCP\Server\Tool\Attribute\Parameter;

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
$server->addCapability($toolsCapability);

// 3. Setup Resources Capability
$resourcesCapability = new ResourcesCapability();
$resourcesCapability->addResource(new GreetingResource());
$server->addCapability($resourcesCapability);

// 4. Connect StdioTransport
$transport = new StdioTransport();
$server->connect($transport);

// 5. Run the Server
fwrite(STDERR, "STDIO Server listening...\n"); // Message to stderr
$server->run();

?>
