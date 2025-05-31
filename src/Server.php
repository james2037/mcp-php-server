<?php

/**
 * This file contains the Server class.
 */

namespace MCP\Server;

use MCP\Server\Message\JsonRpcMessage;
use MCP\Server\Transport\TransportInterface;
use MCP\Server\Capability\CapabilityInterface;
use MCP\Server\Transport\HttpTransport;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter; // Add this
use Psr\Http\Message\ServerRequestInterface; // For type hinting if needed
use MCP\Server\Exception\TransportException; // Ensure this is used if thrown by HttpTransport

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
    private ?ServerRequestInterface $currentHttpRequest = null; // Add this

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

    // Add a method to set the current HTTP request, if passed from an entry point
    public function setCurrentHttpRequest(ServerRequestInterface $request): void
    {
        $this->currentHttpRequest = $request;
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

        // Handle HTTP request-response lifecycle if HttpTransport is used
        if ($this->transport instanceof HttpTransport) {
            $this->runHttpRequestCycle();
            return; // Exit after handling one HTTP request
        }

        // Existing StdioTransport loop
        while (!$this->shuttingDown) {
            // To keep it in scope for outer catch if needed
            $receivedMessages = null;
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
                    break;
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
                    // Use processSingleMessage for cleaner loop
                    $response = $this->processSingleMessage($currentMessage);
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
            }
        }
        $this->shutdown();
    }


    private function runHttpRequestCycle(): void
    {
        if (!($this->transport instanceof HttpTransport)) {
            $this->logMessage('critical', 'HttpTransport not available in runHttpRequestCycle', 'Server.run');
            return;
        }

        $responseMessages = [];
        $errorResponse = null;

        try {
            $receivedMessages = $this->transport->receive(); // Can throw TransportException

            if ($receivedMessages === null) {
                // This case is for GET requests or POSTs with no body/unparsable body not throwing immediately.
                // If HttpTransport.receive() returns null for GET, it implies no messages to process,
                // but the connection might be for SSE.
                // If Origin validation failed in HttpTransport for GET, getResponse() will reflect 403.
            } elseif (empty($receivedMessages)) {
                // This means an empty batch "[]" was received from a POST request.
                // JSON-RPC spec indicates this is invalid.
                $errorResponse = JsonRpcMessage::error(
                    JsonRpcMessage::INVALID_REQUEST,
                    'Empty batch request is invalid.',
                    null
                );
            } else {
                foreach ($receivedMessages as $currentMessage) {
                    if (!$currentMessage instanceof JsonRpcMessage) {
                         $this->logMessage('error', "Received non-JsonRpcMessage object in HTTP batch.", 'Server.runHttpRequestCycle');
                        // This scenario should ideally result in a parse error at the transport level
                        // or be part of a general batch error. For now, skip.
                        continue;
                    }
                    // Use the refactored processSingleMessage
                    $response = $this->processSingleMessage($currentMessage);
                    if ($response) {
                        $responseMessages[] = $response;
                    }
                }
            }
        } catch (TransportException $e) {
            $this->logMessage('error', 'TransportException in HTTP cycle: ' . $e->getMessage(), 'Server.runHttpRequestCycle', ['code' => $e->getCode()]);
            $errorCode = ($e->getCode() !== 0 && is_int($e->getCode())) ? $e->getCode() : JsonRpcMessage::INTERNAL_ERROR;
            // Check if it's the specific Origin validation error code from HttpTransport
            if ($e->getMessage() === 'Origin not allowed.') { // This relies on the exact message
                 $errorCode = -32001; // The custom code used in HttpTransport
            }
            $errorResponse = JsonRpcMessage::error($errorCode, $e->getMessage(), null);
        } catch (\Throwable $e) {
            $this->logMessage('critical', "Critical Server Error in HTTP cycle: " . $e->getMessage(), 'Server.runHttpRequestCycle', ['trace' => $e->getTraceAsString()]);
            $errorResponse = JsonRpcMessage::error(JsonRpcMessage::INTERNAL_ERROR, 'Internal server error.', null);
        }

        // Send the response(s)
        if ($errorResponse) {
            $this->transport->send($errorResponse);
        } elseif (!empty($responseMessages)) {
            $this->transport->send($responseMessages);
        } elseif ($this->currentHttpRequest && $this->currentHttpRequest->getMethod() === 'GET') {
            // For GET requests (SSE stream initiation), if no errors and no specific messages to send,
            // call send([]) to trigger SSE header emission if not already handled by an Origin error.
             if (!$this->transport->getResponse()->getStatusCode() || $this->transport->getResponse()->getStatusCode() === 200) { // check if not already a 403
                $this->transport->send([]);
            }
        } else {
             // For POST with only notifications (HttpTransport handles 202) or other edge cases.
             // If $responseMessages is empty and no $errorResponse, and it's a POST,
             // it could be all notifications (handled by HttpTransport's 202) or an issue.
             // If HttpTransport did not set a 202, and we have no other response,
             // it might be appropriate to send a 204 or let HttpTransport's default response play out.
             // This path is less clear without more specific scenarios.
             // For now, if no error and no messages, and not a GET for SSE, we assume HttpTransport handles it.
             // One explicit case: POST that was an empty array `[]` which is an error (handled above).
             // If it was a POST with valid notifications only, HttpTransport.send() would set 202.
             // If it's a POST that somehow resulted in zero messages and no error, and not all notifications.
             // This shouldn't happen with current logic.
        }

        $httpResponse = $this->transport->getResponse();

        if ($httpResponse) {
            // Check if headers have already been sent by HttpTransport (e.g. for early 403 error on SSE GET)
            if (!headers_sent()) {
                $emitter = new SapiEmitter();
                try {
                    $emitter->emit($httpResponse);
                } catch (\Exception $e) {
                    // Catch emitter exceptions, e.g. if headers were already sent due to an error echo
                    $this->logMessage('critical', 'SapiEmitter failed to emit response: ' . $e->getMessage(), 'Server.runHttpRequestCycle');
                    // Fallback or ensure script termination if needed
                    if (!headers_sent()) { // Check again, just in case
                        http_response_code(500);
                        echo "Error emitting response.";
                    }
                }
            } elseif ($this->transport->isStreamOpen()) {
                 // Headers sent, stream open: this is an SSE stream, SapiEmitter would fail.
                 // HttpTransport::sendSseEventData handles direct echo.
                 // The script needs to be kept alive by the entry point for long-running SSE.
            } else {
                // Headers sent, but not an SSE stream - likely an error was echoed directly by HttpTransport.
                $this->logMessage('info', 'Headers already sent, SapiEmitter skipped.', 'Server.runHttpRequestCycle');
            }
        } else {
            $this->logMessage('critical', 'No PSR-7 response object available to emit.', 'Server.runHttpRequestCycle');
            if (!headers_sent()) {
                http_response_code(500); // Internal Server Error
                echo 'Internal Server Error: No response generated.';
            }
        }
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

        // Session ID handling for HttpTransport
        $clientSessionId = null;
        if ($this->transport instanceof HttpTransport) {
            $clientSessionId = $this->transport->getClientSessionId();
        }

        $serverSessionId = $clientSessionId ?: uniqid('mcp-session-');

        if ($this->transport instanceof HttpTransport) {
            $this->transport->setServerSessionId($serverSessionId);
        }

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

    private function getServerCapabilitiesArray(): array
    {
        $serverCapabilities = [];
        foreach ($this->capabilities as $capability) {
            $serverCapabilities = array_merge($serverCapabilities, $capability->getCapabilities());
        }
        $serverCapabilities['logging'] = new \stdClass();
        $serverCapabilities['completions'] = new \stdClass();
        return $serverCapabilities;
    }

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
            if ($currentMessage->isRequest()) {
                $code = JsonRpcMessage::INTERNAL_ERROR;
                if ($e instanceof \MCP\Server\Exception\MethodNotSupportedException) {
                    $code = JsonRpcMessage::METHOD_NOT_FOUND;
                } elseif ($e instanceof \MCP\Server\Exception\InvalidRequestException) { // Assuming this exists or similar
                    $code = JsonRpcMessage::INVALID_REQUEST;
                } elseif ($e instanceof \MCP\Server\Exception\InvalidParamsException) { // Assuming this exists
                    $code = JsonRpcMessage::INVALID_PARAMS;
                } elseif ($e instanceof \RuntimeException && $e->getCode() !== 0 && is_int($e->getCode())) {
                    $code = $e->getCode();
                } elseif (is_int($e->getCode()) && $e->getCode() !== 0) {
                    $code = $e->getCode();
                }
                if ($code === 0 || !is_int($code)) { // Ensure valid integer code
                    $code = JsonRpcMessage::INTERNAL_ERROR;
                }
                return JsonRpcMessage::error($code, $e->getMessage(), $currentMessage->id);
            }
        }
        return null;
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
            if ($structuredData !== null) $params['data'] = $structuredData;
            if ($loggerName !== null) $params['logger'] = $loggerName;
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
        if ($this->clientSetLogLevel === null) return false;
        $messageLevelLower = strtolower($messageLevel);
        $clientSetLogLevelLower = strtolower($this->clientSetLogLevel);
        $messagePriority = self::$logLevelPriorities[$messageLevelLower] ?? LOG_DEBUG;
        $clientPriority = self::$logLevelPriorities[$clientSetLogLevelLower] ?? LOG_DEBUG;
        return $messagePriority <= $clientPriority;
    }
}
