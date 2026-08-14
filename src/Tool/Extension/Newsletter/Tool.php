<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Extension\Newsletter;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\NewsletterBundle\ContaoNewsletterBundle;
use Contao\NewsletterChannelModel;
use Contao\NewsletterModel;
use Contao\NewsletterRecipientsModel;
use Contao\PageModel;
use Contao\Versions;
use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\Security\McpPermissionGuard;
use Netzhirsch\ContaoMcpBundle\Service\AuthorResolver;
use Netzhirsch\ContaoMcpBundle\Service\DbalRetry;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use Psr\Log\LoggerInterface;

/**
 * MCP facade for contao/newsletter-bundle. Three entities, fourteen tools
 * total, all gated by {@see ensureAvailable()} so the tools stay visible to
 * the LLM but return a structured `extension_not_available` error when the
 * host installation doesn't ship the newsletter bundle.
 *
 * Entities:
 *   - tl_newsletter_channel:    container (5 tools)
 *   - tl_newsletter:            single mailing (5 tools)
 *   - tl_newsletter_recipients: subscribers (4 tools — no get, only list/create/update/delete)
 *
 * Notably absent: newsletter_send / newsletter_preview. Send is destructive
 * (touches real mailboxes); we keep it out of the LLM's reach by design.
 * Use the Backend module if you want to send.
 */
