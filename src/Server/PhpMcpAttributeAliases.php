<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Server;

use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;

/**
 * Keeps extension tools written against php-mcp/server working.
 *
 * Up to 1.40 the server ran on php-mcp/server, and EXTENDING.md told
 * third-party authors to write `use PhpMcp\Server\Attributes\McpTool;`. That
 * package is gone now. Without it, an extension bundle that still imports the
 * old names would lose its tools without a word: an attribute whose class does
 * not exist is simply never matched.
 *
 * So the old names become aliases of the SDK attributes. The SDK looks its
 * attributes up with `IS_INSTANCEOF`, which resolves an alias to the class it
 * points at — an old `#[McpTool(name: …, description: …)]` and an old
 * `#[Schema(…)]` on a parameter are then read exactly like the new ones.
 * Named arguments line up between the two; a positional second argument would
 * not (php-mcp's was the description, the SDK's is the title), and EXTENDING.md
 * has only ever shown named ones.
 *
 * Nothing is aliased when the old class exists after all — some other package
 * still requires php-mcp/server. {@see ExtensionToolRegistrar} then reads the
 * old attribute by name instead.
 */
final class PhpMcpAttributeAliases
{
    private const ALIASES = [
        'PhpMcp\\Server\\Attributes\\McpTool' => McpTool::class,
        'PhpMcp\\Server\\Attributes\\Schema' => Schema::class,
        'PhpMcp\\Server\\Attributes\\McpPrompt' => McpPrompt::class,
    ];

    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;

        foreach (self::ALIASES as $old => $new) {
            // With autoloading: a php-mcp/server still installed by someone
            // else must keep its own class, or aliasing would collide with it.
            if (!class_exists($old) && class_exists($new)) {
                class_alias($new, $old);
            }
        }
    }

    /**
     * @return list<string> the attribute class names a tool method may carry
     */
    public static function toolAttributeNames(): array
    {
        return [McpTool::class, 'PhpMcp\\Server\\Attributes\\McpTool'];
    }
}
