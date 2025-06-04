<?php

namespace MCP\Server\Tests\Transport;

use MCP\Server\Transport\HttpTransport;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use MCP\Server\Exception\TransportException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use MCP\Server\Message\JsonRpcMessage; // For error codes
use Laminas\Diactoros\ServerRequestFactory;

// Moved here

class TestableHttpTransport extends HttpTransport
{
    private ?ServerRequestInterface $mockRequest = null;
    // Removed: private ?\Throwable $exceptionToThrowOnReceive = null;

    public function __construct(
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        // Allow injecting the initial mock request directly via constructor for convenience
        ?ServerRequestInterface $initialMockRequest = null
    ) {
        // HttpTransport's constructor expects a ServerRequestInterface or null.
        // We pass the $initialMockRequest here. If it's null, HttpTransport creates one from globals.
        // Our getRequest() override will ensure this mock is used if set later via setMockRequest.
        parent::__construct($responseFactory, $streamFactory, $initialMockRequest);
        if ($initialMockRequest !== null) {
            $this->mockRequest = $initialMockRequest;
            // Ensure the protected $this->request in HttpTransport is also set.
            $this->request = $initialMockRequest;
        }
    }

    // This is the primary way to inject or update the request for the test.
    public function setMockRequest(ServerRequestInterface $request): void
    {
        $this->mockRequest = $request;
        // Update the protected $this->request in HttpTransport.
        $this->request = $this->mockRequest;
    }

    // Removed: public function setExceptionToThrowOnReceive(\Throwable $e)

    /**
     * Override getRequest to ensure our mock request is used by receive().
     * HttpTransport::receive() calls $this->request. This method ensures $this->request is our mock.
     * Note: HttpTransport::receive() itself uses $this->request directly, not $this->getRequest().
     * The constructor and setMockRequest now directly set $this->request (protected in parent).
     * This method is more of a conceptual override if there were other places getRequest() was used.
     * For direct testing of HttpTransport::receive(), setting $this->request via setMockRequest is key.
     */
    // public function getRequest(): ServerRequestInterface // This override might not be strictly necessary
    // {
    //     if ($this->mockRequest !== null) {
    //         return $this->mockRequest;
    //     }
    //     // Fallback to parent or error if not set, though HttpTransport sets $this->request in constructor.
    //     return parent::getRequest(); // Or throw if always expect mock to be set.
    // }

    // Removed: public function receive(): array
    // We want to test HttpTransport::receive(), not override it here.

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
        // HttpTransport::getResponse() always returns a ResponseInterface, so no null check needed here.
        return $this->getResponse();
    }
}
