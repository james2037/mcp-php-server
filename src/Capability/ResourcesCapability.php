<?php

declare(strict_types=1);

namespace MCP\Server\Capability;

use MCP\Server\Exception\MethodNotSupportedException;
use MCP\Server\Message\JsonRpcMessage;
use MCP\Server\Resource\Resource;

class ResourcesCapability implements CapabilityInterface
{
    /**
     *
     *
     * @var array<string, Resource>
     */
    private array $resources = [];

    public function addResource(Resource $resource): void
    {
        $this->resources[$resource->getUri()] = $resource;
    }

    public function getCapabilities(): array
    {
        return [
            'resources' => [
                'subscribe' => false,
                'listChanged' => false
            ]
        ];
    }

    public function canHandleMessage(JsonRpcMessage $message): bool
    {
        return match ($message->method) {
            'resources/list', 'resources/read' => true,
            default => false
        };
    }

    public function handleMessage(JsonRpcMessage $message): ?JsonRpcMessage
    {
        return match ($message->method) {
            'resources/list' => $this->handleList($message),
            'resources/read' => $this->handleRead($message),
            default => throw new MethodNotSupportedException($message->method)
        };
    }

    public function initialize(): void
    {
    }
    public function shutdown(): void
    {
    }

    private function handleList(JsonRpcMessage $message): JsonRpcMessage
    {
        $resources = [];
        foreach ($this->resources as $resource) {
            $resources[] = [
                'uri' => $resource->getUri(),
                'name' => $resource->getUri(), // Could be nicer
                'description' => $resource->getDescription()
            ];
        }

        return JsonRpcMessage::result(['resources' => $resources], $message->id);
    }

    private function handleRead(JsonRpcMessage $message): JsonRpcMessage
    {
        $uri = $message->params['uri'] ?? null;
        if (!$uri) {
            return JsonRpcMessage::error(
                JsonRpcMessage::INVALID_PARAMS,
                'Missing uri parameter',
                $message->id
            );
        }

        // Find matching resource and extract parameters
        foreach ($this->resources as $resource) {
            $template = $resource->getUri();
            $parameters = $this->matchUriTemplate($template, $uri);
            if ($parameters !== null) {
                try {
                    $contents = $resource->read($parameters);
                    return JsonRpcMessage::result(['contents' => [$contents]], $message->id);
                } catch (\Exception $e) {
                    return JsonRpcMessage::result(
                        [
                        'contents' => [[
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

        return JsonRpcMessage::error(
            JsonRpcMessage::INVALID_PARAMS,
            'Resource not found: ' . $uri,
            $message->id
        );
    }

    private function matchUriTemplate(string $template, string $uri): ?array
    {
        // Convert template to regex pattern
        $pattern = preg_quote($template, '/');
        $pattern = preg_replace('/\\\{([^}]+)\\\}/', '(?P<$1>[^\/]+)', $pattern);
        $pattern = '/^' . $pattern . '$/';

        if (preg_match($pattern, $uri, $matches)) {
            // Filter out numeric keys
            return array_filter($matches, fn($key) => !is_numeric($key), ARRAY_FILTER_USE_KEY);
        }

        return null;
    }
}
