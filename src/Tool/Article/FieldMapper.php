<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Article;

use Contao\ArticleModel;
use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Netzhirsch\ContaoMcpBundle\Service\DcaPalette;
use Netzhirsch\ContaoMcpBundle\Service\DcaScalarWriter;
use Netzhirsch\ContaoMcpBundle\Service\FieldProviderRegistry;

/**
 * Maps MCP tool input onto an ArticleModel. tl_article has no DCA "type" concept,
 * so the same field whitelist applies to every record. Strict validation rejects
 * unknown fields with a "field not valid for tl_article" message and provider-claimed
 * fields with "extension_not_available" when the provider's bundle isn't installed.
 */
final class FieldMapper
{
    public function __construct(
        private readonly FieldProviderRegistry $providers,
        private readonly ContaoFramework $framework,
    ) {
    }

    /**
     * @var list<string>
     */
    private const STRING_FIELDS = [
        'title', 'alias', 'inColumn', 'teaserCssID', 'teaser', 'customTpl',
    ];

    /**
     * @var list<string>
     */
    private const BOOL_FIELDS = [
        'showTeaser', 'protected', 'guests', 'published',
    ];

    /**
     * @param array<string, mixed> $input
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException
     */
    public function apply(ArticleModel $article, array $input, bool $detectChanges = true): array
    {
        $changed = [];

        // ─── Validate every input key ───
        $this->framework->initialize();
        $this->framework->getAdapter(Controller::class)->loadDataContainer('tl_article');

        $known = $this->knownFields();
        $providers = $this->providers->forTable('tl_article');

        foreach (array_keys($input) as $field) {
            if (\in_array($field, $known, true)) {
                continue;
            }
            $claimedBy = null;
            foreach ($providers as $p) {
                if (\in_array($field, $p->getDeclaredFields(), true)) {
                    $claimedBy = $p;
                    break;
                }
            }
            if ($claimedBy === null) {
                throw new \InvalidArgumentException(sprintf(
                    'Field "%s" is not a known tl_article field. Known: %s.',
                    $field,
                    implode(', ', $known),
                ));
            }
            if (!$claimedBy->isAvailable()) {
                throw new \InvalidArgumentException(sprintf(
                    'Field "%s" requires the %s extension, which is not installed in this Contao project.',
                    $field,
                    $claimedBy->getRequiredExtension(),
                ));
            }
        }

        $touch = static function (string $field) use (&$changed): void {
            if (!\in_array($field, $changed, true)) {
                $changed[] = $field;
            }
        };

        // ─── String/bool/int simple fields ───
        foreach (self::STRING_FIELDS as $field) {
            if (!\array_key_exists($field, $input) || $input[$field] === null) {
                continue;
            }
            $new = (string) $input[$field];
            if (!$detectChanges || (string) $article->$field !== $new) {
                $article->$field = $new;
                $touch($field);
            }
        }

        foreach (self::BOOL_FIELDS as $field) {
            if (!\array_key_exists($field, $input) || $input[$field] === null) {
                continue;
            }
            $new = $input[$field] ? 1 : 0;
            if (!$detectChanges || (int) $article->$field !== $new) {
                $article->$field = $new;
                $touch($field);
            }
        }

        // ─── pid alias (page_id) ───
        if (\array_key_exists('page_id', $input) && $input['page_id'] !== null) {
            $new = (int) $input['page_id'];
            if (!$detectChanges || (int) $article->pid !== $new) {
                $article->pid = $new;
                $touch('pid');
            }
        }

        // ─── sorting / author_id ───
        if (\array_key_exists('sorting', $input) && $input['sorting'] !== null) {
            $new = (int) $input['sorting'];
            if (!$detectChanges || (int) $article->sorting !== $new) {
                $article->sorting = $new;
                $touch('sorting');
            }
        }

        if (\array_key_exists('author_id', $input) && $input['author_id'] !== null) {
            $new = (int) $input['author_id'];
            if (!$detectChanges || (int) $article->author !== $new) {
                $article->author = $new;
                $touch('author');
            }
        }

        // ─── printable (serialized list<string>) ───
        if (\array_key_exists('printable', $input) && $input['printable'] !== null) {
            $raw = $input['printable'];
            if (!\is_array($raw)) {
                throw new \InvalidArgumentException('"printable" must be a list of strings (any of: print, pdf, facebook, twitter).');
            }
            $cleaned = array_values(array_map('strval', $raw));
            $new = $cleaned === [] ? '' : serialize($cleaned);
            if (!$detectChanges || (string) $article->printable !== $new) {
                $article->printable = $new;
                $touch('printable');
            }
        }

        // ─── groups (serialized list<int>) ───
        if (\array_key_exists('groups', $input) && $input['groups'] !== null) {
            $raw = $input['groups'];
            if (!\is_array($raw)) {
                throw new \InvalidArgumentException('"groups" must be an array of tl_member_group.id integers.');
            }
            $cleaned = array_values(array_map('intval', $raw));
            $new = $cleaned === [] ? '' : serialize($cleaned);
            if (!$detectChanges || (string) $article->groups !== $new) {
                $article->groups = $new;
                $touch('groups');
            }
        }

        // ─── cssID (serialized [id, class]) ───
        if (\array_key_exists('cssID', $input) && $input['cssID'] !== null) {
            $tuple = self::normaliseStringPair($input['cssID'], 'cssID', 'id', 'class');
            $new = $tuple === ['', ''] ? '' : serialize($tuple);
            if (!$detectChanges || (string) $article->cssID !== $new) {
                $article->cssID = $new;
                $touch('cssID');
            }
        }

        // ─── space (serialized [top, bottom]) ───
        if (\array_key_exists('space', $input) && $input['space'] !== null) {
            $tuple = self::normaliseStringPair($input['space'], 'space', 'top', 'bottom');
            $new = $tuple === ['', ''] ? '' : serialize($tuple);
            if (!$detectChanges || (string) $article->space !== $new) {
                $article->space = $new;
                $touch('space');
            }
        }

        // ─── start / stop (varchar(10) unix ts or '') ───
        foreach (['start', 'stop'] as $field) {
            if (!\array_key_exists($field, $input) || $input[$field] === null) {
                continue;
            }
            $raw = (string) $input[$field];
            $new = $raw === '' ? '' : (string) self::parseDateTime($raw);
            if (!$detectChanges || (string) $article->$field !== $new) {
                $article->$field = $new;
                $touch($field);
            }
        }

        // ─── Provider apply ───
        $providerFields = [];
        foreach ($providers as $provider) {
            foreach ($provider->getDeclaredFields() as $declared) {
                $providerFields[] = $declared;
            }
            if (!$provider->isAvailable()) {
                continue;
            }
            foreach ($provider->apply($article, $input, $detectChanges) as $field) {
                $touch($field);
            }
        }

        // Everything the groups above do not know about — see the same loop
        // in Tool\Page\FieldMapper. Refuses rather than guesses at an
        // encoding it cannot know.
        foreach ($input as $field => $value) {
            if ($value === null || \in_array($field, self::HANDLED_BY_GROUPS, true)) {
                continue;
            }

            if (\in_array($field, $providerFields, true) || \in_array($field, $changed, true)) {
                continue;
            }

            if (DcaScalarWriter::write('tl_article', $article, $field, $value, $detectChanges)) {
                $touch($field);
            }
        }

        return $changed;
    }

