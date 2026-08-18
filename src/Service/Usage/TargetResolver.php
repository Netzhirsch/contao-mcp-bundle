<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Service\Usage;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\Tool\File\PathResolver;

/**
 * Turns the caller's "type + identifier" into a {@see UsageTarget}.
 *
 * Accepts the friendly type names the MCP tools already use ("page", "news",
 * "file") as well as a raw table name, so a reference lookup also works for
 * tables this bundle has no dedicated tool for.
 */
final class TargetResolver
{
    /** Strict identifier rule — these values reach SQL as identifiers. */
    private const TABLE_PATTERN = '/^tl_[a-z0-9_]+$/';

    /**
     * How many files inside a folder are folded into the reference lookup.
     * Each one widens the SQL predicate, so a media folder with 10 000 images
     * would otherwise build a query nobody wants to run.
     */
    private const MAX_FOLDER_CONTENTS = 200;

    /**
     * Friendly type => Contao table. Mirrors the entity naming the CRUD tools
     * use, so "the thing you deleted with news_delete" is "news" here too.
     *
     * @var array<string, string>
     */
    private const TYPE_TABLES = [
        'page' => 'tl_page',
        'article' => 'tl_article',
        'content' => 'tl_content',
        'content_element' => 'tl_content',
        'module' => 'tl_module',
        'layout' => 'tl_layout',
        'theme' => 'tl_theme',
        'form' => 'tl_form',
        'form_field' => 'tl_form_field',
        'news' => 'tl_news',
        'news_archive' => 'tl_news_archive',
        'event' => 'tl_calendar_events',
        'calendar_event' => 'tl_calendar_events',
        'calendar' => 'tl_calendar',
        'faq' => 'tl_faq',
        'faq_category' => 'tl_faq_category',
        'file' => 'tl_files',
        'folder' => 'tl_files',
        'image' => 'tl_files',
        'image_size' => 'tl_image_size',
        'member' => 'tl_member',
        'member_group' => 'tl_member_group',
        'newsletter' => 'tl_newsletter',
        'newsletter_channel' => 'tl_newsletter_channel',
        'comment' => 'tl_comments',
        // "template" is handled separately — it is a file, not a table row.
    ];

    /**
     * Columns tried, in order, when building a human-readable label. The DCA's
     * own `list.label.fields` wins when it names a real column.
     *
     * @var list<string>
     */
    private const LABEL_COLUMNS = [
        'title', 'name', 'headline', 'subject', 'question', 'username', 'firstname', 'path',
    ];

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
        private readonly SchemaIndex $schema,
        private readonly PathResolver $paths,
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return array<string, string> friendly type => table
     */
    public static function knownTypes(): array
    {
        return self::TYPE_TABLES;
    }

    /**
     * The name a human would use for a table — "page", not "tl_page". Falls
     * back to the table name so unknown tables still read sensibly.
     */
    public static function friendlyType(string $table): string
    {
        $type = array_search($table, self::TYPE_TABLES, true);

        return \is_string($type) ? $type : $table;
    }

    /**
     * @return UsageTarget|array{error: string, message: string}
     */
    public function resolve(string $type, string $identifier): UsageTarget|array
    {
        $this->framework->initialize();

        $type = strtolower(trim($type));
        $identifier = trim($identifier);

        if ('' === $type) {
            return ['error' => 'invalid_input', 'message' => 'Provide a type, e.g. "page", "file" or a table name like "tl_module".'];
        }

        if ('' === $identifier) {
            return ['error' => 'invalid_input', 'message' => 'Provide an id (or, for files, a path or UUID).'];
        }

        if ('template' === $type) {
            return $this->resolveTemplate($identifier);
        }

        $table = self::TYPE_TABLES[$type] ?? (1 === preg_match(self::TABLE_PATTERN, $type) ? $type : null);

        if (null === $table) {
            return [
                'error' => 'unknown_type',
                'message' => sprintf(
                    'Unknown type "%s". Use one of: %s — or a table name (tl_…).',
                    $type,
                    implode(', ', array_keys(self::TYPE_TABLES)),
                ),
            ];
        }

        if (!$this->schema->hasTable($table)) {
            return ['error' => 'unknown_type', 'message' => sprintf('Table "%s" does not exist in this installation.', $table)];
        }

        return 'tl_files' === $table
            ? $this->resolveFile($type, $identifier)
            : $this->resolveRecord($type, $table, $identifier);
    }

    /**
     * @return UsageTarget|array{error: string, message: string}
     */
    private function resolveRecord(string $type, string $table, string $identifier): UsageTarget|array
    {
        if (1 !== preg_match('/^\d+$/', $identifier)) {
            return [
                'error' => 'invalid_input',
                'message' => sprintf('"%s" needs a numeric id (got "%s").', $table, $identifier),
            ];
        }

        $row = $this->connection->fetchAssociative(
            'SELECT * FROM '.$this->connection->quoteIdentifier($table).' WHERE id = ?',
            [(int) $identifier],
        );

        if (false === $row) {
            return [
                'error' => 'not_found',
                'message' => sprintf('No %s record with id %s.', $table, $identifier),
            ];
        }

        $aliases = [];

        if (\is_string($row['alias'] ?? null) && '' !== $row['alias']) {
            $aliases[] = strtolower($row['alias']);
        }

        return new UsageTarget(
            type: $type,
            table: $table,
            id: (int) $identifier,
            label: $this->labelFor($table, $row),
            aliases: $aliases,
        );
    }

