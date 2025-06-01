# PHP Model Context Protocol Server

This project is a PHP implementation of the Model Context Protocol (MCP).

MCP is an open protocol that standardizes how applications provide context to LLMs.

For more information about MCP, please visit the official website: https://modelcontextprotocol.io

## Features

| Feature    | Status      |
|------------|-------------|
| Resources  | Complete    |
|   - Text Content | Complete    |
|   - Blob Content | Complete    |
| Tools      | Complete    |
| Prompts    | Not Started |
| Sampling   | Not Started |
| Roots      | Not Started |
| Transport (Stdio) | Complete    |
| Transport (Streamable HTTP) | Complete (MCP 2025-03-26 Compliant) |

## Capabilities

This server implements the following MCP capabilities:

- **Resources**: Allows the server to expose data and content to LLMs. For more details, see the [MCP Resources documentation](https://modelcontextprotocol.io/docs/concepts/resources).
- **Tools**: Enables LLMs to perform actions through the server. For more details, see the [MCP Tools documentation](https://modelcontextprotocol.io/docs/concepts/tools).

## Usage

### Installation

Dependencies are managed with Composer. To install them, run:

```bash
composer install
```

### Running the server

The `src/Server.php` file defines a `Server` class. To use it, you need to create your own PHP script that performs the following steps:

1.  **Instantiate the `Server`**: Create an instance of the `Server` class, providing a name and version for your server.
    ```php
    $server = new \MCP\Server\Server('My MCPServer', '1.0.0');
    ```
2.  **Add Capabilities**: Instantiate and add the desired MCP capabilities. For example, to add Resources and Tools support:
    ```php
    $server->addCapability(new \MCP\Server\Capability\ResourcesCapability());
    $server->addCapability(new \MCP\Server\Capability\ToolsCapability());
    ```
3.  **Connect a Transport**: Instantiate and connect a transport mechanism. The most common is `StdioTransport`.
    ```php
    $transport = new \MCP\Server\Transport\StdioTransport();
    $server->connect($transport);
    ```
4.  **Run the Server**: Call the `run()` method on the server instance to start listening for requests.
    ```php
    $server->run();
    ```

### Transport

By default, the server is designed to work with `StdioTransport`, communicating over standard input/output. You will need to instantiate this or another transport as shown above. For more information on transports, see the [MCP Transports documentation](https://modelcontextprotocol.io/docs/concepts/transports).

### HttpTransport (Streamable HTTP)

The `HttpTransport` class implements the Streamable HTTP transport mechanism as defined by the Model Context Protocol, specifically adhering to the **MCP 2025-03-26 specification**. It allows the server to communicate over HTTP, handling standard JSON-RPC requests/responses and Server-Sent Events (SSE) for streaming.

Key features:
-   Uses PSR-7 HTTP message interfaces (`ServerRequestInterface`, `ResponseInterface`) for handling HTTP requests and responses.
-   **POST Request Handling**:
    -   If the client `Accept` header includes both `application/json` and `text/event-stream`, the server can decide the response `Content-Type`. It may respond with `application/json` for a single JSON response or initiate a `text/event-stream` for events related to that POST request.
    -   If a client's POST input consists solely of JSON-RPC notifications or responses, the server will return an **HTTP 202 Accepted** response with no body.
-   **GET Request Handling (SSE)**:
    -   Primarily supports SSE stream **resumability** using the `Last-Event-ID` header.
    -   If a GET request with `Accept: text/event-stream` does not indicate resumability (no `Last-Event-ID`) and the server is not configured to initiate a new, unsolicited SSE stream for that context, it will respond with **HTTP 405 Method Not Allowed**.
    -   If a GET request does not include `Accept: text/event-stream`, it will receive an **HTTP 406 Not Acceptable**.
-   **DELETE Request Handling**:
    -   Supports session termination via HTTP `DELETE` requests targeting a specific `Mcp-Session-Id`. Responses can include 200/204 (success), 404 (session not found), or 400/405 (bad request/not allowed).
-   **SSE Features**:
    -   Supports SSE resumability via `Last-Event-ID` headers and includes unique `id` fields in all outgoing SSE events.
    -   Implements explicit output flushing for more reliable SSE event delivery.
-   **Session Management**: Supports `Mcp-Session-Id` headers for identifying client sessions.
-   **Security**: Includes Origin header validation.

**Usage with `HttpTransport`:**

To use `HttpTransport`, you need to:
1.  Create a PSR-7 `ServerRequestInterface` object from the current HTTP request (e.g., using a library like `nyholm/psr7-server`).
2.  Instantiate PSR-7 factories (`ResponseFactoryInterface`, `StreamFactoryInterface`, e.g., from `nyholm/psr7`).
3.  Instantiate `HttpTransport` with the request and factories.
4.  Optionally, provide an array of allowed origins to the `HttpTransport` constructor for Origin header validation. If an `Origin` header is sent by the client, it *must* be in this list. If the list is empty, any request sending an `Origin` header will be rejected. Requests without an `Origin` header (e.g., same-origin or non-browser clients) are considered acceptable if the list is empty.
5.  Connect it to the `Server` instance.
6.  Call the `Server::run()` method. When used with `HttpTransport`, `run()` will handle the single HTTP request/response cycle and then exit.
7.  An external PSR-7 emitter (like `Laminas\HttpHandlerRunner\Emitter\SapiEmitter`) is used by the `Server` to send the final HTTP response.

**Example (conceptual `public/index.php` or bootstrap file):**

```php
<?php

require_once __DIR__ . '/../vendor/autoload.php'; // Adjust path as needed

use MCP\Server\Server;
use MCP\Server\Transport\HttpTransport;
use MCP\Server\Capability\ResourcesCapability; // Example capability
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;

// 1. Create PSR-7 request and factories
$psr17Factory = new Psr17Factory();
$requestCreator = new ServerRequestCreator(
    $psr17Factory, // ServerRequestFactory
    $psr17Factory, // UriFactory
    $psr17Factory, // UploadedFileFactory
    $psr17Factory  // StreamFactory
);
$request = $requestCreator->fromGlobals();

// 2. Instantiate Server
$server = new Server('My MCPServer (HTTP)', '1.0.0');

// 3. Add Capabilities (example)
$server->addCapability(new ResourcesCapability());
// Add other capabilities (Tools, etc.)

// 4. Configure allowed origins (optional, but recommended for security)
//    Replace with your actual allowed client origins.
$allowedOrigins = [
    // 'http://localhost:3000', // Example: local frontend development server
    // 'https://your-client-app.com',
];

// 5. Instantiate and connect HttpTransport
$transport = new HttpTransport($request, $psr17Factory, $psr17Factory, $allowedOrigins);
$server->connect($transport);

// Optional: If your Server needs the request for other purposes (not typical for MCP server directly)
// $server->setCurrentHttpRequest($request);

// 6. Run the server for this request
//    This will process the request, send the response (including SSE headers if applicable),
//    and then HttpTransport will echo SSE events if the stream is kept open by server logic.
//    The SapiEmitter within Server::runHttpRequestCycle will send the response.
$server->run();

// For SSE, if HttpTransport::send() was used to stream events, those would have been echoed.
// The script typically ends here for a standard PHP request.
// See the "Usage in Shared Hosting Environments" section for more details on SSE behavior.
```

## Usage in Shared Hosting Environments

The MCP PHP Server with `HttpTransport` can be used in shared hosting environments, but it's important to understand how PHP's execution model in such environments affects streaming capabilities.

**Core Principle:** Shared hosting typically imposes limits on script execution time (`max_execution_time`) and may not support true long-running processes required for persistent, unsolicited Server-Sent Events (SSE) streams.

**Supported & Recommended Modes:**

1.  **POST with `application/json` Response:**
    *   This is the most straightforward and shared-hosting-friendly mode. The client sends a POST request, and the server responds with a complete `application/json` payload. This is a standard request-response cycle.

2.  **POST with Finite SSE Stream Response:**
    *   A client can send a POST request and receive a stream of server-sent events that are *directly related to processing that specific request*. For example, a tool call that generates multiple, sequential pieces of information.
    *   `HttpTransport` will send the events and then the connection for that request will be closed by the server (specifically, `HttpTransport` marks its internal stream as closed for that request).
    *   Explicit output flushing is implemented in `HttpTransport` to help ensure these events are delivered to the client as they are generated, rather than being fully buffered.
    *   **Caution:** Even these finite streams are subject to the server's `max_execution_time`. If processing the POST request and generating all related events takes longer than this limit, the script may be terminated prematurely.

3.  **GET Requests for SSE Resumability:**
    *   If a POST-initiated SSE stream (as described above) is interrupted, clients can use the `Last-Event-ID` header with a GET request to attempt to resume the stream. The server can then potentially replay missed events. This is supported and can be useful in shared hosting.

4.  **Session Management (DELETE):**
    *   Using HTTP `DELETE` requests to terminate a session (identified by `Mcp-Session-Id`) is fully supported and suitable for shared hosting. This is a standard, short-lived HTTP request.

**Limitations & Alternatives for Long-Running SSE:**

*   **New, Unsolicited Long-Running SSE via GET:**
    *   Initiating *new, unsolicited, long-running* SSE streams via client GET requests, where the server is expected to keep the connection open indefinitely to push arbitrary future messages (e.g., notifications not directly tied to an initial request), is generally **not suitable** for typical PHP shared hosting environments.
    *   This is because PHP scripts in these environments are usually terminated after `max_execution_time`, and there's often no mechanism to keep a PHP process alive and actively pushing data over a long period for many concurrent users.
    *   The `HttpTransport` will correctly respond with **HTTP 405 Method Not Allowed** if a GET request attempts to establish such a new SSE stream unless the server logic explicitly configures it (e.g., via `preferSseStream(true)` for a specific GET context, which should be used with caution in shared hosting).

*   **Recommendations for Full, Persistent SSE:**
    *   If your application requires true, persistent, long-running server-push capabilities for many users, consider hosting environments designed for such workloads:
        *   **VPS or Dedicated Servers:** Where you have full control over execution limits and server software.
        *   **Platforms with Asynchronous PHP Support:** Using technologies like Swoole or ReactPHP, which allow PHP to handle many concurrent, long-lived connections efficiently.

**Resumability is Key:**
Regardless of the hosting environment, if you use any form of SSE, implementing robust resumability logic on both client and server is highly recommended. Network connections can be unreliable, and resumability ensures a better user experience.

## Creating Custom Resources

To add your own custom resources to the server, you need to create classes that extend `\MCP\Server\Resource\Resource`. This base class is responsible for defining how a resource is discovered by clients (its URI, name, description, etc.) and how its content is read when requested.

### Key steps for creating a custom Resource class:

1.  **Extend `\MCP\Server\Resource\Resource`**: Your custom class must extend this base class.
2.  **Constructor**: Your constructor should call the parent constructor:
    `parent::__construct(string $name, ?string $mimeType = null, ?int $size = null, ?\MCP\Server\Tool\Content\Annotations $annotations = null, ?array $config = null);`
    The `$name` parameter is particularly important as it's often used in user interfaces to identify the resource.
3.  **`#[ResourceUri]` Attribute**: Use the PHP class attribute `#[\MCP\Server\Resource\Attribute\ResourceUri]` to define the resource's URI and description. The URI can be a template.
    Example:
    ```php
    #[\MCP\Server\Resource\Attribute\ResourceUri(uri: "customprovider://example/{itemId}", description: "Provides an example item.")]
    ```
4.  **Implement `read()` Method**: You must implement the abstract method `public function read(array $parameters = []): \MCP\Server\Resource\ResourceContents;`.
    *   The `$parameters` array will contain values extracted from any URI template placeholders. For instance, if your `ResourceUri` is `customprovider://example/{itemId}` and a client requests `customprovider://example/123`, then `$parameters` will be `['itemId' => '123']`.
    *   This method must return an instance of `\MCP\Server\Resource\TextResourceContents` or `\MCP\Server\Resource\BlobResourceContents`.
    *   The base `Resource` class provides helper methods to easily create these:
        *   `protected function text(string $text, ?string $mimeType = null, array $parameters = []): TextResourceContents`
        *   `protected function blob(string $data, string $mimeType, array $parameters = []): BlobResourceContents`

### Example Custom Resource: `EchoResource`

Here's a simple example of a custom resource that echoes back a message provided in its URI:

```php
<?php

namespace MyServer\Resources;

use MCP\Server\Resource\Resource;
use MCP\Server\Resource\ResourceContents;
use MCP\Server\Resource\TextResourceContents; // Explicit import for clarity
use MCP\Server\Resource\Attribute\ResourceUri;

#[ResourceUri(uri: "echo://{message}", description: "Echoes back the message provided in the URI.")]
class EchoResource extends Resource
{
    public function __construct()
    {
        // Name, an optional MimeType for hints, etc.
        parent::__construct("Echo Resource", "text/plain");
    }

    public function read(array $parameters = []): ResourceContents
    {
        $messageToEcho = $parameters['message'] ?? 'No message provided';
        // Use the helper method from the base Resource class
        return $this->text("You said: " . $messageToEcho, 'text/plain', $parameters);
    }
}
```

### Registering the Custom Resource

To make your custom resource available through the server:

1.  Instantiate your custom resource class.
2.  Add it to an instance of `\MCP\Server\Capability\ResourcesCapability`.
3.  Add the `ResourcesCapability` instance to your server.

```php
// ... assume $server is your \MCP\Server\Server instance,
// and transport is already connected as shown in the main "Usage" section ...

// 1. Create and configure ResourcesCapability
$resourcesCapability = new \MCP\Server\Capability\ResourcesCapability();

// 2. Instantiate and add your custom resource(s)
// Make sure \MyServer\Resources\EchoResource is defined as in the example above
$echoResource = new \MyServer\Resources\EchoResource();
$resourcesCapability->addResource($echoResource);
// You can add more resources to $resourcesCapability here
// For example: $resourcesCapability->addResource(new \MyServer\Resources\AnotherResource());

// 3. Add the configured capability to the server
$server->addCapability($resourcesCapability);

// ... then $server->run();
```

## Creating Custom Tools

To add your own custom tools to the server, you need to create classes that extend `\MCP\Server\Tool\Tool`. This base class helps define the tool's name, its description, the input schema it expects, and its core execution logic.

### Key steps for creating a custom Tool class:

1.  **Extend `\MCP\Server\Tool\Tool`**: Your custom class must extend this base class.
2.  **`#[Tool]` Attribute**: Use the PHP class attribute `#[\MCP\Server\Tool\Attribute\Tool]` to define the tool's unique `name` (e.g., `mytool/action` or `calculator/add`) and a human-readable `description`.
    Example:
    ```php
    #[\MCP\Server\Tool\Attribute\Tool(name: "string/reverse", description: "Reverses a given string.")]
    ```
3.  **Input Schema (Parameters via `doExecute` method parameters)**: The input parameters your tool accepts are defined by adding `#[\MCP\Server\Tool\Attribute\Parameter]` attributes to the parameters of the `doExecute` method. The `name` you specify in the `Parameter` attribute will be the key in the `$arguments` array passed to `doExecute`.
    Example:
    ```php
    protected function doExecute(
        #[\MCP\Server\Tool\Attribute\Parameter(name: "inputString", type: "string", description: "The string to reverse.", required: true)]
        $inputString // This PHP variable name is for use within the method
    ): array {
        // ... logic using $inputString ...
    }
    ```
4.  **Implement `doExecute()` Method**: You must implement the abstract method `protected function doExecute(array $arguments): array;`.
    *   The `$arguments` associative array will contain the input values provided by the client, already validated against the schema defined by your `Parameter` attributes.
    *   This method must return an array of objects, where each object implements `\MCP\Server\Tool\Content\ContentItemInterface`.
    *   The base `Tool` class provides helper methods to easily create common content types, such as `createTextContent()`, `createImageContent()`, `createAudioContent()`, and `createEmbeddedResource()`.
5.  **Optional `#[ToolAnnotations]` Attribute**: You can add `#[\MCP\Server\Tool\Attribute\ToolAnnotations]` at the class level to provide additional hints like a `title` for display, a `readOnlyHint`, or other metadata.
6.  **Optional Completion Suggestions**: You can override the `public function getCompletionSuggestions(string $argumentName, string $query, int $limit): array` method to provide dynamic suggestions for tool inputs if needed.

### Example Custom Tool: `StringReverserTool`

Here's a simple example of a custom tool that reverses a string:

```php
<?php

namespace MyServer\Tools;

use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute; // Alias for clarity
use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute; // Alias for clarity
use MCP\Server\Tool\Content\ContentItemInterface; // For return type hint
use MCP\Server\Tool\Content\TextContent; // For specific content type

#[ToolAttribute(name: "string/reverse", description: "Reverses a given string.")]
class StringReverserTool extends Tool
{
    // The doExecute method's parameters, with their attributes, define the input schema.
    // The name in ParameterAttribute ('text_to_reverse') should match the PHP parameter name.
    protected function doExecute(
        #[ParameterAttribute(name: "text_to_reverse", type: "string", description: "The string that will be reversed.", required: true)]
        $text_to_reverse
    ): array {
        // The $text_to_reverse parameter directly contains the validated input string.
        $reversedString = strrev($text_to_reverse);

        // Use the helper method from the base Tool class to create a TextContent item
        return [$this->createTextContent($reversedString)];
    }
}
```

*Note: The name of the PHP parameter in `doExecute` should match the `name` specified in its `ParameterAttribute` for direct access to the argument's value. If they differ, you would need to access the value via the `$arguments` array passed to `doExecute` (e.g., `$arguments['text_to_reverse']`).*


### Registering the Custom Tool

To make your custom tool available through the server:

1.  Instantiate your custom tool class.
2.  Add it to an instance of `\MCP\Server\Capability\ToolsCapability`.
3.  Add the `ToolsCapability` instance to your server.

```php
// ... assume $server is your \MCP\Server\Server instance,
// and transport is already connected as shown in the main "Usage" section ...

// 1. Create and configure ToolsCapability
$toolsCapability = new \MCP\Server\Capability\ToolsCapability();

// 2. Instantiate and add your custom tool(s)
// Make sure \MyServer\Tools\StringReverserTool is defined as in the example above
$stringReverser = new \MyServer\Tools\StringReverserTool();
$toolsCapability->addTool($stringReverser);
// You can add more tools to $toolsCapability here
// For example: $toolsCapability->addTool(new \MyServer\Tools\AnotherTool());

// 3. Add the configured capability to the server
$server->addCapability($toolsCapability);

// ... then $server->run();
```

## Project Structure

- `src/`: Contains the core source code of the server, including capabilities, resources, tools, and transport implementations.
- `tests/`: Contains unit tests for the server components.
- `vendor/`: Contains project dependencies managed by Composer. This directory is created after running `composer install`.

## Contributing

We welcome contributions from the community! If you'd like to contribute to this project, please follow these steps:

1. Fork the repository.
2. Create a new branch for your changes.
3. Make your changes, ensuring to include clear comments and add or update tests where applicable.
4. Submit a pull request for review.

For more general information on contributing to MCP projects, please see the [main MCP contributing guide](https://modelcontextprotocol.io/development/contributing).

## License

This project is licensed under the MIT License. The full license text can be found in the `LICENSE` file.
