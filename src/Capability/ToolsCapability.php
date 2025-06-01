<?php

namespace MCP\Server\Capability;

use MCP\Server\Exception\MethodNotSupportedException;
use MCP\Server\Message\JsonRpcMessage;
use MCP\Server\Tool\Tool;

class ToolsCapability implements CapabilityInterface
{
    /**
     *
     *
     * @var array<string, Tool>
     */
    private array $tools = [];

    public function addTool(Tool $tool): void
    {
        $this->tools[$tool->getName()] = $tool;
    }

    public function getCapabilities(): array
    {
        return [
            'tools' => [
                'listChanged' => false  // Could support dynamic tool updates later
            ]
        ];
    }

    public function canHandleMessage(JsonRpcMessage $message): bool
    {
        return match ($message->method) {
            'tools/list', 'tools/call', 'completion/complete' => true,
            default => false
        };
    }

    public function handleMessage(JsonRpcMessage $message): ?JsonRpcMessage
    {
        return match ($message->method) {
            'tools/list' => $this->handleList($message),
            'tools/call' => $this->handleCall($message),
            'completion/complete' => $this->handleComplete($message),
            default => throw new MethodNotSupportedException($message->method)
        };
    }

    public function initialize(): void
    {
        foreach ($this->tools as $tool) {
            $tool->initialize();
        }
    }

    public function shutdown(): void
    {
        foreach ($this->tools as $tool) {
            $tool->shutdown();
        }
    }

    private function handleList(JsonRpcMessage $message): JsonRpcMessage
    {
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

    private function handleCall(JsonRpcMessage $message): JsonRpcMessage
    {
        $params = $message->params;
        $toolName = $params['name'] ?? null;
        $toolArguments = $params['arguments'] ?? []; // Default to empty array if not provided

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
                $isError = false; // Explicitly set after successful execution
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

    private function handleComplete(JsonRpcMessage $message): JsonRpcMessage
    {
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
        // @phpstan-ignore-next-line - Defensive check for tools not adhering to documented return type
        if (!is_array($suggestions) || !isset($suggestions['values']) || !is_array($suggestions['values'])) {
             error_log("Tool {$toolName} provided invalid suggestions format for argument '{$argumentName}'.");
             $suggestions = ['values' => [], 'total' => 0, 'hasMore' => false];
        }

        return JsonRpcMessage::result(['completion' => $suggestions], $message->id);
    }
}
