<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Server;

use Mcp\Exception\RegistryException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use Netzhirsch\ContaoMcpBundle\License\LicenseGate;
use Netzhirsch\ContaoMcpBundle\Security\McpPermissionEnforcer;
use Netzhirsch\ContaoMcpBundle\Security\OperatorPolicy;
use Netzhirsch\ContaoMcpBundle\Service\ArgumentGuard;
use Netzhirsch\ContaoMcpBundle\Service\DeletionGuard;
use Netzhirsch\ContaoMcpBundle\Service\UndoRecorder;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;

/**
 * Every direct `tools/call`, start to finish.
 *
 * Registered with the SDK builder ahead of its own CallToolHandler, which it
 * replaces — the SDK checks custom handlers first. Taking the whole call
 * rather than wrapping the SDK's keeps three things the way this server has
 * always answered them:
 *
 *   - The result is the tool's value as JSON text, nothing more. The SDK would
 *     also send every array result a second time as `structuredContent`, which
 *     doubles each answer on the wire and in the model's context.
 *   - An exception inside a tool never reaches the caller with its message.
 *     Those messages carry SQL fragments and paths; the caller gets a
 *     reference that is also in the log.
 *   - Validation errors read the way they always have, and a missing
 *     parameter or a misspelled one is named before anything runs.
 *
 * The order of the checks matters and is the same as in `contao_call`, which
 * reaches tools without coming through here: licence, permission, the
 * operator's create-unpublished rewrite, unknown or missing arguments, the
 * schema, the reference guard for deletions — and only then the undo
 * snapshot, so a call that is refused never leaves one behind.
 */
final class ToolCallHandler implements RequestHandlerInterface
{
    /**
     * How many times a tool is retried after a transient cold-DCA-cache
     * filesystem race (Windows only). See {@see isTransientCacheError()}.
     */
    private const DCA_CACHE_RACE_RETRIES = 3;

    private readonly ObjectAwareSchemaValidator $schemaValidator;

    public function __construct(
        private readonly RegistryAccessor $registryAccessor,
        private readonly LicenseGate $licenseGate,
        private readonly McpPermissionEnforcer $permissionEnforcer,
        private readonly OperatorPolicy $operatorPolicy,
        private readonly ArgumentGuard $argumentGuard,
        private readonly DeletionGuard $deletionGuard,
        private readonly UndoRecorder $undoRecorder,
        private readonly PostCallHook $postCallHook,
        private readonly LoggerInterface $logger,
    ) {
        $this->schemaValidator = new ObjectAwareSchemaValidator(logger: $logger);
    }

    public function supports(Request $request): bool
    {
        return $request instanceof CallToolRequest;
    }

    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        \assert($request instanceof CallToolRequest);

