<?php

namespace MCP\Server\Capability;

use MCP\Server\Exception\MethodNotSupportedException;
use MCP\Server\Message\JsonRpcMessage;
use MCP\Server\Tool\Tool;

/**
 * Manages the registration and execution of tools available to the server.
 * It handles listing available tools, calling tools, and providing completion suggestions.
 */
class ToolsCapability implements CapabilityInterface
{
    /**
     * Registered tools, keyed by their name.
     * @var array<string, Tool>
     */
    private array $tools = [];

    /**
     * Adds a tool to the capability.
     *
     * @param Tool $tool The tool to add.
     */
    public function addTool(Tool $tool): void
    {
        $this->tools[$tool->getName()] = $tool;
    }

    /**
     * Declares the capabilities provided by this class.
     *
     * @return array{tools: array{listChanged: bool}}
     * An associative array describing the 'tools' capability.
     */
    public function getCapabilities(): array
    {
        return [
            'tools' => [
                'listChanged' => false  // Could support dynamic tool updates later
            ]
        ];
    }

    /**
     * Checks if this capability can handle the given JSON-RPC message.
     *
     * @param JsonRpcMessage $message The message to check.
     * @return bool True if the method is 'tools/list', 'tools/call', or 'completion/complete', false otherwise.
     */
    public function canHandleMessage(JsonRpcMessage $message): bool
    {
        return match ($message->method) {
            'tools/list', 'tools/call', 'completion/complete' => true,
            default => false
        };
    }

    /**
     * Handles an incoming JSON-RPC request or notification related to tools.
     *
     * @param JsonRpcMessage $message The message to handle.
     * @return JsonRpcMessage|null A response message, or null for notifications.
     * @throws MethodNotSupportedException if the method is not supported by this capability.
     */
    public function handleMessage(JsonRpcMessage $message): ?JsonRpcMessage
    {
        return match ($message->method) {
            'tools/list' => $this->handleList($message),
            'tools/call' => $this->handleCall($message),
            'completion/complete' => $this->handleComplete($message),
            default => throw new MethodNotSupportedException($message->method)
        };
    }

    /**
     * Initializes all registered tools.
     * This method is called when the server is initializing.
     */
    public function initialize(): void
    {
        foreach ($this->tools as $tool) {
            $tool->initialize();
        }
    }

    /**
     * Shuts down all registered tools.
     * This method is called when the server is shutting down.
     */
    public function shutdown(): void
    {
        foreach ($this->tools as $tool) {
            $tool->shutdown();
        }
    }

    /**
     * Handles the 'tools/list' method.
     * Returns a list of all registered tools, including their name, description, input schema, and annotations.
     *
     * @param JsonRpcMessage $message The incoming 'tools/list' message.
     * @return JsonRpcMessage A response message containing the list of tools.
     */
    private function handleList(JsonRpcMessage $message): ?JsonRpcMessage
    {
        if ($message->id === null) {
            return null; // Notifications should not be responded to
        }
        $tools = [];
        foreach ($this->tools as $tool) {
            $toolData = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'inputSchema' => $tool->getInputSchema()
            ];

            $annotations = $tool->getAnnotations();
            if ($annotations !== null) {
                $toolData['annotations'] = $annotations;
            }
            $tools[] = $toolData;
        }

