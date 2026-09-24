<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Service;

use Contao\Model;
use Netzhirsch\ContaoMcpBundle\Tool\Contract\FieldProvider;

/**
 * The extension-field plumbing every table-specific FieldMapper needs.
 *
 * Field providers let another bundle (contao-bootstrap-bundle, changelanguage,
 * …) contribute columns it owns to a table this bundle maps. Article and
 * CalendarEvent grew their own copy of the wiring; tl_theme needed the third,
 * and tl_layout / tl_content are queued behind it. Copying it a fourth time is
 * how the copies drift apart — so it lives here once, and a mapper adopts it
 * with two calls.
 *
 * Deliberately NOT a mapper base class: the mappers differ far more than they
 * share, and inheritance would drag their unrelated internals together.
 */
final class ProviderFields
{
    public function __construct(
        private readonly FieldProviderRegistry $registry,
    ) {
    }

    /**
     * Every field name providers contribute for a table, whether or not the
     * providing extension is installed — used for "is this key known?" checks,
     * so an unavailable extension yields a precise error instead of "unknown".
     *
     * @return list<string>
     */
    public function declaredFor(string $table): array
    {
        $fields = [];
        foreach ($this->registry->forTable($table) as $provider) {
            $fields = array_merge($fields, $provider->getDeclaredFields());
        }

        return array_values(array_unique($fields));
    }

    /**
     * The provider fields that can actually be written on this type: declared
     * by an INSTALLED extension whose provider allows them here.
     *
     * declaredFor() answers "is this key known at all" and includes missing
     * extensions on purpose, so the write path can name them. A palette answer
     * built from it would offer `rsce_data` on a text element — a column only
     * an RSCE type has.
     *
     * $type is null for tables without a type concept; every declared field
     * of an available provider counts there, as the gate in apply() is skipped.
     *
     * @return list<string>
     */
    public function allowedFor(string $table, ?string $type): array
    {
        $fields = [];
        foreach ($this->registry->availableForTable($table) as $provider) {
            $declared = $provider->getDeclaredFields();
            $fields = array_merge(
                $fields,
                $type === null ? $declared : array_intersect($declared, $provider->getAllowedFields($type)),
            );
        }

        return array_values(array_unique($fields));
    }

    /**
     * What apply() would refuse for these keys, without applying anything.
     *
     * A batch tool has to know before its first write — content_create_tree
     * checks every node up front, entity_duplicate every override — and it
     * has to say it in the same words, or the same mistake reads differently
     * depending on which tool made it.
     *
     * @param list<string> $keys
     *
     * @return array<string, string> field => message (one message may cover several fields)
     */
    public function refusals(string $table, array $keys, ?string $type = null): array
    {
        $out = [];

        foreach ($this->registry->forTable($table) as $provider) {
            $claims = array_values(array_intersect($keys, $provider->getDeclaredFields()));
            if ($claims === []) {
                continue;
            }

            $refusal = $this->gate($provider, $claims, $type);
            if ($refusal === null) {
                continue;
            }

            foreach ($refusal[1] as $field) {
                $out[$field] ??= $refusal[0];
            }
        }

        return $out;
    }

    /**
     * Reads provider-owned columns for the MCP response. Only available
     * providers contribute — a field whose extension is gone would otherwise
     * report a value nothing can write back.
     *
     * @return array<string, mixed>
     */
    public function serialize(string $table, Model $model): array
    {
        $out = [];
        foreach ($this->registry->availableForTable($table) as $provider) {
            $out = array_merge($out, $provider->serialize($model));
        }

        return $out;
    }

    /**
     * Hands the input to every provider for the table.
     *
     * Providers validate their own values and throw on bad input — the
     * bootstrap SCSS provider, for instance, compiles what it is given so a
     * syntax error surfaces as a failed tool call instead of a silently
     * missing stylesheet. Those messages are collected rather than rethrown so
     * the caller sees ALL validation problems at once, and so the Tool layer
     * can decide to persist nothing.
     *
     * @param array<string, mixed> $input
     *
     * @return array{applied: list<string>, errors: list<string>}
     */
    public function apply(string $table, Model $model, array $input, bool $detectChanges = true, ?string $type = null): array
    {
        $applied = [];
        $errors = [];

        foreach ($this->registry->forTable($table) as $provider) {
            $claims = array_values(array_intersect(array_keys($input), $provider->getDeclaredFields()));
            if ($claims === []) {
                continue;
            }

            $refusal = $this->gate($provider, $claims, $type);
            if ($refusal !== null) {
                $errors[] = $refusal[0];
                continue;
            }

            try {
                foreach ($provider->apply($model, $input, $detectChanges) as $field) {
                    if (!\in_array($field, $applied, true)) {
                        $applied[] = $field;
                    }
                }
            } catch (\InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        }

        return ['applied' => $applied, 'errors' => $errors];
    }

    /**
     * Why a provider may not take these fields here, or null when it may.
     *
     * The per-type half is the gate the contract promises. It used to be
     * honoured only by the page mapper, so a provider that filtered correctly
     * in getAllowedFields() still had its apply() called on every type — and a
     * provider that trusted the contract instead of re-checking wrote to the
     * wrong record. Silently: no error, a value in the wrong place. Reported by
     * the bootstrap bundle, whose fields span several component types.
     *
     * $type is null for tables that have no type concept (tl_theme,
     * tl_layout); there is nothing to gate on there.
     *
     * @param list<string> $claims the provider's fields present in the input
     *
     * @return array{0: string, 1: list<string>}|null the message and the fields it covers
     */
    private function gate(FieldProvider $provider, array $claims, ?string $type): ?array
    {
        if (!$provider->isAvailable()) {
            return [
                sprintf(
                    'Field(s) %s require the %s extension, which is not installed in this Contao project.',
                    implode(', ', $claims),
                    $provider->getRequiredExtension(),
                ),
                $claims,
            ];
        }

        if ($type === null) {
            return null;
        }

        $wrongType = array_values(array_diff($claims, $provider->getAllowedFields($type)));
        if ($wrongType === []) {
            return null;
        }

        return [
            sprintf(
                'Field(s) %s are provided by %s but are not valid for type "%s".',
                implode(', ', $wrongType),
                $provider->getRequiredExtension(),
                $type,
            ),
            $wrongType,
        ];
    }
}
