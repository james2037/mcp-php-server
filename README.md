# PHP Model Context Protocol Server SDK

This project provides a PHP implementation of the Model Context Protocol (MCP), enabling you to build servers that can provide context (data and tools) to Large Language Models (LLMs).

MCP is an open protocol that standardizes how applications interact with LLMs. For more information about MCP, please visit the official website: [https://modelcontextprotocol.io](https://modelcontextprotocol.io)

## Features

This PHP MCP SDK provides the following core features:

*   **Server Implementation**: Easily create MCP compliant servers.
*   **Capabilities**:
    *   `Tools`: Expose functionalities for LLMs to execute.
    *   `Resources`: Provide data and content to LLMs.
*   **Transports**:
    *   `StdioTransport`: For command-line based MCP servers.
    *   `HttpTransport`: For exposing MCP servers over HTTP (compatible with standard PHP web server environments like Apache/Nginx with PHP-FPM/mod_php).
*   **JSON-RPC Handling**: Manages request parsing and response formatting.
*   **Attribute-Based Configuration**: Use PHP attributes to define tools, resources, and their parameters.

## Getting Started

This guide will walk you through creating simple MCP servers using this SDK.

### Prerequisites

*   PHP 8.1 or higher
*   Composer for dependency management

### Installation

Clone this repository and install the dependencies using Composer:

```bash
git clone https://github.com/modelcontextprotocol/php-sdk.git <your-project-name>
cd <your-project-name>
composer install
```

(If you are embedding this SDK into an existing project, you would typically add it via `composer require modelcontextprotocol/php-sdk` once it's published on Packagist. For now, usage from a cloned repository is assumed for development.)

## Creating an STDIO Server

An STDIO server communicates over standard input and standard output. This is commonly used for local tools or development purposes.

The following example demonstrates a server with:
1.  An `EchoTool`: Takes a string and returns it.
2.  A `GreetingResource`: Provides a static greeting message.

The full code for this example can be found in `examples/stdio_server.php`.

**Key parts of `examples/stdio_server.php`:**

```php
<?php

// Autoload dependencies
require_once __DIR__ . '/../vendor/autoload.php';

// Required MCP SDK classes
use MCP\Server\Server;
use MCP\Server\Transport\StdioTransport;
use MCP\Server\Capability\ToolsCapability;
use MCP\Server\Capability\ResourcesCapability;

// For defining the tool
use MCP\Server\Tool\Tool as BaseTool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Attribute\Parameter;

// For defining the resource
use MCP\Server\Resource\Resource as BaseResource;
use MCP\Server\Resource\Attribute\ResourceUri;
use MCP\Server\Resource\ResourceContents;

// 1. Define a Tool
#[ToolAttribute(name: "echo", description: "Echoes back the provided message.")]
class EchoTool extends BaseTool
{
    protected function doExecute(array $arguments): array {
        // Access parameters from the $arguments array
        $message = $arguments['message'] ?? 'Default message if not provided';
        return [$this->createTextContent("Echo: " . $message)];
    }
}

// 2. Define a Resource
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

// 3. Instantiate the Server
$server = new Server('MySimpleServer (STDIO)', '1.0.0');

// 4. Setup and Add Tools Capability
$toolsCapability = new ToolsCapability();
$toolsCapability->addTool(new EchoTool());
$server->addCapability($toolsCapability);

// 5. Setup and Add Resources Capability
$resourcesCapability = new ResourcesCapability();
$resourcesCapability->addResource(new GreetingResource());
$server->addCapability($resourcesCapability);

// 6. Connect StdioTransport
$transport = new StdioTransport();
$server->connect($transport);

// 7. Run the Server
fwrite(STDERR, "STDIO Server listening...\n"); // Optional: message to stderr
$server->run();

```
*(Note: The `EchoTool::doExecute` method uses `array $arguments` as its parameter. This is because the base `Tool` class passes all arguments as an associative array to this method. Your tool logic should then access specific parameters from this array, e.g., `$arguments['message']`.)*


**Running the STDIO Server:**

Execute the script from your terminal:

```bash
php examples/stdio_server.php
```
The server will listen for JSON-RPC messages on standard input.

## Creating an HTTP Server

You can also expose your MCP server over HTTP using the `HttpTransport`. This example uses the same `EchoTool` and `GreetingResource`.

The full code for this example is in `examples/http_server.php`. This script requires PSR-7 and PSR-17 HTTP message implementations (e.g., `nyholm/psr7` and `nyholm/psr7-server`, which are included in `composer.json`).

**Key parts of `examples/http_server.php`:**

```php
<?php

// Autoload dependencies
require_once __DIR__ . '/../vendor/autoload.php';

// Required MCP SDK classes & PSR implementations
use MCP\Server\Server;
use MCP\Server\Transport\HttpTransport;
use MCP\Server\Capability\ToolsCapability;
use MCP\Server\Capability\ResourcesCapability;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;

// Tool and Resource definitions (e.g., EchoTool, GreetingResource)
// would be included here, same as in the STDIO example.
// For brevity, they are omitted from this snippet but are in the actual file.

// --- Assume EchoTool is defined as in the STDIO example ---
use MCP\Server\Tool\Tool as BaseTool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
// Parameter attribute is on the actual class, not repeated in every snippet.
// use MCP\Server\Tool\Attribute\Parameter;
#[ToolAttribute(name: "echo", description: "Echoes back the provided message.")]
class EchoTool extends BaseTool {
    protected function doExecute(array $arguments): array { // Changed signature
        $message = $arguments['message'] ?? 'Default message if not provided'; // Added logic
        return [$this->createTextContent("Echo: " . $message)];
    }
}

// --- Assume GreetingResource is defined as in the STDIO example ---
use MCP\Server\Resource\Resource as BaseResource;
use MCP\Server\Resource\Attribute\ResourceUri;
use MCP\Server\Resource\ResourceContents;
#[ResourceUri(uri: "greeting://welcome", description: "Provides a welcome greeting.")]
class GreetingResource extends BaseResource {
    public function __construct() { parent::__construct("Welcome Greeting", "text/plain"); }
    public function read(array $parameters = []): ResourceContents {
        return $this->text("Hello from your MCP server!");
    }
}


// 1. Create PSR-7 request and factories
$psr17Factory = new Psr17Factory();
$requestCreator = new ServerRequestCreator(
    $psr17Factory, // ServerRequestFactory
    $psr17Factory, // UriFactory
    $psr17Factory, // UploadedFileFactory
    $psr17Factory  // StreamFactory
);
$request = $requestCreator->fromGlobals();

// 2. Instantiate the Server
$server = new Server('MySimpleServer (HTTP)', '1.0.0');

// 3. Setup and Add Capabilities (Tools, Resources)
// Similar to STDIO example:
$toolsCapability = new ToolsCapability();
$toolsCapability->addTool(new EchoTool());
$server->addCapability($toolsCapability);

$resourcesCapability = new ResourcesCapability();
$resourcesCapability->addResource(new GreetingResource());
$server->addCapability($resourcesCapability);

// 4. Instantiate and connect HttpTransport
$transport = new HttpTransport($request, $psr17Factory, $psr17Factory);
$server->connect($transport);

// 5. Run the Server
// For HTTP, run() processes the current request and sends the response.
$server->run();
```

**Running the HTTP Server:**

Use PHP's built-in web server for development:

```bash
php -S localhost:8000 examples/http_server.php
```

Then, send MCP requests using an HTTP client like `curl`.

**Example `curl` request for the `echo` tool:**

```bash
curl -X POST -H "Content-Type: application/json"      -d '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"echo","arguments":{"message":"Hello HTTP"}},"id":1}'      http://localhost:8000/
```

**Expected JSON-RPC Response:**

```json
{"jsonrpc":"2.0","result":{"content":[{"type":"text","text":"Echo: Hello HTTP"}],"isError":false},"id":1}
```

### Using with PHP-FPM or mod_php

The `examples/http_server.php` script is designed to be compatible with common PHP web server setups like Apache or Nginx using PHP-FPM or `mod_php`.

**Key Points:**

*   **Single Request Processing:** The script, particularly `(new HttpTransport(...))->run()`, processes a single HTTP request and then exits. This model fits perfectly with how PHP-FPM and `mod_php` handle PHP requests. Each incoming HTTP request to your web server that's routed to this script will be a fresh execution.
*   **PSR-7 `fromGlobals()`:** The use of `ServerRequestCreator::fromGlobals()` correctly populates the PSR-7 request object from PHP's global variables (e.g., `$_SERVER`, `$_POST`), which are populated by the web server.
*   **Output Handling:** The SDK's `HttpTransport` uses a SAPI emitter (like `Laminas\HttpHandlerRunner\Emitter\SapiEmitter`) internally, which correctly sends headers and body content to the web server for transmission to the client.

**Conceptual Webserver Configuration:**

You would configure your web server (e.g., Nginx or Apache) to pass requests to your `http_server.php` script.

*   **Nginx Example (conceptual):**
    ```nginx
    server {
        listen 80;
        server_name your-mcp-server.example.com;
        root /path/to/your/project/examples; # Or wherever http_server.php is

        location / {
            try_files $uri /http_server.php$is_args$args;
        }

        location ~ \.php$ {
            include snippets/fastcgi-php.conf;
            fastcgi_pass unix:/var/run/php/php8.1-fpm.sock; # Adjust to your PHP-FPM version/socket
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            include fastcgi_params;
        }
    }
    ```

*   **Apache Example (conceptual, using `.htaccess` in the `examples` directory if `AllowOverride` is enabled):**
    ```apacheconf
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^(.*)$ http_server.php [L,QSA]
    ```
    Ensure `mod_rewrite` is enabled. Your Apache VirtualHost would also need to be configured to execute PHP scripts.

**Running the Server:**
Unlike the `php -S localhost:8000 examples/http_server.php` command (which runs PHP's built-in development server), in a PHP-FPM/mod_php setup, your main web server (Nginx/Apache) is responsible for receiving the request and passing it to the PHP interpreter. You don't run a separate PHP command to "start" the server in the same way.

The `curl` commands provided earlier for testing the HTTP server would remain the same, targeting the URL configured in your web server (e.g., `http://your-mcp-server.example.com/`).

## Further Information

For more detailed information on MCP concepts, capabilities, and advanced usage, please refer to the source code and the official [Model Context Protocol documentation](https://modelcontextprotocol.io/docs).
```
