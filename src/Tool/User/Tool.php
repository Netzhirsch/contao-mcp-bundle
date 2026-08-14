<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\User;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\UserGroupModel;
use Contao\UserModel;
use Netzhirsch\ContaoMcpBundle\Security\McpPermissionGuard;
use Netzhirsch\ContaoMcpBundle\Service\QueryFilterResolver;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;

/**
 * Read-only discovery for Contao backend users (tl_user) and user groups
 * (tl_user_group). Lets the LLM resolve `cuser` / `cgroup` ids on pages,
 * and pick a sensible `author_id` for write operations.
 */
final class Tool
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly QueryFilterResolver $filterResolver,
        private readonly McpPermissionGuard $guard,
    ) {
    }

    /**
     * @param object|null $filters DCA-validated filter map. See entity_query_options("tl_user").
     *
     * @return array{items: list<array<string, mixed>>, count: int, limit: int, offset: int}|array{error: string, message: string}
     */
    #[McpTool(
        name: 'users_list',
        description: 'Lists Contao backend users (tl_user). Default: ALL incl. disabled (pass include_disabled=false for active-only). Optional admins_only true returns only admin accounts. q does LIKE-search across DCA-searchable fields; filters is a DCA-validated equality map; updated_after/before take Unix-ts or ISO-8601.',
    )]
    public function usersList(
        bool $admins_only = false,
        bool $include_disabled = true,
        int $limit = 100,
        int $offset = 0,
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

        if ($admins_only) {
            $columns[] = 'tl_user.admin = ?';
            $values[] = '1';
        }
        if (!$include_disabled) {
            $time = time();
            $columns[] = 'tl_user.disable = ?';
            $values[] = '';
            $columns[] = "(tl_user.start = '' OR tl_user.start <= ?)";
            $values[] = (string) $time;
            $columns[] = "(tl_user.stop = '' OR tl_user.stop > ?)";
            $values[] = (string) $time;
        }

        $search = $this->filterResolver->buildSearchClause('tl_user', $q);
        if ($search !== null) {
            $columns[] = $search['clause'];
            $values = array_merge($values, $search['params']);
        }
        try {
            $filtersArr = $this->filterResolver->normaliseFilters($filters);
            $fr = $this->filterResolver->buildFilterClauses('tl_user', $filtersArr);
            $columns = array_merge($columns, $fr['clauses']);
            $values = array_merge($values, $fr['params']);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_filter', 'message' => $e->getMessage()];
        }
        try {
            $ts = $this->filterResolver->buildTstampRange('tl_user', $updated_after, $updated_before);
            $columns = array_merge($columns, $ts['clauses']);
            $values = array_merge($values, $ts['params']);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

        $items = UserModel::findBy(
            $columns ?: null,
            $values ?: null,
            ['order' => 'tl_user.name', 'limit' => $limit, 'offset' => $offset],
        );

        $out = [];
        foreach ($items ?? [] as $u) {
            if (!$this->guard->mayRead('tl_user', $u->row())) {
                continue;
            }
            $out[] = [
                'id' => (int) $u->id,
                'username' => (string) $u->username,
                'name' => (string) $u->name,
                'email' => (string) $u->email,
                'language' => (string) $u->language,
                'admin' => (bool) $u->admin,
                'disabled' => (bool) $u->disable,
            ];
        }

        return ['items' => $out, 'count' => \count($out), 'limit' => $limit, 'offset' => $offset];
    }

    /**
     * @return array{items: list<array<string, mixed>>, count: int}
     */
    #[McpTool(
        name: 'user_groups_list',
        description: 'Lists Contao backend user groups (tl_user_group). Used as cgroup on pages. Default: ALL incl. disabled (pass include_disabled=false for active-only).',
    )]
    public function userGroupsList(bool $include_disabled = true): array
    {
        $this->framework->initialize();

        $columns = [];
        $values = [];

        if (!$include_disabled) {
            $columns[] = 'tl_user_group.disable = ?';
            $values[] = '';
        }

        $items = UserGroupModel::findBy(
            $columns ?: null,
            $values ?: null,
            ['order' => 'tl_user_group.name'],
        );

        $out = [];
        foreach ($items ?? [] as $g) {
            $out[] = [
                'id' => (int) $g->id,
                'name' => (string) $g->name,
                'disabled' => (bool) $g->disable,
            ];
        }

        return ['items' => $out, 'count' => \count($out)];
    }
}
