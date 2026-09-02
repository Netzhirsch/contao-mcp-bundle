<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Service;

use Doctrine\DBAL\Connection;

/**
 * The half of a `terminal42/contao-changelanguage` translation link that lives
 * on the COLLECTION rather than on the record.
 *
 * The extension stores a translation relationship in two places:
 *
 *   - the record:      `tl_news.languageMain`  → id of the record it translates
 *   - the collection:  `tl_news_archive.master` → id of the archive it translates
 *
 * and the second is not optional. `AbstractNavigationListener` resolves the
 * counterpart with
 *
 *     pid = (SELECT id FROM tl_news_archive WHERE (id=? OR master=?) AND jumpTo=?)
 *
 * so an archive whose `master` is still 0 matches nothing: the language switcher
 * silently falls back to the language root and the `hreflang` alternate is never
 * emitted. In the backend the same value gates the field — `languageMain` is only
 * added to the palette when `getRelated('pid')->master > 0`.
 *
 * Nothing in the database looks wrong when this half is missing, which is why it
 * was reported from a live site rather than found here: `news_create(languageMain: 8)`
 * answered `created: true, languageMain: 8`, and readers who switched language
 * landed on the home page.
 *
 * This class only answers questions — the writes stay in the tool, where the
 * version snapshot and the tl_log entry belong.
 */
final class TranslationMaster
{
    /**
     * Record table → the collection table that carries `master`.
     *
     * tl_page and tl_article are absent on purpose: pages carry their own
     * `languageMain`/`languageRoot` pair and articles inherit the page's, so
     * neither has a collection half to keep in step.
     */
    private const COLLECTIONS = [
        'tl_news' => 'tl_news_archive',
        'tl_calendar_events' => 'tl_calendar',
        'tl_faq' => 'tl_faq_category',
    ];

    /** @var array<string, list<string>> */
    private array $columns = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return list<string>
     */
    public static function collectionTables(): array
    {
        return array_values(self::COLLECTIONS);
    }

    public static function collectionFor(string $recordTable): ?string
    {
        return self::COLLECTIONS[$recordTable] ?? null;
    }

    public static function isCollectionTable(string $table): bool
    {
        return \in_array($table, self::COLLECTIONS, true);
    }

    /**
     * Both columns are added by the extension, so on an instance without it
     * they simply are not there. Every question below is asked through this so
     * a missing extension answers "nothing to check" instead of an SQL error
     * on a table the caller never mentioned.
     */
    public function hasColumn(string $table, string $column): bool
    {
        if (!isset($this->columns[$table])) {
            try {
                $this->columns[$table] = array_map(
                    static fn ($c) => strtolower($c->getName()),
                    $this->connection->createSchemaManager()->listTableColumns($table),
                );
            } catch (\Throwable) {
                $this->columns[$table] = [];
            }
        }

        return \in_array(strtolower($column), $this->columns[$table], true);
    }

    public function pidOf(string $table, int $id): ?int
    {
        $value = $this->connection->fetchOne(
            sprintf('SELECT pid FROM %s WHERE id = ?', $this->connection->quoteIdentifier($table)),
            [$id],
        );

        return $value === false ? null : (int) $value;
    }

    public function masterOf(string $collectionTable, int $id): ?int
    {
        $value = $this->connection->fetchOne(
            sprintf('SELECT master FROM %s WHERE id = ?', $this->connection->quoteIdentifier($collectionTable)),
            [$id],
        );

        return $value === false ? null : (int) $value;
    }

    /**
     * Why `$translated` must not be pointed at `$master`, or null when it may be.
     *
     * These are changelanguage's own rules, restated where they can be checked
     * before the write instead of after: the target has to be a master itself,
     * and `ParentTableListener::validateMaster()` refuses a second collection
     * on the same reader page claiming the same master.
     */
    public function blocker(string $collectionTable, int $translated, int $master): ?string
    {
        if (!$this->hasColumn($collectionTable, 'master')) {
            return sprintf(
                '%s has no `master` column — terminal42/contao-changelanguage is not installed here. '
                .'Check with installed_bundles.',
                $collectionTable,
            );
        }

        if ($translated === $master) {
            return sprintf('%s.%d cannot be its own master.', $collectionTable, $translated);
        }

        $masterRow = $this->row($collectionTable, $master);
        if ($masterRow === null) {
            return sprintf('%s.%d does not exist.', $collectionTable, $master);
        }
        if ((int) ($masterRow['master'] ?? 0) !== 0) {
            return sprintf(
                '%s.%d is itself a translation (master=%d), so it cannot be a master. Point at %d instead.',
                $collectionTable,
                $master,
                (int) $masterRow['master'],
                (int) $masterRow['master'],
            );
        }

        $translatedRow = $this->row($collectionTable, $translated);
        if ($translatedRow === null) {
            return sprintf('%s.%d does not exist.', $collectionTable, $translated);
        }

        $q = $this->connection->quoteIdentifier($collectionTable);
        $clash = $this->connection->fetchOne(
            "SELECT title FROM {$q} WHERE jumpTo = ? AND master = ? AND id != ? LIMIT 1",
            [(int) ($translatedRow['jumpTo'] ?? 0), $master, $translated],
        );

        if ($clash !== false) {
            return sprintf(
                '"%s" already translates %s.%d on the same reader page — changelanguage allows only one. '
                .'Give the two collections different `jumpTo` pages, or link the other one instead.',
                (string) $clash,
                $collectionTable,
                $master,
            );
        }

        return null;
    }

