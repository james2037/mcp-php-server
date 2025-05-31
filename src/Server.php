<?php

/**
 * This file contains the Server class.
 */

namespace MCP\Server;

use MCP\Server\Message\JsonRpcMessage;
use MCP\Server\Transport\TransportInterface;
use MCP\Server\Capability\CapabilityInterface;

/**
 * Represents the MCP Server.
 *
 * This class is responsible for handling the server lifecycle,
 * managing capabilities, and processing messages via a transport.
 *
 * @var array<string, CapabilityInterface> $_capabilities Stores registered capabilities.
 */
class Server
{
    private array $capabilities = [];
    private bool $initialized = false;
    private bool $shuttingDown = false;
    private bool $capabilitiesAlreadyShutdown = false; // New flag
    private ?TransportInterface $transport = null;
    private ?string $clientSetLogLevel = null;

    // Authorization properties
    private bool $isAuthorizationRequired = false;
    private ?string $expectedAuthTokenValue = null;

    // PSR-3 Log Levels (RFC 5424)
    private static array $logLevelPriorities = [
        'emergency' => LOG_EMERG, // 0
        'alert'     => LOG_ALERT,   // 1
        'critical'  => LOG_CRIT,  // 2
        'error'     => LOG_ERR,     // 3
        'warning'   => LOG_WARNING, // 4
        'notice'    => LOG_NOTICE,  // 5
        'info'      => LOG_INFO,    // 6
        'debug'     => LOG_DEBUG,   // 7
    ];

    /**
     * Constructs a new Server instance.
     *
     * @param string $name The name of the server.
     * @param string $version The version of the server.
     */
    public function __construct(
        private readonly string $name,
        private readonly string $version = '1.0.0'
    ) {
    }

    /**
     * Configures server authorization based on a shared token.
     *
     * When set, the server will expect this token to be passed via the
     * MCP_AUTHORIZATION_TOKEN environment variable during initialization.
     *
     * @param string $expectedToken The token the server will expect.
     * @return void
     */
    public function requireAuthorization(string $expectedToken): void
    {
        if (empty($expectedToken)) {
            $this->logMessage(
                'warning',
                'Authorization required but an empty token was configured.',
                'Server.Authorization'
            );
        }
        $this->isAuthorizationRequired = true;
        $this->expectedAuthTokenValue = $expectedToken;
    }

    /**
     * Adds a capability to the server.
     *
     * @param CapabilityInterface $capability The capability to add.
     * @return void
     */
    public function addCapability(CapabilityInterface $capability): void
    {
        $this->capabilities[] = $capability;
    }

    /**
     * Connects a transport to the server.
     *
     * @param TransportInterface $transport The transport to connect.
     * @return void
     */
    public function connect(TransportInterface $transport): void
    {
        $this->transport = $transport;
    }

