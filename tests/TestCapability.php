<?php

namespace MCP\Server\Tests;

use MCP\Server\Capability\CapabilityInterface;
use MCP\Server\Message\JsonRpcMessage;

class TestCapability implements CapabilityInterface
{
    /** @var array<string, ?JsonRpcMessage> */
    private array $expectedResponses = [];
    /** @var array<int, JsonRpcMessage> */
    private array $receivedMessages = [];

    public function addExpectedResponse(string $method, ?JsonRpcMessage $response): void
    {
        $this->expectedResponses[$method] = $response;
    }

    /** @return array<int, JsonRpcMessage> */
    public function getReceivedMessages(): array
    {
        return $this->receivedMessages;
    }

    public function resetReceivedMessages(): void
    {
        $this->receivedMessages = [];
    }

    /** @return array<string, array<string, bool>> */
    public function getCapabilities(): array
    {
        return ['test' => ['enabled' => true]];
    }

    public function canHandleMessage(JsonRpcMessage $message): bool
    {
        // return isset($this->expectedResponses[$message->method]);
        return str_starts_with($message->method, 'test.'); // More general implementation
    }

    public function handleMessage(JsonRpcMessage $message): ?JsonRpcMessage
    {
        $this->receivedMessages[] = $message;
        $responseTemplate = $this->expectedResponses[$message->method] ?? null;

        if ($responseTemplate) {
            // If it's an error template, keep it as is (error responses might have null ID sometimes)
            // JsonRpcMessage::$error is typed as '?object'. If isset, it's an object.
            if (isset($responseTemplate->error)) {
                // Ensure the ID from the original request is used if the error template ID is placeholder or different
                // $responseTemplate->error is an array['code' => ..., 'message' => ...] as per JsonRpcMessage::error()
                return JsonRpcMessage::error(
                    $responseTemplate->error['code'],
                    $responseTemplate->error['message'],
                    $message->id // Always use the request's ID for the response envelope
                );
            }
            // For result templates, use their result but the request's ID
            $resultData = $responseTemplate->result ?? [];
            return JsonRpcMessage::result($resultData, $message->id);
        }
        // Default behavior: acknowledge with empty result, using original request ID
        error_log("TestCapability::handleMessage called with method: " . $message->method . " and ID: " . $message->id . " - USING DEFAULT RESPONSE");
        return JsonRpcMessage::result([], $message->id);
    }

    public function initialize(): void
    {
    }
    public function shutdown(): void
    {
    }
}
