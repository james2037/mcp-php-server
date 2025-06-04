<?php

namespace MCP\Server;

use MCP\Server\Message\JsonRpcMessage;
use MCP\Server\Transport\TransportInterface;
use MCP\Server\Capability\CapabilityInterface;
use MCP\Server\Transport\HttpTransport;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use MCP\Server\Exception\TransportException;

/**
 * Represents the MCP Server.
 *
 * This class is responsible for handling the server lifecycle,
 * managing capabilities, and processing messages via a transport.
 */
class Server
{
    /** @var array<int, CapabilityInterface> Registered capabilities. */
    private array $capabilities = [];
    /** Flag indicating if the server has been initialized. */
    private bool $initialized = false;
    /** Flag indicating if the server is in the process of shutting down. */
    private bool $shuttingDown = false;
    /** Flag to prevent shutting down capabilities multiple times. */
    private bool $capabilitiesAlreadyShutdown = false;
    /** The transport layer used for communication. */
    private ?TransportInterface $transport = null;
    /** The log level set by the client via 'logging/setLevel'. */
    private ?string $clientSetLogLevel = null;
    // private ?ServerRequestInterface $currentHttpRequest = null; // Removed by simplification

    /** Whether client authorization is required. */
    private bool $isAuthorizationRequired = false;
    /** The expected authorization token value if authorization is required. */
    private ?string $expectedAuthTokenValue = null;

    /** @var array<string, int> Mapping of PSR-3 log levels to system log priorities. */
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
     * @param string $name The name of the server. (Readonly property)
     * @param string $version The version of the server. (Readonly property)
     */
    public function __construct(
        private readonly string $name,
        private readonly string $version = '1.0.0'
    ) {
    }

    // setNextResponsePrefersSse method removed
    // setCurrentHttpRequest method removed

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

        // Handle HTTP request-response lifecycle if HttpTransport is used
        if ($this->transport instanceof HttpTransport) {
            $this->runHttpRequestCycle();
            return; // Exit after handling one HTTP request
        }