    /**
     * @return list<string>
     */
    private function knownFields(): array
    {
        // …plus the live DCA palette, so a field another bundle hangs on
        // tl_article is accepted the day that bundle is installed rather
        // than after a release here. Empty when the DCA is not loaded, and
        // the curated lists still carry it then.
        $dca = $GLOBALS['TL_DCA']['tl_article'] ?? null;
        $fromDca = \is_array($dca) ? DcaPalette::resolve($dca, 'default')['fields'] : [];

        return array_values(array_unique(array_merge(
            self::HANDLED_BY_GROUPS,
            $fromDca,
        )));
    }

    /**
     * Every field one of the hand-written loops above already writes. The
     * generic writer must not touch these.
     *
     * @var list<string>
     */
    private const HANDLED_BY_GROUPS = [
        'title', 'alias', 'inColumn', 'teaserCssID', 'teaser', 'customTpl',
        'showTeaser', 'protected', 'guests', 'published',
        'page_id', 'sorting', 'author_id', 'printable', 'groups', 'cssID', 'space', 'start', 'stop',
    ];

    /**
     * Normalises an object/dict like {id: "...", class: "..."} or {top, bottom} into
     * a pair of strings, validating the only allowed keys.
     *
     * @return array{0: string, 1: string}
     */
    private static function normaliseStringPair(mixed $raw, string $field, string $keyA, string $keyB): array
    {
        if (\is_object($raw)) {
            $raw = (array) $raw;
        }
        if (!\is_array($raw)) {
            throw new \InvalidArgumentException(sprintf(
                '"%s" must be an object with keys "%s" and "%s".',
                $field,
                $keyA,
                $keyB,
            ));
        }
        foreach (array_keys($raw) as $k) {
            if (!\in_array($k, [$keyA, $keyB], true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Unknown key "%s" in "%s". Allowed: %s, %s.',
                    (string) $k,
                    $field,
                    $keyA,
                    $keyB,
                ));
            }
        }

        return [(string) ($raw[$keyA] ?? ''), (string) ($raw[$keyB] ?? '')];
    }

    /**
     * @throws \InvalidArgumentException
     */
    private static function parseDateTime(string $iso): int
    {
        $ts = strtotime($iso);
        if ($ts === false) {
            throw new \InvalidArgumentException(sprintf('Invalid date/datetime "%s". Use ISO 8601.', $iso));
        }

        return $ts;
    }
}
