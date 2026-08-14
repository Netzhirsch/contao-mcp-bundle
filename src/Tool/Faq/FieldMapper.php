<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Faq;

use Contao\FaqModel;
use Netzhirsch\ContaoMcpBundle\Service\FieldProviderRegistry;

/**
 * Maps MCP input onto a FaqModel (tl_faq). FAQ entries are a News-light variant:
 *   - No featured, start/stop, protected/groups (those live at category level).
 *   - Primary content lives in `answer` (HTML), label in `question`.
 *   - Inherits image / enclosure / SEO sub-palettes from the common pattern.
 *
 * languageMain is contributed by terminal42/contao-changelanguage when installed.
 */
final class FieldMapper
{
    public function __construct(
        private readonly FieldProviderRegistry $providers,
    ) {
    }

    /**
     * @var list<string>
     */
    private const STRING_FIELDS = [
        'question', 'alias', 'answer', 'pageTitle', 'robots', 'description',
        'alt', 'imageTitle', 'imageUrl', 'size', 'caption', 'floating', 'searchIndexer',
    ];

    /**
     * @var list<string>
     */
    private const BOOL_FIELDS = [
        'addImage', 'overwriteMeta', 'fullsize', 'addEnclosure', 'noComments', 'published',
    ];

    /**
     * @param array<string, mixed> $input
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException
     */
    public function apply(FaqModel $faq, array $input, bool $detectChanges = true): array
    {
        $changed = [];
        $touch = static function (string $field) use (&$changed): void {
            if (!\in_array($field, $changed, true)) {
                $changed[] = $field;
            }
        };

        // ─── Validate every input key ───
        $known = self::knownFields();
        $providers = $this->providers->forTable('tl_faq');

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
                    'Field "%s" is not a known tl_faq field. Known: %s.',
                    $field,
                    implode(', ', $known),
                ));
            }
            if (!$claimedBy->isAvailable()) {
                throw new \InvalidArgumentException(sprintf(
                    'Field "%s" requires the %s extension, which is not installed.',
                    $field,
                    $claimedBy->getRequiredExtension(),
                ));
            }
        }

        foreach (self::STRING_FIELDS as $field) {
            if (!\array_key_exists($field, $input) || $input[$field] === null) {
                continue;
            }
            $new = (string) $input[$field];
            if (!$detectChanges || (string) $faq->$field !== $new) {
                $faq->$field = $new;
                $touch($field);
            }
        }

        foreach (self::BOOL_FIELDS as $field) {
            if (!\array_key_exists($field, $input) || $input[$field] === null) {
                continue;
            }
            $new = $input[$field] ? 1 : 0;
            if (!$detectChanges || (int) $faq->$field !== $new) {
                $faq->$field = $new;
                $touch($field);
            }
        }

        if (\array_key_exists('category_id', $input) && $input['category_id'] !== null) {
            $new = (int) $input['category_id'];
            if (!$detectChanges || (int) $faq->pid !== $new) {
                $faq->pid = $new;
                $touch('pid');
            }
        }

        if (\array_key_exists('sorting', $input) && $input['sorting'] !== null) {
            $new = (int) $input['sorting'];
            if (!$detectChanges || (int) $faq->sorting !== $new) {
                $faq->sorting = $new;
                $touch('sorting');
            }
        }

        if (\array_key_exists('author_id', $input) && $input['author_id'] !== null) {
            $new = (int) $input['author_id'];
            if (!$detectChanges || (int) $faq->author !== $new) {
                $faq->author = $new;
                $touch('author');
            }
        }

        if (\array_key_exists('singleSRC', $input) && $input['singleSRC'] !== null) {
            $raw = (string) $input['singleSRC'];
            $bin = $raw === '' ? null : self::hexToBin($raw, 'singleSRC');
            if (!$detectChanges || $faq->singleSRC !== $bin) {
                $faq->singleSRC = $bin;
                $touch('singleSRC');
            }
        }

        // ─── Provider apply ───
        foreach ($providers as $provider) {
            if (!$provider->isAvailable()) {
                continue;
            }
            foreach ($provider->apply($faq, $input, $detectChanges) as $field) {
                $touch($field);
            }
        }

        return $changed;
    }

    /**
     * @return list<string>
     */
    private static function knownFields(): array
    {
        return array_values(array_unique(array_merge(
            self::STRING_FIELDS,
            self::BOOL_FIELDS,
            ['category_id', 'sorting', 'author_id', 'singleSRC'],
        )));
    }

    /**
     * @throws \InvalidArgumentException
     */
    private static function hexToBin(string $raw, string $field): string
    {
        $hex = str_replace('-', '', $raw);
        if (\strlen($hex) !== 32 || preg_match('/^[0-9a-fA-F]+$/', $hex) !== 1) {
            throw new \InvalidArgumentException(sprintf('"%s" must be a 32-char hex UUID (got "%s").', $field, $raw));
        }
        return hex2bin($hex);
    }
}
