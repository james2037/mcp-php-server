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
            'tools/list', 'tools/call' => true,
            default => false
        };
    }

    public function handleMessage(JsonRpcMessage $message): ?JsonRpcMessage
    {
        return match ($message->method) {
            'tools/list' => $this->handleList($message),
            'tools/call' => $this->handleCall($message),
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
}
