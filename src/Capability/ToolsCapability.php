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
            $tools[] = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'inputSchema' => $tool->getInputSchema()
            ];
        }

        return JsonRpcMessage::result(['tools' => $tools], $message->id);
    }

    private function handleCall(JsonRpcMessage $message): JsonRpcMessage
    {
        $params = $message->params;
        $name = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];

        if (!$name || !isset($this->tools[$name])) {
            return JsonRpcMessage::result(
                [
                'content' => [[
                    'type' => 'text',
                    'text' => "Tool not found: " . ($name ?? 'undefined')
                ]],
                'isError' => true
                ],
                $message->id
            );
        }

        try {
            $result = $this->tools[$name]->execute($arguments);
            return JsonRpcMessage::result(
                [
                'content' => $result,
                'isError' => false
                ],
                $message->id
            );
        } catch (\Exception $e) {
            return JsonRpcMessage::result(
                [
                'content' => [[
                    'type' => 'text',
                    'text' => $e->getMessage()
                ]],
                'isError' => true
                ],
                $message->id
            );
        }
    }
}