    /**
     * Runs the server, processing messages from the transport.
     *
     * @return void
     * @throws \RuntimeException If no transport is connected.
     */
    public function run(): void
    {
        if (!$this->transport) {
            throw new \RuntimeException('No transport connected');
        }

        while (!$this->shuttingDown) {
            // To keep it in scope for outer catch if needed
            $receivedMessages = null;
            try {
                $receivedMessages = $this->transport->receive(); // Expects ?array

                if ($receivedMessages === null) { // No message, transport open
                    // Should be redundant if receive() returns [] for closed
                    if ($this->transport->isClosed()) {
                        break;
                    }
                    continue;
                }

                if (empty($receivedMessages)) { // Transport closed or empty batch
                    if ($this->transport->isClosed()) {
                        break; // Closed, exit loop
                    }
                    // If it's an empty array `[]` from an open transport,
                    // it implies an empty batch request `[]` was received.
                    // JSON-RPC spec: "If the batch rpc call itself fails to be
                    // recognized as an valid JSON or as an array with at least
                    // one element, the response from the Server MUST be a
                    // single Response object."
                    // Our receive() returning [] for "empty batch" might be a
                    // slight deviation, or means "nothing to process".
                    // Current TransportInterface implies [] means "transport closed".
                    // Assume `receive()` returning `[]` means "transport
                    // definitively closed or nothing to process that warrants
                    // a response".
                    // If it was an invalid empty batch `[]` that needs an error,
                    // `JsonRpcMessage::fromJsonArray` should have thrown.
                    // So, if `empty($receivedMessages)` and not `null`, we can break.
                    break;
                }

                $responseMessages = [];
                foreach ($receivedMessages as $currentMessage) {
                    if (!$currentMessage instanceof JsonRpcMessage) {
                        // This shouldn't happen if transport->receive() is correct
                        $this->logMessage(
                            'error',
                            "Received non-JsonRpcMessage object in batch.",
                            'Server.run'
                        );
                        // Potentially add a generic error to responseMessages
                        // if possible, though without an ID it's hard.
                        continue;
                    }
                    try {
                        $response = $this->handleMessage($currentMessage);
                        if ($response !== null) {
                            $responseMessages[] = $response;
                        }
                    } catch (\Throwable $e) {
                        $logCtx = ['id' => $currentMessage->id, 'trace' => $e->getTraceAsString()];
                        $this->logMessage(
                            'error',
                            "Error processing individual message: " . $e->getMessage(),
                            'Server.run',
                            $logCtx
                        );
                        if ($currentMessage->isRequest()) {
                            $code = JsonRpcMessage::INTERNAL_ERROR; // Default
                            if ($e instanceof \MCP\Server\Exception\MethodNotSupportedException) {
                                $code = JsonRpcMessage::METHOD_NOT_FOUND;
                            } elseif ($e instanceof \MCP\Server\Exception\InvalidRequestException) {
                                $code = JsonRpcMessage::INVALID_REQUEST;
                            } elseif ($e instanceof \MCP\Server\Exception\InvalidParamsException) {
                                $code = JsonRpcMessage::INVALID_PARAMS;
                            } elseif ($e instanceof \RuntimeException && $e->getCode() !== 0) {
                                $code = $e->getCode();
                            } elseif ($e->getCode() !== 0) {
                                // For other exception types that might have a relevant code
                                $code = $e->getCode();
                            }

                            // Ensure code is within valid JSON-RPC error code range
                            if ($code === 0 || !is_int($code)) {
                                $code = JsonRpcMessage::INTERNAL_ERROR;
                            }

                            $responseMessages[] = JsonRpcMessage::error(
                                $code,
                                $e->getMessage(),
                                $currentMessage->id
                            );
                        }
                        // If it's a notification, we don't send an error response.
                    }
                }

                if (!empty($responseMessages)) {
                    $this->transport->send($responseMessages);
                }
            } catch (\Throwable $e) {
                // This outer catch is for issues with transport->receive(),
                // transport->send(), or other unexpected errors not tied to
                // a single message processing.
                $logCtx = ['trace' => $e->getTraceAsString()];
                $this->logMessage(
                    'critical',
                    "Critical Server Error: " . $e->getMessage(),
                    'Server.run',
                    $logCtx
                );
                // We don't attempt to send an error response here as the
                // transport itself might be compromised, or we don't have
                // a specific message context.
                // Consider if a specific type of error
                // (e.g. TransportException on send) should cause a break.
                if ($e instanceof \MCP\Server\Exception\TransportException) {
                    $this->logMessage(
                        'critical',
                        "TransportException occurred. Shutting down: " . $e->getMessage(),
                        'Server.run'
                    );
                    $this->shuttingDown = true; // Force shutdown
                }
            }
        }

        $this->shutdown();
    }

    /**
     * Shuts down the server and its capabilities.
     *
     * This method ensures that all registered capabilities have their
     * shutdown methods called. It handles potential errors during
     * capability shutdown by logging them.
     *
     * @return void
     */
    public function shutdown(): void
    {
        // If server was never initialized, and we are not already in a
        // shutdown sequence (e.g. initiated by handleShutdown),
        // then there's likely nothing to do.
        if (!$this->initialized && !$this->shuttingDown) {
            $this->shuttingDown = true; // Mark state
            return;
        }

        // If capabilities were already handled by handleShutdown,
        // don't do it again.
        if ($this->capabilitiesAlreadyShutdown) {
            $this->shuttingDown = true; // Ensure state is consistent
            return;
        }

        $this->shuttingDown = true; // Mark state
        foreach ($this->capabilities as $capability) {
            try {
                $capability->shutdown();
            } catch (\Throwable $e) {
                $capabilityClass = get_class($capability);
                $this->logMessage(
                    'error',
                    "Error during shutdown of capability '{$capabilityClass}': " .
                    $e->getMessage(),
                    'Server.shutdown'
                );
            }
        }
        $this->capabilitiesAlreadyShutdown = true; // Mark them as processed now
    }

