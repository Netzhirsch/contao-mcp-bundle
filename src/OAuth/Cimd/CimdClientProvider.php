<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\OAuth\Cimd;

use Contao\CoreBundle\Monolog\ContaoContext;
use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\OAuth\Entity\ClientEntity;
use Psr\Log\LoggerInterface;

/**
 * Bridges a validated Client ID Metadata Document into the client entity
 * league/oauth2-server expects.
 *
 * Two things happen here, and the split between them is deliberate.
 *
 * **What gets persisted** is the DECLARED redirect URI list — the stable one
 * from the document. It goes into `tl_mcp_oauth_client` so the backend list, the
 * consent record and the token endpoint see a CIMD client exactly the way they
 * see a dynamically registered one, with no special cases downstream.
 *
 * **What gets handed to league** may carry one extra entry: the concrete
 * loopback URI of the request in progress. Claude Code declares
 * `http://localhost/callback` and arrives on `http://localhost:3118/callback`,
 * a port that changes every session. Persisting that would be wrong twice over
 * — two concurrent sessions would overwrite each other, and the stored row
 * would claim a redirect URI that is only valid for one process for a few
 * minutes. So it lives in a per-request memo instead, and only ever after
 * {@see RedirectUriMatcher} has agreed that it matches something declared.
 *
 * league re-reads the client at the token endpoint, but compares the redirect
 * URI against the one recorded in the authorization code rather than against
 * the client — so the memo not surviving the request is correct, not a gap.
 */
final class CimdClientProvider
{
    /** @var array<string, ClientEntity> */
    private array $memo = [];

    public function __construct(
        private readonly CimdResolver $resolver,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Called before the authorization request is validated. Returns null when
     * this is not a CIMD flow at all; throws when it is one and it fails.
     *
     * @throws CimdException
     */
    public function prepare(string $clientId, ?string $requestedRedirectUri): ?ClientEntity
    {
        if (!ClientIdUrl::looksLikeUrl($clientId) || !$this->resolver->isEnabled()) {
            return null;
        }

        try {
            $document = $this->resolver->resolve($clientId);
        } catch (CimdException $e) {
            // The reason is for the operator, not the caller — see CimdException.
            $this->log(sprintf('CIMD refused "%s": %s', $clientId, $e->getMessage()));

            throw $e;
        }

        $this->persist($document);

        $effective = $document['redirect_uris'];

        if (
            \is_string($requestedRedirectUri)
            && $requestedRedirectUri !== ''
            && !\in_array($requestedRedirectUri, $effective, true)
            && RedirectUriMatcher::matches($effective, $requestedRedirectUri)
        ) {
            $effective[] = $requestedRedirectUri;
        }

        $client = new ClientEntity();
        $client->setIdentifier($document['client_id']);
        $client->setName($document['client_name']);
        $client->setRedirectUri(array_values($effective));
        // PKCE-protected public client: `none` at the token endpoint. Marking
        // it confidential would make league demand a secret that, by
        // definition, a CIMD client does not have.
        $client->setConfidential(false);

        $this->memo[$clientId] = $client;

        return $client;
    }

    /**
     * The entity prepared earlier in THIS request, if any. Consulted by the
     * client repository before it falls back to the database.
     */
    public function pending(string $clientId): ?ClientEntity
    {
        return $this->memo[$clientId] ?? null;
    }

    /**
     * Details for the consent screen. The MCP specification requires the
     * redirect host to be shown and asks for an extra warning when every
     * declared redirect URI is a loopback address — a metadata document cannot
     * stop a local process from binding a port and borrowing the client's name.
     *
     * @return array{host: string, loopback_only: bool}|null
     */
    public function consentDetails(string $clientId): ?array
    {
        $client = $this->pending($clientId);

        if ($client === null) {
            return null;
        }

        $declared = $client->getRedirectUri();

        return [
            'host' => ClientIdUrl::host($clientId),
            'loopback_only' => RedirectUriMatcher::isLoopbackOnly(\is_array($declared) ? $declared : [$declared]),
        ];
    }

    /**
     * @param array{client_id: string, client_name: string, redirect_uris: list<string>} $document
     */
    private function persist(array $document): void
    {
        $uris = json_encode($document['redirect_uris'], \JSON_UNESCAPED_SLASHES) ?: '[]';
        $now = time();

        $existing = $this->connection->fetchOne(
            'SELECT id FROM tl_mcp_oauth_client WHERE client_id = ?',
            [$document['client_id']],
        );

        if ($existing === false) {
            $this->connection->insert('tl_mcp_oauth_client', [
                'tstamp' => $now,
                'client_id' => $document['client_id'],
                'client_secret_hash' => '',
                'name' => $document['client_name'],
                'redirect_uris' => $uris,
                'is_confidential' => 0,
                'created_at' => $now,
            ]);

            $this->log(sprintf('CIMD client registered: %s (%s)', $document['client_name'], $document['client_id']));

            return;
        }

        // The document is the source of truth and may legitimately change — a
        // renamed client or an added redirect URI must take effect on the next
        // authorization, not at the next cache eviction.
        $this->connection->update(
            'tl_mcp_oauth_client',
            [
                'tstamp' => $now,
                'name' => $document['client_name'],
                'redirect_uris' => $uris,
                'is_confidential' => 0,
            ],
            ['client_id' => $document['client_id']],
        );
    }

    private function log(string $message): void
    {
        $this->logger->info($message, ['contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL)]);
    }
}
