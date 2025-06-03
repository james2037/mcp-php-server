<?php

namespace MCP\Server\Tests\Tool;

use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Content\TextContent;
use MCP\Server\Tool\Tool;

#[ToolAttribute('greeter', 'A friendly greeter')]
class OptionalParamTool extends Tool
{
    /**
     * Greets a person with an optional title.
     *
     * @param string $name Name to greet.
     * @param string $title Optional title. Defaults to 'friend'.
     * @return TextContent[]
     */
    protected function executeTool(
        #[ParameterAttribute('name', type: 'string', description: 'Name to greet', required: true)]
        string $name,
        #[ParameterAttribute('title', type: 'string', description: 'Optional title', required: false)]
        string $title = 'friend'
    ): array {
        return [$this->createTextContent("Hello {$title} {$name}")];
    }

    protected function doExecute(array $arguments): array
    {
        // ValidateArguments in base Tool class handles required checks and type conformity based on attributes.
        // It also means $arguments will contain entries for all parameters of executeTool,
        // using default values from executeTool's signature if not present in original $arguments.
        // However, explicit defaults from attributes are not automatically passed yet by base validateArguments.
        // The current Tool::validateArguments doesn't add missing optional args with defaults to $arguments.
        // So, we need to handle optional args carefully here for passing to executeTool.

        $name = $arguments['name']; // Assumed present and string by validateArguments

        if (isset($arguments['title'])) {
            return $this->executeTool($name, $arguments['title']);
        }
        // If 'title' was not provided in the original call, rely on executeTool's default value.
        return $this->executeTool($name);
    }
}
