<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Security;

use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\Backend\McpServerConfigStorage;

/**
 * The operator's switches in MCP-Server → Konfiguration: read-only, no
 * deletions, no publishing, new content unpublished, privacy mode.
 *
 * They are not rights. A right says what one backend user may do and comes
 * from Contao; a switch says what this installation lets an AI do at all,
 * whoever is logged in — the administrator included, and in trusted mode as
 * well. That is why they sit beside the permission check and not inside it.
 *
 * Four ways in:
 *   - {@see blockedTools()} removes whole tools from the catalogue, the same
 *     way an operator-disabled tool disappears (HttpDispatcherFactory).
 *   - {@see check()} refuses a single call whose arguments cross a switch. It
 *     runs in McpPermissionEnforcer, so direct calls and contao_call alike.
 *   - {@see rewrite()} makes a create unpublished when the caller left the
 *     flag out.
 *   - {@see redact()} takes IBAN and BIC out of every result in privacy mode.
 */
final class OperatorPolicy
{
    /**
     * Tables whose rows are about people: members, newsletter recipients,
     * commenters, form senders and backend users.
     */
    public const PRIVACY_TABLES = ['tl_member', 'tl_newsletter_recipients', 'tl_comments', 'tl_lead', 'tl_lead_data', 'tl_user'];

    /** Never read and never written in privacy mode, on whatever table they turn up. */
    public const PRIVACY_FIELDS = ['iban', 'bic'];

    /** Tools on those tables that their name does not lead to. */
    private const PRIVACY_TOOLS = ['users_list', 'leads_list', 'lead_get'];

    /**
     * The column that decides whether a record is visible, and whether a
     * truthy value means visible (`published`) or hidden (`invisible`).
     *
     * @var array<string, array{0: string, 1: bool}>
     */
    private const PUBLISH_COLUMNS = [
        'tl_page' => ['published', true],
        'tl_article' => ['published', true],
        'tl_news' => ['published', true],
        'tl_calendar_events' => ['published', true],
        'tl_faq' => ['published', true],
        'tl_comments' => ['published', true],
        'tl_content' => ['invisible', false],
    ];

    /** Create tools that take the publish flag as a flat argument. */
    private const FLAT_PUBLISH_CREATE = ['page_create', 'article_create', 'news_create', 'calendar_event_create', 'faq_create'];

    public function __construct(
        private readonly McpServerConfigStorage $configStorage,
        private readonly ToolPermissionMap $map,
        private readonly Connection $connection,
    ) {
    }

    /**
     * The switches that are currently narrowing something, as codes:
     * read_only, no_delete, no_publish, create_unpublished, privacy_mode,
     * no_admins. Empty when the installation runs with the defaults.
     *
     * @return list<string>
     */
    public function activeRestrictions(): array
    {
        $c = $this->settings();
        $active = [];

        if ($c['read_only']) {
            $active[] = 'read_only';
        }
        if (!$c['read_only'] && !$c['allow_delete']) {
            $active[] = 'no_delete';
        }
        if (!$c['read_only'] && !$c['allow_publish']) {
            $active[] = 'no_publish';
        }
        if (!$c['read_only'] && $c['allow_publish'] && $c['create_unpublished']) {
            $active[] = 'create_unpublished';
        }
        if ($c['privacy_mode']) {
            $active[] = 'privacy_mode';
        }
        if (!$c['admins_allowed']) {
            $active[] = 'no_admins';
        }

        return $active;
    }

    /**
     * Tools the switches take out of the catalogue entirely.
     *
     * @param iterable<string> $toolNames
     *
     * @return list<string>
     */
    public function blockedTools(iterable $toolNames): array
    {
        $c = $this->settings();
        $blocked = [];

        foreach ($toolNames as $name) {
            // A tool nothing here can place counts as one that writes: the
            // read-only switch must not let an unknown through on a guess.
            $op = $this->map->operationOf($name) ?? 'update';

            if (($c['read_only'] && $op !== 'read')
                || (!$c['allow_delete'] && $op === 'delete')
                || ($c['privacy_mode'] && $this->touchesPersonalData($name))
            ) {
                $blocked[] = $name;
            }
        }

        return $blocked;
    }

