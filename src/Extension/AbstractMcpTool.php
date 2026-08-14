<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Extension;

use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\Security\McpPermissionGuard;
use Netzhirsch\ContaoMcpBundle\Service\AuthorResolver;
use Netzhirsch\ContaoMcpBundle\Service\DbalRetry;
use Netzhirsch\ContaoMcpBundle\Service\McpCallContext;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Recommended base class for third-party MCP tools.
 *
 * Extend this, add `#[McpTool]`-annotated public methods, register the class
 * as an autowired service, and (once the operator enables it via
 * `extension_tools_enabled`) your tools join the MCP server alongside the
 * core ones — same dispatch path, same OAuth gate, same rate limit.
 *
 * What you get for free:
 *   - {@see callContext()}   — who is calling (user id, OAuth client).
 *   - {@see authorResolver()} — resolves the tl_user id + log username/source
 *     for writes, so your changes land in tl_log + tl_version under the right
 *     identity instead of as anonymous DB mutations.
 *   - {@see withDeadlockRetry()} — wraps a transaction in DbalRetry so two
 *     concurrent operators don't surface a raw MySQL deadlock to the LLM.
 *   - {@see requireConfirmation()} — the standard `confirm_destructive` gate
 *     so a hallucinated delete can't fire without an explicit flag.
 *   - {@see permissionGuard()} — Contao-parity helpers to filter your list
 *     results / authorise a record for the calling backend user, so your tool
 *     never surfaces or mutates data the caller couldn't reach in the backend.
 *
 * Dependencies are injected via a single `#[Required]` setter, NOT the
 * constructor — so your subclass is free to declare its own constructor for
 * its own collaborators without having to thread ours through. This requires
 * your service to be `autowire: true` (the bundle default).
 *
 * Author contract (enforced by review + the operator allowlist, not by the
 * compiler — see EXTENDING.md):
 *   1. Validate every argument. MCP input is JSON-decoded `mixed`; never
 *      pass it unchecked into SQL, filesystem paths, or HTTP requests.
 *   2. Attribute writes via {@see authorResolver()} so the audit trail holds.
 *   3. Gate destructive operations with {@see requireConfirmation()}.
 *   4. Namespace your tool names with a vendor prefix (e.g. `acme_invoice_get`)
 *      so you never collide with a current or future core tool.
 *   5. Mirror backend rights. Declare each tool's permission requirement via
 *      {@see \Netzhirsch\ContaoMcpBundle\Extension\McpToolPermissionProviderInterface}
 *      (else your tool is admin-only) AND filter list results / authorise
 *      records with {@see permissionGuard()} — see EXTENDING.md.
 */
abstract class AbstractMcpTool implements McpToolProviderInterface
{
    private ?McpCallContext $mcpCallContext = null;
    private ?AuthorResolver $mcpAuthorResolver = null;
    private ?DbalRetry $mcpDbalRetry = null;
    private ?McpPermissionGuard $mcpPermissionGuard = null;

    /**
     * Container-invoked dependency injection. Do NOT call manually and do NOT
     * override without calling parent — the MCP server relies on these being
     * set before your tool methods run.
     *
     * @internal
     */
    #[Required]
    public function setMcpToolServices(
        McpCallContext $mcpCallContext,
        AuthorResolver $mcpAuthorResolver,
        DbalRetry $mcpDbalRetry,
        McpPermissionGuard $mcpPermissionGuard,
    ): void {
        $this->mcpCallContext = $mcpCallContext;
        $this->mcpAuthorResolver = $mcpAuthorResolver;
        $this->mcpDbalRetry = $mcpDbalRetry;
        $this->mcpPermissionGuard = $mcpPermissionGuard;
    }

    /**
     * Identity of the current MCP caller (OAuth user id + client). Empty when
     * `auth_mode=none`. Read-only — never mutate the context from a tool.
     */
    protected function callContext(): McpCallContext
    {
        return $this->mcpCallContext ?? throw $this->notWired();
    }

    /**
     * Resolves the tl_user id + log username/source for attributing writes.
     * Use it when calling Contao's Versions / writing tl_log so your changes
     * are traceable to the MCP caller instead of appearing anonymous.
     */
    protected function authorResolver(): AuthorResolver
    {
        return $this->mcpAuthorResolver ?? throw $this->notWired();
    }

    /**
     * The tl_user id to attribute writes to (OAuth user, or the configured
     * default author when unauthenticated). Convenience wrapper over
     * {@see authorResolver()}.
     */
    protected function resolveAuthorId(): int
    {
        return $this->authorResolver()->resolve();
    }

    /**
     * Contao-parity helpers for backend rights, so your tool mirrors what the
     * calling backend user may do — same enforcement the core tools use:
     *
     *   - filterReadable(table, rows) / mayRead(table, row) — drop rows the
     *     caller can't READ (uses Contao's ReadAction voter; no-op for admins,
     *     trusted mode, and tables without a record-level read voter).
     *   - accessiblePageIds() — the tl_page ids inside the caller's pagemounts
     *     (recursive); null = unrestricted. Scope page/article lists with this.
     *   - mayAccessRecord(table, row) — combined "may see/edit this record"
     *     check (pagemounts for tl_page/tl_article, voter otherwise).
     *   - ensureCan(table, op, id, fields) — full per-operation parity check,
     *     returns an error array or null. Usually unnecessary: declaring the
     *     tool's requirement (see McpToolPermissionProviderInterface) already
     *     gates the CALL; use this only for extra, in-body authorisation.
     *
     * Declaring your tool's requirement gates whether the call runs at all;
     * these helpers stop a permitted call from LEAKING or mutating rows the
     * caller couldn't reach in the backend. Use both.
     */
    protected function permissionGuard(): McpPermissionGuard
    {
        return $this->mcpPermissionGuard ?? throw $this->notWired();
    }

    /**
     * Run a DB transaction with deadlock/lock-wait retry. Prefer this over a
     * raw `$connection->transactional(...)` for any multi-row write — it
     * absorbs the transient MySQL deadlocks two concurrent operators can
     * trigger, instead of bubbling a hard SQL error up to the LLM.
     *
     * @template T
     *
     * @param callable(Connection): T $op
     *
     * @return T
     */
    protected function withDeadlockRetry(Connection $connection, callable $op): mixed
    {
        return ($this->mcpDbalRetry ?? throw $this->notWired())->transactional($connection, $op);
    }

    /**
     * Standard destructive-action gate. Call at the top of any tool that
     * deletes or irreversibly mutates data:
     *
     *     public function wipe(int $id, bool $confirm_destructive = false): array
     *     {
     *         if ($err = $this->requireConfirmation($confirm_destructive)) {
     *             return $err;
     *         }
     *         // ... proceed
     *     }
     *
     * Returns the error array to hand straight back to the caller when the
     * flag is missing, or null when it's safe to proceed. Mirrors the
     * `confirm_destructive` convention used by every core delete tool.
     *
     * @return array{error: string, message: string}|null
     */
    protected function requireConfirmation(bool $confirmDestructive): ?array
    {
        if ($confirmDestructive) {
            return null;
        }

        return [
            'error' => 'destructive_confirmation_required',
            'message' => 'This action is destructive and was not performed. Pass confirm_destructive=true to proceed.',
        ];
    }

    private function notWired(): \LogicException
    {
        return new \LogicException(sprintf(
            '%s was instantiated without MCP services. Ensure it is registered as an autowired service so #[Required] setMcpToolServices() runs.',
            static::class,
        ));
    }
}
