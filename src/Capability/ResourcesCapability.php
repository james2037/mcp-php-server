<?php

/**
 * @phpcs:ignore PEAR.Commenting.FileComment.MissingVersion
 */
/**
 * This file defines the ResourcesCapability class, which manages access to resources.
 *
 * @category MCP
 * @package  Server
 * @author   Your Name <you@example.com>
 * @license  MIT License
 * @version  GIT: <git_id>
 * @link     https://github.com/your/project
 * @since    1.0.0
 *
 * @phpcs:ignore PEAR.Commenting.FileComment.MissingVersion
 */

declare(strict_types=1);

namespace MCP\Server\Capability;

use MCP\Server\Exception\MethodNotSupportedException;
use MCP\Server\Message\JsonRpcMessage;
use MCP\Server\Resource\Resource;

/**
 * Provides capabilities for listing and reading server resources.
 *
 * This class allows clients to discover available resources and read their contents
 * using URI patterns.
 *
 * @category MCP
 * @package  Server
 * @author   Your Name <you@example.com>
 * @license  MIT License
 * @link     https://github.com/your/project
 */
class ResourcesCapability implements CapabilityInterface
{
    /**
     * Stores the registered resources.
     *
     * The keys are resource URIs (which can be templates) and values are Resource objects.
     *
     * @var array<string, Resource>
     */
    private array $_resources = [];

    /**
     * Adds a resource to be managed by this capability.
     *
     * @param Resource $resource The resource object to add.
     *
     * @return void
     */
    public function addResource(Resource $resource): void
    {
        $this->_resources[$resource->getUri()] = $resource;
    }

    /**
     * Declares the capabilities provided by this class.
     *
     * @return array An associative array describing the 'resources' capability.
     */
    public function getCapabilities(): array
    {
        return [
            'resources' => [
                'subscribe' => false,
                'listChanged' => false
            ]
        ];
    }

    /**
     * Determines if this capability can handle the given JSON-RPC message.
     *
     * @param JsonRpcMessage $message The message to check.
     *
     * @return bool True if the method is 'resources/list' or 'resources/read',
     *              false otherwise.
     */
    public function canHandleMessage(JsonRpcMessage $message): bool
    {
        return match ($message->method) {
            'resources/list', 'resources/read' => true,
            default => false
        };
    }

    /**
     * Processes the JSON-RPC message and returns a response or null.
     *
     * @param JsonRpcMessage $message The message to handle.
     *
     * @return JsonRpcMessage|null A response message or null if it's a notification.
     * @throws MethodNotSupportedException If the method is not supported by this capability.
     */
    public function handleMessage(JsonRpcMessage $message): ?JsonRpcMessage
    {
        return match ($message->method) {
            'resources/list' => $this->_handleList($message),
            'resources/read' => $this->_handleRead($message),
            default => throw new MethodNotSupportedException($message->method)
        };
    }

    /**
     * Initializes the capability. Currently does nothing.
     *
     * @return void
     */
    public function initialize(): void
    {
    }

    /**
     * Shuts down the capability. Currently does nothing.
     *
     * @return void
     */
    public function shutdown(): void
    {
    }

    /**
     * Handles the 'resources/list' method.
     *
     * Returns a list of all registered resources.
     *
     * @param JsonRpcMessage $message The incoming message.
     *
     * @return JsonRpcMessage A response message containing the list of resources.
     */
    private function _handleList(JsonRpcMessage $message): JsonRpcMessage
    {
        $resourceList = [];
        foreach ($this->_resources as $resource) {
            // The Resource class now has a toArray() method
            // that includes all necessary fields
            $resourceList[] = $resource->toArray();
        }

        return JsonRpcMessage::result(['resources' => $resourceList], $message->id);
    }

    /**
     * Handles the 'resources/read' method.
     *
     * Reads a resource specified by URI, potentially using URI template parameters.
     *
     * @param JsonRpcMessage $message The incoming message.
     *
     * @return JsonRpcMessage A response message with the resource contents or an error.
     */
    private function _handleRead(JsonRpcMessage $message): JsonRpcMessage
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
        foreach ($this->_resources as $resource) {
            $template = $resource->getUri();
            $parameters = $this->_matchUriTemplate($template, $uri);
            if ($parameters !== null) {
                try {
                    $resourceContent = $resource->read($parameters);
                    // The result structure must be
                    // ['contents' => [ResourceContents->toArray()]]
                    return JsonRpcMessage::result(
                        ['contents' => [$resourceContent->toArray()]],
                        $message->id
                    );
                } catch (\Exception $e) {
                    // Error reporting for read should also conform to a structure
                    // if defined, but for now, this is how it was. The subtask
                    // didn't specify changing this error structure.
                    // However, the successful path returns `contents` as an array
                    // of content item arrays. For consistency, an error could
                    // also be structured similarly. For now, sticking to the
                    // specific change requested for the success path.
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

    /**
     * Matches a URI against a template and extracts parameters.
     *
     * The template can contain placeholders like {paramName}.
     *
     * @param string $template The URI template (e.g., /items/{id}).
     * @param string $uri      The URI to match (e.g., /items/123).
     *
     * @return array|null An associative array of parameters if matched, null otherwise.
     */
    private function _matchUriTemplate(string $template, string $uri): ?array
    {
        // Convert template to regex pattern
        $pattern = preg_quote($template, '/');
        $pattern = preg_replace('/\\\{([^}]+)\\\}/', '(?P<$1>[^\/]+)', $pattern);
        $pattern = '/^' . $pattern . '$/';

        if (preg_match($pattern, $uri, $matches)) {
            // Filter out numeric keys
            return array_filter(
                $matches,
                fn($key) => !is_numeric($key),
                ARRAY_FILTER_USE_KEY
            );
        }

        return null;
    }
}
