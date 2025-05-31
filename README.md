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
