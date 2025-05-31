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

    public function __construct(
        private readonly string $name,
        private readonly string $version = '1.0.0'
    ) {
    }

    /**
     * Configures server authorization based on a shared token.
     * When set, the server will expect this token to be passed via the
     * MCP_AUTHORIZATION_TOKEN environment variable during initialization.
     *
     * @param string $expectedToken The token the server will expect.
     * @return void
     */
    public function requireAuthorization(string $expectedToken): void
    {
        if (empty($expectedToken)) {
            $this->logMessage('warning', 'Authorization required but an empty token was configured.', 'Server.Authorization');
        }
        $this->isAuthorizationRequired = true;
        $this->expectedAuthTokenValue = $expectedToken;
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
            $receivedMessages = null; // To keep it in scope for outer catch if needed, though less relevant now
            try {
                $receivedMessages = $this->transport->receive(); // Expects ?array

                if ($receivedMessages === null) { // No message, transport open
                    if ($this->transport->isClosed()) { // Should be redundant if receive() returns [] for closed
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
                    // JSON-RPC spec says: "If the batch rpc call itself fails to be recognized as an valid JSON or as an array with at least one element, the response from the Server MUST be a single Response object."
                    // However, our receive() returning [] for "empty batch" might be a slight deviation, or means "nothing to process".
                    // For now, if it's an empty array and transport isn't closed, we can send an error or ignore.
                    // Current TransportInterface implies [] means "transport closed".
                    // Let's assume `receive()` returning `[]` means "transport definitively closed or nothing to process that warrants a response".
                    // If it was an invalid empty batch `[]` that needs an error, `JsonRpcMessage::fromJsonArray` should have thrown.
                    // So, if `empty($receivedMessages)` and not `null`, we can break.
                    break;
                }

                $responseMessages = [];
                foreach ($receivedMessages as $currentMessage) {
                    if (!$currentMessage instanceof JsonRpcMessage) {
                        // This shouldn't happen if transport->receive() is correct
                        $this->logMessage('error', "Received non-JsonRpcMessage object in batch.", 'Server.run');
                        // Potentially add a generic error to responseMessages if possible, though without an ID it's hard.
                        continue;
                    }
                    try {
                        $response = $this->handleMessage($currentMessage);
                        if ($response !== null) {
                            $responseMessages[] = $response;
                        }
                    } catch (\Throwable $e) {
                        $this->logMessage('error', "Error processing individual message (ID: {$currentMessage->id}): " . $e->getMessage() . "\n" . $e->getTraceAsString(), 'Server.run');
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
                            } elseif ($e->getCode() !== 0) { // For other exception types that might have a relevant code
                                $code = $e->getCode();
                            }

                            // Ensure code is within valid JSON-RPC error code range if possible, or use defined constants
                            if ($code === 0 || !is_int($code)) { // Ensure $code is a valid integer error.
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
                // This outer catch is for issues with transport->receive(), transport->send(),
                // or other unexpected errors not tied to a single message processing.
                $this->logMessage('critical', "Critical Server Error: " . $e->getMessage() . "\n" . $e->getTraceAsString(), 'Server.run');
                // We don't attempt to send an error response here as the transport itself might be compromised,
                // or we don't have a specific message context.
                // Consider if a specific type of error (e.g. TransportException on send) should cause a break.
                if ($e instanceof \MCP\Server\Exception\TransportException) {
                    $this->logMessage('critical', "TransportException occurred. Shutting down: " . $e->getMessage(), 'Server.run');
                    $this->shuttingDown = true; // Force shutdown on transport errors
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

    private function handleInitialize(JsonRpcMessage $message): JsonRpcMessage
    {
        if ($this->isAuthorizationRequired) {
            $tokenFromEnv = getenv('MCP_AUTHORIZATION_TOKEN');

            if ($tokenFromEnv === false || $tokenFromEnv === '') {
                $this->logMessage('error', 'Client failed to provide MCP_AUTHORIZATION_TOKEN during initialization.', 'Server.Authorization');
                return JsonRpcMessage::error(
                    -32000, // Implementation-defined server error
                    'Authorization required: MCP_AUTHORIZATION_TOKEN environment variable not set or empty.',
                    $message->id
                );
            }

            if ($this->expectedAuthTokenValue === null) {
                 $this->logMessage('critical', 'Authorization is required but no expected token is configured on the server.', 'Server.Authorization');
                 return JsonRpcMessage::error(
                    JsonRpcMessage::INTERNAL_ERROR,
                    'Server authorization configuration error.',
                    $message->id
                 );
            }

            if (!hash_equals((string)$this->expectedAuthTokenValue, $tokenFromEnv)) {
                $this->logMessage('error', 'Client provided an invalid MCP_AUTHORIZATION_TOKEN during initialization.', 'Server.Authorization');
                return JsonRpcMessage::error(
                    -32001, // Implementation-defined server error
                    'Authorization failed: Invalid token.',
                    $message->id
                );
            }
            $this->logMessage('info', 'Client successfully authorized via MCP_AUTHORIZATION_TOKEN.', 'Server.Authorization');
        }

        if (!isset($message->params['protocolVersion'])) {
            // This was the duplicated error block in the source read, it should be a single check for protocolVersion
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

        // Add inherent server capabilities not covered by CapabilityInterface instances
        // For now, assume they are always supported.
        // The value for these capabilities, if they have no sub-properties in the schema,
        // should be an empty object (e.g., new \stdClass() in PHP).
        $serverCapabilities['logging'] = new \stdClass();
        $serverCapabilities['completions'] = new \stdClass();

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

    private function handleSetLogLevel(JsonRpcMessage $message): JsonRpcMessage
    {
        $level = $message->params['level'] ?? null;

        if ($level === null || !is_string($level) || !self::isValidLogLevel($level)) {
            return JsonRpcMessage::error(
                JsonRpcMessage::INVALID_PARAMS,
                'Invalid or missing log level. Must be one of: ' . implode(', ', array_keys(self::$logLevelPriorities)),
                $message->id
            );
        }

        $this->clientSetLogLevel = strtolower($level);
        // Optionally log that the log level was changed, to the new level itself or a fixed level e.g. info
        $this->logMessage('info', "Client log level set to: {$this->clientSetLogLevel}");

        return JsonRpcMessage::result([], $message->id);
    }

    /**
     * Logs a message and potentially sends it to the client as a notification.
     *
     * @param string $level The log level (e.g., 'error', 'info', 'debug').
     * @param string $logContent The main textual content of the log message.
     * @param string|null $loggerName Optional name of the logger/module generating the message.
     * @param mixed|null $structuredData Optional structured data to include with the log.
     */
    public function logMessage(string $level, string $logContent, ?string $loggerName = null, mixed $structuredData = null): void
    {
        $levelLower = strtolower($level);
        if (!self::isValidLogLevel($levelLower)) {
            // Fallback for invalid levels, or throw error
            $levelLower = 'info'; // Default to 'info' or 'error'
        }

        // Local server log (e.g., to stderr or a file)
        // This format can be adjusted as needed.
        error_log(
            sprintf(
                "[%s] [%s] %s%s%s",
                date('Y-m-d H:i:s'),
                strtoupper($levelLower),
                $loggerName ? $loggerName . ': ' : '',
                $logContent,
                $structuredData !== null ? " | Data: " . json_encode($structuredData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ""
            )
        );

        // Check if the transport is available and if this message level should be sent to the client
        if ($this->transport && $this->clientSetLogLevel !== null && $this->shouldSendToClient($levelLower)) {
            $params = [
                'level' => $levelLower,
                // Per schema: `data` is any, `message` (from original schema) is string.
                // Let's use `data` for structured, or `message` for plain string.
                // The subtask says: 'data' => $structuredData ?? $logContent
                'data' => $structuredData ?? $logContent,
            ];

            // The original schema had `message: string`, `data?: any`. Let's try to adhere to that.
            // If structuredData is present, use it for `data`. The `logContent` is always the `message`.
            $params['message'] = $logContent;
            if ($structuredData !== null) {
                $params['data'] = $structuredData;
            } else {
                // If only logContent is there, spec says data is optional.
                // However, the prompt was `$structuredData ?? $logContent` for data field.
                // Let's stick to the prompt for now, it simplifies client handling if `data` is always there.
                // Re-evaluating: schema `notifications/message` has `level`, `message`, optional `data`, optional `logger`.
                // The prompt `data => $structuredData ?? $logContent` is a bit ambiguous.
                // Let's use: `message` for `logContent`, and `data` for `structuredData` if present.
                unset($params['data']); // remove the previous assignment
                $params['message'] = $logContent;
                if($structuredData !== null) {
                    $params['data'] = $structuredData;
                }
            }


            if ($loggerName !== null) {
                $params['logger'] = $loggerName;
            }

            try {
                // Ensure JsonRpcMessage can be created with named params
                $notification = JsonRpcMessage::notification('notifications/message', $params);
                $this->transport->send([$notification]); // Send as an array of one notification
            } catch (\Throwable $e) {
                // Log error locally if sending notification fails
                error_log("Failed to send log notification to client: " . $e->getMessage());
            }
        }
    }

    private static function isValidLogLevel(string $level): bool
    {
        return array_key_exists(strtolower($level), self::$logLevelPriorities);
    }

    private function shouldSendToClient(string $messageLevel): bool
    {
        if ($this->clientSetLogLevel === null) {
            return false; // No client level set, don't send
        }
        // Ensure levels are comparable by using lowercase consistently
        $messageLevelLower = strtolower($messageLevel);
        $clientSetLogLevelLower = strtolower($this->clientSetLogLevel);

        // Default to a high priority (less verbose) if level is unknown, to avoid spamming
        $messagePriority = self::$logLevelPriorities[$messageLevelLower] ?? LOG_DEBUG;
        $clientPriority = self::$logLevelPriorities[$clientSetLogLevelLower] ?? LOG_DEBUG; // Should be valid due to prior checks

        return $messagePriority <= $clientPriority;
    }
}