    /**
     * Handles an incoming JSON-RPC message.
     *
     * This method determines the type of message and routes it to the
     * appropriate handler (e.g., initialize, shutdown, capability-specific).
     *
     * @param JsonRpcMessage $message The message to handle.
     * @return JsonRpcMessage|null A response message, or null for notifications.
     */
    private function handleMessage(JsonRpcMessage $message): ?JsonRpcMessage
    {
        // Always allow shutdown, even if not initialized
        if ($message->method === 'shutdown') {
            return $this->handleShutdown($message);
        }

        // Handle initialization
        if (!$this->initialized) {
            // Allow 'initialize' and 'logging/setLevel' before full initialization
            if ($message->method === 'initialize') {
                return $this->handleInitialize($message);
            } elseif ($message->method === 'logging/setLevel') {
                return $this->handleSetLogLevel($message);
            } else {
                return JsonRpcMessage::error(
                    JsonRpcMessage::INVALID_REQUEST,
                    'Server not initialized',
                    $message->id
                );
            }
        }

        // Handle other methods after initialization
        switch ($message->method) {
            case 'shutdown': // Already handled if moved here
                return $this->handleShutdown($message);
            case 'logging/setLevel':
                return $this->handleSetLogLevel($message);
            default:
                return $this->handleCapabilityMessage($message);
        }
    }

