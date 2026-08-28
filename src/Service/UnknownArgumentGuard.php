<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Service;

use Netzhirsch\ContaoMcpBundle\Server\RegistryAccessor;

/**
 * Refuses a tool call that carries a parameter the tool does not have.
 *
 * Until now such a parameter was dropped without a word. Two layers had to
 * miss it for that to happen, and both did:
 *
 *   1. php-mcp's SchemaGenerator never emits `additionalProperties`, and in
 *      JSON Schema its absence means "anything else is allowed" — so the
 *      dispatcher's validation pass waves the unknown key through.
 *   2. `RegisteredElement::prepareArguments()` then walks the METHOD's
 *      parameters and picks matching entries out of the arguments. Anything
 *      with no matching parameter is never looked at.
 *
 * The result was a call that reported success while changing nothing:
 * `page_update(id: 7, pageTitel: "…")` — one transposed letter — returned the
 * page summary and `applied: 0`. Only a caller who checks `applied` notices,
 * and a caller who trusted the absent error had no reason to.
 *
 * Tools taking a `fields` object never had this problem: they validate names
 * against the DCA and say so ("Field \"x\" is not valid for content type
 * \"text\""). This guard gives the ~80-parameter tools the same manners.
 *
 * It runs in the same two places the permission check and the undo snapshot
 * run — {@see \Netzhirsch\ContaoMcpBundle\Controller\McpController} for direct
 * `tools/call`, and the lazy-mode `contao_call` proxy, which invokes tools
 * itself and therefore skips the dispatcher's validation entirely. Lazy-mode
 * instances route EVERY call through that proxy, so leaving it out would have
 * left the gap open exactly where it is widest.
 *
 * Deliberately only the TOP level. A `fields` object legitimately carries
 * arbitrary DCA column names, and the tool that owns it validates them far
 * better than a schema could.
 */
final class UnknownArgumentGuard
{
    /** How many allowed names the human-readable message lists before it stops. */
    private const MAX_LISTED = 30;

    public function __construct(private readonly RegistryAccessor $registryAccessor)
    {
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>|null structured error, or null when the call is clean
     */
    public function check(string $toolName, array $arguments): ?array
    {
        if ($toolName === '' || $arguments === []) {
            return null;
        }

        $tool = $this->registryAccessor->get()->getTool($toolName);
        if ($tool === null) {
            // Unknown tool — the dispatcher's own "not found" is the better
            // answer, and it comes right after this.
            return null;
        }

        // `Tool::inputSchema` is declared as
        // `array{type: 'object', properties: array<string, mixed>, ...}`, so
        // `properties` is always there - for a tool that takes no arguments
        // it is simply empty, and then every argument is an unknown one.
        $allowed = array_map(strval(...), array_keys($tool->schema->inputSchema['properties']));

        $unknown = [];
        foreach (array_keys($arguments) as $key) {
            if (!\in_array((string) $key, $allowed, true)) {
                $unknown[] = (string) $key;
            }
        }

        if ($unknown === []) {
            return null;
        }

        return [
            'error' => 'invalid_input',
            'tool' => $toolName,
            'unknown_parameters' => $unknown,
            'allowed_parameters' => $allowed,
            'message' => self::message($toolName, $unknown, $allowed),
        ];
    }

    /**
     * @param list<string> $unknown
     * @param list<string> $allowed
     */
    private static function message(string $toolName, array $unknown, array $allowed): string
    {
        $parts = [];
        foreach ($unknown as $name) {
            $suggestion = self::closest($name, $allowed);
            $parts[] = $suggestion === null
                ? sprintf('"%s"', $name)
                : sprintf('"%s" (did you mean "%s"?)', $name, $suggestion);
        }

        $listed = \array_slice($allowed, 0, self::MAX_LISTED);
        $tail = \count($allowed) > self::MAX_LISTED
            ? sprintf(', … and %d more — see the tool schema', \count($allowed) - self::MAX_LISTED)
            : '';

        return sprintf(
            'Tool "%s" has no parameter %s. Nothing was changed. Allowed parameters: %s%s',
            $toolName,
            implode(', ', $parts),
            implode(', ', $listed),
            $tail,
        );
    }

    /**
     * Nearest allowed name, if it is near enough to be worth suggesting.
     * The threshold grows with the name's length so that a single typo in
     * `canonicalKeepParams` is still caught without `id` matching everything.
     *
     * @param list<string> $allowed
     */
    private static function closest(string $name, array $allowed): ?string
    {
        $limit = max(2, (int) floor(mb_strlen($name) / 3));
        $best = null;
        $bestDistance = \PHP_INT_MAX;

        foreach ($allowed as $candidate) {
            $distance = levenshtein(mb_strtolower($name), mb_strtolower($candidate));
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $candidate;
            }
        }

        return $bestDistance <= $limit ? $best : null;
    }
}