        return JsonRpcMessage::result(['tools' => $tools], $message->id);
    }

    /**
     * Handles the 'tools/call' method.
     * Executes a specified tool with the given arguments.
     *
     * @param JsonRpcMessage $message The incoming 'tools/call' message.
     *                                It expects 'name' (string) and 'arguments' (object|array) in params.
     * @return JsonRpcMessage A response message containing the tool's output or an error.
     *                       The result includes 'content' (array of content items) and 'isError' (bool).
     */
    private function handleCall(JsonRpcMessage $message): ?JsonRpcMessage
    {
        if ($message->id === null) {
            return null; // Notifications should not be responded to
        }
        $params = $message->params;
        $toolName = $params['name'] ?? null;
        $toolArguments = $params['arguments'] ?? [];

        if (!is_string($toolName) || empty($toolName)) {
            $contentItemsArray = [[
                'type' => 'text',
                'text' => "Invalid or missing tool name.",
            ]];
            $isError = true;
        } elseif (!isset($this->tools[$toolName])) {
            $contentItemsArray = [[
                'type' => 'text',
                'text' => "Tool not found: " . $toolName,
            ]];
            $isError = true;
        } elseif (!is_array($toolArguments)) {
            // JSON-RPC params are decoded to PHP associative arrays (objects) or indexed arrays (arrays).
            // Tool arguments are expected to be a JSON object, hence a PHP associative array.
            $contentItemsArray = [[
                'type' => 'text',
                'text' => "Invalid arguments format: arguments must be an object/map.",
            ]];
            $isError = true;
        } else {
            $tool = $this->tools[$toolName];
            try {
                // $tool->execute() returns an array of content item arrays
                $contentItemsArray = $tool->execute($toolArguments);
                $isError = false;
            } catch (\Throwable $e) {
                // Optional: Log the full error internally
                // error_log("Tool execution error for '{$tool->getName()}': " . $e->getMessage() . "\n" . $e->getTraceAsString());

                $errorMessageContent = [
                    'type' => 'text',
                    'text' => "Error executing tool '{$tool->getName()}': " . $e->getMessage(),
                ];
                $contentItemsArray = [$errorMessageContent]; // Report error as content
                $isError = true;
            }
        }

        $callToolResultData = [
            'content' => $contentItemsArray,
            'isError' => $isError,
        ];

        return JsonRpcMessage::result($callToolResultData, $message->id);
    }

    /**
     * Handles the 'completion/complete' method.
     * Provides suggestions for a tool argument based on the current input.
     *
     * @param JsonRpcMessage $message The incoming 'completion/complete' message.
     *                                It expects 'ref' (object with 'type' and 'name') and
     *                                'argument' (object with 'name' and 'value') in params.
     * @return JsonRpcMessage A response message containing completion suggestions or an error.
     */
    private function handleComplete(JsonRpcMessage $message): ?JsonRpcMessage
    {
        // For notifications, no response should be sent.
        // All error responses and result responses below require an ID.
        if ($message->id === null) {
            return null;
        }

        if (!isset($message->params['ref']) || !is_array($message->params['ref'])) {
            return JsonRpcMessage::error(JsonRpcMessage::INVALID_PARAMS, 'Missing or invalid "ref" parameter for completion/complete', $message->id);
        }
        if (!isset($message->params['argument']) || !is_array($message->params['argument'])) {
            return JsonRpcMessage::error(JsonRpcMessage::INVALID_PARAMS, 'Missing or invalid "argument" parameter for completion/complete', $message->id);
        }

        $ref = $message->params['ref'];
        $argumentParams = $message->params['argument'];

        if (!isset($ref['type']) || !is_string($ref['type'])) {
            return JsonRpcMessage::error(JsonRpcMessage::INVALID_PARAMS, 'Invalid "ref.type" for completion/complete', $message->id);
        }

        // For now, we'll assume 'ref/prompt' where 'name' is the tool name,
        // as 'ref/tool' is not in the current MCP schema's CompleteRequest.ref.
        // This interpretation might need refinement based on how clients use this.
        if ($ref['type'] !== 'ref/prompt' && $ref['type'] !== 'ref/tool') { // Allow 'ref/tool' as a practical interpretation
             return JsonRpcMessage::error(JsonRpcMessage::INVALID_PARAMS, 'Unsupported "ref.type" for tool completion: ' . $ref['type'], $message->id);
        }

        if (!isset($ref['name']) || !is_string($ref['name'])) {
             return JsonRpcMessage::error(JsonRpcMessage::INVALID_PARAMS, 'Missing or invalid "ref.name" (tool name) for completion/complete', $message->id);
        }
        $toolName = $ref['name'];

        if (!isset($argumentParams['name']) || !is_string($argumentParams['name'])) {
            return JsonRpcMessage::error(JsonRpcMessage::INVALID_PARAMS, 'Missing or invalid "argument.name" for completion/complete', $message->id);
        }
        $argumentName = $argumentParams['name'];
        // Value can be any type, but getCompletionSuggestions expects mixed. Default to empty string if not set.
        $currentValue = $argumentParams['value'] ?? '';


        $tool = $this->tools[$toolName] ?? null;

        if (!$tool instanceof Tool) {
            return JsonRpcMessage::error(JsonRpcMessage::METHOD_NOT_FOUND, "Tool not found for completion: {$toolName}", $message->id);
        }

        // Assuming allArguments might be useful, but not directly in schema for 'completion/complete' params.
        // We'll pass an empty array for now, or if other arguments are somehow available.
        // The current message structure for completion/complete doesn't provide 'allArguments'.
        // This is a limitation of the current design if tools need full context.
        // For now, Tool::getCompletionSuggestions will receive only arg name and its value.
        $allCurrentArguments = $message->params['arguments'] ?? []; // Pass other arguments if available under 'arguments' key, like in tools/call

        $suggestions = $tool->getCompletionSuggestions($argumentName, $currentValue, $allCurrentArguments);

        // Validate suggestions structure
        // Based on PHPStan's analysis of the documented return type for $suggestions:
        // array{values: array<string>, total?: int, hasMore?: bool}
        // - If $suggestions is an array, 'values' is guaranteed to be set and an array.
        // - If 'total' is set, it's guaranteed to be an int.
        // - If 'hasMore' is set, it's guaranteed to be a bool.
        $errorMessage = null;
        if (!is_array($suggestions)) {
            $errorMessage = "Tool '{$toolName}' returned suggestions that is not an array.";
        } else {
            // The existence and array type of $suggestions['values'] is trusted if $suggestions is an array.
            // We still need to validate that all elements in 'values' are strings.
            foreach ($suggestions['values'] as $value) {
                if (!is_string($value)) {
                    $errorMessage = "Tool '{$toolName}' returned suggestions where 'values' contains non-string elements.";
                    break;
                }
            }
            // The checks for 'total' being int and 'hasMore' being bool are removed as PHPStan
            // considers them redundant if the keys are present, due to the strict PHPDoc type.
        }

        // PHPStan indicates $errorMessage will always be null here if tools adhere to PHPDoc.
        // If $errorMessage was set, it implies a deviation from the PHPDoc that PHPStan
        // did not foresee as possible (e.g. a tool returning a non-array or non-string in values).
        // Removing this block because PHPStan reports "Strict comparison using !== between null and null will always evaluate to false."
        // if ($errorMessage !== null) {
        //     error_log("Tool {$toolName} provided invalid suggestions format for argument '{$argumentName}': {$errorMessage}");
        //     return JsonRpcMessage::error(
        //         JsonRpcMessage::INTERNAL_ERROR,
        //         $errorMessage,
        //         $message->id
        //     );
        // }

        return JsonRpcMessage::result(['completion' => $suggestions], $message->id);
    }
}
