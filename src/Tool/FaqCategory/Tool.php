<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\FaqCategory;

use Contao\ContentModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\FaqCategoryModel;
use Contao\FaqModel;
use Contao\PageModel;
use Contao\Versions;
use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\Security\McpPermissionGuard;
use Netzhirsch\ContaoMcpBundle\Service\AuthorResolver;
use Netzhirsch\ContaoMcpBundle\Service\DbalRetry;
use Netzhirsch\ContaoMcpBundle\Service\QueryFilterResolver;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use Psr\Log\LoggerInterface;

/**
 * MCP facade for tl_faq_category — the parent of tl_faq entries.
 *
 * Delete is safe by default: refuses if entries exist, unless force=true (cascades
 * entries + their tl_content rows).
 */
final class Tool
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly LoggerInterface $logger,
        private readonly AuthorResolver $authorResolver,
        private readonly DbalRetry $dbalRetry,
        private readonly FieldMapper $mapper,
        private readonly QueryFilterResolver $filterResolver,
        private readonly Connection $connection,
        private readonly McpPermissionGuard $guard,
    ) {
    }

    /**
     * @param object|null $filters DCA-validated filter map. See entity_query_options("tl_faq_category").
     *
     * @return array{items: list<array<string, mixed>>, count: int, limit: int, offset: int}|array{error: string, message: string}
     */
    #[McpTool(
        name: 'faq_categories_list',
        description: 'Lists Contao FAQ categories (tl_faq_category). Use the returned id as category_id in faq_create. q does LIKE-search across DCA-searchable fields; filters is a DCA-validated equality map; updated_after/before take Unix-ts or ISO-8601.',
    )]
    public function list(
        int $limit = 50,
        int $offset = 0,
        ?string $q = null,
        #[Schema(type: 'object')] mixed $filters = null,
        ?string $updated_after = null,
        ?string $updated_before = null,
    ): array {
        $this->framework->initialize();
        $limit = max(1, min($limit, 100));
        $offset = max(0, $offset);

        $columns = [];
        $values = [];

        $search = $this->filterResolver->buildSearchClause('tl_faq_category', $q);
        if ($search !== null) {
            $columns[] = $search['clause'];
            $values = array_merge($values, $search['params']);
        }
        try {
            $filtersArr = $this->filterResolver->normaliseFilters($filters);
            $fr = $this->filterResolver->buildFilterClauses('tl_faq_category', $filtersArr);
            $columns = array_merge($columns, $fr['clauses']);
            $values = array_merge($values, $fr['params']);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_filter', 'message' => $e->getMessage()];
        }
        try {
            $ts = $this->filterResolver->buildTstampRange('tl_faq_category', $updated_after, $updated_before);
            $columns = array_merge($columns, $ts['clauses']);
            $values = array_merge($values, $ts['params']);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

        $items = FaqCategoryModel::findBy(
            $columns === [] ? null : $columns,
            $values === [] ? null : $values,
            ['order' => 'tl_faq_category.title', 'limit' => $limit, 'offset' => $offset],
        );
        $out = [];
        foreach ($items ?? [] as $c) {
            if (!$this->guard->mayRead('tl_faq_category', $c->row())) {
                continue;
            }
            $out[] = Serializer::summary($c);
        }
        return ['items' => $out, 'count' => \count($out), 'limit' => $limit, 'offset' => $offset];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'faq_category_get',
        description: 'Returns a single FAQ category by id, plus the count of FAQ entries it contains.',
    )]
    public function get(int $id): array
    {
        $this->framework->initialize();
        $cat = FaqCategoryModel::findById($id);
        if ($cat === null) {
            return ['error' => 'not_found', 'message' => "Faq category $id not found."];
        }
        return Serializer::summary($cat) + ['faq_count' => (int) FaqModel::countBy('pid', $id)];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'faq_category_create',
        description: 'Creates a new FAQ category. Required: title, jumpTo (tl_page.id of the FAQ-reader page). Optional: headline (FE display heading), allowComments (+ moderate, notify, sortOrder, perPage, bbcode, requireLogin, disableCaptcha). Versions snapshot + tl_log.',
    )]
    public function create(
        string $title,
        int $jumpTo,
        ?string $headline = null,
        ?bool $allowComments = null,
        ?string $notify = null,
        ?string $sortOrder = null,
        ?int $perPage = null,
        ?bool $moderate = null,
        ?bool $bbcode = null,
        ?bool $requireLogin = null,
        ?bool $disableCaptcha = null,
    ): array {
        $this->framework->initialize();

        if (PageModel::findById($jumpTo) === null) {
            return ['error' => 'page_not_found', 'message' => "jumpTo page $jumpTo does not exist."];
        }

        $cat = new FaqCategoryModel();
        $cat->tstamp = time();
        $cat->title = '';
        $cat->headline = '';
        $cat->jumpTo = 0;
        foreach (['allowComments', 'moderate', 'bbcode', 'requireLogin', 'disableCaptcha'] as $bf) {
            $cat->$bf = 0;
        }
        $cat->notify = 'notify_admin';
        $cat->sortOrder = 'ascending';

        $input = array_filter(
            compact('title', 'headline', 'jumpTo', 'allowComments', 'notify', 'sortOrder', 'perPage', 'moderate', 'bbcode', 'requireLogin', 'disableCaptcha'),
            static fn ($v): bool => $v !== null,
        );

        try {
            $this->mapper->apply($cat, $input, detectChanges: false);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

        try {
            $cat->save();
        } catch (\Throwable $e) {
            return ['error' => 'save_failed', 'message' => $e->getMessage(), 'class' => $e::class];
        }

        $this->bootVersions((int) $cat->id)->create();
        $this->log(sprintf('Created FAQ category ID %d ("%s") via MCP', (int) $cat->id, $title), __METHOD__);

        return Serializer::summary($cat) + ['created' => true];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'faq_category_update',
        description: 'Updates a FAQ category. Only fields you pass are changed. Versions snapshot + tl_log.',
    )]
    public function update(
        int $id,
        ?string $title = null,
        ?string $headline = null,
        ?int $jumpTo = null,
        ?bool $allowComments = null,
        ?string $notify = null,
        ?string $sortOrder = null,
        ?int $perPage = null,
        ?bool $moderate = null,
        ?bool $bbcode = null,
        ?bool $requireLogin = null,
        ?bool $disableCaptcha = null,
    ): array {
        $this->framework->initialize();
        $cat = FaqCategoryModel::findById($id);
        if ($cat === null) {
            return ['error' => 'not_found', 'message' => "Faq category $id not found."];
        }
        if ($jumpTo !== null && PageModel::findById($jumpTo) === null) {
            return ['error' => 'page_not_found', 'message' => "jumpTo page $jumpTo does not exist."];
        }

        $versions = $this->bootVersions($id);
        $input = array_filter(
            compact('title', 'headline', 'jumpTo', 'allowComments', 'notify', 'sortOrder', 'perPage', 'moderate', 'bbcode', 'requireLogin', 'disableCaptcha'),
            static fn ($v): bool => $v !== null,
        );

        try {
            $changed = $this->mapper->apply($cat, $input, detectChanges: true);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }
        if ($changed === []) {
            return Serializer::summary($cat) + [
                'updated' => false,
                'id' => (int) $cat->id,
                'changed_fields' => [],
                'applied' => 0,
            ];
        }

        $cat->tstamp = time();
        try {
            $cat->save();
            $versions->create();
        } catch (\Throwable $e) {
            return ['error' => 'save_failed', 'message' => $e->getMessage(), 'class' => $e::class];
        }

        $this->log(sprintf('Updated FAQ category ID %d via MCP (fields: %s)', $id, implode(', ', $changed)), __METHOD__);
        return Serializer::summary($cat) + [
            'updated' => true,
            'id' => (int) $cat->id,
            'changed_fields' => $changed,
            'applied' => \count($changed),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'faq_category_delete',
        description: 'Deletes a FAQ category. Safe by default: refuses if it contains FAQ entries. Pass cascade=true to cascade entries + their content elements. Requires confirm_destructive=true to proceed.',
    )]
    public function delete(int $id, bool $confirm_destructive = false, bool $cascade = false): array
    {
        $this->framework->initialize();

        if (!$confirm_destructive) {
            return [
                'error' => 'destructive_confirmation_required',
                'message' => 'faq_category_delete is irreversible. Pass confirm_destructive=true to proceed. Use cascade=true to also drop children.',
            ];
        }

        $cat = FaqCategoryModel::findById($id);
        if ($cat === null) {
            return ['error' => 'not_found', 'message' => "Faq category $id not found."];
        }

        $faqCount = (int) FaqModel::countBy('pid', $id);
        if ($faqCount > 0 && !$cascade) {
            return [
                'error' => 'category_not_empty',
                'message' => "Faq category $id contains $faqCount entries. Pass cascade=true to cascade.",
                'faq_count' => $faqCount,
            ];
        }

        $title = (string) $cat->title;
        $cascadedFaqs = 0;
        $cascadedContent = 0;

        $this->bootVersions($id);
        // Wrap the full cascade (faqs + each faq's tl_content + the category
        // itself) in a single DBAL transaction. Without it, a mid-cascade failure
        // (deadlock, dropped connection, constraint conflict) leaves orphan rows
        // — e.g. faqs gone but their tl_content still referencing the now-dead
        // faq id, or content gone while the category still exists.
        $this->dbalRetry->transactional($this->connection, function () use ($id, $faqCount, $cat, &$cascadedFaqs, &$cascadedContent): void {
            if ($faqCount > 0) {
                $faqs = FaqModel::findBy('pid', $id);
                foreach ($faqs ?? [] as $f) {
                    $contentItems = ContentModel::findBy(['pid = ?', 'ptable = ?'], [(int) $f->id, 'tl_faq']);
                    foreach ($contentItems ?? [] as $content) {
                        $content->delete();
                        ++$cascadedContent;
                    }
                    $f->delete();
                    ++$cascadedFaqs;
                }
            }

            $cat->delete();
        });

        $this->log(sprintf('Deleted FAQ category ID %d ("%s") via MCP — cascaded %d faqs, %d content elements', $id, $title, $cascadedFaqs, $cascadedContent), __METHOD__);

        return ['deleted' => true, 'id' => $id, 'cascaded_faqs' => $cascadedFaqs, 'cascaded_content_elements' => $cascadedContent];
    }

    private function bootVersions(int $id): Versions
    {
        $v = new Versions('tl_faq_category', $id);
        $v->setUsername($this->authorResolver->getLogUsername());
        $v->setUserId($this->authorResolver->resolve());
        $v->initialize();
        return $v;
    }

    private function log(string $message, string $caller): void
    {
        $this->logger->info($message, ['contao' => new ContaoContext($caller, ContaoContext::GENERAL, $this->authorResolver->getLogUsername(), null, null, $this->authorResolver->getLogSource())]);
    }
}
