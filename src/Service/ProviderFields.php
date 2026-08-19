<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Service;

use Contao\Model;

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
    public function apply(string $table, Model $model, array $input, bool $detectChanges = true): array
    {
        $applied = [];
        $errors = [];

        foreach ($this->registry->forTable($table) as $provider) {
            $claims = array_intersect(array_keys($input), $provider->getDeclaredFields());
            if ($claims === []) {
                continue;
            }

            if (!$provider->isAvailable()) {
                $errors[] = sprintf(
                    'Field(s) %s require the %s extension, which is not installed in this Contao project.',
                    implode(', ', $claims),
                    $provider->getRequiredExtension(),
                );
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
}
