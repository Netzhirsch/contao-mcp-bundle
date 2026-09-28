<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Extension\OpenGraph;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\StringUtil;

/**
 * Reads the OpenGraph field layout out of the LIVE DCA rather than restating it.
 *
 * numero2/contao-opengraph3 defines every field once, in
 * `contao/dca/opengraph_fields.php`, and `OpenGraphFields::addToTable()` copies
 * the ones carrying an `sql` key onto each host table. Everything else — 52 of
 * the 62 fields at the time of writing — exists only inside the `og_properties`
 * blob. Restating either list here would mean shipping a copy that goes stale
 * the first time upstream adds a property, so both are derived at call time.
 *
 * Two rules live in that DCA and are easy to miss from the outside:
 *
 *   1. Which properties are valid depends on `og_type`: the widget keeps
 *      `og_subpalettes[<type>]` plus `og_subpalettes['__all__']` and **silently
 *      drops every other row** the next time an editor saves the record in the
 *      Backend. A value written through the wrong type is not rejected, it just
 *      quietly disappears later.
 *   2. A host table may restrict the types it accepts at all, via
 *      `config.allowedOpenGraphTypes` — tl_news takes only `article`,
 *      tl_calendar_events only `website`.
 */
final class Schema
{
    /** Present on every table addToTable() has run for. */
    private const PROBE_FIELD = 'og_title';

    public const PROPERTIES_COLUMN = 'og_properties';

    /** Not og:types — the widget filters both out of the type list. */
    private const PSEUDO_TYPES = ['__basic__', '__all__'];

    public function __construct(private readonly ContaoFramework $framework)
    {
    }

    /**
     * True when the extension has attached its fields to this table.
     */
    public function coversTable(string $table): bool
    {
        $this->loadTable($table);

        return isset($GLOBALS['TL_DCA'][$table]['fields'][self::PROBE_FIELD]);
    }

    /**
     * The real columns on a host table, `og_properties` excluded — those are
     * the fields with an `sql` definition, which is exactly what addToTable()
     * copies over.
     *
     * @return list<string>
     */
    public function columns(): array
    {
        $columns = [];

        foreach ($this->definitions() as $name => $definition) {
            if (isset($definition['sql']) && $name !== self::PROPERTIES_COLUMN) {
                $columns[] = (string) $name;
            }
        }

        return $columns;
    }

    /**
     * Columns holding a file reference as binary(16).
     *
     * @return list<string>
     */
    public function uuidColumns(): array
    {
        $uuid = [];

        foreach ($this->definitions() as $name => $definition) {
            if (\is_string($definition['sql'] ?? null) && stripos($definition['sql'], 'binary(16)') !== false) {
                $uuid[] = (string) $name;
            }
        }

        return $uuid;
    }

    /**
     * Every field that can only live inside `og_properties`.
     *
     * @return list<string>
     */
    public function propertyNames(): array
    {
        $names = [];

        foreach ($this->definitions() as $name => $definition) {
            if (!isset($definition['sql'])) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }

    /**
     * The property names a given og:type actually keeps.
     *
     * An empty type is not an error: `__all__` still applies, so `og_description`
     * is writable before any type is chosen.
     *
     * @return list<string>
     */
    public function propertiesForType(string $type): array
    {
        $subpalettes = $this->subpalettes();
        $definitions = $this->definitions();

        $palette = (string) ($subpalettes['__all__'] ?? '');

        if ($type !== '' && ($subpalettes[$type] ?? '') !== '') {
            $palette = $subpalettes[$type].','.$palette;
        }

        $names = [];

        foreach (StringUtil::trimsplit(',', $palette) as $name) {
            $name = (string) $name;
            if ($name !== '' && isset($definitions[$name]) && !\in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * The og:types this table accepts, honouring `config.allowedOpenGraphTypes`.
     *
     * @return list<string>
     */
    public function typesForTable(string $table): array
    {
        $this->loadTable($table);

        $types = array_values(array_diff(array_keys($this->subpalettes()), self::PSEUDO_TYPES));

        /** @var list<string> $allowed */
        $allowed = $GLOBALS['TL_DCA'][$table]['config']['allowedOpenGraphTypes'] ?? [];

        if ($allowed !== []) {
            $types = array_values(array_intersect($types, $allowed));
        }

        return array_map(strval(...), $types);
    }

    /**
     * Which og:type would make a property valid — so a refusal can say what to
     * change instead of only what is wrong.
     *
     * @return list<string>
     */
    public function typesAllowing(string $table, string $property): array
    {
        $types = [];

        foreach ($this->typesForTable($table) as $type) {
            if (\in_array($property, $this->propertiesForType($type), true)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * The raw DCA definition of one field, for input-type decisions.
     *
     * @return array<string, mixed>
     */
    public function definition(string $field): array
    {
        $definition = $this->definitions()[$field] ?? [];

        return \is_array($definition) ? $definition : [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function definitions(): array
    {
        $this->loadFieldDefinitions();

        /** @var array<string, array<string, mixed>> $fields */
        $fields = $GLOBALS['TL_DCA']['opengraph_fields']['fields'] ?? [];

        return $fields;
    }

    /**
     * @return array<string, string>
     */
    private function subpalettes(): array
    {
        $this->loadFieldDefinitions();

        /** @var array<string, string> $subpalettes */
        $subpalettes = $GLOBALS['TL_DCA']['opengraph_fields']['og_subpalettes'] ?? [];

        return $subpalettes;
    }

    private function loadFieldDefinitions(): void
    {
        $this->framework->initialize();
        $this->framework->getAdapter(Controller::class)->loadDataContainer('opengraph_fields');
    }

    private function loadTable(string $table): void
    {
        $this->framework->initialize();
        $this->framework->getAdapter(Controller::class)->loadDataContainer($table);
    }
}
