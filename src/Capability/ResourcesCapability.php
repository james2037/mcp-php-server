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
        $resourceList = [];
        foreach ($this->resources as $resource) {
            // The Resource class now has a toArray() method that includes all necessary fields
            $resourceList[] = $resource->toArray();
        }

        return JsonRpcMessage::result(['resources' => $resourceList], $message->id);
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
                    $resourceContent = $resource->read($parameters);
                    // The result structure must be ['contents' => [ResourceContents->toArray()]]
                    return JsonRpcMessage::result(['contents' => [$resourceContent->toArray()]], $message->id);
                } catch (\Exception $e) {
                    // Error reporting for read should also conform to a structure if defined,
                    // but for now, this is how it was. The subtask didn't specify changing this error structure.
                    // However, the successful path returns `contents` as an array of content item arrays.
                    // For consistency, an error could also be structured similarly.
                    // For now, sticking to the specific change requested for the success path.
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