        return $this->withCleanup($request->name, function () use ($request, $session): Response|Error {
            // Without an active licence or trial every tool is refused — Contao
            // itself keeps running. `ping` stays allowed for health checks.
            if ($denial = $this->licenseGate->denialForTool($request->name)) {
                return self::refusal($request->getId(), $denial);
            }

            return $this->call($request, $session);
        });
    }

    /**
     * Everything {@see handle()} does after the licence gate.
     *
     * The smoke test drives this directly: it runs against installations that
     * have no licence, and what it checks — permissions, argument guard,
     * validation, undo — sits behind the gate. The licence itself has its own
     * tests.
     */
    public function dispatch(CallToolRequest $request, SessionInterface $session): Response|Error
    {
        return $this->withCleanup($request->name, fn (): Response|Error => $this->call($request, $session));
    }

    /**
     * @param \Closure(): (Response|Error) $call
     */
    private function withCleanup(string $toolName, \Closure $call): Response|Error
    {
        try {
            return $call();
        } finally {
            // Clears Contao's Model registry and the per-call identity, even
            // when the tool threw. A failing hook must not mask the answer.
            try {
                $this->postCallHook->afterToolCall($toolName);
            } catch (\Throwable $e) {
                $this->logger->warning('Post-call hook failed.', ['tool' => $toolName, 'exception' => $e]);
            }
        }
    }

    private function call(CallToolRequest $request, SessionInterface $session): Response|Error
    {
        $id = $request->getId();
        $name = $request->name;
        $arguments = $request->arguments;

        // Backend-permission parity, and the operator switches before that: a
        // non-admin may only run a tool whose backend operation they may do.
        if ($denial = $this->permissionEnforcer->check($name, $arguments)) {
            return self::refusal($id, $denial);
        }

        $reference = $this->registryAccessor->find($name);
        if ($reference === null) {
            return Error::forInvalidParams(sprintf("Tool '%s' not found.", $name), $id);
        }

        // New content starts unpublished when the operator says so; the tool
        // must see the flag the caller left out.
        $arguments = $this->operatorPolicy->rewrite($name, $arguments);

        if ($invalid = $this->argumentGuard->check($name, $arguments)) {
            return self::refusal($id, $invalid);
        }

        $errors = $this->schemaValidator->validateAgainstJsonSchema($arguments, $reference->tool->inputSchema);
        if ($errors !== []) {
            return Error::forInvalidParams(self::summarise($name, $errors), $id, ['validation_errors' => $errors]);
        }

        // Refuse a deletion while something still points at the record —
        // before the snapshot, because a blocked delete removes nothing.
        if ($inUse = $this->deletionGuard->check($name, $arguments)) {
            return self::refusal($id, $inUse);
        }

        // Deletions are snapshotted into tl_undo BEFORE they happen, so a
        // human can recover them through Contao's own backend undo.
        $undoId = $this->undoRecorder->beforeToolCall($name, $arguments);

        try {
            $value = $this->invoke($reference, $arguments, $session);
        } catch (RegistryException $e) {
            $this->undoRecorder->discard($undoId);

            // A value the handler could not cast to the parameter's type. The
            // message is about the call, not about the server.
            if ($e->getCode() === Error::INVALID_PARAMS) {
                return self::refusal($id, ['error' => 'invalid_input', 'tool' => $name, 'message' => $e->getMessage()]);
            }

            return self::refusal($id, $this->opaqueFailure($name, $e));
        } catch (\Throwable $e) {
            $this->undoRecorder->discard($undoId);

            return self::refusal($id, $this->opaqueFailure($name, $e));
        }

        $result = $value instanceof CallToolResult
            ? $value
            : new CallToolResult($reference->formatResult($this->operatorPolicy->redact($value)));

        // The tool refused (not found, cascade guard, …) — the rows are still
        // there, so the snapshot would be a lie.
        if ($result->isError || (\is_array($value) && isset($value['error']))) {
            $this->undoRecorder->discard($undoId);
        }

        return new Response($id, $result);
    }

    /**
     * Runs the tool, retrying the Windows-only cold-DCA-cache race: when several
     * requests hit a COLD DCA cache at once, Contao regenerates
     * var/cache/<env>/contao/dca/*.php and the atomic rename of the temp file
     * fails ("Access is denied") while a sibling process still holds the target
     * open. loadDataContainer runs before any DB write, so running the tool
     * again is safe — the losing process just waits for the winner to finish.
     * Linux/prod warm the cache at deploy time and never get here.
     *
     * @param array<string, mixed> $arguments
     */
    private function invoke(\Mcp\Capability\Registry\ToolReference $reference, array $arguments, SessionInterface $session): mixed
    {
        for ($attempt = 1; ; ++$attempt) {
            try {
                return $this->registryAccessor->invoke($reference, $arguments, $session);
            } catch (\Throwable $e) {
                if ($attempt >= self::DCA_CACHE_RACE_RETRIES || !self::isTransientCacheError($e)) {
                    throw $e;
                }

                usleep(150_000 * $attempt); // 150ms, then 300ms backoff
            }
        }
    }

    /**
     * Log the real exception and hand the caller a message that says what to do
     * without saying what broke. Same answer `contao_call` gives.
     *
     * @return array{error: string, tool: string, message: string}
     */
    private function opaqueFailure(string $name, \Throwable $e): array
    {
        $reference = bin2hex(random_bytes(4));

        $this->logger->error(sprintf('MCP tool "%s" failed.', $name), ['exception' => $e, 'reference' => $reference]);

        return [
            'error' => 'tool_failed',
            'tool' => $name,
            'message' => sprintf('Internal error while running "%s" (reference %s). See the Contao application log.', $name, $reference),
        ];
    }

    /**
     * True when a thrown error looks like the Windows cold-DCA-cache rename
     * race. Walks the exception chain so a wrapped IOException is still
     * recognised, and falls back to a message signature for cases where
     * Contao re-throws a plain \RuntimeException.
     */
    private static function isTransientCacheError(\Throwable $e): bool
    {
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            if ($cur instanceof IOExceptionInterface) {
                return true;
            }

            $msg = $cur->getMessage();
            $isRenameFail = stripos($msg, 'rename') !== false
                || stripos($msg, 'access is denied') !== false
                || stripos($msg, 'zugriff verweigert') !== false
                || stripos($msg, 'permission denied') !== false;

            if ($isRenameFail && (stripos($msg, 'cache') !== false || stripos($msg, 'dca') !== false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A refusal is a tool result with isError set, carrying the structured
     * reason as JSON — the shape the tools use for their own errors, so the
     * model reads both the same way.
     *
     * @param array<string, mixed> $reason
     */
    private static function refusal(string|int $id, array $reason): Response
    {
        return new Response($id, new CallToolResult([new TextContent((string) json_encode($reason))], true));
    }

    /**
     * @param list<array{pointer: string, keyword: string, message: string}> $errors
     */
    private static function summarise(string $name, array $errors): string
    {
        $messages = [];
        foreach ($errors as $error) {
            $pointer = $error['pointer'] ?? '';
            $messages[] = ($pointer !== '/' && $pointer !== '' ? "Property '{$pointer}': " : '').($error['message'] ?? 'Unknown validation error');
        }

        $summary = sprintf("Invalid parameters for tool '%s': %s", $name, implode('; ', \array_slice($messages, 0, 3)));

        return \count($messages) > 3 ? $summary.'; ...and more errors.' : $summary;
    }
}