    /**
     * Refuses a call that one of the switches rules out, or null.
     *
     * The catalogue is already pruned for everything a tool name alone
     * decides; this is the second layer for what only the arguments say —
     * a table named in the call, a record being made visible — and it repeats
     * the name-based checks so a pruning that did not happen cannot leave a
     * gap.
     *
     * @param array<string, mixed> $args
     *
     * @return array{error: string, message: string}|null
     */
    public function check(string $tool, array $args): ?array
    {
        $c = $this->settings();
        $op = $this->map->operationOf($tool) ?? 'update';

        if ($c['read_only'] && $op !== 'read') {
            return self::deny('read_only', sprintf('"%s" changes data, and this installation is set to read-only for AI clients. An administrator can change that under MCP-Server → Konfiguration.', $tool));
        }

        if (!$c['allow_delete'] && $op === 'delete') {
            return self::deny('delete_not_allowed', sprintf('"%s" deletes, and deleting is switched off for AI clients on this installation. Ask a person to delete it in the backend.', $tool));
        }

        $req = $this->map->requirement($tool, $args) ?? [];
        $table = ($req['kind'] ?? null) === 'dc' && \is_string($req['table'] ?? null) ? $req['table'] : null;
        $fields = \is_array($req['fields'] ?? null) ? $req['fields'] : [];

        if ($c['privacy_mode']) {
            if ($this->touchesPersonalData($tool) || ($table !== null && \in_array($table, self::PRIVACY_TABLES, true))) {
                return self::deny('privacy_mode', 'This data is about people (members, newsletter recipients, comments, form submissions or backend users), and privacy mode keeps it away from AI clients on this installation.');
            }

            if ($this->namesPrivacyField($tool, $args, $fields)) {
                return self::deny('privacy_mode', 'IBAN and BIC are never read or written by AI clients while privacy mode is on.');
            }
        }

        if ($table === null || !isset(self::PUBLISH_COLUMNS[$table])) {
            return null;
        }

        [$column] = self::PUBLISH_COLUMNS[$table];
        $createHidden = $c['create_unpublished'] || !$c['allow_publish'];
        $reqOp = (string) ($req['op'] ?? '');

        if ($reqOp === 'create' && $createHidden && $this->createsVisible($tool, $table, $args, $fields)) {
            return $c['allow_publish']
                ? self::deny('create_unpublished', sprintf('New content is created unpublished on this installation. Leave "%s" out (or set it to the hidden value) and publish it afterwards with the matching *_update tool.', $column))
                : self::deny('publish_not_allowed', sprintf('Publishing is switched off for AI clients on this installation, so new content is created unpublished. Leave "%s" out; a person publishes it in the backend.', $column));
        }

        if ($reqOp === 'update' && !$c['allow_publish'] && $this->makesVisible($tool, $table, $args, $fields, $req)) {
            return self::deny('publish_not_allowed', 'Publishing is switched off for AI clients on this installation. A person publishes it in the backend.');
        }

        return null;
    }

