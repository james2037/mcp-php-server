<?php

namespace MCP\Server\Tests\Transport;

use MCP\Server\Transport\StdioTransport;

/**
 * This class is a helper to test the original protected getXStream methods
 * of StdioTransport. It extends StdioTransport but does not override
 * the getXStream methods, nor does it define its own constructor,
 * so StdioTransport's constructor and original stream getters are used.
 */
class ProtectedAccessorStdioTransport extends StdioTransport
{
    /**
     * Calls the inherited getInputStream() from StdioTransport.
     * @return resource
     */
    public function callParentGetInputStream()
    {
        return $this->getInputStream();
    }

    /**
     * Calls the inherited getOutputStream() from StdioTransport.
     * @return resource
     */
    public function callParentGetOutputStream()
    {
        return $this->getOutputStream();
    }

    /**
     * Calls the inherited getErrorStream() from StdioTransport.
     * @return resource
     */
    public function callParentGetErrorStream()
    {
        return $this->getErrorStream();
    }
}