    /**
     * The state of the collection half behind a record link, or null when the
     * record table has no collection half at all.
     *
     * @return array{collection_table: string, translated: int, master: int, current: int, blocker: string|null}|null
     */
    public function inspect(string $recordTable, int $translationId, int $defaultId): ?array
    {
        $collectionTable = self::collectionFor($recordTable);
        if ($collectionTable === null || !$this->hasColumn($collectionTable, 'master')) {
            return null;
        }

        $translated = $this->pidOf($recordTable, $translationId);
        $master = $this->pidOf($recordTable, $defaultId);
        if ($translated === null || $master === null || $translated === 0 || $master === 0) {
            return null;
        }

        $current = $this->masterOf($collectionTable, $translated) ?? 0;

        return [
            'collection_table' => $collectionTable,
            'translated' => $translated,
            'master' => $master,
            'current' => $current,
            'blocker' => $current === 0 ? $this->blocker($collectionTable, $translated, $master) : null,
        ];
    }

    /**
     * What is still missing after a record was given a `languageMain`, or null
     * when the link is whole (or there is none).
     *
     * Called from the create/update tools that take `languageMain` directly.
     * They accept the value, store it and report success — which is exactly the
     * shape of the reported failure, so they now say what the value does not yet
     * do on its own.
     */
    public function recordLinkWarning(string $recordTable, int $recordId): ?string
    {
        if (self::collectionFor($recordTable) === null || !$this->hasColumn($recordTable, 'languageMain')) {
            return null;
        }

        $languageMain = (int) $this->connection->fetchOne(
            sprintf('SELECT languageMain FROM %s WHERE id = ?', $this->connection->quoteIdentifier($recordTable)),
            [$recordId],
        );

        if ($languageMain === 0) {
            return null;
        }

        $state = $this->inspect($recordTable, $recordId, $languageMain);
        if ($state === null || (int) $state['current'] === (int) $state['master']) {
            return null;
        }

        return self::unlinkedWarning($recordTable, $recordId, $state)
            .($state['blocker'] !== null ? ' '.$state['blocker'] : '');
    }

    /**
     * The sentence to hand a caller whose record link is complete but whose
     * collection link is not — naming the exact call that finishes the job.
     *
     * @param array{collection_table: string, translated: int, master: int, current: int, blocker: string|null} $state
     */
    public static function unlinkedWarning(string $recordTable, int $translationId, array $state): string
    {
        $collectionTable = (string) $state['collection_table'];
        $current = (int) $state['current'];
        $master = (int) $state['master'];
        $translated = (int) $state['translated'];

        if ($current !== 0 && $current !== $master) {
            return sprintf(
                '%s.%d sits in %s.%d, which already translates %s.%d — but the default record sits in %s.%d. '
                .'The language switcher resolves through the collection, so it will not find this translation.',
                $recordTable,
                $translationId,
                $collectionTable,
                $translated,
                $collectionTable,
                $current,
                $collectionTable,
                $master,
            );
        }

        return sprintf(
            '%s.%d has master=0, so `%s.languageMain` is not evaluated: the language switcher falls back to '
            .'the language root and no hreflang alternate is emitted. Finish the link with '
            .'entity_language_link(table: "%s", default_id: %d, translations: {"<lang>": %d}).',
            $collectionTable,
            $translated,
            $recordTable,
            $collectionTable,
            $master,
            $translated,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function row(string $table, int $id): ?array
    {
        $row = $this->connection->fetchAssociative(
            sprintf('SELECT * FROM %s WHERE id = ?', $this->connection->quoteIdentifier($table)),
            [$id],
        );

        return $row === false ? null : $row;
    }
}