    /**
     * The arguments a create runs with: unpublished, if the switches say new
     * content starts hidden and the caller did not set the flag itself (an
     * explicit visible value is refused by {@see check()} instead of being
     * quietly turned around).
     *
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public function rewrite(string $tool, array $args): array
    {
        $c = $this->settings();
        if (!$c['create_unpublished'] && $c['allow_publish']) {
            return $args;
        }

        if (\in_array($tool, self::FLAT_PUBLISH_CREATE, true)) {
            if (!$this->hasArg($args, 'published')) {
                $args['published'] = false;
            }

            return $args;
        }

        switch ($tool) {
            case 'content_create':
                $args['fields'] = self::withDefault($args['fields'] ?? null, 'invisible', true);
                break;

            case 'content_create_tree':
                // The top level is enough: a child renders through its
                // container, and a hidden container shows none of them.
                if (\is_array($args['elements'] ?? null)) {
                    foreach ($args['elements'] as $i => $node) {
                        if (\is_array($node)) {
                            $node['fields'] = self::withDefault($node['fields'] ?? null, 'invisible', true);
                            $args['elements'][$i] = $node;
                        }
                    }
                }
                break;

            case 'pages_create_tree':
                // Every level: a hidden parent page does not keep a child page
                // off its own URL.
                if (\is_array($args['pages'] ?? null)) {
                    $args['pages'] = self::hidePages($args['pages']);
                }
                break;

            case 'entity_duplicate':
                $table = \is_string($args['table'] ?? null) ? $args['table'] : '';
                if (isset(self::PUBLISH_COLUMNS[$table])) {
                    [$column, $truthyVisible] = self::PUBLISH_COLUMNS[$table];
                    $args['overrides'] = self::withDefault($args['overrides'] ?? null, $column, !$truthyVisible);
                }
                break;
        }

        return $args;
    }

    /**
     * A result with IBAN and BIC removed, at any depth, while privacy mode is
     * on. Unchanged otherwise.
     */
    public function redact(mixed $result): mixed
    {
        if (!$this->settings()['privacy_mode']) {
            return $result;
        }

        return self::stripPrivacyFields($result);
    }

    /**
     * True when {@see redact()} would change the decoded result — lets a
     * caller that holds JSON skip re-encoding in the common case.
     */
    public function wouldRedact(mixed $result): bool
    {
        return $this->settings()['privacy_mode'] && self::containsPrivacyField($result);
    }

    public function isPrivacyModeOn(): bool
    {
        return $this->settings()['privacy_mode'];
    }

    public function touchesPersonalData(string $tool): bool
    {
        return \in_array($tool, self::PRIVACY_TOOLS, true)
            || \in_array($this->map->tableOf($tool), self::PRIVACY_TABLES, true);
    }

