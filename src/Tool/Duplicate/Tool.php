<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Duplicate;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\Versions;
use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\Security\McpPermissionGuard;
use Netzhirsch\ContaoMcpBundle\Service\AuthorResolver;
use Netzhirsch\ContaoMcpBundle\Service\RecordDuplicator;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use Psr\Log\LoggerInterface;

/**
 * Generic, DCA-driven record duplication for the high-value Contao trees:
 * pages, articles and content elements. Mirrors the backend "copy" button —
 * children cascade (article → content, container → nested content, page →
 * articles), `doNotCopy` fields regenerate, external-id mappings are reset.
 *
 * The heavy lifting is in {@see RecordDuplicator}; this tool adds the table
 * allowlist, permission parity (read source + create under target, via the
 * same voters the CRUD tools use) and tl_log attribution.
 */
final class Tool
{
    /**
     * Tables that may be duplicated. Their child trees cascade automatically
     * via DCA `ctable`, so listing the roots is enough.
     *
     * @var list<string>
     */
    private const ALLOWED = ['tl_page', 'tl_article', 'tl_content'];

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
        private readonly RecordDuplicator $duplicator,
        private readonly McpPermissionGuard $guard,
        private readonly AuthorResolver $authorResolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param object|null $overrides Top-level field overrides applied to the copy (e.g. {"title": "Copy of …"}).
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'entity_duplicate',
        description: <<<'DESC'
            Duplicates a Contao record with its child tree — the MCP equivalent of the
            backend "copy" button. Supported tables: tl_page, tl_article, tl_content.

            What cascades automatically (DCA ctable, always):
              - tl_article  → its content elements (incl. nested elements)
              - tl_content  → nested children of container elements
                              (accordion / element_group / swiper)
              - tl_page     → its articles (and their content)

            Parameters:
              - table:         tl_page | tl_article | tl_content
              - id:            source record id
              - into_pid:      optional new parent id (default: same parent as the source,
                               i.e. duplicate in place). For tl_content this is the parent
                               article/element id; for tl_article the page id; for tl_page
                               the parent page id (0 = root level).
              - into_ptable:   optional parent table for tl_content (default: keep source's
                               ptable, e.g. tl_article or tl_content for nested).
              - with_children: tl_page only — also copy the whole sub-page tree (default false).
              - overrides:     object of fields to set on the TOP-LEVEL copy (e.g. {"title": "…"}).
                               Values are strings, numbers, booleans or null — `published: false`
                               is written as 0, so an unpublished copy needs no follow-up call.
                               A column the table does not have is rejected before anything
                               is copied.

            Conventions mirrored from Contao's own copy:
              - doNotCopy fields (alias, …) are NOT carried over — Contao regenerates them.
              - the author is set to the calling identity, as Contao's copy button does
                (the source's author is a doNotCopy field and is not inherited).
              - external-id mappings (external_id_namespace/key) are reset on every copy.
              - the copy is appended after the last sibling (fresh sorting).
              - a duplicated ROOT page gets its fallback + dns cleared (uniqueness) — set
                them explicitly afterwards via page_update.

            Permission parity: you must be allowed to READ the source and CREATE under the
            target (same voters / pagemount scope as the CRUD tools). The new primary record
            gets a Versions snapshot; the operation is logged to tl_log.

            Returns {duplicated: true, table, source_id, new_id, copied: <total rows>, tree}.
        DESC,
    )]
    public function duplicate(
        string $table,
        int $id,
        ?int $into_pid = null,
        ?string $into_ptable = null,
        bool $with_children = false,
        #[Schema(type: 'object')] mixed $overrides = null,
    ): array {
        $this->framework->initialize();

        if (($denied = $this->guard->ensureMcpAccess()) !== null) {
            return $denied;
        }

        if (!\in_array($table, self::ALLOWED, true)) {
            return [
                'error' => 'unsupported_table',
                'message' => sprintf('entity_duplicate supports: %s.', implode(', ', self::ALLOWED)),
                'supported' => self::ALLOWED,
            ];
        }

        // Read parity on the source.
        if (($denied = $this->guard->ensureCan($table, 'read', $id)) !== null) {
            return $denied;
        }

        $q = $this->connection->quoteIdentifier($table);
        $source = $this->connection->fetchAssociative("SELECT * FROM {$q} WHERE id = ?", [$id]);
        if ($source === false) {
            return ['error' => 'not_found', 'message' => sprintf('%s.%d does not exist.', $table, $id)];
        }

        // Resolve the effective target parent (default: duplicate in place).
        $intoPid = $into_pid ?? (int) ($source['pid'] ?? 0);
        $intoPtable = $into_ptable;
        if ($table === 'tl_content' && $intoPtable === null) {
            $intoPtable = (string) ($source['ptable'] ?? 'tl_article');
        }

        // Create parity under the target parent.
        $newData = ['pid' => $intoPid];
        if ($intoPtable !== null) {
            $newData['ptable'] = $intoPtable;
        }
        if (($denied = $this->guard->ensureCan($table, 'create', null, $newData)) !== null) {
            return $denied;
        }

        $overridesArr = $this->normaliseOverrides($overrides);

        try {
            $tree = $this->duplicator->duplicate(
                $table,
                $id,
                $into_pid,
                $intoPtable,
                $with_children,
                $overridesArr,
                $this->authorResolver->resolve(),
            );
        } catch (\InvalidArgumentException $e) {
            // A rejected override — the caller's own input, so it gets its own
            // error rather than being reported as a failed copy.
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['error' => 'duplicate_failed', 'message' => $e->getMessage(), 'class' => $e::class];
        }

        // Versions snapshot for the primary new record, attributed to the
        // calling identity (same as the CRUD create tools).
        $versions = new Versions($table, $tree['new_id']);
        $versions->setUsername($this->authorResolver->getLogUsername());
        $versions->setUserId($this->authorResolver->resolve());
        $versions->initialize();
        $versions->create();

        $this->log(
            sprintf('Duplicated %s.%d → %d (%d rows copied) via MCP', $table, $id, $tree['new_id'], $tree['copied']),
            __METHOD__,
        );

        return [
            'duplicated' => true,
            'table' => $table,
            'source_id' => $id,
            'new_id' => $tree['new_id'],
            'copied' => $tree['copied'],
            'tree' => $tree['children'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normaliseOverrides(mixed $overrides): array
    {
        if ($overrides === null) {
            return [];
        }
        if (\is_object($overrides)) {
            return (array) $overrides;
        }
        if (\is_array($overrides)) {
            return $overrides;
        }

        return [];
    }

    private function log(string $message, string $caller): void
    {
        $this->logger->info($message, ['contao' => new ContaoContext($caller, ContaoContext::GENERAL, $this->authorResolver->getLogUsername(), null, null, $this->authorResolver->getLogSource())]);
    }
}
