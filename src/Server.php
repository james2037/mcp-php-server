<?php

namespace MCP\Server;

use MCP\Server\Message\JsonRpcMessage;
use MCP\Server\Transport\TransportInterface;
use MCP\Server\Capability\CapabilityInterface;

class Server
{
    /**
     *
     *
     * @var array<string, CapabilityInterface>
     */
    private array $capabilities = [];
    private bool $initialized = false;
    private bool $shuttingDown = false;
    private ?TransportInterface $transport = null;

    public function __construct(
        private readonly string $name,
        private readonly string $version = '1.0.0'
    ) {
    }

    public function addCapability(CapabilityInterface $capability): void
    {
        $this->capabilities[] = $capability;
    }

    public function connect(TransportInterface $transport): void
    {
        $this->transport = $transport;
    }

    public function run(): void
    {
        if (!$this->transport) {
            throw new \RuntimeException('No transport connected');
        }

        while (!$this->shuttingDown) {
            try {
                $message = $this->transport->receive();
                if (!$message) {
                    if ($this->transport->isClosed()) {
                        break;
                    }
                    continue;
                }

                $response = $this->handleMessage($message);
                if ($response) {
                    $this->transport->send($response);
                }
            } catch (\Throwable $e) {
                $this->transport->log("Error: " . $e->getMessage());
                if ($message && $message->isRequest()) {
                    $code = $e instanceof \RuntimeException ? $e->getCode() : JsonRpcMessage::INTERNAL_ERROR;
                    if ($code === 0) {
                        $code = JsonRpcMessage::INTERNAL_ERROR;
                    }
                    $this->transport->send(
                        JsonRpcMessage::error(
                            $code,
                            $e->getMessage(),
                            $message->id
                        )
                    );
                }
            }
        }

        $this->shutdown();
    }

    public function shutdown(): void
    {
        if (!$this->initialized) {
            return;
        }

        $this->shuttingDown = true;
        foreach ($this->capabilities as $capability) {
            $capability->shutdown();
        }
    }

    private function handleMessage(JsonRpcMessage $message): ?JsonRpcMessage
    {
        // Always allow shutdown, even if not initialized
        if ($message->method === 'shutdown') {
            return $this->handleShutdown($message);
        }

        // Handle initialization
        if (!$this->initialized) {
            if ($message->method !== 'initialize') {
                return JsonRpcMessage::error(
                    JsonRpcMessage::INVALID_REQUEST,
                    'Server not initialized',
                    $message->id
                );
            }
            return $this->handleInitialize($message);
        }

        // Handle all other messages through capabilities
        return $this->handleCapabilityMessage($message);
    }

    private function handleInitialize(JsonRpcMessage $message): JsonRpcMessage
    {
        if (!isset($message->params['protocolVersion'])) {
            return JsonRpcMessage::error(
                JsonRpcMessage::INVALID_PARAMS,
                'Missing protocol version',
                $message->id
            );
        }

        $serverCapabilities = [];
        foreach ($this->capabilities as $capability) {
            $serverCapabilities = array_merge(
                $serverCapabilities,
                $capability->getCapabilities()
            );
        }

        // Initialize capabilities within try-catch
        try {
            foreach ($this->capabilities as $capability) {
                $capability->initialize();
            }
        } catch (\Throwable $e) {
            return JsonRpcMessage::error(
                JsonRpcMessage::INTERNAL_ERROR,
                $e->getMessage(),
                $message->id
            );
        }

        $this->initialized = true;

        return JsonRpcMessage::result(
            [
            'protocolVersion' => '2024-11-05',
            'capabilities' => $serverCapabilities,
            'serverInfo' => [
                'name' => $this->name,
                'version' => $this->version
            ],
            'instructions' => $this->getServerInstructions()
            ],
            $message->id
        );
    }

    private function handleShutdown(JsonRpcMessage $message): JsonRpcMessage
    {
        try {
            foreach ($this->capabilities as $capability) {
                $capability->shutdown();
            }

            $this->shuttingDown = true;
            return JsonRpcMessage::result([], $message->id);
        } catch (\Throwable $e) {
            return JsonRpcMessage::error(
                JsonRpcMessage::INTERNAL_ERROR,
                $e->getMessage(),
                $message->id
            );
        }
    }

    private function handleCapabilityMessage(JsonRpcMessage $message): ?JsonRpcMessage
    {
        $handlingCapability = null;

        // First find a capability that can handle this message
        foreach ($this->capabilities as $capability) {
            if ($capability->canHandleMessage($message)) {
                $handlingCapability = $capability;
                break;
            }
        }

        if (!$handlingCapability) {
            if ($message->isRequest()) {
                return JsonRpcMessage::error(
                    JsonRpcMessage::METHOD_NOT_FOUND,
                    "Method not found: {$message->method}",
                    $message->id
                );
            }
            return null;
        }

        // Now handle the message, knowing we have a capable handler
        return $handlingCapability->handleMessage($message);
    }

    private function getServerInstructions(): string
    {
        $instructions = [];

        $instructions[] = "This server implements the Model Context Protocol (MCP) and provides the following capabilities:";

        // Add more capability-specific instructions here

        return implode("\n", $instructions);
    }
}
