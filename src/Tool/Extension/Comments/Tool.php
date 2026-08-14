<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Extension\Comments;

use Contao\CommentsBundle\ContaoCommentsBundle;
use Contao\CommentsModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\Versions;
use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\Service\AuthorResolver;
use Netzhirsch\ContaoMcpBundle\Service\QueryFilterResolver;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use Psr\Log\LoggerInterface;

/**
 * MCP facade for contao/comments-bundle.
 *
 * Five tools (full CRUD):
 *   comments_list, comment_get, comment_create, comment_update, comment_delete.
 *
 * SECURITY NOTE: comment_create exists at the user's request but carries
 * real spam-injection risk — any LLM client with auth_mode=none on a public
 * port could mass-produce comments. Recommended posture:
 *   - In production keep auth_mode=oauth so only authenticated backend users
 *     can write.
 *   - The `published` flag is `false` by default on create — review them
 *     before they go public.
 *
 * tl_comments is polymorphic: a comment belongs to a (source, parent) pair
 * where `source` is e.g. "tl_news" or "tl_calendar_events" and `parent` is
 * the row id in that table.
 */
final class Tool
{
    private const MARKER_CLASS = ContaoCommentsBundle::class;
    private const REQUIRED_EXTENSION = 'contao/comments-bundle';

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly AuthorResolver $authorResolver,
        private readonly QueryFilterResolver $filterResolver,
    ) {
    }

    /**
     * @param object|null $filters DCA-validated filter map. See entity_query_options("tl_comments").
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'comments_list',
        description: <<<'DESC'
            Lists tl_comments rows. Optional filters: source (e.g. "tl_news") + parent (the
            id of the row in that source table) — typical combo to fetch all comments
            on one news entry. unpublished_only=true returns only comments that haven't
            been moderated yet (useful for a moderation queue).

            q does LIKE-search across DCA-searchable fields; filters is a DCA-validated
            equality map; updated_after/before take Unix-ts or ISO-8601.
        DESC,
    )]
    public function list(
        ?string $source = null,
        ?int $parent = null,
        bool $unpublished_only = false,
        int $limit = 50,
        int $offset = 0,
        ?string $q = null,
        #[Schema(type: 'object')] mixed $filters = null,
        ?string $updated_after = null,
        ?string $updated_before = null,
    ): array {
        if (($err = $this->ensureAvailable()) !== null) {
            return $err;
        }
        $this->framework->initialize();

        $limit = max(1, min($limit, 200));
        $offset = max(0, $offset);

        $columns = [];
        $values = [];
        if ($source !== null && $source !== '') {
            $columns[] = 'tl_comments.source = ?';
            $values[] = $source;
        }
        if ($parent !== null) {
            $columns[] = 'tl_comments.parent = ?';
            $values[] = $parent;
        }
        if ($unpublished_only) {
            $columns[] = "tl_comments.published = ''";
        }

        $search = $this->filterResolver->buildSearchClause('tl_comments', $q);
        if ($search !== null) {
            $columns[] = $search['clause'];
            $values = array_merge($values, $search['params']);
        }
        try {
            $filtersArr = $this->filterResolver->normaliseFilters($filters);
            $fr = $this->filterResolver->buildFilterClauses('tl_comments', $filtersArr);
            $columns = array_merge($columns, $fr['clauses']);
            $values = array_merge($values, $fr['params']);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_filter', 'message' => $e->getMessage()];
        }
        try {
            $ts = $this->filterResolver->buildTstampRange('tl_comments', $updated_after, $updated_before);
            $columns = array_merge($columns, $ts['clauses']);
            $values = array_merge($values, $ts['params']);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

        $items = CommentsModel::findBy(
            $columns === [] ? null : $columns,
            $values === [] ? null : $values,
            ['order' => 'tl_comments.date DESC', 'limit' => $limit, 'offset' => $offset],
        );

        $out = [];
        foreach ($items ?? [] as $c) {
            $out[] = $this->summary($c);
        }

        return ['items' => $out, 'count' => \count($out), 'limit' => $limit, 'offset' => $offset];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'comment_get',
        description: 'Returns a single tl_comments row by id (full payload including reply).',
    )]
    public function get(int $id): array
    {
        if (($err = $this->ensureAvailable()) !== null) {
            return $err;
        }
        $this->framework->initialize();

        $c = CommentsModel::findByPk($id);
        if ($c === null) {
            return ['error' => 'not_found', 'message' => sprintf('No comment with id %d', $id)];
        }

        return $this->summary($c) + [
            'website' => (string) $c->website,
            'add_reply' => (bool) $c->addReply,
            'reply_author' => (string) $c->author,
            'reply' => (string) $c->reply,
            'ip' => (string) $c->ip,
            'notified' => (bool) $c->notified,
            'notified_reply' => (bool) $c->notifiedReply,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'comment_create',
        description: <<<'DESC'
            Creates a new tl_comments row.

            Required: source (parent table name, e.g. "tl_news"), parent (row id in that
            table), name (commenter name), email, comment (body text).

            Optional via `fields`:
              - website (URL the commenter linked to themselves)
              - member (tl_member.id if commenter is logged in)
              - published (bool, default false — comment is HIDDEN until moderated)
              - add_reply (bool) + reply_author + reply (your reply text)
              - date (ISO timestamp, default = now)
              - ip (recorded source IP — usually computed by Contao)

            SECURITY: defaults to unpublished. Spam risk in untrusted contexts.
        DESC,
    )]
    /**
     * @param object|null $fields Optional comment columns as a JSON object.
     */
    public function create(
        string $source,
        int $parent,
        string $name,
        string $email,
        string $comment,
        #[Schema(type: 'object')] mixed $fields = null,
    ): array {
        if (($err = $this->ensureAvailable()) !== null) {
            return $err;
        }
        $this->framework->initialize();

        if (trim($source) === '') {
            return ['error' => 'invalid_input', 'message' => 'source is required (e.g. "tl_news")'];
        }
        if ($parent <= 0) {
            return ['error' => 'invalid_input', 'message' => 'parent must be a positive integer (row id in source table)'];
        }
        if (trim($name) === '' || trim($comment) === '') {
            return ['error' => 'invalid_input', 'message' => 'name and comment must not be empty'];
        }
        if (!filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return ['error' => 'invalid_input', 'message' => 'email must be a valid address'];
        }

        $c = new CommentsModel();
        $c->tstamp = time();
        $c->date = time();
        $c->source = $source;
        $c->parent = $parent;
        $c->name = mb_substr(trim($name), 0, 64);
        $c->email = mb_substr(trim($email), 0, 255);
        $c->comment = $comment;
        $c->published = 0;
        $c->addReply = 0;

        $errors = $this->applyFields($c, self::normaliseFields($fields));
        if ($errors !== []) {
            return ['error' => 'invalid_input', 'message' => 'field validation failed', 'errors' => $errors];
        }
        $c->save();

        $this->log(sprintf(
            'Created comment by "%s" on %s:%d (id=%d, published=%s)',
            $c->name, $c->source, $c->parent, (int) $c->id, $c->published ? 'true' : 'false',
        ), __METHOD__);

        return ['created' => true, 'id' => (int) $c->id, 'published' => (bool) $c->published] + $this->summary($c);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'comment_update',
        description: 'Updates a tl_comments row — typical moderation use case is comment_update(id, {published: true}). Also accepts name, email, website, comment, add_reply, reply_author, reply.',
    )]
    /**
     * @param object|null $fields Comment columns to change as a JSON object.
     */
    public function update(int $id, #[Schema(type: 'object')] mixed $fields = null): array
    {
        if (($err = $this->ensureAvailable()) !== null) {
            return $err;
        }
        $this->framework->initialize();

        $c = CommentsModel::findByPk($id);
        if ($c === null) {
            return ['error' => 'not_found', 'message' => sprintf('No comment with id %d', $id)];
        }

        $input = self::normaliseFields($fields);
        if ($input === []) {
            return ['error' => 'no_fields', 'message' => 'fields must be a non-empty JSON object'];
        }

        $versions = $this->bootVersions($id);
        $errors = $this->applyFields($c, $input);
        if ($errors !== []) {
            return ['error' => 'invalid_input', 'message' => 'field validation failed', 'errors' => $errors];
        }

        $c->tstamp = time();
        $c->save();
        $versions->create();

        $this->log(sprintf('Updated comment id=%d (published=%s)', $id, $c->published ? 'true' : 'false'), __METHOD__);

        return ['updated' => true, 'id' => $id, 'published' => (bool) $c->published];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'comment_delete',
        description: 'Deletes a tl_comments row. Hard-delete — for soft-hide use comment_update with {"published": false}. Requires confirm_destructive=true to proceed.',
    )]
    public function delete(int $id, bool $confirm_destructive = false): array
    {
        if (($err = $this->ensureAvailable()) !== null) {
            return $err;
        }
        $this->framework->initialize();

        if (!$confirm_destructive) {
            return [
                'error' => 'destructive_confirmation_required',
                'message' => 'comment_delete is irreversible. Pass confirm_destructive=true to proceed.',
            ];
        }

        $c = CommentsModel::findByPk($id);
        if ($c === null) {
            return ['error' => 'not_found', 'message' => sprintf('No comment with id %d', $id)];
        }

        $this->bootVersions($id);
        $author = (string) $c->name;
        $source = (string) $c->source;
        $parent = (int) $c->parent;
        $c->delete();

        $this->log(sprintf('Deleted comment by "%s" on %s:%d (id=%d)', $author, $source, $parent, $id), __METHOD__);

        return ['deleted' => true, 'id' => $id];
    }

    // ─────────────────────────── helpers ────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function summary(CommentsModel $c): array
    {
        return [
            'id' => (int) $c->id,
            'source' => (string) $c->source,
            'parent' => (int) $c->parent,
            'date' => (int) $c->date,
            'name' => (string) $c->name,
            'email' => (string) $c->email,
            'member_id' => (int) $c->member,
            'comment' => (string) $c->comment,
            'published' => (bool) $c->published,
            'tstamp' => (int) $c->tstamp,
        ];
    }

    /**
     * @return list<string>
     */
    private function applyFields(CommentsModel $c, array $input): array
    {
        $errors = [];

        foreach (['name' => 'name', 'email' => 'email', 'website' => 'website',
                  'comment' => 'comment', 'reply_author' => 'author', 'reply' => 'reply',
                  'source' => 'source', 'ip' => 'ip'] as $key => $column) {
            if (\array_key_exists($key, $input)) {
                $c->{$column} = (string) ($input[$key] ?? '');
            }
        }

        if (\array_key_exists('email', $input)) {
            $value = (string) $input['email'];
            if ($value !== '' && !filter_var($value, \FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'email must be a valid address';
            }
        }
        if (\array_key_exists('parent', $input)) {
            $c->parent = (int) $input['parent'];
        }
        if (\array_key_exists('member', $input)) {
            $c->member = (int) $input['member'];
        }
        if (\array_key_exists('date', $input)) {
            $c->date = (int) $input['date'];
        }
        foreach (['published', 'add_reply' => 'addReply', 'notified', 'notified_reply' => 'notifiedReply'] as $key => $column) {
            if (\is_int($key)) {
                $key = $column;
            }
            if (\array_key_exists($key, $input)) {
                $c->{$column} = (bool) $input[$key] ? 1 : 0;
            }
        }

        return $errors;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function ensureAvailable(): ?array
    {
        if (\class_exists(self::MARKER_CLASS)) {
            return null;
        }

        return [
            'error' => 'extension_not_available',
            'message' => sprintf('The "%s" extension is not installed in this Contao instance.', self::REQUIRED_EXTENSION),
            'required_extension' => self::REQUIRED_EXTENSION,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function normaliseFields(mixed $fields): array
    {
        if ($fields === null) {
            return [];
        }
        if (\is_object($fields)) {
            return (array) $fields;
        }
        if (\is_array($fields)) {
            if ($fields !== [] && array_is_list($fields)) {
                throw new \InvalidArgumentException('`fields` must be a JSON object, not a list.');
            }
            return $fields;
        }

        throw new \InvalidArgumentException('`fields` must be a JSON object.');
    }

    private function bootVersions(int $id): Versions
    {
        $v = new Versions('tl_comments', $id);
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
