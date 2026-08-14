<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Server;

use PhpMcp\Server\Utils\SchemaValidator;

/**
 * Drop-in replacement for php-mcp/server's {@see SchemaValidator} that fixes a
 * false-positive rejection of EMPTY object parameters (`fields: {}`,
 * `args: {}`, `filters: {}`).
 *
 * Root cause: the JSON-RPC body is decoded with `json_decode($json, true)`
 * (associative). A JSON empty object `{}` therefore arrives as an empty PHP
 * array `[]`, indistinguishable from an empty JSON array. php-mcp's
 * {@see SchemaValidator::convertDataForValidator()} leaves that `[]` as a PHP
 * array, and opis/json-schema cannot classify it as `object` — it reports
 * "Invalid type. Expected `null|object`, but received `unknown`." even though
 * the caller sent a perfectly valid (if empty) object.
 *
 * The information needed to disambiguate is the SCHEMA: the property is
 * declared `type: object`. So before delegating to the stock validator we walk
 * the arguments against their schema and coerce any empty array back to an
 * empty `stdClass` for properties whose schema allows `object` but NOT `array`.
 * Non-empty objects already validate correctly on every PHP version, so they
 * are untouched; properties that legitimately accept a list (`type: array`)
 * are left alone, preserving empty-list semantics.
 *
 * Second job: tolerate EXPLICIT `null` values for optional parameters. Since
 * {@see NullableUnionFlattener} rewrites `["null", T]` unions to a single `T`
 * (so fragile clients keep the type), a client that still sends
 * `fallback: null` instead of omitting the key would fail strict validation.
 * Semantically those are identical — every optional param defaults to null —
 * so we strip null-valued, non-required top-level properties from the
 * validation COPY. The handler still receives the original arguments
 * (nullable PHP params accept null natively); only validation is relaxed.
 *
 * Injected over the Dispatcher's internal validator by
 * {@see HttpDispatcherFactory::getDispatcher()} — no vendor patch required.
 */
final class ObjectAwareSchemaValidator extends SchemaValidator
{
    /**
     * @param array<string, mixed>|object $schema
     *
     * @return list<array{pointer: string, keyword: string, message: string}>
     */
    public function validateAgainstJsonSchema(mixed $data, array|object $schema): array
    {
        $data = $this->stripExplicitNullsForOptional($data, $schema);
        $data = $this->coerceEmptyObjects($data, $schema);

        return parent::validateAgainstJsonSchema($data, $schema);
    }

    /**
     * Remove top-level properties whose value is null and that the schema does
     * not list as required — "explicitly null" and "omitted" mean the same
     * thing for our optional tool params (all default to null).
     *
     * @param array<string, mixed>|object $schema
     */
    private function stripExplicitNullsForOptional(mixed $data, array|object $schema): mixed
    {
        if (!\is_array($data) || $data === []) {
            return $data;
        }

        $schemaArr = $this->normaliseSchema($schema);
        $props = $schemaArr['properties'] ?? null;
        if (!\is_array($props)) {
            return $data;
        }
        $required = $schemaArr['required'] ?? [];
        $required = \is_array($required) ? $required : [];

        foreach ($data as $key => $value) {
            if ($value === null && isset($props[$key]) && !\in_array($key, $required, true)) {
                unset($data[$key]);
            }
        }

        return $data;
    }

    /**
     * Recursively restore empty-object intent on data that was flattened to an
     * empty array by associative json_decode, guided by the schema's declared
     * property types.
     */
    private function coerceEmptyObjects(mixed $data, array|object $schema): mixed
    {
        if (!\is_array($data)) {
            return $data;
        }

        $schemaArr = $this->normaliseSchema($schema);
        $props = $schemaArr['properties'] ?? null;
        if (!\is_array($props)) {
            return $data;
        }

        foreach ($props as $key => $propSchema) {
            if (!\is_array($propSchema) || !\array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];
            if (!\is_array($value)) {
                continue;
            }

            // Empty array that the schema wants to be an object → empty {}.
            // Skip when the schema also allows a list, to keep []-as-array intact.
            if ($value === []
                && $this->typeAllows($propSchema, 'object')
                && !$this->typeAllows($propSchema, 'array')
            ) {
                $data[$key] = new \stdClass();
                continue;
            }

            // Recurse into objects with a defined shape (nested properties).
            if (isset($propSchema['properties'])) {
                $data[$key] = $this->coerceEmptyObjects($value, $propSchema);
            }
        }

        return $data;
    }

    /**
     * @param array<string, mixed>|object $schema
     *
     * @return array<string, mixed>
     */
    private function normaliseSchema(array|object $schema): array
    {
        if (\is_array($schema)) {
            return $schema;
        }

        try {
            $decoded = json_decode(json_encode($schema, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $propSchema
     */
    private function typeAllows(array $propSchema, string $type): bool
    {
        $declared = $propSchema['type'] ?? null;

        if (\is_string($declared)) {
            return $declared === $type;
        }

        if (\is_array($declared)) {
            return \in_array($type, $declared, true);
        }

        return false;
    }
}
