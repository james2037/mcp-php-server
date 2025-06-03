<?php

// Autoload dependencies
require_once __DIR__ . '/../vendor/autoload.php';

use MCP\Server\Server;
use MCP\Server\Transport\HttpTransport;
use MCP\Server\Capability\ToolsCapability;
use MCP\Server\Capability\ResourcesCapability;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use MCP\Server\Tool\Tool as BaseTool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Attribute\Parameter;
use MCP\Server\Resource\Resource as BaseResource;
use MCP\Server\Resource\Attribute\ResourceUri;
use MCP\Server\Resource\ResourceContents;

#[ToolAttribute(name: "echo", description: "Echoes back the provided message.")]
class EchoTool extends BaseTool
{
    protected function doExecute(array $arguments): array {
        // The Parameter attribute is on the base class, so we don't redefine it here.
        // We just need to ensure the method signature is compatible.
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

// 1. Create PSR-7 request and factories
$psr17Factory = new Psr17Factory();
$requestCreator = new ServerRequestCreator(
    $psr17Factory,
    $psr17Factory,
    $psr17Factory,
    $psr17Factory
);
$request = $requestCreator->fromGlobals();

// 2. Instantiate the Server
$server = new Server('MySimpleServer (HTTP)', '1.0.0');

// 3. Setup Tools Capability
$toolsCapability = new ToolsCapability();
$toolsCapability->addTool(new EchoTool());
$server->addCapability($toolsCapability);

// 4. Setup Resources Capability
$resourcesCapability = new ResourcesCapability();
$resourcesCapability->addResource(new GreetingResource());
$server->addCapability($resourcesCapability);

// 5. Instantiate and connect HttpTransport
$transport = new HttpTransport($psr17Factory, $psr17Factory, $request);
$server->connect($transport);

// 6. Run the Server
$server->run();

?>
