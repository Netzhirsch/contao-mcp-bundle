<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Service\Usage;

/**
 * The thing whose usages we are looking for.
 *
 * A plain value object, deliberately NOT a service — see the exclude in
 * config/services.yaml. It carries every identifier a reference could be
 * written as, because Contao does not use one canonical form:
 *
 *   tl_page   id 42          → `jumpTo = 42`, `{{link::42}}`, `{{link::kontakt}}`
 *   tl_files  uuid + path    → `binary(16)` column, `{{file::<uuid>}}`,
 *                              `{{file::files/x.jpg}}`, `@import 'x'` in SCSS
 */
final class UsageTarget
{
    /**
     * Pseudo-table for template overrides. Templates are files, not rows, but
     * they are referenced like records (`tl_content.customTpl = 'ce_text_my'`),
     * so they travel through the same scan. Deliberately not `tl_`-prefixed:
     * every code path that touches real tables screens on that prefix, so this
     * value can never be mistaken for one.
     */
    public const TABLE_TEMPLATES = 'templates';

    /**
     * @param string       $type    Friendly type the caller asked for ("page", "file", …)
     * @param string       $table   Contao table the record lives in, or {@see TABLE_TEMPLATES}
     * @param int          $id      Primary key (0 for templates)
     * @param string       $label   Human-readable name, for the report
     * @param list<string> $aliases Alias strings this record can be linked by
     *                              ({{link::my-alias}}) — lower-cased, no empties.
     *                              For templates: the name(s) a DCA column stores
     *                              it under ("ce_text_my", "content_element/text/my")
     * @param string|null  $uuid    tl_files only: the string form of the UUID
     * @param string|null  $path    tl_files only: path relative to the project root
     * @param list<string> $contents Folders only: the UUIDs of the files inside.
     *                               Deleting a folder deletes them too, so a
     *                               reference to any of them blocks just as hard
     *                               as one to the folder itself.
     */
    public function __construct(
        public readonly string $type,
        public readonly string $table,
        public readonly int $id,
        public readonly string $label,
        public readonly array $aliases = [],
        public readonly ?string $uuid = null,
        public readonly ?string $path = null,
        public readonly bool $isFolder = false,
        public readonly array $contents = [],
    ) {
    }

    /**
     * Every UUID that this target stands for.
     *
     * @return list<string>
     */
    public function uuids(): array
    {
        $uuids = null !== $this->uuid && '' !== $this->uuid ? [$this->uuid] : [];

        return array_values(array_unique([...$uuids, ...$this->contents]));
    }

    /**
     * Every string a reference to this record could be spelled as inside an
     * insert tag. Ordered most-specific first so the longest match wins when
     * two needles overlap.
     *
     * @return list<string>
     */
    public function insertTagNeedles(): array
    {
        $needles = $this->uuids();

        if (null !== $this->path && '' !== $this->path) {
            $needles[] = $this->path;
        }

        // Files are never addressed by their tl_files id in an insert tag —
        // {{file::…}} takes a UUID or a path. Searching for the id anyway
        // would drag in every unrelated `{{link::<same number>}}`.
        if ($this->id > 0 && 'tl_files' !== $this->table) {
            $needles[] = (string) $this->id;
        }

        foreach ($this->aliases as $alias) {
            if ('' !== $alias) {
                $needles[] = $alias;
            }
        }

        return array_values(array_unique($needles));
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        $out = [
            'type' => $this->type,
            'table' => $this->table,
            'id' => $this->id,
            'label' => $this->label,
        ];

        if ([] !== $this->aliases) {
            $out['alias'] = $this->aliases[0];
        }

        if (null !== $this->uuid) {
            $out['uuid'] = $this->uuid;
        }

        if (null !== $this->path) {
            $out['path'] = $this->path;
        }

        if ($this->isFolder) {
            $out['is_folder'] = true;
        }

        return $out;
    }
}
