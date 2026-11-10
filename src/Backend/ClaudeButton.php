<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Backend;

use Contao\BackendUser;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\Security\BackendUserContext;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The "Claude" button in the backend header and in the front-end preview.
 *
 * A click opens a small form ("What should Claude do?"), and sending it opens
 * claude.ai with a request that already says which installation and which
 * screen it is about — the module, the record being edited, the address. The
 * person types the task, not the context.
 *
 * Everything a click needs is decided here and handed to the browser as one
 * JSON attribute; public/claude-button.js only assembles and opens. The text
 * is in the backend user's language, because it is their request.
 *
 * A record's name goes into the context only when the user may read that
 * record, asked the way the backend asks — an address can be typed by hand,
 * and the button must not answer for a record the screen itself refuses.
 */
final class ClaudeButton
{
    public const DOMAIN = 'contao_mcp_server';

    /** Columns that name a record, first non-empty wins. */
    private const LABEL_COLUMNS = ['title', 'headline', 'name', 'question', 'subject', 'alias'];

    public function __construct(
        private readonly McpServerConfigStorage $configStorage,
        private readonly BackendUserContext $userContext,
        private readonly Connection $connection,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Whether this backend user gets the button: the server must be set up
     * (a backend URL to name the installation by), and the user must be one
     * the MCP server would let in.
     */
    public function isAvailableFor(int $userId): bool
    {
        $config = $this->configStorage->load();

        return ($config['config_state'] ?? null) === null
            && trim((string) $config['backend_url']) !== ''
            && $userId > 0
            && $this->userContext->hasMcpAccess($userId);
    }

    public function currentUserId(): int
    {
        $user = $this->security->getUser();

        // BackendUser keeps `id` in arrData behind __get.
        return $user instanceof BackendUser ? (int) $user->id : 0;
    }

    /**
     * The JSON the button carries, for a backend screen.
     */
    public function backendPayload(Request $request): string
    {
        $context = [];

        $do = (string) $request->query->get('do', '');
        if ($do !== '') {
            $module = $GLOBALS['TL_LANG']['MOD'][$do] ?? null;
            $label = \is_array($module) ? (string) ($module[0] ?? $do) : (\is_string($module) ? $module : $do);
            $context[] = $this->trans('claude_prompt_module', [$label.' (do='.$do.')']);

            $table = (string) $request->query->get('table', '') ?: $this->firstTableOf($do);
            $id = (int) $request->query->get('id', 0);
            $act = (string) $request->query->get('act', '');

            if ($table !== '' && $id > 0) {
                if ($act !== '' && $act !== 'select' && $act !== 'paste') {
                    $record = $table.' '.$id.$this->recordLabel($table, $id);
                    $context[] = $this->trans('claude_prompt_record', [$record]).($act === 'edit' ? ' — '.$this->trans('claude_prompt_editing') : '');
                } else {
                    // Without an action the id is the parent of a list view.
                    $context[] = $this->trans('claude_prompt_list', [$table.' ('.$this->trans('claude_prompt_parent', [(string) $id]).')']);
                }
            }
        }

        $context[] = $this->trans('claude_prompt_url', [self::cleanUrl($request)]);

        return $this->payload($context);
    }

    /**
     * The JSON the button carries, for a page in the front-end preview.
     */
    public function frontendPayload(Request $request, string $pageTitle, int $pageId): string
    {
        $context = [];
        if ($pageId > 0) {
            $context[] = $this->trans('claude_prompt_page', ['tl_page '.$pageId.($pageTitle !== '' ? ' "'.$pageTitle.'"' : '')]);
        }
        $context[] = $this->trans('claude_prompt_url', [self::cleanUrl($request)]);

        return $this->payload($context);
    }

    /**
     * @param list<string> $context
     */
    private function payload(array $context): string
    {
        $host = (string) (parse_url((string) $this->configStorage->load()['backend_url'], PHP_URL_HOST) ?: '');

        return (string) json_encode([
            'intro' => $this->trans('claude_prompt_intro', [$host]),
            'context' => $context,
            'labels' => [
                'question' => $this->trans('claude_dialog_question'),
                'send' => $this->trans('claude_dialog_send'),
                'cancel' => $this->trans('claude_dialog_cancel'),
                'hint' => $this->trans('claude_dialog_hint'),
                'context' => $this->trans('claude_dialog_context'),
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    /**
     * ` "Title"` for a record the user may read, '' otherwise.
     */
    private function recordLabel(string $table, int $id): string
    {
        if (preg_match('/^tl_[a-z0-9_]+$/', $table) !== 1) {
            return '';
        }

        try {
            $row = $this->connection->fetchAssociative(
                sprintf('SELECT * FROM %s WHERE id = ?', $this->connection->quoteIdentifier($table)),
                [$id],
            );
        } catch (\Throwable) {
            return '';
        }

        if ($row === false || !$this->security->isGranted(ContaoCorePermissions::DC_PREFIX.$table, new ReadAction($table, $row))) {
            return '';
        }

        foreach (self::LABEL_COLUMNS as $column) {
            $value = $row[$column] ?? null;
            if (!\is_string($value) || trim($value) === '') {
                continue;
            }
            // A headline is stored as serialize(['value' => …, 'unit' => …]).
            $unserialized = StringUtil::deserialize($value);
            $text = \is_array($unserialized) ? (string) ($unserialized['value'] ?? '') : $value;
            $text = trim(strip_tags(html_entity_decode($text, ENT_QUOTES | ENT_HTML5)));
            if ($text !== '') {
                return ' "'.mb_substr($text, 0, 120).'"';
            }
        }

        return '';
    }

    private function firstTableOf(string $do): string
    {
        foreach ($GLOBALS['BE_MOD'] ?? [] as $group) {
            if (\is_array($group) && \is_array($group[$do]['tables'] ?? null)) {
                return (string) ($group[$do]['tables'][0] ?? '');
            }
        }

        return '';
    }

    /**
     * The address of the current screen without the request token and the
     * referer id — neither means anything to someone else, and the token is
     * a CSRF secret.
     */
    private static function cleanUrl(Request $request): string
    {
        $query = $request->query->all();
        unset($query['rt'], $query['ref']);

        return $request->getSchemeAndHttpHost().$request->getBaseUrl().$request->getPathInfo()
            .($query !== [] ? '?'.http_build_query($query) : '');
    }

    public function translate(string $key): string
    {
        return $this->trans($key);
    }

    /**
     * @param list<string> $args
     */
    private function trans(string $key, array $args = []): string
    {
        $text = $this->translator->trans('mcp_server.'.$key, [], self::DOMAIN);

        return $args === [] ? $text : vsprintf($text, $args);
    }
}
