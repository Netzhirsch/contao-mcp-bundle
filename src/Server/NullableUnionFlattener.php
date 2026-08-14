<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Server;

use PhpMcp\Schema\Tool;
use PhpMcp\Server\Registry;

/**
 * Rewrites every registered tool's input schema so that nullable-optional
 * parameters carry a SINGLE-STRING `type` instead of the `["null", T]` union
 * php-mcp's SchemaGenerator derives from `?bool $x = null` & friends.
 *
 * Why: several MCP bridges/clients (mcp-remote, the Claude Code deferred-tool
 * loader) only keep simple scalar `type` values and DROP every array/union
 * form from proxied schemas. The parameter then becomes typeless client-side,
 * its value is transmitted as `unknown`, and the server rejects the call with
 * `-32602 Invalid type … received unknown` — for ANY value. Verified twice:
 * first for object params (fixed per-param via `#[Schema(type:'object')]`,
 * v0.7.0-beta8), now for ALL flat optional params (`page_create.fallback`,
 * `.language`, `.layout`, `contao_call.args`, …, CWA-26 rest fix).
 *
 * The `"null"` branch in those unions is redundant anyway: optionality is
 * already expressed by `default: null` AND the parameter not being listed in
 * `required`. Dropping it loses nothing semantically — it only stops fragile
 * clients from discarding the type.
 *
 * Mapping (applied recursively, also inside `items` / nested `properties`):
 *   ["null","string"]  → "string"      ["null","boolean"] → "boolean"
 *   ["null","integer"] → "integer"     ["array","null"]   → "array"
 *   ["null","object"]  → "object"      ["null","number"]  → "number"
 *   ["string","integer", …] (no null, >1 types) → kept as-is (no such params
 *   today; flattening a real multi-type union would change semantics)
 *
 * Explicit `null` VALUES sent by well-behaved clients keep working: the
 * validation side ({@see ObjectAwareSchemaValidator}) treats null for a
 * non-required property as "not provided" before validating.
 *
 * Runs once per worker boot in {@see HttpDispatcherFactory::buildServer()},
 * AFTER discovery + extension registration, so it covers core tools, cached
 * discoveries and third-party tools alike. Idempotent — a single-string type
 * passes through untouched, so re-running on an already-flattened registry
 * (or a future php-mcp that emits single types itself) is a no-op.
 */
final class NullableUnionFlattener
{
    /**
     * Rewrite all tools in the registry in place (re-registering each tool
     * whose schema changed — Tool/inputSchema are readonly, the registry's
     * registerTool() overwrite is the supported mutation path).
     */
    public function flattenRegistry(Registry $registry): void
    {
        foreach (array_keys($registry->getTools()) as $name) {
            $registered = $registry->getTool($name);
            if ($registered === null) {
                continue;
            }

            $schema = $registered->schema;
            $flattened = $this->flattenSchema($schema->inputSchema);
            if ($flattened === $schema->inputSchema) {
                continue;
            }

            $registry->registerTool(
                Tool::make($schema->name, $flattened, $schema->description, $schema->annotations),
                $registered->handler,
                $registered->isManual,
            );
        }
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    public function flattenSchema(array $schema): array
    {
        foreach (['properties', 'patternProperties', '$defs', 'definitions'] as $bag) {
            if (isset($schema[$bag]) && \is_array($schema[$bag])) {
                foreach ($schema[$bag] as $key => $propSchema) {
                    if (\is_array($propSchema)) {
                        $schema[$bag][$key] = $this->flattenSchema($propSchema);
                    }
                }
            }
        }

        foreach (['items', 'additionalProperties'] as $single) {
            if (isset($schema[$single]) && \is_array($schema[$single])) {
                $schema[$single] = $this->flattenSchema($schema[$single]);
            }
        }

        if (isset($schema['type']) && \is_array($schema['type'])) {
            $nonNull = array_values(array_filter(
                $schema['type'],
                static fn ($t): bool => $t !== 'null',
            ));

            if (\count($nonNull) === 1 && \is_string($nonNull[0])) {
                $schema['type'] = $nonNull[0];
            }
            // 0 non-null types (pure ["null"]) or a real multi-type union:
            // leave untouched — neither occurs in our tools, and guessing a
            // type here would change semantics.
        }

        return $schema;
    }
}