    /**
     * @return array{read_only: bool, allow_publish: bool, allow_delete: bool, create_unpublished: bool, privacy_mode: bool, admins_allowed: bool}
     */
    private function settings(): array
    {
        $config = $this->configStorage->load();

        return [
            'read_only' => (bool) ($config['read_only'] ?? false),
            'allow_publish' => (bool) ($config['allow_publish'] ?? true),
            'allow_delete' => (bool) ($config['allow_delete'] ?? true),
            'create_unpublished' => (bool) ($config['create_unpublished'] ?? false),
            'privacy_mode' => (bool) ($config['privacy_mode'] ?? false),
            'admins_allowed' => (bool) ($config['admins_allowed'] ?? true),
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @param array<string, mixed> $fields
     */
    private function namesPrivacyField(string $tool, array $args, array $fields): bool
    {
        foreach (array_keys($fields) as $key) {
            if (\in_array(strtolower((string) $key), self::PRIVACY_FIELDS, true)) {
                return true;
            }
        }

        // entity_field_patch names the column as a value, not as a key.
        return $tool === 'entity_field_patch'
            && \is_string($args['field'] ?? null)
            && \in_array(strtolower($args['field']), self::PRIVACY_FIELDS, true);
    }

    /**
     * @param array<string, mixed> $args
     * @param array<string, mixed> $fields
     */
    private function createsVisible(string $tool, string $table, array $args, array $fields): bool
    {
        [$column, $truthyVisible] = self::PUBLISH_COLUMNS[$table];

        if ($tool === 'pages_create_tree') {
            return \is_array($args['pages'] ?? null) && self::anyPageVisible($args['pages']);
        }

        if ($tool === 'content_create_tree') {
            foreach (\is_array($args['elements'] ?? null) ? $args['elements'] : [] as $node) {
                $nodeFields = \is_array($node) ? self::toArray($node['fields'] ?? null) : [];
                if (\array_key_exists($column, $nodeFields) && self::isVisible($nodeFields[$column], $truthyVisible)) {
                    return true;
                }
            }

            return false;
        }

        if ($tool === 'entity_duplicate') {
            $overrides = self::toArray($args['overrides'] ?? null);

            return \array_key_exists($column, $overrides) && self::isVisible($overrides[$column], $truthyVisible);
        }

        return \array_key_exists($column, $fields) && self::isVisible($fields[$column], $truthyVisible);
    }

    /**
     * Whether an update turns a hidden record visible. Making something hidden
     * is not publishing; writing the value it already has is not either.
     *
     * @param array<string, mixed> $args
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $req
     */
    private function makesVisible(string $tool, string $table, array $args, array $fields, array $req): bool
    {
        [$column, $truthyVisible] = self::PUBLISH_COLUMNS[$table];

        // Patches text inside a column — the publish flag is no text.
        if ($tool === 'entity_field_patch') {
            return ($args['field'] ?? null) === $column;
        }

        if (!\array_key_exists($column, $fields) || !self::isVisible($fields[$column], $truthyVisible)) {
            return false;
        }

        $id = isset($req['id']) ? (int) $req['id'] : 0;
        if ($id <= 0) {
            return true; // nothing to compare with — refuse rather than guess
        }

        try {
            $current = $this->connection->fetchOne(
                sprintf('SELECT %s FROM %s WHERE id = ?', $this->connection->quoteIdentifier($column), $table),
                [$id],
            );
        } catch (\Throwable) {
            return true;
        }

        // A record that does not exist is the tool's not_found to report.
        return $current !== false && !self::isVisible($current, $truthyVisible);
    }

    /**
     * @param array<mixed> $args
     */
    private function hasArg(array $args, string $key): bool
    {
        if (\array_key_exists($key, $args)) {
            return true;
        }

        foreach (['fields', 'extras'] as $nested) {
            if (\array_key_exists($key, self::toArray($args[$nested] ?? null))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $pages
     */
    private static function anyPageVisible(array $pages): bool
    {
        foreach ($pages as $node) {
            if (!\is_array($node)) {
                continue;
            }
            if (\array_key_exists('published', $node) && self::isVisible($node['published'], true)) {
                return true;
            }
            if (\is_array($node['children'] ?? null) && self::anyPageVisible($node['children'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $pages
     *
     * @return array<mixed>
     */
    private static function hidePages(array $pages): array
    {
        foreach ($pages as $i => $node) {
            if (!\is_array($node)) {
                continue;
            }
            if (!\array_key_exists('published', $node)) {
                $node['published'] = false;
            }
            if (\is_array($node['children'] ?? null)) {
                $node['children'] = self::hidePages($node['children']);
            }
            $pages[$i] = $node;
        }

        return $pages;
    }

    /**
     * @return array<string, mixed>
     */
    private static function withDefault(mixed $object, string $key, mixed $value): array
    {
        $array = self::toArray($object);
        if (!\array_key_exists($key, $array)) {
            $array[$key] = $value;
        }

        return $array;
    }

    /**
     * @return array<mixed>
     */
    private static function toArray(mixed $value): array
    {
        if (\is_array($value)) {
            return $value;
        }

        return \is_object($value) ? get_object_vars($value) : [];
    }

    private static function isVisible(mixed $value, bool $truthyVisible): bool
    {
        return $truthyVisible ? self::truthy($value) : !self::truthy($value);
    }

    private static function truthy(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return $value != 0;
        }
        if (\is_string($value)) {
            return !\in_array(strtolower(trim($value)), ['', '0', 'false', 'no', 'off'], true);
        }

        return false;
    }

    private static function stripPrivacyFields(mixed $value): mixed
    {
        if (\is_object($value)) {
            $value = get_object_vars($value);
        }
        if (!\is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $inner) {
            if (\is_string($key) && \in_array(strtolower($key), self::PRIVACY_FIELDS, true)) {
                unset($value[$key]);
                continue;
            }
            $value[$key] = self::stripPrivacyFields($inner);
        }

        return $value;
    }

    private static function containsPrivacyField(mixed $value): bool
    {
        if (\is_object($value)) {
            $value = get_object_vars($value);
        }
        if (!\is_array($value)) {
            return false;
        }

        foreach ($value as $key => $inner) {
            if ((\is_string($key) && \in_array(strtolower($key), self::PRIVACY_FIELDS, true)) || self::containsPrivacyField($inner)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{error: string, message: string}
     */
    private static function deny(string $error, string $message): array
    {
        return ['error' => $error, 'message' => $message];
    }
}
