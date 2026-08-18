<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Usage;

use Contao\CoreBundle\Framework\ContaoFramework;
use Netzhirsch\ContaoMcpBundle\Service\DeletionGuard;
use Netzhirsch\ContaoMcpBundle\Service\DeletionScope;
use Netzhirsch\ContaoMcpBundle\Service\Usage\TargetResolver;
use Netzhirsch\ContaoMcpBundle\Service\Usage\UsageScanner;
use Netzhirsch\ContaoMcpBundle\Service\Usage\UsageTarget;
use PhpMcp\Server\Attributes\McpTool;

/**
 * "Where is this actually used?" — the question to answer BEFORE deleting.
 *
 * Read-only. The same scan runs automatically in front of every delete tool
 * ({@see DeletionGuard}); this exposes it so the model can check first, and so
 * a human can ask "what breaks if this goes?" without deleting anything.
 */
final class Tool
{
    private const MAX_LIMIT = 200;

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly TargetResolver $resolver,
        private readonly UsageScanner $scanner,
        private readonly DeletionScope $scope,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'usage_find',
        description: <<<'DESC'
            Finds everywhere a record is used, so you can tell what a deletion would break.

            Call this BEFORE deleting anything whose removal is not obviously safe — and to
            answer questions like "is this image still needed?", "which pages link to the
            contact page?", "can I remove this module?".

            Parameters:
              - `type` — "page", "article", "content", "module", "layout", "theme", "form",
                "news", "event", "faq", "file", "folder", "template", "image_size",
                "member_group", … or a raw table name ("tl_url_rewrite").
              - `id`   — the numeric id. For type="file"/"folder" pass the path relative to the
                upload folder ("theme/logo.svg"), a DBAFS path ("files/theme/logo.svg") or a UUID.
                For type="template" pass the path relative to templates/
                ("ce_text_my.html5", "content_element/text/my.html.twig").
              - `include_file_contents` — files and templates only; scan stylesheets/templates
                on disk (default true). Set false for a faster, database-only answer.
              - `limit` — max references returned (default 50, cap 200).

            Searches three places, because Contao references things in three ways:
              1. DATABASE FIELDS — jumpTo, singleSRC, layout modules, image sizes, … derived
                 from the DCA, so extension fields are covered too.
              2. INSERT TAGS in any text column — {{link::42}}, {{link::alias}},
                 {{insert_module::7}}, {{file::<uuid>}}, {{picture::…}} …
              3. FILE CONTENTS (type=file/folder) — @import/@use in SCSS, url() in CSS, paths
                 hardcoded in templates. This catches the SCSS partial case, where
                 `_base.scss` is imported as `@import 'base'` and no path search would find it.
              4. TEMPLATES (type=template) — every `customTpl` / `template` / `…Tpl` column that
                 selects it (a content element or module rendering through it), plus other
                 templates that `{% extends %}` / `{% include %}` it or call
                 `$this->extend()` / `$this->insert()` on it.

            Returns {target, in_use, total, blocking, references:[…], other_findings, scanned,
            notes}. `references` are proven AND breaking — those refuse a delete.
            `other_findings` are everything else: a file name that merely looks like a match
            (`confidence: "possible"`), and backend permission mounts (tl_user.pagemounts,
            filemount …), where a stale entry is harmless. Reported for a human to judge,
            never blocking.

            Each reference carries `identity` — what it is anchored on: "uuid", "path",
            "name" or "id". This is what separates a delete from a rename. Renaming or
            moving a file rewrites tl_files.path but keeps the row, the id and the UUID, so
            `singleSRC = <uuid>` and `{{file::<uuid>}}` survive it, while
            `{{file::files/x.svg}}`, an SCSS `@import` and a hardcoded template path do not.
            file_rename / file_move are therefore only refused for `identity: "path"`, and
            template_rename only when the template NAME actually changes — moving a legacy
            `.html5` between folders keeps its basename, so nothing breaks and nothing is
            refused. Deletions take everything, so they are refused for any of them.

            Rows that the deletion would remove anyway (a page's own articles, a folder's own
            files) are NOT counted — they are the cascade, not a dangling reference.

            Note: caches and history (tl_search, tl_version, tl_undo, tl_log) are skipped on
            purpose — a hit there is the past, not current usage.
        DESC,
    )]
    public function find(
        string $type,
        string $id,
        bool $include_file_contents = true,
        int $limit = 50,
    ): array {
        $this->framework->initialize();

        $target = $this->resolver->resolve($type, $id);

        if (!$target instanceof UsageTarget) {
            return $target;
        }

        $result = $this->scanner->scan(
            $target,
            // Same subtraction the delete guard makes, so the tool's answer and
            // the guard's decision cannot contradict each other.
            $this->scope->collect($target->table, $target->id),
            scanFiles: $include_file_contents
                && \in_array($target->table, ['tl_files', UsageTarget::TABLE_TEMPLATES], true),
            limit: max(1, min($limit, self::MAX_LIMIT)),
        );

        $blocking = [];
        $other = [];

        foreach ($result['references'] as $reference) {
            if (UsageScanner::blocks($reference)) {
                $blocking[] = $reference;
            } else {
                $other[] = $reference;
            }
        }

        return [
            'target' => $target->describe(),
            'in_use' => $result['in_use'],
            'total' => $result['total'],
            'blocking' => $result['blocking'],
            'truncated' => $result['truncated'],
            'references' => $blocking,
            'other_findings' => $other,
            'scanned' => $result['scanned'],
            'notes' => $result['notes'],
            'delete_hint' => $result['in_use']
                ? sprintf(
                    'A delete tool will refuse this until the references are gone. Pass %s=true to override.',
                    DeletionGuard::OVERRIDE_ARGUMENT,
                )
                : 'No proven references — a delete tool will not object.',
        ];
    }
}
