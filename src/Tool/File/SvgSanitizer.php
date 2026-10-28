<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\File;

use enshrined\svgSanitize\Sanitizer;

/**
 * Cleans an uploaded SVG the way Contao's own file manager does — and then
 * checks that what is left is still an SVG.
 *
 * Until now this bundle refused any SVG that looked active, with a pattern
 * check of its own. Measured against real files that was wrong in both
 * directions it can be wrong in:
 *
 *   - It refused ordinary logos. An Illustrator "Save as SVG" export declares
 *     its namespaces as internal entities (`<!ENTITY ns_ai "http://…">`), which
 *     the check read as XXE. And any SVG with a DOCTYPE was refused as soon as
 *     the word "entity" appeared anywhere in it — `<title>Corporate
 *     Identity</title>` was enough.
 *   - It was a pattern list, which is the thing the docblock on it warned
 *     about: a sanitiser that is 95% right.
 *
 * Contao has used enshrined/svg-sanitize for backend uploads since 5.3.38. That
 * is a maintained library, and it is what an SVG uploaded through the backend
 * goes through — so an SVG uploaded through MCP now ends up exactly as safe as
 * one a person uploaded, which is the bar the rest of this bundle holds itself
 * to.
 *
 * One thing the backend gets wrong, and this does not: the library drops the
 * DOCTYPE, and with it the entity declarations — but not the references to
 * them. An Illustrator file comes out as `xmlns:i="&ns_ai;"` with `ns_ai`
 * declared nowhere, which is not well-formed XML, and a browser shows nothing.
 * So the plain internal entities are resolved first ({@see resolvePlainEntities()}),
 * and the result is parsed once more before it is accepted. A file that is
 * still broken after that is refused with a reason instead of being stored as
 * an empty image.
 *
 * On a Contao that predates the library (5.3.0 – 5.3.37) the old refusal runs,
 * with its false positive fixed.
 */
final class SvgSanitizer
{
    /**
     * @return array{ok: true, bytes: string, removed: list<string>}|array{ok: false, error: string, message: string}
     */
    public function sanitize(string $bytes, bool $gzipped): array
    {
        if ($gzipped) {
            $inflated = @gzdecode($bytes);

            if (!\is_string($inflated) || $inflated === '') {
                return ['ok' => false, 'error' => 'invalid_svg', 'message' => 'This .svgz could not be decompressed.'];
            }

            $bytes = $inflated;
        }

        $resolved = self::resolvePlainEntities($bytes);

        if ($resolved === null) {
            return [
                'ok' => false,
                'error' => 'invalid_svg',
                'message' => 'This SVG declares entities that cannot be resolved safely (external, parameter or nested entities). Export it without a DOCTYPE — in Illustrator: Export As → SVG instead of Save As — or upload it through the Contao backend.',
            ];
        }

        if (class_exists(Sanitizer::class)) {
            $result = $this->sanitiseWithLibrary($resolved);
        } else {
            $active = UploadValidator::activeMarkupIn($resolved);

            $result = $active === null
                ? ['ok' => true, 'bytes' => $resolved, 'removed' => []]
                : [
                    'ok' => false,
                    'error' => 'active_content_refused',
                    'message' => sprintf('This svg contains %s. Served from files/ it would run on the site\'s own origin, so it is refused here. Remove it, or upload the file through the Contao backend.', $active),
                ];
        }

        if ($result['ok'] && $gzipped) {
            $result['bytes'] = (string) gzencode($result['bytes']);
        }

        return $result;
    }

    /**
     * Replaces references to internal entities whose value is plain text, and
     * removes the internal subset that declared them.
     *
     * "Plain" is narrow on purpose: a general entity (not `%`), no SYSTEM or
     * PUBLIC identifier, and a value with no `&`, `%` or `<` in it. That rules
     * out the three things entities are dangerous for — reading a file (XXE),
     * fetching a URL, and expanding into itself (billion laughs) — and it is
     * still exactly what the Illustrator namespace declarations are.
     *
     * @return string|null the document with plain entities substituted (unchanged
     *                     when it has no internal subset), or null when it
     *                     declares anything that is not plain
     */
    public static function resolvePlainEntities(string $xml): ?string
    {
        if (preg_match('/<!DOCTYPE\s[^\[>]*\[(.*?)\]\s*>/is', $xml, $doctype, PREG_OFFSET_CAPTURE) !== 1) {
            return $xml;
        }

        $subset = $doctype[1][0];

        // Every declaration in the subset must be a plain entity; anything else
        // (ELEMENT/ATTLIST are harmless but pointless here, so are refused too —
        // a document that needs them is not one we can vouch for).
        $declarations = preg_match_all('/<!(\w+)/', $subset);
        $plain = preg_match_all('/<!ENTITY\s+([A-Za-z_][\w.-]*)\s+(["\'])([^&%<"\']*)\2\s*>/', $subset, $entities, PREG_SET_ORDER);

        if ($declarations !== $plain) {
            return null;
        }

        $values = [];
        foreach ($entities as [, $name, , $value]) {
            $values['&'.$name.';'] = htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
        }

        $before = substr($xml, 0, $doctype[0][1]);
        $after = substr($xml, $doctype[0][1] + \strlen($doctype[0][0]));

        return $before.strtr($after, $values);
    }

    /**
     * @return array{ok: true, bytes: string, removed: list<string>}|array{ok: false, error: string, message: string}
     */
    private function sanitiseWithLibrary(string $svg): array
    {
        $sanitizer = new Sanitizer();
        $clean = $sanitizer->sanitize($svg);

        $issues = array_values(array_filter(array_map(
            static fn ($issue): string => \is_array($issue) ? (string) ($issue['message'] ?? '') : '',
            (array) $sanitizer->getXmlIssues(),
        )));

        if (!\is_string($clean) || $clean === '') {
            return [
                'ok' => false,
                'error' => 'invalid_svg',
                'message' => 'This is not a readable SVG'.($issues !== [] ? ': '.$issues[0] : '.'),
            ];
        }

        $broken = self::wellFormednessError($clean);

        if ($broken !== null) {
            return [
                'ok' => false,
                'error' => 'invalid_svg',
                'message' => sprintf('After removing what is not allowed, this SVG is no longer valid XML (%s), and a browser would show nothing. Export it again without a DOCTYPE — in Illustrator: Export As → SVG.', $broken),
            ];
        }

        // The library reports the DOCTYPE it drops as a "suspicious node"; that
        // is housekeeping, not something the caller has to know about.
        $removed = array_values(array_filter(
            $issues,
            static fn (string $issue): bool => !str_starts_with($issue, 'Suspicious node'),
        ));

        return ['ok' => true, 'bytes' => $clean, 'removed' => $removed];
    }

    private static function wellFormednessError(string $xml): ?string
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $document = new \DOMDocument();
            $loaded = $document->loadXML($xml, LIBXML_NONET);
            $errors = libxml_get_errors();

            if ($loaded && $errors === []) {
                return null;
            }

            return $errors !== [] ? trim($errors[0]->message) : 'unparsable';
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