        // Existing StdioTransport loop
        while (!$this->shuttingDown) {
            $receivedMessages = null; // To keep it in scope for outer catch if needed
            try {
                $receivedMessages = $this->transport->receive(); // Expects ?array

                if ($receivedMessages === null) { // No message, transport open
                    if ($this->transport->isClosed()) {
                        break;
                    }
                    continue;
                }

                if (empty($receivedMessages)) { // Transport closed or empty batch
                    if ($this->transport->isClosed()) {
                        break; // Closed, exit loop
                    }
                    break; // Assume empty batch means end or error, exit.
                }

                $responseMessages = [];
                foreach ($receivedMessages as $currentMessage) {
                    if (!$currentMessage instanceof JsonRpcMessage) {
                        $this->logMessage(
                            'error',
                            "Received non-JsonRpcMessage object in batch.",
                            'Server.run'
                        );
                        continue;
                    }
                    $response = $this->processSingleMessage($currentMessage); // Use processSingleMessage for cleaner loop
                    if ($response) {
                        $responseMessages[] = $response;
                    }
                }

                if (!empty($responseMessages)) {
                    $this->transport->send($responseMessages);
                }
            } catch (\Throwable $e) {
                $logCtx = ['trace' => $e->getTraceAsString()];
                $this->logMessage(
                    'critical',
                    "Critical Server Error in stdio loop: " . $e->getMessage(),
                    'Server.run',
                    $logCtx
                );
                if ($e instanceof \MCP\Server\Exception\TransportException) {
                    $this->logMessage(
                        'critical',
                        "TransportException occurred. Shutting down: " . $e->getMessage(),
                        'Server.run'
                    );
                    $this->shuttingDown = true; // Force shutdown
                }
                // For stdio, we might not be able to send a JSON-RPC error if transport is broken.
            }
        }
        $this->shutdown();
    }

    /**
     * Handles the request-response cycle for HTTP transport.
     * It receives a payload, processes it (single or batch),
     * sends the response(s), and emits the HTTP response.
     */
    private function runHttpRequestCycle(): void
    {
        if (!($this->transport instanceof HttpTransport)) {
            $this->logMessage('critical', 'HttpTransport not available in runHttpRequestCycle', 'Server.run');
            return;
        }
        /** @var HttpTransport $httpTransport */
        $httpTransport = $this->transport;

        $responsePayload = null;

        try {
            // HttpTransport::receive() now returns JsonRpcMessage[]|null|false or throws TransportException.
            $messagesReceived = $httpTransport->receive();
            $isBatchResponse = false; // Default: assume response is not a batch unless determined otherwise.

            if ($messagesReceived === false) {
                // Transport validation failed (e.g., wrong HTTP method, wrong Content-Type).
                $this->logMessage('error', 'HttpTransport->receive() returned false, request unsuitable.', 'Server.runHttpRequestCycle');
                $responsePayload = JsonRpcMessage::error(JsonRpcMessage::INVALID_REQUEST, 'Invalid request (transport level error).', null);
            } elseif ($messagesReceived === null) {
                // Empty or whitespace-only body, parseMessages returned null.
                $this->logMessage('error', 'HttpTransport->receive() returned null (empty request body).', 'Server.runHttpRequestCycle');
                $responsePayload = JsonRpcMessage::error(JsonRpcMessage::INVALID_REQUEST, 'Request body was empty or contained only whitespace.', null);
            } else {
                // $messagesReceived is JsonRpcMessage[] (can be an empty array for "[]" batch from parseMessages)
                $isBatchResponse = $httpTransport->lastRequestAppearedAsBatch();
                $responseMessages = $this->processMessageBatch($messagesReceived); // Use the new helper

                if ($isBatchResponse) {
                    // For a batch request, always respond with an array of responses.
                    // $responseMessages is already JsonRpcMessage[] from processMessageBatch.
                    // If all were notifications, $responseMessages will be empty, resulting in "[]" response.
                    $responsePayload = $responseMessages;
                } elseif (!empty($responseMessages)) {
                    // Single request that yielded a response.
                    $responsePayload = $responseMessages[0];
                } else {
                    // Single request that was a notification (no response generated),
                    // or an empty batch "[]" that didn't appear as a batch (edge case, defensive).
                    // $responsePayload remains null.
                    $responsePayload = null;
                }
            }
        } catch (TransportException $e) {
            $this->logMessage('error', 'TransportException in HTTP cycle: ' . $e->getMessage(), 'Server.runHttpRequestCycle', ['code' => $e->getCode()]);
            $errorCode = (is_int($e->getCode()) && $e->getCode() !== 0) ? $e->getCode() : JsonRpcMessage::INTERNAL_ERROR;
            $responsePayload = JsonRpcMessage::error($errorCode, $e->getMessage(), null);
            $isBatchResponse = false; // Not a batch response in case of these transport errors before processing
        } catch (\Throwable $e) { // Catchall for unexpected errors
            $this->logMessage('critical', "Critical Server Error in HTTP cycle: " . $e->getMessage(), 'Server.runHttpRequestCycle', ['trace' => $e->getTraceAsString()]);
            $responsePayload = JsonRpcMessage::error(JsonRpcMessage::INTERNAL_ERROR, 'Internal server error.', null);
            $isBatchResponse = false; // Not a batch response for critical internal errors
        }

        // Only call send if there's a non-null payload OR if it's a batch response (which might be an empty array for all-notification batches).
        if ($responsePayload !== null || $isBatchResponse) {
             /** @var JsonRpcMessage|JsonRpcMessage[] $responsePayload PHPStan type hint */
            // If $isBatchResponse is true, $responsePayload is guaranteed to be an array (possibly empty from processMessageBatch).
            // If $responsePayload is not null and $isBatchResponse is false, it's a JsonRpcMessage.
            $httpTransport->send($responsePayload);
        }
        // If $responsePayload is null AND $isBatchResponse is false (e.g. single notification), send() is not called.
        // HttpTransport->getResponse() will then provide a default (e.g. HTTP 204 or empty 200).
        $httpResponse = $httpTransport->getResponse(); // This will always return a ResponseInterface

        if (!headers_sent()) {
            $emitter = new SapiEmitter();
            try {
                $emitter->emit($httpResponse);
            } catch (\Exception $e) {
                $this->logMessage('critical', 'SapiEmitter failed to emit response: ' . $e->getMessage(), 'Server.runHttpRequestCycle');
                    // If emit() fails, we attempt to send a 500 error, but only if headers haven't been (partially) sent by the failed emit().
                    // Assuming if headers_sent() was false before emit(), and emit() failed,
                    // it's safer to assume headers might be in an indeterminate state or not sent.
                    // The initial check for headers_sent() before calling emit() is the main guard.
                    // If emit fails, it's unlikely we can reliably send a new response.
                    // However, if NO headers were sent by emit() before it failed, this is a last resort.
                if (!headers_sent()) {
                    http_response_code(500);
                    echo "Error emitting response.";
                }
            }
        } else {
            $this->logMessage('info', 'Headers already sent, SapiEmitter skipped.', 'Server.runHttpRequestCycle');
        }
    }

    /**
     * Processes a batch of received JsonRpcMessage objects.
     *
     * @param JsonRpcMessage[] $receivedMessages An array of messages to process.
     *                                           This array can be empty if an empty batch "[]" was received.
     * @return JsonRpcMessage[] An array of response messages. Empty if all messages were notifications or errors for notifications.
     */
    private function processMessageBatch(array $receivedMessages): array
    {
        $responseMessages = [];
        if (empty($receivedMessages)) {
            // If an empty batch "[]" was received and parsed by AbstractTransport::parseMessages,
            // $receivedMessages will be an empty array. No processing needed.
            return [];
        }

        foreach ($receivedMessages as $currentMessage) {
            // Type safety: $currentMessage should already be JsonRpcMessage
            // due to the return type of AbstractTransport::parseMessages.
            $response = $this->processSingleMessage($currentMessage);
            if ($response) {
                $responseMessages[] = $response;
            }
        }
        return $responseMessages;
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
        if (!$this->initialized && !$this->shuttingDown) {
            $this->shuttingDown = true;
            return;
        }
        if ($this->capabilitiesAlreadyShutdown) {
            $this->shuttingDown = true;
            return;
        }

        $this->shuttingDown = true;
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
        $this->capabilitiesAlreadyShutdown = true;
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
        if ($message->method === 'shutdown') {
            return $this->handleShutdown($message);
        }

        if (!$this->initialized) {
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

        switch ($message->method) {
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
                $this->logMessage('error', 'Client failed to provide MCP_AUTHORIZATION_TOKEN during initialization.', 'Server.Authorization');
                return JsonRpcMessage::error(-32000, 'Authorization required: MCP_AUTHORIZATION_TOKEN environment variable not set or empty.', $message->id);
            }
            if ($this->expectedAuthTokenValue === null) {
                $this->logMessage('critical', 'Authorization is required but no expected token is configured on the server.', 'Server.Authorization');
                return JsonRpcMessage::error(JsonRpcMessage::INTERNAL_ERROR, 'Server authorization configuration error.', $message->id);
            }
            if (!hash_equals((string)$this->expectedAuthTokenValue, $tokenFromEnv)) {
                $this->logMessage('error', 'Client provided an invalid MCP_AUTHORIZATION_TOKEN during initialization.', 'Server.Authorization');
                return JsonRpcMessage::error(-32001, 'Authorization failed: Invalid token.', $message->id);
            }
            $this->logMessage('info', 'Client successfully authorized via MCP_AUTHORIZATION_TOKEN.', 'Server.Authorization');
        }

        if (!isset($message->params['protocolVersion'])) {
            return JsonRpcMessage::error(JsonRpcMessage::INVALID_PARAMS, 'Missing protocol version parameter in initialize request.', $message->id);
        }

        // Session ID handling removed as HttpTransport no longer manages these via getClientSessionId/setServerSessionId

        try {
            foreach ($this->capabilities as $capability) {
                $capability->initialize();
            }
        } catch (\Throwable $e) {
            return JsonRpcMessage::error(JsonRpcMessage::INTERNAL_ERROR, $e->getMessage(), $message->id);
        }

        $this->initialized = true;
        return JsonRpcMessage::result(
            [
                'protocolVersion' => '2025-03-26',
                'capabilities' => $this->getServerCapabilitiesArray(),
                'serverInfo' => ['name' => $this->name, 'version' => $this->version],
                'instructions' => $this->getServerInstructions(),
            ],
            $message->id
        );
    }

    /**
     * Gathers capabilities from all registered modules and formats them for the 'initialize' response.
     * Also adds standard 'logging' and 'completions' capabilities.
     *
     * @return array<string, mixed> Associative array of server capabilities.
     */
    private function getServerCapabilitiesArray(): array
    {
        $serverCapabilities = [];
        foreach ($this->capabilities as $capability) {
            $serverCapabilities = array_merge($serverCapabilities, $capability->getCapabilities());
        }
        $serverCapabilities['logging'] = new \stdClass(); // Indicates presence of logging/setLevel
        $serverCapabilities['completions'] = new \stdClass(); // Indicates presence of completion/complete
        return $serverCapabilities;
    }

    /**
     * Processes a single JsonRpcMessage.
     * This method encapsulates the logic for handling a message and catching exceptions,
     * converting them to JSON-RPC error responses if the message was a request.
     *
     * @param JsonRpcMessage $currentMessage The message to process.
     * @return JsonRpcMessage|null The response message, or null if it's a notification or an error occurs for a notification.
     */
    private function processSingleMessage(JsonRpcMessage $currentMessage): ?JsonRpcMessage
    {
        try {
            $response = $this->handleMessage($currentMessage);
            if ($response !== null) {
                return $response;
            }
        } catch (\Throwable $e) {
            $logCtx = ['id' => $currentMessage->id, 'trace' => $e->getTraceAsString()];
            $this->logMessage('error', "Error processing individual message: " . $e->getMessage(), 'Server.processSingleMessage', $logCtx);

            if ($currentMessage->isRequest()) { // Only generate error responses for requests
                $code = JsonRpcMessage::INTERNAL_ERROR; // Default error code
                if ($e instanceof \MCP\Server\Exception\MethodNotSupportedException) {
                    $code = JsonRpcMessage::METHOD_NOT_FOUND;
                } elseif ($e instanceof \MCP\Server\Exception\InvalidRequestException) {
                    $code = JsonRpcMessage::INVALID_REQUEST;
                } elseif ($e instanceof \MCP\Server\Exception\InvalidParamsException) {
                    $code = JsonRpcMessage::INVALID_PARAMS;
                } elseif ($e instanceof \RuntimeException && $e->getCode() !== 0 && is_int($e->getCode())) {
                    // Use the code from RuntimeException if it's a valid JSON-RPC error code
                    $code = $e->getCode();
                } elseif (is_int($e->getCode()) && $e->getCode() !== 0) {
                    // Use any other valid integer exception code
                    $code = $e->getCode();
                }
                // The following check was deemed always false by PHPStan because $code should always be a valid non-zero integer here.
                // if ($code === 0 || !is_int($code)) { // Ensure valid integer code
                //     $code = JsonRpcMessage::INTERNAL_ERROR;
                // }
                return JsonRpcMessage::error($code, $e->getMessage(), $currentMessage->id);
            }
        }
        return null; // No response for notifications or if an error occurs processing a notification
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
            foreach ($this->capabilities as $capability) {
                $capability->shutdown();
            }
            $this->shuttingDown = true;
            $this->capabilitiesAlreadyShutdown = true;
            return JsonRpcMessage::result([], $message->id);
        } catch (\Throwable $e) {
            $this->shuttingDown = true;
            $this->capabilitiesAlreadyShutdown = true;
            return JsonRpcMessage::error(JsonRpcMessage::INTERNAL_ERROR, $e->getMessage(), $message->id);
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
    private function handleCapabilityMessage(JsonRpcMessage $message): ?JsonRpcMessage
    {
        $handlingCapability = null;
        foreach ($this->capabilities as $capability) {
            if ($capability->canHandleMessage($message)) {
                $handlingCapability = $capability;
                break;
            }
        }

        if (!$handlingCapability) {
            if ($message->isRequest()) {
                return JsonRpcMessage::error(JsonRpcMessage::METHOD_NOT_FOUND, "Method not found: {$message->method}", $message->id);
            }
            return null;
        }
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
        $instructions = ["This server implements the Model Context Protocol (MCP) and provides the following capabilities:"];
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
            return JsonRpcMessage::error(JsonRpcMessage::INVALID_PARAMS, "Invalid or missing log level. Must be one of: {$validLevels}", $message->id);
        }
        $this->clientSetLogLevel = strtolower($level);
        $this->logMessage('info', "Client log level set to: {$this->clientSetLogLevel}");
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
            $levelLower = 'info';
        }

        $logParts = [sprintf("[%s]", date('Y-m-d H:i:s')), sprintf("[%s]", strtoupper($levelLower)),];
        if ($loggerName) {
            $logParts[] = $loggerName . ':';
        }
        $logParts[] = $logContent;
        if ($structuredData !== null) {
            $logParts[] = "| Data: " . json_encode($structuredData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        error_log(implode(' ', $logParts));

        if ($this->transport && $this->clientSetLogLevel !== null && $this->shouldSendToClient($levelLower)) {
            $params = ['level' => $levelLower, 'message' => $logContent];
            if ($structuredData !== null) {
                $params['data'] = $structuredData;
            }
            if ($loggerName !== null) {
                $params['logger'] = $loggerName;
            }
            try {
                $notification = new JsonRpcMessage('notifications/message', $params);
                $this->transport->send([$notification]);
            } catch (\Throwable $e) {
                error_log("Failed to send log notification to client: " . $e->getMessage());
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
            return false;
        }
        $messageLevelLower = strtolower($messageLevel);
        $clientSetLogLevelLower = strtolower($this->clientSetLogLevel);
        $messagePriority = self::$logLevelPriorities[$messageLevelLower] ?? LOG_DEBUG;
        $clientPriority = self::$logLevelPriorities[$clientSetLogLevelLower] ?? LOG_DEBUG;
        return $messagePriority <= $clientPriority;
    }
}