final class Tool
{
    private const MARKER_CLASS = ContaoNewsletterBundle::class;
    private const REQUIRED_EXTENSION = 'contao/newsletter-bundle';

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly AuthorResolver $authorResolver,
        private readonly DbalRetry $dbalRetry,
        private readonly McpPermissionGuard $guard,
    ) {
    }

    // ═════════════════════ CHANNELS (tl_newsletter_channel) ═════════════════════

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'newsletter_channels_list',
        description: 'Lists tl_newsletter_channel rows (container/mailing-list level).',
    )]
    public function channelsList(int $limit = 100, int $offset = 0): array
    {
        if (($err = $this->ensureAvailable()) !== null) {
            return $err;
        }
        $this->framework->initialize();

        $limit = max(1, min($limit, 200));
        $offset = max(0, $offset);

        $items = NewsletterChannelModel::findBy(
            null,
            null,
            ['order' => 'tl_newsletter_channel.title', 'limit' => $limit, 'offset' => $offset],
        );

        $out = [];
        foreach ($items ?? [] as $c) {
            if (!$this->guard->mayRead('tl_newsletter_channel', $c->row())) {
                continue;
            }
            $out[] = [
                'id' => (int) $c->id,
                'title' => (string) $c->title,
                'jump_to' => (int) $c->jumpTo,
                'sender' => (string) $c->sender,
                'sender_name' => (string) $c->senderName,
                'recipient_count' => (int) $this->connection->fetchOne(
                    'SELECT COUNT(*) FROM tl_newsletter_recipients WHERE pid = ?',
                    [(int) $c->id],
                ),
                'tstamp' => (int) $c->tstamp,
            ];
        }

        return ['items' => $out, 'count' => \count($out), 'limit' => $limit, 'offset' => $offset];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'newsletter_channel_get',
        description: 'Returns a single tl_newsletter_channel by id + counts of its newsletters and recipients.',
    )]
    public function channelGet(int $id): array
    {
        if (($err = $this->ensureAvailable()) !== null) {
            return $err;
        }
        $this->framework->initialize();

        $channel = NewsletterChannelModel::findByPk($id);
        if ($channel === null) {
            return ['error' => 'not_found', 'message' => sprintf('No newsletter_channel with id %d', $id)];
        }

        return [
            'id' => (int) $channel->id,
            'title' => (string) $channel->title,
            'jump_to' => (int) $channel->jumpTo,
            'template' => (string) $channel->template,
            'mailer_transport' => (string) $channel->mailerTransport,
            'sender' => (string) $channel->sender,
            'sender_name' => (string) $channel->senderName,
            'newsletter_count' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_newsletter WHERE pid = ?', [$id]),
            'recipient_count' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_newsletter_recipients WHERE pid = ?', [$id]),
            'tstamp' => (int) $channel->tstamp,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'newsletter_channel_create',
        description: <<<'DESC'
            Creates a new tl_newsletter_channel.

            Required: title. Optional via `fields`:
              - jump_to (page id where unsubscribe-link points)
              - template (template name)
              - mailer_transport (Symfony mailer transport id)
              - sender (from-email), sender_name (from-display-name)
        DESC,
    )]
    /**
     * @param object|null $fields Optional channel columns as a JSON object.
     */
    public function channelCreate(string $title, #[Schema(type: 'object')] mixed $fields = null): array
    {
        if (($err = $this->ensureAvailable()) !== null) {
            return $err;
        }
        $this->framework->initialize();

        if (trim($title) === '') {
            return ['error' => 'invalid_input', 'message' => 'title is required'];
        }

        $channel = new NewsletterChannelModel();
        $channel->tstamp = time();
        $channel->title = mb_substr(trim($title), 0, 255);

        $errors = $this->applyChannelFields($channel, self::normaliseFields($fields));
        if ($errors !== []) {
            return ['error' => 'invalid_input', 'message' => 'field validation failed', 'errors' => $errors];
        }
        $channel->save();

        $this->log(sprintf('Created newsletter_channel "%s" (id=%d)', $channel->title, (int) $channel->id), __METHOD__);

        return ['created' => true, 'id' => (int) $channel->id, 'title' => (string) $channel->title];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'newsletter_channel_update',
        description: 'Updates a tl_newsletter_channel row. Accepts title, jump_to, template, mailer_transport, sender, sender_name in `fields`.',
    )]
    /**
     * @param object|null $fields Channel columns to change as a JSON object.
     */
    public function channelUpdate(int $id, #[Schema(type: 'object')] mixed $fields = null): array
    {
        if (($err = $this->ensureAvailable()) !== null) {
            return $err;
        }
        $this->framework->initialize();

        $channel = NewsletterChannelModel::findByPk($id);
        if ($channel === null) {
            return ['error' => 'not_found', 'message' => sprintf('No newsletter_channel with id %d', $id)];
        }

        $input = self::normaliseFields($fields);
        if ($input === []) {
            return ['error' => 'no_fields', 'message' => 'fields must be a non-empty JSON object'];
        }

        $errors = $this->applyChannelFields($channel, $input);
        if ($errors !== []) {
            return ['error' => 'invalid_input', 'message' => 'field validation failed', 'errors' => $errors];
        }

        $channel->tstamp = time();
        $channel->save();

        $this->log(sprintf('Updated newsletter_channel id=%d', $id), __METHOD__);

        return ['updated' => true, 'id' => $id, 'title' => (string) $channel->title];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'newsletter_channel_delete',
        description: 'Deletes a tl_newsletter_channel. Safe-by-default: refuses while newsletters or recipients exist, unless cascade=true (cascade-deletes both). Requires confirm_destructive=true to proceed.',
    )]
    public function channelDelete(int $id, bool $confirm_destructive = false, bool $cascade = false): array
    {
        if (($err = $this->ensureAvailable()) !== null) {
            return $err;
        }
        $this->framework->initialize();

        if (!$confirm_destructive) {
            return [
                'error' => 'destructive_confirmation_required',
                'message' => 'newsletter_channel_delete is irreversible. Pass confirm_destructive=true to proceed. Use cascade=true to also drop children.',
            ];
        }

        $channel = NewsletterChannelModel::findByPk($id);
        if ($channel === null) {
            return ['error' => 'not_found', 'message' => sprintf('No newsletter_channel with id %d', $id)];
        }

        $newsletterCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_newsletter WHERE pid = ?', [$id]);
        $recipientCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_newsletter_recipients WHERE pid = ?', [$id]);

        if (($newsletterCount + $recipientCount) > 0 && !$cascade) {
            return [
                'error' => 'has_children',
                'message' => 'Channel has children — pass cascade=true to cascade',
                'newsletter_count' => $newsletterCount,
                'recipient_count' => $recipientCount,
            ];
        }

        // Cascade newsletters + recipients + channel atomically. A failure
        // halfway through (deadlock, connection drop, constraint conflict)
        // without the wrapper leaves either: (a) recipients orphaned from a
        // gone channel, or (b) the channel deleted but mailing rows still
        // pointing at its pid — the LLM can't recover from either.
        $title = (string) $channel->title;
        $this->dbalRetry->transactional($this->connection, function () use ($id, $channel, $newsletterCount, $recipientCount) {
            if ($newsletterCount > 0) {
                $this->connection->executeStatement('DELETE FROM tl_newsletter WHERE pid = ?', [$id]);
            }
            if ($recipientCount > 0) {
                $this->connection->executeStatement('DELETE FROM tl_newsletter_recipients WHERE pid = ?', [$id]);
            }
            $channel->delete();
        });

        $this->log(sprintf(
            'Deleted newsletter_channel "%s" (id=%d, cascaded: newsletters=%d, recipients=%d)',
            $title, $id, $newsletterCount, $recipientCount,
        ), __METHOD__);

        return [
            'deleted' => true,
            'id' => $id,
            'cascaded' => ['newsletters' => $newsletterCount, 'recipients' => $recipientCount],
        ];
    }

    // ═════════════════════ NEWSLETTERS (tl_newsletter) ═════════════════════

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'newsletters_list',
        description: 'Lists tl_newsletter rows (individual mailings). Optional channel_id filter. Sorted by tstamp DESC (newest first).',
    )]
    public function newslettersList(?int $channel_id = null, ?bool $sent_only = null, int $limit = 50, int $offset = 0): array
    {
        if (($err = $this->ensureAvailable()) !== null) {
            return $err;
        }
        $this->framework->initialize();

        $limit = max(1, min($limit, 200));
        $offset = max(0, $offset);

        $columns = [];
        $values = [];
        if ($channel_id !== null) {
            $columns[] = 'tl_newsletter.pid = ?';
            $values[] = $channel_id;
        }
        if ($sent_only === true) {
            $columns[] = "tl_newsletter.sent = '1'";
        } elseif ($sent_only === false) {
            $columns[] = "tl_newsletter.sent = ''";
        }

        $items = NewsletterModel::findBy(
            $columns === [] ? null : $columns,
            $values === [] ? null : $values,
            ['order' => 'tl_newsletter.tstamp DESC', 'limit' => $limit, 'offset' => $offset],
        );

        $out = [];
        foreach ($items ?? [] as $n) {
            if (!$this->guard->mayRead('tl_newsletter', $n->row())) {
                continue;
            }
            $out[] = [
                'id' => (int) $n->id,
                'channel_id' => (int) $n->pid,
                'subject' => (string) $n->subject,
                'alias' => (string) $n->alias,
                'sent' => (bool) $n->sent,
                'date_sent' => (int) $n->date,
                'tstamp' => (int) $n->tstamp,
            ];
        }

        return ['items' => $out, 'count' => \count($out), 'limit' => $limit, 'offset' => $offset];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'newsletter_get',
        description: 'Returns a single tl_newsletter row by id with subject, content, text, files, sender info etc.',
    )]
    public function newsletterGet(int $id): array
    {
        if (($err = $this->ensureAvailable()) !== null) {
            return $err;
        }
        $this->framework->initialize();

        $n = NewsletterModel::findByPk($id);
        if ($n === null) {
            return ['error' => 'not_found', 'message' => sprintf('No newsletter with id %d', $id)];
        }

        return [
            'id' => (int) $n->id,
            'channel_id' => (int) $n->pid,
            'subject' => (string) $n->subject,
            'alias' => (string) $n->alias,
            'preheader' => (string) $n->preheader,
            'content' => (string) $n->content,
            'text' => (string) $n->text,
            'send_text' => (bool) $n->sendText,
            'external_images' => (bool) $n->externalImages,
            'add_file' => (bool) $n->addFile,
            'template' => (string) $n->template,
            'mailer_transport' => (string) $n->mailerTransport,
            'sender' => (string) $n->sender,
            'sender_name' => (string) $n->senderName,
            'sent' => (bool) $n->sent,
            'date_sent' => (int) $n->date,
            'tstamp' => (int) $n->tstamp,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'newsletter_create',
        description: <<<'DESC'
            Creates a new tl_newsletter row (one mailing).

            Required: channel_id, subject. Optional via `fields`:
              - alias, preheader, content (HTML body), text (plain-text body)
              - send_text (bool), external_images (bool), add_file (bool)
              - template (mail template name)
              - mailer_transport, sender (overrides channel), sender_name

            DOES NOT send. Sending happens through the Backend module to keep
            this destructive operation out of the LLM's reach.
        DESC,
    )]
    /**
     * @param object|null $fields Optional newsletter columns as a JSON object.
     */
    public function newsletterCreate(int $channel_id, string $subject, #[Schema(type: 'object')] mixed $fields = null): array
    {
        if (($err = $this->ensureAvailable()) !== null) {
            return $err;
        }
        $this->framework->initialize();

        if (NewsletterChannelModel::findByPk($channel_id) === null) {
            return ['error' => 'invalid_input', 'message' => sprintf('No newsletter_channel with id %d', $channel_id)];
        }
        if (trim($subject) === '') {
            return ['error' => 'invalid_input', 'message' => 'subject is required'];
        }

        $n = new NewsletterModel();
        $n->pid = $channel_id;
        $n->tstamp = time();
        $n->subject = mb_substr(trim($subject), 0, 255);
        $n->sent = 0;

        $errors = $this->applyNewsletterFields($n, self::normaliseFields($fields));
        if ($errors !== []) {
            return ['error' => 'invalid_input', 'message' => 'field validation failed', 'errors' => $errors];
        }
        $n->save();

        $this->bootVersions('tl_newsletter', (int) $n->id)->create();
        $this->log(sprintf('Created newsletter "%s" (id=%d, channel=%d)', $n->subject, (int) $n->id, $channel_id), __METHOD__);

        return ['created' => true, 'id' => (int) $n->id, 'subject' => (string) $n->subject];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'newsletter_update',
        description: 'Updates a tl_newsletter row. Pass id + `fields` (same keys as newsletter_create plus `sent` if you want to manually flip the sent-flag).',
    )]
    /**
     * @param object|null $fields Newsletter columns to change as a JSON object.
     */
    public function newsletterUpdate(int $id, #[Schema(type: 'object')] mixed $fields = null): array
    {
        if (($err = $this->ensureAvailable()) !== null) {
            return $err;
        }
        $this->framework->initialize();

        $n = NewsletterModel::findByPk($id);
        if ($n === null) {
            return ['error' => 'not_found', 'message' => sprintf('No newsletter with id %d', $id)];
        }

        $input = self::normaliseFields($fields);
        if ($input === []) {
            return ['error' => 'no_fields', 'message' => 'fields must be a non-empty JSON object'];
        }

        $versions = $this->bootVersions('tl_newsletter', $id);
        $errors = $this->applyNewsletterFields($n, $input);
        if ($errors !== []) {
            return ['error' => 'invalid_input', 'message' => 'field validation failed', 'errors' => $errors];
        }

        $n->tstamp = time();
        $n->save();
        $versions->create();

        $this->log(sprintf('Updated newsletter id=%d', $id), __METHOD__);

        return ['updated' => true, 'id' => $id];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'newsletter_delete',
        description: 'Deletes a tl_newsletter row. No recipients are touched (those belong to the channel, not the individual mailing). Requires confirm_destructive=true to proceed.',
    )]
    public function newsletterDelete(int $id, bool $confirm_destructive = false): array
    {
        if (($err = $this->ensureAvailable()) !== null) {
            return $err;
        }
        $this->framework->initialize();

        if (!$confirm_destructive) {
            return [
                'error' => 'destructive_confirmation_required',
                'message' => 'newsletter_delete is irreversible. Pass confirm_destructive=true to proceed.',
            ];
        }

        $n = NewsletterModel::findByPk($id);
        if ($n === null) {
            return ['error' => 'not_found', 'message' => sprintf('No newsletter with id %d', $id)];
        }

        $this->bootVersions('tl_newsletter', $id);
        $subject = (string) $n->subject;
        $n->delete();

        $this->log(sprintf('Deleted newsletter "%s" (id=%d)', $subject, $id), __METHOD__);

        return ['deleted' => true, 'id' => $id];
    }

    // ═════════════════════ RECIPIENTS (tl_newsletter_recipients) ═════════════════════

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'newsletter_recipients_list',
        description: 'Lists subscribers of one newsletter channel. Optional active_only filter.',
    )]
    public function recipientsList(int $channel_id, bool $active_only = false, int $limit = 100, int $offset = 0): array
    {
        if (($err = $this->ensureAvailable()) !== null) {
            return $err;
        }
        $this->framework->initialize();

        $limit = max(1, min($limit, 500));
        $offset = max(0, $offset);

        $columns = ['tl_newsletter_recipients.pid = ?'];
        $values = [$channel_id];
        if ($active_only) {
            $columns[] = "tl_newsletter_recipients.active = '1'";
        }

        $items = NewsletterRecipientsModel::findBy(
            $columns,
            $values,
            ['order' => 'tl_newsletter_recipients.email', 'limit' => $limit, 'offset' => $offset],
        );

        $out = [];
        foreach ($items ?? [] as $r) {
            if (!$this->guard->mayRead('tl_newsletter_recipients', $r->row())) {
                continue;
            }
            $out[] = [
                'id' => (int) $r->id,
                'channel_id' => (int) $r->pid,
                'email' => (string) $r->email,
                'active' => (bool) $r->active,
                'added_on' => (int) $r->addedOn,
                'tstamp' => (int) $r->tstamp,
            ];
        }

        return ['items' => $out, 'count' => \count($out), 'limit' => $limit, 'offset' => $offset];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'newsletter_recipient_create',
        description: 'Subscribes an email to a newsletter channel. Default active=true. Fails with "duplicate" if (channel, email) already exists.',
    )]
    public function recipientCreate(int $channel_id, string $email, bool $active = true): array
    {
        if (($err = $this->ensureAvailable()) !== null) {
            return $err;
        }
        $this->framework->initialize();

        if (NewsletterChannelModel::findByPk($channel_id) === null) {
            return ['error' => 'invalid_input', 'message' => sprintf('No newsletter_channel with id %d', $channel_id)];
        }
        $email = trim($email);
        if ($email === '' || !filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return ['error' => 'invalid_input', 'message' => 'email must be a valid address'];
        }

        $existing = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM tl_newsletter_recipients WHERE pid = ? AND email = ?',
            [$channel_id, $email],
        );
        if ($existing > 0) {
            return ['error' => 'duplicate', 'message' => sprintf('"%s" is already subscribed to channel %d', $email, $channel_id)];
        }

        $r = new NewsletterRecipientsModel();
        $r->pid = $channel_id;
        $r->tstamp = time();
        $r->addedOn = time();
        $r->email = $email;
        $r->active = $active ? 1 : 0;
        $r->save();

        $this->log(sprintf('Subscribed "%s" to newsletter_channel %d (id=%d)', $email, $channel_id, (int) $r->id), __METHOD__);

        return ['created' => true, 'id' => (int) $r->id, 'channel_id' => $channel_id, 'email' => $email, 'active' => (bool) $r->active];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'newsletter_recipient_update',
        description: 'Toggles subscriber state. Pass id + active (true/false). Useful for confirm-subscription / temporarily-pause flows.',
    )]
    public function recipientUpdate(int $id, bool $active): array
    {
        if (($err = $this->ensureAvailable()) !== null) {
            return $err;
        }
        $this->framework->initialize();

        $r = NewsletterRecipientsModel::findByPk($id);
        if ($r === null) {
            return ['error' => 'not_found', 'message' => sprintf('No newsletter_recipient with id %d', $id)];
        }

        $r->active = $active ? 1 : 0;
        $r->tstamp = time();
        $r->save();

        $this->log(sprintf('Set newsletter_recipient id=%d active=%s', $id, $active ? 'true' : 'false'), __METHOD__);

        return ['updated' => true, 'id' => $id, 'active' => $active];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'newsletter_recipient_delete',
        description: 'Unsubscribes an email — deletes the tl_newsletter_recipients row hard. For a soft-pause use newsletter_recipient_update(active=false). Requires confirm_destructive=true to proceed.',
    )]
    public function recipientDelete(int $id, bool $confirm_destructive = false): array
    {
        if (($err = $this->ensureAvailable()) !== null) {
            return $err;
        }
        $this->framework->initialize();

        if (!$confirm_destructive) {
            return [
                'error' => 'destructive_confirmation_required',
                'message' => 'newsletter_recipient_delete is irreversible. Pass confirm_destructive=true to proceed.',
            ];
        }

        $r = NewsletterRecipientsModel::findByPk($id);
        if ($r === null) {
            return ['error' => 'not_found', 'message' => sprintf('No newsletter_recipient with id %d', $id)];
        }

        $email = (string) $r->email;
        $channelId = (int) $r->pid;
        $r->delete();

        $this->log(sprintf('Unsubscribed "%s" from channel %d (id=%d)', $email, $channelId, $id), __METHOD__);

        return ['deleted' => true, 'id' => $id];
    }

    // ─────────────────────────── helpers ────────────────────────────

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
     * @return list<string>
     */
    private function applyChannelFields(NewsletterChannelModel $c, array $input): array
    {
        $errors = [];

        if (\array_key_exists('title', $input)) {
            $value = trim((string) $input['title']);
            if ($value === '') {
                $errors[] = 'title must not be empty';
            } else {
                $c->title = mb_substr($value, 0, 255);
            }
        }
        if (\array_key_exists('jump_to', $input)) {
            $value = (int) $input['jump_to'];
            if ($value > 0 && PageModel::findByPk($value) === null) {
                $errors[] = sprintf('jump_to: page id %d does not exist', $value);
            } else {
                $c->jumpTo = $value;
            }
        }
        if (\array_key_exists('template', $input)) {
            $c->template = (string) $input['template'];
        }
        if (\array_key_exists('mailer_transport', $input)) {
            $c->mailerTransport = (string) $input['mailer_transport'];
        }
        if (\array_key_exists('sender', $input)) {
            $value = (string) $input['sender'];
            if ($value !== '' && !filter_var($value, \FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'sender must be a valid email or empty';
            } else {
                $c->sender = $value;
            }
        }
        if (\array_key_exists('sender_name', $input)) {
            $c->senderName = (string) $input['sender_name'];
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function applyNewsletterFields(NewsletterModel $n, array $input): array
    {
        $errors = [];

        if (\array_key_exists('subject', $input)) {
            $value = trim((string) $input['subject']);
            if ($value === '') {
                $errors[] = 'subject must not be empty';
            } else {
                $n->subject = mb_substr($value, 0, 255);
            }
        }
        foreach (['alias' => 'alias', 'preheader' => 'preheader', 'content' => 'content', 'text' => 'text',
                  'template' => 'template', 'mailer_transport' => 'mailerTransport',
                  'sender_name' => 'senderName'] as $key => $column) {
            if (\array_key_exists($key, $input)) {
                $n->{$column} = (string) ($input[$key] ?? '');
            }
        }
        if (\array_key_exists('sender', $input)) {
            $value = (string) $input['sender'];
            if ($value !== '' && !filter_var($value, \FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'sender must be a valid email or empty';
            } else {
                $n->sender = $value;
            }
        }
        foreach (['send_text' => 'sendText', 'external_images' => 'externalImages',
                  'add_file' => 'addFile', 'sent' => 'sent'] as $key => $column) {
            if (\array_key_exists($key, $input)) {
                $n->{$column} = (bool) $input[$key] ? 1 : 0;
            }
        }

        return $errors;
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

    private function bootVersions(string $table, int $id): Versions
    {
        $v = new Versions($table, $id);
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
