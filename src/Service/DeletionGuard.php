<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Service;

use Contao\CoreBundle\Monolog\ContaoContext;
use Netzhirsch\ContaoMcpBundle\Security\ToolPermissionMap;
use Netzhirsch\ContaoMcpBundle\Service\Usage\TargetResolver;
use Netzhirsch\ContaoMcpBundle\Service\Usage\UsageScanner;
use Netzhirsch\ContaoMcpBundle\Service\Usage\UsageTarget;
use Psr\Log\LoggerInterface;

/**
 * Refuses a deletion while something still points at the record.
 *
 * The AI can see the whole site but not the consequences of removing one row
 * from it: a page that six modules use as their `jumpTo`, an image sitting in
 * forty content elements, an SCSS partial three stylesheets import. Every one
 * of those deletions succeeds silently today and breaks the site later.
 *
 * So the check runs automatically, in the same two places the permission check
 * and the undo snapshot run — {@see \Netzhirsch\ContaoMcpBundle\Controller\McpController}
 * and the lazy-mode `contao_call` proxy — which covers every delete tool,
 * including ones added after this.
 *
 * It is a guard rail, not a veto. `ignore_references=true` deletes anyway;
 * that override is logged to tl_log, because "the AI deleted it and something
 * broke" is a question somebody will ask later.
 *
 * Only PROVABLE references block ({@see UsageScanner::CONFIDENCE_CERTAIN}).
 * Weaker signals — a file name mentioned in a template — travel in the same
 * payload as `possible_references` for a human to judge, and never stand in
 * the way of a legitimate cleanup.
 */
final class DeletionGuard
{
    /** The caller's way to say "I know, delete it anyway". */
    public const OVERRIDE_ARGUMENT = 'ignore_references';

    /** How many referrers are listed back to the caller. */
    private const MAX_REPORTED = 25;

    /**
     * Delete tools whose target is a PATH, not a table row. They are gated by
     * backend MODULE permission rather than by a DataContainer requirement, so
     * they cannot be recognised the way the record tools are. All three take
     * the path in an argument called `path`.
     *
     * @var array<string, string> tool => usage type
     */
    private const PATH_TOOLS = [
        'file_delete' => 'file',
        'folder_delete' => 'folder',
        'template_delete' => 'template',
    ];

    public function __construct(
        private readonly ToolPermissionMap $map,
        private readonly TargetResolver $resolver,
        private readonly UsageScanner $scanner,
        private readonly DeletionScope $scope,
        private readonly AuthorResolver $authorResolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>|null Denial payload, or null to let the delete run
     */
    public function check(string $tool, array $args): ?array
    {
        $target = $this->targetFor($tool, $args);

        if (null === $target) {
            return null;
        }

        // The tool itself will refuse without the confirmation flag, so there
        // is nothing to protect and no reason to pay for a scan.
        if (\array_key_exists('confirm_destructive', $args) && true !== $args['confirm_destructive']) {
            return null;
        }

        if (true === ($args[self::OVERRIDE_ARGUMENT] ?? null)) {
            $this->logOverride($tool, $target);

            return null;
        }

        try {
            $result = $this->scanner->scan(
                $target,
                $this->scope->collect($target->table, $target->id),
                // Reading files off disk is the expensive half, and only file
                // and template targets can be referenced from inside a file.
                scanFiles: \in_array($target->table, ['tl_files', UsageTarget::TABLE_TEMPLATES], true),
                limit: self::MAX_REPORTED,
            );
        } catch (\Throwable $e) {
            // A broken guard must not become an outage: the deletion proceeds
            // exactly as it did before this feature existed, and the failure is
            // visible in the log instead of being silently swallowed.
            $this->logger->warning('Deletion reference check failed — deletion allowed to proceed.', [
                'tool' => $tool,
                'exception' => $e,
            ]);

            return null;
        }

        $blocking = array_values(array_filter($result['references'], static fn (array $r): bool => UsageScanner::blocks($r)));
        $blocking = $this->forgiveHandledReferences($tool, $args, $blocking);

        if ([] === $blocking) {
            return null;
        }

        return [
            'error' => 'still_in_use',
            'message' => sprintf(
                '%s "%s" is still referenced %d time(s) — deleting it would break those places. '
                .'Review the list, remove or repoint the references first, or pass %s=true to delete anyway.',
                ucfirst($target->type),
                $target->label,
                \count($blocking),
                self::OVERRIDE_ARGUMENT,
            ),
            'target' => $target->describe(),
            'references' => $blocking,
            // Everything found but not strong enough to refuse on: file names
            // that merely look right, and permission mounts that go stale
            // harmlessly. Shown so the caller can judge, not to be acted on.
            'other_findings' => array_values(array_filter(
                $result['references'],
                static fn (array $r): bool => !UsageScanner::blocks($r),
            )),
            'truncated' => $result['truncated'],
            'notes' => $result['notes'],
            'hint' => sprintf(
                'usage_find(type: "%s", id: "%s") shows the same list at any time, without attempting a deletion.',
                $target->type,
                $target->path ?? $target->id,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $args
     */
    private function targetFor(string $tool, array $args): ?UsageTarget
    {
        $identifier = null;
        $type = null;

        if (isset(self::PATH_TOOLS[$tool])) {
            $type = self::PATH_TOOLS[$tool];
            $identifier = \is_string($args['path'] ?? null) ? $args['path'] : null;
        } else {
            $requirement = $this->map->requirement($tool, $args);

            if (!\is_array($requirement) || 'dc' !== ($requirement['kind'] ?? null) || 'delete' !== ($requirement['op'] ?? null)) {
                return null;
            }

            $table = (string) ($requirement['table'] ?? '');
            $id = (int) ($requirement['id'] ?? 0);

            if ('' === $table || $id <= 0) {
                return null;
            }

            $type = TargetResolver::friendlyType($table);
            $identifier = (string) $id;
        }

        if (null === $identifier || '' === $identifier) {
            return null;
        }

        $target = $this->resolver->resolve($type, $identifier);

        // Not resolvable (already gone, bad path) — let the tool produce its
        // own, more specific error instead of shadowing it with ours.
        return $target instanceof UsageTarget ? $target : null;
    }

    /**
     * Drops references the delete tool itself repairs, so the guard does not
     * refuse a call that was already going to do the right thing.
     *
     * `page_delete(cascade: true)` resets every `jumpTo` pointing at the page
     * inside the same transaction — those referrers end up consistent, so
     * blocking on them would make the documented cascade unusable.
     *
     * @param array<string, mixed>       $args
     * @param list<array<string, mixed>> $references
     *
     * @return list<array<string, mixed>>
     */
    private function forgiveHandledReferences(string $tool, array $args, array $references): array
    {
        if ('page_delete' !== $tool || true !== ($args['cascade'] ?? null)) {
            return $references;
        }

        return array_values(array_filter(
            $references,
            static fn (array $r): bool => !('db_field' === ($r['source'] ?? '') && 'jumpTo' === ($r['field'] ?? '')),
        ));
    }

    private function logOverride(string $tool, UsageTarget $target): void
    {
        $this->logger->info(
            sprintf(
                'MCP: %s deleted %s "%s" (%s id %d) with %s=true — reference check bypassed.',
                $tool,
                $target->type,
                $target->label,
                $target->table,
                $target->id,
                self::OVERRIDE_ARGUMENT,
            ),
            ['contao' => new ContaoContext(
                __METHOD__,
                ContaoContext::GENERAL,
                $this->authorResolver->getLogUsername(),
                null,
                null,
                $this->authorResolver->getLogSource(),
            )],
        );
    }
}
