<?php

namespace MCP\Server\Tests\Transport;

use MCP\Server\Transport\HttpTransport;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use MCP\Server\Exception\TransportException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use MCP\Server\Message\JsonRpcMessage; // For error codes
use Laminas\Diactoros\ServerRequestFactory; // Moved here

class TestableHttpTransport extends HttpTransport
{
    private ?ServerRequestInterface $mockRequest = null;
    private ?\Throwable $exceptionToThrowOnReceive = null;

    public function __construct(
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null
    ) {
        // Pass null for the request. The parent HttpTransport will default to fromGlobals(),
        // but TestableHttpTransport::getRequest() overrides to use the mock request.
        // Factories are passed through; if null, parent HttpTransport creates defaults.
        parent::__construct($responseFactory, $streamFactory, null);
    }

    // This is the primary way to inject the request for the test.
    public function setMockRequest(ServerRequestInterface $request): void
    {
        $this->mockRequest = $request;
        // Also update the internal $this->request property of AbstractTransport
        // so that if any parent method relies on $this->request directly, it's set.
        // And so that our getRequest() override below can also set it if needed.
        $this->request = $this->mockRequest;
    }

    public function setExceptionToThrowOnReceive(\Throwable $e): void
    {
        $this->exceptionToThrowOnReceive = $e;
    }

    /**
     * Override getRequest to ensure our mock request is used by receive().
     * The receive() method (whether parent's or overridden) will call getRequest().
     */
    public function getRequest(): ServerRequestInterface
    {
        if ($this->mockRequest !== null) {
            // Ensure the parent's $this->request is also updated if it hasn't been.
            if ($this->request !== $this->mockRequest) {
                $this->request = $this->mockRequest;
            }
            return $this->mockRequest;
        }
        // If no mock request is set, and we are in a test environment,
        // calling parent::getRequest() could lead to errors if it tries to access SAPI globals.
        throw new \LogicException('Mock request not set in TestableHttpTransport. Call setMockRequest() before receive() is triggered.');
    }

    /**
     * Override receive to use the mock request's body or throw a predefined exception.
     * This method is called by Server::runHttpRequestCycle().
     */
    public function receive(): array
    {
        if ($this->exceptionToThrowOnReceive !== null) {
            $e = $this->exceptionToThrowOnReceive;
            $this->exceptionToThrowOnReceive = null; // Clear after use to prevent re-throwing
            throw $e;
        }

        // Our overridden getRequest() will be called here.
        $currentRequest = $this->getRequest(); 
        $body = $currentRequest->getBody();
        $body->rewind(); // Ensure reading from the start of the stream
        $contents = $body->getContents();

        if (empty($contents)) {
            throw new TransportException('Request body is empty.', TransportException::CODE_EMPTY_REQUEST_BODY);
        }

        $decoded = json_decode($contents, true);

        // Check for JSON decoding errors
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new TransportException('JSON decode error: ' . json_last_error_msg(), JsonRpcMessage::PARSE_ERROR);
        }

        // Validate the basic structure of the decoded JSON for JSON-RPC
        if (!is_array($decoded)) {
            // Must be a JSON object (associative array) or array of objects (numerically indexed array for batch)
            throw new TransportException('Invalid JSON payload: expected JSON object or array of objects.', JsonRpcMessage::INVALID_REQUEST);
        }

        $isBatch = array_is_list($decoded);
        if ($isBatch) { // Batch request
            if (empty($decoded)) { // Batch array cannot be empty
                throw new TransportException('Invalid JSON-RPC batch: Batch array cannot be empty.', JsonRpcMessage::INVALID_REQUEST);
            }
            foreach ($decoded as $item) {
                // Each item in a batch must be a JSON object (associative array)
                if (!is_array($item) || array_is_list($item)) {
                    throw new TransportException('Invalid JSON-RPC batch: All items in batch must be JSON objects.', JsonRpcMessage::INVALID_REQUEST);
                }
            }
        } else { // Single request (must be an associative array/JSON object)
            // If $decoded is an empty array [], it means the JSON was "{}"
            // This is an empty object, which is not a valid JSON-RPC request.
            if (empty($decoded)) { 
                throw new TransportException('Invalid JSON-RPC request: Request object cannot be empty.', JsonRpcMessage::INVALID_REQUEST);
            }
        }
        
        // The Server will further validate 'jsonrpc', 'method', 'params', 'id' fields.
        return $decoded;
    }

    /**
     * After the Server calls send() (which is the parent HttpTransport::send()),
     * the response is stored in $this->response (protected in AbstractTransport).
     * The Server then calls getResponse(). So, the parent's getResponse() will return what we need.
     * This method provides a clear way to retrieve that response in tests.
     */
    public function getCapturedResponse(): ResponseInterface
    {
        // HttpTransport::send() populates $this->response.
        // HttpTransport::getResponse() returns $this->response.
        $response = $this->getResponse(); 
        if ($response === null) {
            // This state should ideally not be reached if Server::runHttpRequestCycle called send().
            // If send() was called, $this->response (in parent) should be populated.
            throw new \LogicException('Response was not captured/available. Ensure HttpTransport::send() was successfully called by the Server.');
        }
        return $response;
    }
}
