<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Extension\UrlRewrite;

use Terminal42\UrlRewriteBundle\RewriteConfigInterface;

/**
 * Maps MCP tool input onto a tl_url_rewrite row array (the table has no Model,
 * so we work with Doctrine DBAL associative arrays).
 *
 * - String/int/bool fields are coerced strictly.
 * - "requestHosts" is a serialised list<string> (listWizard).
 * - "requestRequirements" and "conditionalResponseUri" are serialised
 *   key-value-wizard structures: stored as
 *   [['key' => '…', 'value' => '…'], …] in the DB but exposed to the LLM as
 *   flat dict<string,string>.
 * - "responseCode" is validated against the bundle's allow-list.
 * - "inactive" is exposed positively as "active" for sanity, but stored as
 *   the inverted DCA flag.
 */
final class FieldMapper
{
    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $current Existing row (empty array on create).
     *
     * @return array{values: array<string, mixed>, changed: list<string>}
     *
     * @throws \InvalidArgumentException
     */
    public function apply(array $current, array $input): array
    {
        $values = [];
        $changed = [];

        $touch = static function (string $field, mixed $new, mixed $old) use (&$values, &$changed): void {
            if ($old === null || (string) $old !== (string) $new) {
                $values[$field] = $new;
                $changed[] = $field;
            } elseif (!\array_key_exists($field, $values)) {
                // For create: still write the value even if it matches the empty default.
                $values[$field] = $new;
            }
        };

        // --- simple strings ---
        foreach (['name', 'comment', 'requestPath'] as $field) {
            if (!\array_key_exists($field, $input) || $input[$field] === null) {
                continue;
            }
            $new = (string) $input[$field];
            $touch($field, $new, $current[$field] ?? null);
        }

        // --- nullable text columns (use empty string -> NULL semantics for cleanliness) ---
        foreach (['requestCondition', 'responseUri'] as $field) {
            if (!\array_key_exists($field, $input) || $input[$field] === null) {
                continue;
            }
            $new = (string) $input[$field];
            $touch($field, $new === '' ? null : $new, $current[$field] ?? null);
        }

        // --- ints ---
        if (\array_key_exists('priority', $input) && $input['priority'] !== null) {
            $touch('priority', (int) $input['priority'], $current['priority'] ?? null);
        }

        if (\array_key_exists('responseCode', $input) && $input['responseCode'] !== null) {
            $code = (int) $input['responseCode'];
            if (!\in_array($code, RewriteConfigInterface::VALID_RESPONSE_CODES, true)) {
                $valid = implode(', ', RewriteConfigInterface::VALID_RESPONSE_CODES);
                throw new \InvalidArgumentException("Invalid responseCode {$code}. Allowed: {$valid}.");
            }
            $touch('responseCode', $code, $current['responseCode'] ?? null);
        }

        // --- bools (DCA stores tinyint, MySQL strict mode rejects '' on int columns) ---
        // "active" (positive) -> "inactive" (DCA-style, inverted)
        if (\array_key_exists('active', $input) && $input['active'] !== null) {
            $touch('inactive', $input['active'] ? 0 : 1, $current['inactive'] ?? null);
        } elseif (\array_key_exists('inactive', $input) && $input['inactive'] !== null) {
            $touch('inactive', $input['inactive'] ? 1 : 0, $current['inactive'] ?? null);
        }

        if (\array_key_exists('keepQueryParams', $input) && $input['keepQueryParams'] !== null) {
            $touch('keepQueryParams', $input['keepQueryParams'] ? 1 : 0, $current['keepQueryParams'] ?? null);
        }

        // --- listWizard ---
        if (\array_key_exists('requestHosts', $input) && $input['requestHosts'] !== null) {
            $raw = $input['requestHosts'];
            if (!\is_array($raw)) {
                throw new \InvalidArgumentException("'requestHosts' must be an array of host strings.");
            }
            $hosts = [];
            foreach ($raw as $h) {
                $s = trim((string) $h);
                if ($s !== '') {
                    $hosts[] = $s;
                }
            }
            $hosts = array_values(array_unique($hosts));
            $new = $hosts === [] ? null : serialize($hosts);
            $touch('requestHosts', $new, $current['requestHosts'] ?? null);
        }

        // --- keyValueWizard fields ---
        // Accept either associative array (json_decode(..., true)) or stdClass
        // (json_decode(..., false)) — php-mcp lets `mixed` parameters through
        // verbatim, so we don't know upfront which one we got.
        foreach (['requestRequirements', 'conditionalResponseUri'] as $field) {
            if (!\array_key_exists($field, $input) || $input[$field] === null) {
                continue;
            }
            $raw = $input[$field];
            if ($raw instanceof \stdClass) {
                $raw = (array) $raw;
            }
            if (!\is_array($raw)) {
                throw new \InvalidArgumentException("'$field' must be a dict<string,string>.");
            }
            // A JSON list (numeric keys) is rejected — keyValueWizard is dict-shaped.
            if ($raw !== [] && array_is_list($raw)) {
                throw new \InvalidArgumentException("'$field' must be a JSON object (dict<string,string>), got a list.");
            }
            $pairs = [];
            foreach ($raw as $k => $v) {
                $key = (string) $k;
                if ($key === '') {
                    continue;
                }
                $pairs[] = ['key' => $key, 'value' => (string) $v];
            }
            $new = $pairs === [] ? null : serialize($pairs);
            $touch($field, $new, $current[$field] ?? null);
        }

        return ['values' => $values, 'changed' => $changed];
    }
}