    /**
     * A template override in `templates/`, addressed the way the template
     * tools address it: the path relative to that folder.
     *
     * The NAME it is referenced by is not the path. Contao stores two
     * spellings, and which one applies is decided by the file extension:
     *
     *   templates/ce_text_my.html5            → "ce_text_my"              (basename)
     *   templates/content_element/text/my.html.twig
     *                                         → "content_element/text/my" (full path)
     *
     * Both verified against real `customTpl` / `template` values. Using the
     * wrong one finds nothing, which would look exactly like "not referenced".
     *
     * @return UsageTarget|array{error: string, message: string}
     */
    private function resolveTemplate(string $identifier): UsageTarget|array
    {
        $relative = ltrim(str_replace('\\', '/', $identifier), '/');

        if (str_starts_with($relative, 'templates/')) {
            $relative = substr($relative, \strlen('templates/'));
        }

        if ('' === $relative || str_contains($relative, '..')) {
            return ['error' => 'invalid_input', 'message' => 'Pass a template path relative to templates/, e.g. "ce_text_my.html5".'];
        }

        $absolute = $this->projectDir.'/templates/'.$relative;

        if (!is_file($absolute)) {
            return [
                'error' => 'not_found',
                'message' => sprintf(
                    'No template override at templates/%s. Only overrides can be deleted — call templates_list to see what exists.',
                    $relative,
                ),
            ];
        }

        if (str_ends_with($relative, '.html.twig')) {
            $name = substr($relative, 0, -\strlen('.html.twig'));
        } elseif (str_ends_with($relative, '.html5')) {
            $name = basename($relative, '.html5');
        } else {
            return [
                'error' => 'invalid_input',
                'message' => sprintf('templates/%s is neither a .html5 nor a .html.twig template.', $relative),
            ];
        }

        return new UsageTarget(
            type: 'template',
            table: UsageTarget::TABLE_TEMPLATES,
            id: 0,
            label: $name,
            aliases: [$name],
            path: 'templates/'.$relative,
        );
    }

    /**
     * Files may be addressed the way any of the surrounding tools address
     * them: by tool-relative path, by DBAFS path, by UUID, or by tl_files id.
     *
     * @return UsageTarget|array{error: string, message: string}
     */
    private function resolveFile(string $type, string $identifier): UsageTarget|array
    {
        $row = false;

        if (1 === preg_match('/^\d+$/', $identifier)) {
            $row = $this->connection->fetchAssociative('SELECT * FROM tl_files WHERE id = ?', [(int) $identifier]);
        } elseif (1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $identifier)) {
            $row = $this->connection->fetchAssociative(
                'SELECT * FROM tl_files WHERE uuid = ?',
                [StringUtil::uuidToBin($identifier)],
            );
        } else {
            // Accept both "theme/logo.svg" (tool convention) and the DBAFS
            // form "files/theme/logo.svg" that appears in tl_files.path.
            $candidates = [$identifier, $this->paths->toDbafsPath($identifier)];

            foreach (array_unique($candidates) as $candidate) {
                $row = $this->connection->fetchAssociative('SELECT * FROM tl_files WHERE path = ?', [$candidate]);

                if (false !== $row) {
                    break;
                }
            }
        }

        if (false === $row) {
            return [
                'error' => 'not_found',
                'message' => sprintf(
                    'No tl_files entry for "%s". Paths are relative to the upload folder (e.g. "theme/logo.svg"); '
                    .'run dbafs_sync if the file exists on disk but not in the database.',
                    $identifier,
                ),
            ];
        }

        $path = (string) ($row['path'] ?? '');
        $uuid = null;

        if (\is_string($row['uuid'] ?? null) && '' !== $row['uuid']) {
            $uuid = StringUtil::binToUuid($row['uuid']);
        }

        $isFolder = 'folder' === ($row['type'] ?? '');

        return new UsageTarget(
            type: $isFolder ? 'folder' : $type,
            table: 'tl_files',
            id: (int) ($row['id'] ?? 0),
            label: '' !== $path ? basename($path) : (string) ($row['name'] ?? ''),
            uuid: $uuid,
            path: $path,
            isFolder: $isFolder,
            contents: $isFolder ? $this->folderContents($path) : [],
        );
    }

    /**
     * UUIDs of everything inside a folder. Deleting the folder deletes them,
     * so a reference to any of them is a reference to this deletion.
     *
     * @return list<string>
     */
    private function folderContents(string $path): array
    {
        if ('' === $path) {
            return [];
        }

        try {
            $rows = $this->connection->fetchFirstColumn(
                'SELECT uuid FROM tl_files WHERE path LIKE ? ORDER BY path LIMIT '.self::MAX_FOLDER_CONTENTS,
                [str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $path).'/%'],
            );
        } catch (\Throwable) {
            return [];
        }

        $uuids = [];

        foreach ($rows as $binary) {
            if (\is_string($binary) && '' !== $binary) {
                $uuids[] = StringUtil::binToUuid($binary);
            }
        }

        return $uuids;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function labelFor(string $table, array $row): string
    {
        $candidates = [];

        try {
            $this->framework->getAdapter(Controller::class)->loadDataContainer($table);
            $labelFields = $GLOBALS['TL_DCA'][$table]['list']['label']['fields'] ?? [];

            if (\is_array($labelFields)) {
                foreach ($labelFields as $field) {
                    if (\is_string($field)) {
                        $candidates[] = $field;
                    }
                }
            }
        } catch (\Throwable) {
            // No DCA — fall through to the generic column guesses.
        }

        foreach ([...$candidates, ...self::LABEL_COLUMNS] as $column) {
            $value = $row[$column] ?? null;

            if (\is_string($value) && '' !== trim($value)) {
                return mb_substr(trim($value), 0, 120);
            }
        }

        return sprintf('%s #%s', $table, $row['id'] ?? '?');
    }
}
