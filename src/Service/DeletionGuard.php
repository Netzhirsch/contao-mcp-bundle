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
     * Tools whose target is a PATH, not a table row. They are gated by backend
     * MODULE permission rather than by a DataContainer requirement, so they
     * cannot be recognised the way the record tools are. All of them take the
     * path in an argument called `path`.
     *
     * `breaks` is what makes rename usable: a rename or move rewrites
     * `tl_files.path` and keeps the row, the id and the UUID (Contao's
     * Dbafs::moveResource — verified). So `singleSRC = <uuid>` and
     * `{{file::<uuid>}}` survive it and must NOT block, while
     * `{{file::files/x.svg}}`, an `@import` and a hardcoded template path do
     * break and must. Deletions take everything with them, so they break every
     * identity.
     *
     * @var array<string, array{type: string, verb: string, breaks: list<string>}>
     */
    private const PATH_TOOLS = [
        'file_delete' => ['type' => 'file', 'verb' => 'Deleting', 'breaks' => self::ALL_IDENTITIES],
        'folder_delete' => ['type' => 'folder', 'verb' => 'Deleting', 'breaks' => self::ALL_IDENTITIES],
        'template_delete' => ['type' => 'template', 'verb' => 'Deleting', 'breaks' => self::ALL_IDENTITIES],
        'file_rename' => ['type' => 'file', 'verb' => 'Renaming', 'breaks' => [UsageScanner::IDENTITY_PATH]],
        'file_move' => ['type' => 'file', 'verb' => 'Moving', 'breaks' => [UsageScanner::IDENTITY_PATH]],
        // A template is addressed by name, and renaming is what changes it.
        'template_rename' => ['type' => 'template', 'verb' => 'Renaming', 'breaks' => [UsageScanner::IDENTITY_NAME, UsageScanner::IDENTITY_PATH]],
    ];

    private const ALL_IDENTITIES = [
        UsageScanner::IDENTITY_ID,
        UsageScanner::IDENTITY_UUID,
        UsageScanner::IDENTITY_PATH,
        UsageScanner::IDENTITY_NAME,
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

        $operation = self::PATH_TOOLS[$tool] ?? ['type' => $target->type, 'verb' => 'Deleting', 'breaks' => self::ALL_IDENTITIES];
        $breaks = $this->breakingIdentities($tool, $args, $target, $operation['breaks']);

        // Nothing this operation could break — e.g. moving a legacy .html5
        // template into another folder, where the template NAME is the
        // basename and therefore stays exactly the same.
        if ([] === $breaks) {
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

        $blocking = array_values(array_filter(
            $result['references'],
            static fn (array $r): bool => UsageScanner::blocks($r)
                && \in_array($r['identity'] ?? UsageScanner::IDENTITY_ID, $breaks, true),
        ));
        $blocking = $this->forgiveHandledReferences($tool, $args, $blocking);

        if ([] === $blocking) {
            return null;
        }

        return [
            'error' => 'still_in_use',
            'message' => sprintf(
                '%s %s "%s" would break %d place(s) that reference it. '
                .'Review the list, remove or repoint the references first, or pass %s=true to proceed anyway.',
                $operation['verb'],
                $target->type,
                $target->label,
                \count($blocking),
                self::OVERRIDE_ARGUMENT,
            ),
            'target' => $target->describe(),
            'references' => $blocking,
            // Everything found that this operation does NOT break: file names
            // that merely look right, permission mounts that go stale
            // harmlessly — and, for a rename, every reference anchored on the
            // id or UUID, which survives it. Shown so the caller can judge.
            'other_findings' => array_values(array_filter(
                $result['references'],
                static fn (array $r): bool => !\in_array($r, $blocking, true),
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
     * Which of the identities this operation touches actually change here.
     *
     * Only `template_rename` needs the extra thought: a legacy `.html5`
     * template is addressed by its basename, so moving it into another folder
     * leaves every `customTpl` and `$this->extend()` working. Refusing that
     * move would be a false alarm, and false alarms are how a guard rail ends
     * up switched off.
     *
     * @param array<string, mixed> $args
     * @param list<string>         $declared
     *
     * @return list<string>
     */
    private function breakingIdentities(string $tool, array $args, UsageTarget $target, array $declared): array
    {
        if ('template_rename' !== $tool) {
            return $declared;
        }

        $newName = TargetResolver::templateNameFor(\is_string($args['new_path'] ?? null) ? $args['new_path'] : '');

        if (null !== $newName && $newName === ($target->aliases[0] ?? null)) {
            return array_values(array_diff($declared, [UsageScanner::IDENTITY_NAME]));
        }

        return $declared;
    }

    /**
     * @param array<string, mixed> $args
     */
    private function targetFor(string $tool, array $args): ?UsageTarget
    {
        $identifier = null;
        $type = null;

        if (isset(self::PATH_TOOLS[$tool])) {
            $type = self::PATH_TOOLS[$tool]['type'];
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
