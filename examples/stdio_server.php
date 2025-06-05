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
    /**
     * @return \MCP\Server\Tool\Content\ContentItemInterface
     */
    protected function doExecute(
        #[Parameter(name: 'message', type: 'string', description: 'The message to echo.')]
        array $arguments
    ): \MCP\Server\Tool\Content\ContentItemInterface {
        $message = $arguments['message'] ?? 'Default message if not provided';
        return $this->text("Echo: " . $message);
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

// 1. Create Transport
$transport = new StdioTransport();

// Check for debug mode
$isDebug = false;
// Check environment variable
if (getenv('MCP_DEBUG') === 'true') {
    $isDebug = true;
}
// Check command-line arguments
if (in_array('--debug', $argv ?? [], true)) {
    $isDebug = true;
}

if ($isDebug) {
    $transport->setDebug(true);
    // This message will only appear if debug mode was successfully enabled in StdioTransport
    $transport->debugLog('Debug logging enabled by server configuration.');
}

// 2. Instantiate the Server, injecting the transport
$server = new Server('MySimpleServer (STDIO)', '1.0.0', $transport);

// 3. Setup Tools Capability
$toolsCapability = new ToolsCapability();
$toolsCapability->addTool(new EchoTool());
$server->addCapability($toolsCapability);

// 3. Setup Resources Capability
$resourcesCapability = new ResourcesCapability();
$resourcesCapability->addResource(new GreetingResource());
$server->addCapability($resourcesCapability);

// 4. Transport is already connected via constructor. connect() call is no longer needed.
// $server->connect($transport); // This line can be removed.

// 5. Run the Server
fwrite(STDERR, "STDIO Server listening...\n"); // Message to stderr
$server->run();

?>
