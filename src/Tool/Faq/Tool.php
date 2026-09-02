<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Faq;

use Contao\ContentModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\CoreBundle\Slug\Slug;
use Contao\FaqCategoryModel;
use Contao\FaqModel;
use Contao\StringUtil;
use Contao\Versions;
use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\Security\McpPermissionGuard;
use Netzhirsch\ContaoMcpBundle\Service\AuthorResolver;
use Netzhirsch\ContaoMcpBundle\Service\DbalRetry;
use Netzhirsch\ContaoMcpBundle\Service\QueryFilterResolver;
use Netzhirsch\ContaoMcpBundle\Service\TranslationMaster;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use Psr\Log\LoggerInterface;

/**
 * MCP facade for tl_faq. Five CRUD tools.
 *
 * FAQs are simpler than news/events: no featured, no publish-window (start/stop),
 * no protected/groups (those live at category level). languageMain comes from
 * the changelanguage provider when installed.
 */
final class Tool
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly LoggerInterface $logger,
        private readonly Slug $slug,
        private readonly AuthorResolver $authorResolver,
        private readonly DbalRetry $dbalRetry,
        private readonly FieldMapper $mapper,
        private readonly Serializer $serializer,
        private readonly QueryFilterResolver $filterResolver,
        private readonly Connection $connection,
        private readonly McpPermissionGuard $guard,
        private readonly TranslationMaster $translationMaster,
    ) {
    }

    /**
     * `languageMain` alone does not make a translation: changelanguage also
     * needs `master` on the category, and without it the column is never read.
     *
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function withLinkWarning(array $result, int $id): array
    {
        $warning = $this->translationMaster->recordLinkWarning('tl_faq', $id);

        return $warning === null ? $result : $result + ['warnings' => [$warning]];
    }

    /**
     * @param object|null $filters DCA-validated filter map. See entity_query_options("tl_faq").
     *
     * @return array{items: list<array<string, mixed>>, count: int, limit: int, offset: int}|array{error: string, message: string}
     */
    #[McpTool(
        name: 'faqs_list',
        description: 'Lists Contao FAQ entries (tl_faq). Filter by category_id. q does LIKE-search across DCA-searchable fields; filters is a DCA-validated equality map; updated_after/before take Unix-ts or ISO-8601. Use entity_query_options("tl_faq") for the queryable shape. Default: ALL incl. unpublished/disabled (pass include_unpublished=false for published-only).',
    )]
    public function list(
        ?int $category_id = null,
        int $limit = 50,
        int $offset = 0,
        bool $include_unpublished = true,
        ?string $q = null,
        #[Schema(type: 'object')] mixed $filters = null,
        ?string $updated_after = null,
        ?string $updated_before = null,
    ): array {
        $this->framework->initialize();
        $limit = max(1, min($limit, 200));
        $offset = max(0, $offset);

        $columns = [];
        $values = [];
        if ($category_id !== null) {
            $columns[] = 'tl_faq.pid = ?';
            $values[] = $category_id;
        }
        if (!$include_unpublished) {
            $columns[] = 'tl_faq.published = ?';
            $values[] = '1';
        }

        $search = $this->filterResolver->buildSearchClause('tl_faq', $q);
        if ($search !== null) {
            $columns[] = $search['clause'];
            $values = array_merge($values, $search['params']);
        }
        try {
            $filtersArr = $this->filterResolver->normaliseFilters($filters);
            $fr = $this->filterResolver->buildFilterClauses('tl_faq', $filtersArr);
            $columns = array_merge($columns, $fr['clauses']);
            $values = array_merge($values, $fr['params']);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_filter', 'message' => $e->getMessage()];
        }
        try {
            $ts = $this->filterResolver->buildTstampRange('tl_faq', $updated_after, $updated_before);
            $columns = array_merge($columns, $ts['clauses']);
            $values = array_merge($values, $ts['params']);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

        $items = FaqModel::findBy(
            $columns ?: null,
            $values ?: null,
            ['order' => 'tl_faq.pid, tl_faq.sorting', 'limit' => $limit, 'offset' => $offset],
        );

        $out = [];
        foreach ($items ?? [] as $f) {
            if (!$this->guard->mayRead('tl_faq', $f->row())) {
                continue;
            }
            $out[] = $this->serializer->summary($f);
        }
        return ['items' => $out, 'count' => \count($out), 'limit' => $limit, 'offset' => $offset];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'faq_get',
        description: 'Returns a single FAQ entry with all DCA-editable fields plus its content elements (tl_content with ptable=tl_faq).',
    )]
    public function get(int $id, bool $include_unpublished = true): array
    {
        $this->framework->initialize();
        $f = FaqModel::findById($id);
        if ($f === null) {
            return ['error' => 'not_found', 'message' => "FAQ $id not found."];
        }
        return $this->serializer->summary($f) + [
            'content_elements' => $this->collectContentElements((int) $f->id, $include_unpublished),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'faq_create',
        description: 'Creates a new FAQ entry. Required: category_id (tl_faq_category.id), question, sorting (Contao 128-step). Optional: answer (HTML), image fields, languageMain (changelanguage). Alias auto-generated.',
    )]
    public function create(
        int $category_id,
        string $question,
        int $sorting,
        ?string $alias = null,
        ?int $author_id = null,
        ?string $answer = null,
        ?string $pageTitle = null,
        ?string $robots = null,
        ?string $description = null,
        ?bool $addImage = null,
        ?bool $overwriteMeta = null,
        ?string $singleSRC = null,
        ?string $alt = null,
        ?string $imageTitle = null,
        ?string $imageUrl = null,
        ?string $size = null,
        ?bool $fullsize = null,
        ?string $caption = null,
        ?string $floating = null,
        ?bool $addEnclosure = null,
        ?bool $noComments = null,
        ?bool $published = null,
        ?string $searchIndexer = null,
        ?int $languageMain = null,
        #[Schema(type: 'object')] mixed $extras = null,
    ): array {
        $this->framework->initialize();

        if (FaqCategoryModel::findById($category_id) === null) {
            return ['error' => 'category_not_found', 'message' => "Faq category $category_id does not exist."];
        }

        $f = new FaqModel();
        $f->tstamp = time();
        $f->pid = $category_id;
        $f->sorting = $sorting;
        $f->question = $question;
        $f->alias = '';
        $f->author = 0;
        $f->floating = 'above';
        foreach (['addImage', 'overwriteMeta', 'fullsize', 'addEnclosure', 'noComments', 'published'] as $bf) {
            $f->$bf = 0;
        }

        $coreInput = array_filter(
            compact(
                'category_id', 'question', 'sorting', 'alias', 'author_id', 'answer',
                'pageTitle', 'robots', 'description',
                'addImage', 'overwriteMeta', 'singleSRC', 'alt', 'imageTitle', 'imageUrl',
                'size', 'fullsize', 'caption', 'floating',
                'addEnclosure', 'noComments', 'published', 'searchIndexer',
            ),
            static fn ($v): bool => $v !== null,
        );

        $input = self::mergeExtensionInput($coreInput, $languageMain, $extras);

        try {
            $this->mapper->apply($f, $input, detectChanges: false);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

        if ((int) $f->author === 0) {
            $f->author = $this->authorResolver->resolve();
        }
        if ((string) $f->alias === '') {
            $f->alias = $this->generateAlias($question);
        }

        try {
            $f->save();
        } catch (\Throwable $e) {
            return ['error' => 'save_failed', 'message' => $e->getMessage(), 'class' => $e::class];
        }

        $this->bootVersions((int) $f->id)->create();
        $this->log(sprintf('Created FAQ ID %d ("%s") in category %d via MCP', (int) $f->id, $question, $category_id), __METHOD__);

        return $this->withLinkWarning($this->serializer->summary($f) + ['created' => true], (int) $f->id);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'faq_update',
        description: 'Updates an existing FAQ. Only fields you pass are changed. Versions snapshot + tl_log.',
    )]
    public function update(
        int $id,
        ?int $category_id = null,
        ?int $sorting = null,
        ?string $question = null,
        ?string $alias = null,
        ?int $author_id = null,
        ?string $answer = null,
        ?string $pageTitle = null,
        ?string $robots = null,
        ?string $description = null,
        ?bool $addImage = null,
        ?bool $overwriteMeta = null,
        ?string $singleSRC = null,
        ?string $alt = null,
        ?string $imageTitle = null,
        ?string $imageUrl = null,
        ?string $size = null,
        ?bool $fullsize = null,
        ?string $caption = null,
        ?string $floating = null,
        ?bool $addEnclosure = null,
        ?bool $noComments = null,
        ?bool $published = null,
        ?string $searchIndexer = null,
        ?int $languageMain = null,
        #[Schema(type: 'object')] mixed $extras = null,
    ): array {
        $this->framework->initialize();
        $f = FaqModel::findById($id);
        if ($f === null) {
            return ['error' => 'not_found', 'message' => "FAQ $id not found."];
        }
        if ($category_id !== null && FaqCategoryModel::findById($category_id) === null) {
            return ['error' => 'category_not_found', 'message' => "Faq category $category_id does not exist."];
        }

        $versions = $this->bootVersions($id);

        if ($alias === '') {
            $alias = $this->generateAlias((string) ($question ?? $f->question), $id);
        }

        $coreInput = array_filter(
            compact(
                'category_id', 'sorting', 'question', 'alias', 'author_id', 'answer',
                'pageTitle', 'robots', 'description',
                'addImage', 'overwriteMeta', 'singleSRC', 'alt', 'imageTitle', 'imageUrl',
                'size', 'fullsize', 'caption', 'floating',
                'addEnclosure', 'noComments', 'published', 'searchIndexer',
            ),
            static fn ($v): bool => $v !== null,
        );

        $input = self::mergeExtensionInput($coreInput, $languageMain, $extras);

        try {
            $changed = $this->mapper->apply($f, $input, detectChanges: true);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

        if ($changed === []) {
            return $this->serializer->summary($f) + [
                'updated' => false,
                'id' => (int) $f->id,
                'changed_fields' => [],
                'applied' => 0,
            ];
        }

        $f->tstamp = time();
        try {
            $f->save();
            $versions->create();
        } catch (\Throwable $e) {
            return ['error' => 'save_failed', 'message' => $e->getMessage(), 'class' => $e::class];
        }

        $this->log(sprintf('Updated FAQ ID %d via MCP (fields: %s)', $id, implode(', ', $changed)), __METHOD__);
        return $this->withLinkWarning($this->serializer->summary($f) + [
            'updated' => true,
            'id' => (int) $f->id,
            'changed_fields' => $changed,
            'applied' => \count($changed),
        ], (int) $f->id);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'faq_delete',
        description: 'Deletes a FAQ entry and cascades to its content elements (tl_content with ptable=tl_faq). Versions snapshot + tl_log. Requires confirm_destructive=true to proceed.',
    )]
    public function delete(int $id, bool $confirm_destructive = false): array
    {
        $this->framework->initialize();

        if (!$confirm_destructive) {
            return [
                'error' => 'destructive_confirmation_required',
                'message' => 'faq_delete is irreversible. Pass confirm_destructive=true to proceed.',
            ];
        }

        $f = FaqModel::findById($id);
        if ($f === null) {
            return ['error' => 'not_found', 'message' => "FAQ $id not found."];
        }
        $question = (string) $f->question;
        $this->bootVersions($id);

        $contentCount = 0;
        // Wrap content-element cascade + FAQ delete in one transaction so a
        // mid-cascade failure can't leave tl_content rows orphaned (faq gone
        // but its content still referencing the dead id) or vice versa.
        $this->dbalRetry->transactional($this->connection, function () use ($id, $f, &$contentCount): void {
            $contentItems = ContentModel::findBy(['pid = ?', 'ptable = ?'], [$id, 'tl_faq']);
            foreach ($contentItems ?? [] as $content) {
                $content->delete();
                ++$contentCount;
            }

            $f->delete();
        });

        $this->log(sprintf('Deleted FAQ ID %d ("%s") and %d content element(s) via MCP', $id, $question, $contentCount), __METHOD__);

        return ['deleted' => true, 'id' => $id, 'deleted_content_elements' => $contentCount];
    }

    /**
     * @param array<string, mixed> $coreInput
     *
     * @return array<string, mixed>
     */
    private static function mergeExtensionInput(array $coreInput, ?int $languageMain, mixed $extras): array
    {
        $explicit = array_filter(['languageMain' => $languageMain], static fn ($v): bool => $v !== null);
        $extrasArray = match (true) {
            \is_array($extras) => $extras,
            \is_object($extras) => (array) $extras,
            default => [],
        };
        return array_merge($extrasArray, $explicit, $coreInput);
    }

    private function bootVersions(int $id): Versions
    {
        $v = new Versions('tl_faq', $id);
        $v->setUsername($this->authorResolver->getLogUsername());
        $v->setUserId($this->authorResolver->resolve());
        $v->initialize();
        return $v;
    }

    private function log(string $message, string $caller): void
    {
        $this->logger->info($message, ['contao' => new ContaoContext($caller, ContaoContext::GENERAL, $this->authorResolver->getLogUsername(), null, null, $this->authorResolver->getLogSource())]);
    }

    private function generateAlias(string $question, ?int $excludeId = null): string
    {
        $check = $excludeId === null
            ? static fn (string $value): bool => FaqModel::findOneBy('alias', $value) !== null
            : static fn (string $value): bool => FaqModel::findOneBy(['alias = ?', 'id != ?'], [$value, $excludeId]) !== null;
        return $this->slug->generate($question, ['validChars' => '0-9a-z'], $check);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectContentElements(int $faqId, bool $includeUnpublished): array
    {
        $columns = ['tl_content.pid = ?', "tl_content.ptable = 'tl_faq'"];
        $values = [$faqId];
        if (!$includeUnpublished) {
            $time = time();
            $columns[] = "tl_content.invisible = ''";
            $columns[] = "(tl_content.start = '' OR tl_content.start <= ?)";
            $values[] = (string) $time;
            $columns[] = "(tl_content.stop = '' OR tl_content.stop > ?)";
            $values[] = (string) $time;
        }
        $elements = ContentModel::findBy($columns, $values, ['order' => 'tl_content.sorting']);
        $out = [];
        foreach ($elements ?? [] as $el) {
            $headline = StringUtil::deserialize($el->headline, true);
            $out[] = [
                'id' => (int) $el->id,
                'type' => (string) $el->type,
                'sorting' => (int) $el->sorting,
                'headline_text' => isset($headline['value']) ? (string) $headline['value'] : null,
            ];
        }
        return $out;
    }
}