    /**
     * Handles the 'initialize' message.
     *
     * This method checks for authorization if required, validates the
     * protocol version, initializes capabilities, and returns server information.
     *
     * @param JsonRpcMessage $message The 'initialize' message.
     * @return JsonRpcMessage The response to the 'initialize' message.
     */
    private function handleInitialize(JsonRpcMessage $message): JsonRpcMessage
    {
        if ($this->isAuthorizationRequired) {
            $tokenFromEnv = getenv('MCP_AUTHORIZATION_TOKEN');

            if ($tokenFromEnv === false || $tokenFromEnv === '') {
                $this->logMessage(
                    'error',
                    'Client failed to provide MCP_AUTHORIZATION_TOKEN during initialization.',
                    'Server.Authorization'
                );
                return JsonRpcMessage::error(
                    -32000, // Implementation-defined server error
                    'Authorization required: MCP_AUTHORIZATION_TOKEN ' .
                    'environment variable not set or empty.',
                    $message->id
                );
            }

            if ($this->expectedAuthTokenValue === null) {
                $this->logMessage(
                    'critical',
                    'Authorization is required but no expected token is configured on the server.',
                    'Server.Authorization'
                );
                return JsonRpcMessage::error(
                    JsonRpcMessage::INTERNAL_ERROR,
                    'Server authorization configuration error.',
                    $message->id
                );
            }

            if (!hash_equals((string)$this->expectedAuthTokenValue, $tokenFromEnv)) {
                $this->logMessage(
                    'error',
                    'Client provided an invalid MCP_AUTHORIZATION_TOKEN during initialization.',
                    'Server.Authorization'
                );
                return JsonRpcMessage::error(
                    -32001, // Implementation-defined server error
                    'Authorization failed: Invalid token.',
                    $message->id
                );
            }
            $this->logMessage(
                'info',
                'Client successfully authorized via MCP_AUTHORIZATION_TOKEN.',
                'Server.Authorization'
            );
        }

        if (!isset($message->params['protocolVersion'])) {
            return JsonRpcMessage::error(
                JsonRpcMessage::INVALID_PARAMS,
                'Missing protocol version parameter in initialize request.',
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

        // Add inherent server capabilities
        $serverCapabilities['logging'] = new \stdClass();
        $serverCapabilities['completions'] = new \stdClass();

        // Initialize capabilities
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
                'protocolVersion' => '2025-03-26',
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

    /**
     * Handles the 'shutdown' message.
     *
     * This method attempts to shut down all capabilities and marks the server
     * as shutting down.
     *
     * @param JsonRpcMessage $message The 'shutdown' message.
     * @return JsonRpcMessage The response to the 'shutdown' message.
     */
    private function handleShutdown(JsonRpcMessage $message): JsonRpcMessage
    {
        try {
            // Attempt to shut down all capabilities
            foreach ($this->capabilities as $capability) {
                $capability->shutdown();
            }
            $this->shuttingDown = true;
            $this->capabilitiesAlreadyShutdown = true; // Mark as done by handler
            return JsonRpcMessage::result([], $message->id);
        } catch (\Throwable $e) {
            // If any capability fails to shut down, report this as an error
            // for the shutdown command.
            $this->shuttingDown = true; // Still ensure server stops
            $this->capabilitiesAlreadyShutdown = true; // Mark as attempted
            return JsonRpcMessage::error(
                JsonRpcMessage::INTERNAL_ERROR,
                $e->getMessage(), // Use exception message from failed capability
                $message->id
            );
        }
    }

    /**
     * Handles a message by delegating it to the appropriate capability.
     *
     * If no capability can handle the message, a 'Method not found' error
     * is returned for requests.
     *
     * @param JsonRpcMessage $message The message to handle.
     * @return JsonRpcMessage|null A response message, or null for notifications.
     */
    private function handleCapabilityMessage(
        JsonRpcMessage $message
    ): ?JsonRpcMessage {
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

    /**
     * Gets the server instructions.
     *
     * This method can be extended to provide more detailed instructions
     * based on the server's capabilities.
     *
     * @return string The server instructions.
     */
    private function getServerInstructions(): string
    {
        $instructions = [];

        $instructions[] = "This server implements the Model Context Protocol (MCP) " .
                          "and provides the following capabilities:";

        // Add more capability-specific instructions here

        return implode("\n", $instructions);
    }

    /**
     * Handles the 'logging/setLevel' message.
     *
     * Validates the provided log level and sets it for client-bound log messages.
     *
     * @param JsonRpcMessage $message The 'logging/setLevel' message.
     * @return JsonRpcMessage The response to the 'logging/setLevel' message.
     */
    private function handleSetLogLevel(JsonRpcMessage $message): JsonRpcMessage
    {
        $level = $message->params['level'] ?? null;

        if ($level === null || !is_string($level) || !self::isValidLogLevel($level)) {
            $validLevels = implode(', ', array_keys(self::$logLevelPriorities));
            return JsonRpcMessage::error(
                JsonRpcMessage::INVALID_PARAMS,
                "Invalid or missing log level. Must be one of: {$validLevels}",
                $message->id
            );
        }

        $this->clientSetLogLevel = strtolower($level);
        $this->logMessage(
            'info',
            "Client log level set to: {$this->clientSetLogLevel}"
        );

        return JsonRpcMessage::result([], $message->id);
    }

    /**
     * Logs a message and potentially sends it to the client as a notification.
     *
     * @param string      $level          The log level (e.g., 'error', 'info', 'debug').
     * @param string      $logContent     The main textual content of the log message.
     * @param string|null $loggerName     Optional name of the logger/module.
     * @param mixed|null  $structuredData Optional structured data.
     * @return void
     */
    public function logMessage(
        string $level,
        string $logContent,
        ?string $loggerName = null,
        mixed $structuredData = null
    ): void {
        $levelLower = strtolower($level);
        if (!self::isValidLogLevel($levelLower)) {
            $levelLower = 'info'; // Default to 'info' or 'error'
        }

        // Local server log
        $logParts = [
            sprintf("[%s]", date('Y-m-d H:i:s')),
            sprintf("[%s]", strtoupper($levelLower)),
        ];
        if ($loggerName) {
            $logParts[] = $loggerName . ':';
        }
        $logParts[] = $logContent;
        if ($structuredData !== null) {
            $logParts[] = "| Data: " . json_encode(
                $structuredData,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        }
        error_log(implode(' ', $logParts));

        // Check if the transport is available and if this message level
        // should be sent to the client
        if (
            $this->transport &&
            $this->clientSetLogLevel !== null &&
            $this->shouldSendToClient($levelLower)
        ) {
            $params = ['level' => $levelLower];
            $params['message'] = $logContent;
            if ($structuredData !== null) {
                $params['data'] = $structuredData;
            }
            if ($loggerName !== null) {
                $params['logger'] = $loggerName;
            }

            try {
                $notification = new JsonRpcMessage('notifications/message', $params);
                // Send as an array of one notification
                $this->transport->send([$notification]);
            } catch (\Throwable $e) {
                // Log error locally if sending notification fails
                error_log(
                    "Failed to send log notification to client: " . $e->getMessage()
                );
            }
        }
    }

    /**
     * Checks if a given log level is valid.
     *
     * @param string $level The log level to check.
     * @return bool True if the log level is valid, false otherwise.
     */
    private static function isValidLogLevel(string $level): bool
    {
        return array_key_exists(strtolower($level), self::$logLevelPriorities);
    }

    /**
     * Determines if a log message should be sent to the client based on current levels.
     *
     * @param string $messageLevel The level of the message to be logged.
     * @return bool True if the message should be sent, false otherwise.
     */
    private function shouldSendToClient(string $messageLevel): bool
    {
        if ($this->clientSetLogLevel === null) {
            return false; // No client level set, don't send
        }
        // Ensure levels are comparable by using lowercase consistently
        $messageLevelLower = strtolower($messageLevel);
        $clientSetLogLevelLower = strtolower($this->clientSetLogLevel);

        // Default to a high priority (less verbose) if level is unknown
        $messagePriority = self::$logLevelPriorities[$messageLevelLower] ?? LOG_DEBUG;
        // Should be valid due to prior checks
        $clientPriority = self::$logLevelPriorities[$clientSetLogLevelLower] ?? LOG_DEBUG;

        return $messagePriority <= $clientPriority;
    }
}
