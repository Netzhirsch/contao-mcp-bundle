<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Extension\DeepL;

use DeepL\DeepLClient;
use DeepL\TranslateTextOptions;
use numero2\DeepLBundle\DeepLBundle;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * The bundle's own DeepL access, gated on numero2/contao-deepl being installed.
 *
 * Why not simply call `numero2_deepl.api`? Two reasons, both about what the
 * host service can and cannot do:
 *
 *  1. It translates ONE string per call with no `tag_handling`. Contao's rich
 *     text (`tl_content.text`, teasers, FAQ answers) is HTML, and DeepL mangles
 *     attributes when HTML arrives as plain text. We send `tag_handling=html`
 *     for those fields.
 *  2. It gives no cost signal back. Translating a page tree can be hundreds of
 *     records, and the operator asked to see what a call actually spends.
 *
 * It also cannot be called safely without a key: `DeepLApi::translate()` guards
 * with `if (!$this->translator)` on a typed property its constructor only
 * assigns when a key is present, so an unconfigured instance raises
 * "must not be accessed before initialization" instead of returning ''
 * (verified against 1.0.9). Our own gate answers `deepl_not_configured` first.
 *
 * What we DO take from the host bundle is its configuration: the API key comes
 * from `contao.deepl.api_key`, the parameter numero2's extension sets from
 * `DEEPL_API_KEY` or `deepl.api_key`. The operator configures DeepL once, and
 * both the backend button and MCP use it. Installing the host bundle is
 * therefore a hard requirement — it is what makes the key exist and what pulls
 * in `deeplcom/deepl-php`.
 *
 * Caching: numero2's service keeps a permanent cache keyed by
 * `md5(text).targetLang`, which does not record whether the text was treated as
 * HTML. Writing into it from here would let a markup-aware translation come
 * back later as the answer for the same string requested as plain text, and its
 * entries never expire. We therefore leave it alone and keep our own, keyed on
 * target language, source language AND tag handling, with a 30-day lifetime.
 *
 * That cache is not a micro-optimisation, it is what makes the recommended
 * sequence affordable: dry-run, preview, then save are three separate HTTP
 * requests and therefore three separate PHP processes, and without a shared
 * cache the operator would pay DeepL twice for looking before writing.
 */
final class Client
{
    /**
     * Marker for the host bundle. Its presence is what guarantees both the
     * `contao.deepl.api_key` parameter and the DeepL PHP library.
     */
    private const MARKER_CLASS = DeepLBundle::class;
    public const REQUIRED_EXTENSION = 'numero2/contao-deepl';

    /** Parameter numero2\DeepLBundle\DependencyInjection\DeepLExtension::prepend() sets. */
    private const KEY_PARAMETER = 'contao.deepl.api_key';

    /**
     * DeepL accepts at most 50 texts per request. The character ceiling is ours:
     * the API's request-body limit is 128 KiB, and staying well under it keeps
     * a single oversized content element from failing a whole batch.
     */
    private const MAX_TEXTS_PER_REQUEST = 50;
    private const MAX_CHARS_PER_REQUEST = 50000;

    /**
     * How long a translation stays reusable. Long enough that the recommended
     * dry-run → preview → save sequence is paid for once (each of those is a
     * separate HTTP request and therefore a separate PHP process), short enough
     * that a later DeepL model improvement is not locked out for good.
     */
    private const CACHE_TTL_SECONDS = 2592000; // 30 days

    private ?DeepLClient $client = null;

    /**
     * Per-instance memo so one tree run pays once for a string that repeats
     * across records. Keyed by target/source/tag-handling + text.
     *
     * @var array<string, string>
     */
    private array $memo = [];

    private int $charactersSubmitted = 0;
    private int $charactersReused = 0;
    private int $requests = 0;

    public function __construct(
        private readonly ParameterBagInterface $parameters,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * Null when DeepL is usable, otherwise the error payload to return to the
     * caller — worded so the operator knows which of the two steps is missing.
     *
     * @return array{error: string, message: string, required_extension: string}|null
     */
    public function unavailableReason(): ?array
    {
        if (!class_exists(self::MARKER_CLASS)) {
            return [
                'error' => 'extension_not_available',
                'message' => 'DeepL translation requires the numero2/contao-deepl bundle. Install it with "composer require numero2/contao-deepl".',
                'required_extension' => self::REQUIRED_EXTENSION,
            ];
        }

        if (!class_exists(DeepLClient::class)) {
            return [
                'error' => 'extension_not_available',
                'message' => 'The deeplcom/deepl-php library is missing although numero2/contao-deepl is installed — run "composer update numero2/contao-deepl".',
                'required_extension' => self::REQUIRED_EXTENSION,
            ];
        }

        if ($this->apiKey() === null) {
            return [
                'error' => 'deepl_not_configured',
                'message' => 'No DeepL API key is configured. Set DEEPL_API_KEY in .env.local (or deepl.api_key in config.yaml) and clear the cache.',
                'required_extension' => self::REQUIRED_EXTENSION,
            ];
        }

        return null;
    }

    public function isAvailable(): bool
    {
        return $this->unavailableReason() === null;
    }

    /**
     * Translates a list of strings, preserving order and index.
     *
     * Empty slots come back empty without costing anything. A string already
     * translated — earlier in this same list, earlier in this process, or in an
     * earlier call still in the cache — is not sent again. `$html` switches on
     * DeepL's tag handling and therefore has to be uniform for the batch: the
     * caller groups plain and rich-text fields into separate calls.
     *
     * @param list<string> $texts
     *
     * @return array{translations: list<string>, detected_source_lang: string|null}
     *
     * @throws \DeepL\DeepLException
     */
    public function translate(array $texts, string $targetLang, ?string $sourceLang, bool $html): array
    {
        $targetLang = strtoupper(trim($targetLang));
        $sourceLang = ($sourceLang === null || trim($sourceLang) === '') ? null : strtoupper(trim($sourceLang));

        $translations = [];
        $pending = [];   // unique text => list of indexes waiting for it
        $detected = null;

        foreach ($texts as $i => $text) {
            if (trim($text) === '') {
                $translations[$i] = $text;
                continue;
            }

            $memoKey = $this->memoKey($text, $targetLang, $sourceLang, $html);
            if (isset($this->memo[$memoKey])) {
                $translations[$i] = $this->memo[$memoKey];
                $this->charactersReused += mb_strlen($text);
                continue;
            }

            if (isset($pending[$text])) {
                // Same string twice in this batch — one API text, both slots.
                $this->charactersReused += mb_strlen($text);
            }
            $pending[$text][] = $i;
        }

        $pending = $this->takeFromCache($pending, $translations, $targetLang, $sourceLang, $html);

        foreach ($this->chunk(array_keys($pending)) as $chunk) {
            $options = $html ? [TranslateTextOptions::TAG_HANDLING => 'html'] : [];
            $results = $this->deepl()->translateText($chunk, $sourceLang, $targetLang, $options);
            ++$this->requests;

            /** @var list<\DeepL\TextResult> $results */
            foreach ($results as $offset => $result) {
                $source = (string) $chunk[$offset];
                $this->charactersSubmitted += mb_strlen($source);
                $detected ??= $result->detectedSourceLang;

                $this->remember($source, $result->text, $targetLang, $sourceLang, $html);

                foreach ($pending[$source] as $index) {
                    $translations[$index] = $result->text;
                }
            }
        }

        ksort($translations);

        return [
            'translations' => array_values($translations),
            'detected_source_lang' => $detected,
        ];
    }

    /**
     * Fills what the cache already knows and returns what is left to translate.
     *
     * The cache is ours, not the host bundle's: its key covers target language,
     * source language AND whether the text was treated as markup, so a markup
     * translation can never come back as the answer for the same string asked
     * for as plain text. Entries expire, so a later DeepL model improvement is
     * not locked out forever.
     *
     * @param array<string, list<int>> $pending
     * @param array<int, string>       $translations
     *
     * @return array<string, list<int>> the still-unresolved subset of $pending
     */
    private function takeFromCache(array $pending, array &$translations, string $targetLang, ?string $sourceLang, bool $html): array
    {
        if ($pending === []) {
            return $pending;
        }

        $keys = [];
        foreach (array_keys($pending) as $text) {
            $keys[$this->cacheKey((string) $text, $targetLang, $sourceLang, $html)] = (string) $text;
        }

        try {
            $items = $this->cache->getItems(array_keys($keys));
        } catch (\Throwable) {
            return $pending; // a broken cache must not stop a translation
        }

        foreach ($items as $key => $item) {
            if (!$item->isHit()) {
                continue;
            }

            $text = $keys[$key] ?? null;
            $value = $item->get();
            if ($text === null || !\is_string($value)) {
                continue;
            }

            $this->memo[$this->memoKey($text, $targetLang, $sourceLang, $html)] = $value;
            $this->charactersReused += mb_strlen($text);

            foreach ($pending[$text] as $index) {
                $translations[$index] = $value;
            }
            unset($pending[$text]);
        }

        return $pending;
    }

    private function remember(string $source, string $translation, string $targetLang, ?string $sourceLang, bool $html): void
    {
        $this->memo[$this->memoKey($source, $targetLang, $sourceLang, $html)] = $translation;

        try {
            $item = $this->cache->getItem($this->cacheKey($source, $targetLang, $sourceLang, $html));
            $item->set($translation);
            $item->expiresAfter(self::CACHE_TTL_SECONDS);
            $this->cache->save($item);
        } catch (\Throwable) {
            // Caching is an optimisation; failing to store must not fail the call.
        }
    }

    private function memoKey(string $text, string $targetLang, ?string $sourceLang, bool $html): string
    {
        return $targetLang.'|'.($sourceLang ?? '-').'|'.($html ? 'h' : 'p').'|'.$text;
    }

    private function cacheKey(string $text, string $targetLang, ?string $sourceLang, bool $html): string
    {
        // PSR-6 forbids {}()/\@: in keys — a hex digest sidesteps all of them
        // and keeps the key length bounded regardless of the text.
        return 'nh_mcp_deepl.'.hash('sha256', $this->memoKey($text, $targetLang, $sourceLang, $html));
    }

    /**
     * Running totals for this Client instance. `characters_submitted` is the
     * number DeepL bills on (source characters actually sent).
     *
     * The service is shared for the lifetime of the process, so a tool takes a
     * baseline before it starts and reports {@see spendSince()} — otherwise the
     * second tool call in one process would inherit the first one's cost.
     *
     * @return array{characters_submitted: int, characters_reused: int, api_requests: int}
     */
    public function spend(): array
    {
        return [
            'characters_submitted' => $this->charactersSubmitted,
            'characters_reused' => $this->charactersReused,
            'api_requests' => $this->requests,
        ];
    }

    /**
     * What has been spent since a {@see spend()} baseline was taken — the cost
     * of one tool call.
     *
     * @param array{characters_submitted: int, characters_reused: int, api_requests: int} $baseline
     *
     * @return array{characters_submitted: int, characters_reused: int, api_requests: int}
     */
    public function spendSince(array $baseline): array
    {
        return [
            'characters_submitted' => $this->charactersSubmitted - $baseline['characters_submitted'],
            'characters_reused' => $this->charactersReused - $baseline['characters_reused'],
            'api_requests' => $this->requests - $baseline['api_requests'],
        ];
    }

    /**
     * Target language codes DeepL accepts, e.g. DE, EN-GB, FR.
     *
     * @return list<string>
     *
     * @throws \DeepL\DeepLException
     */
    public function targetLanguages(): array
    {
        return array_map(static fn ($l) => $l->code, $this->deepl()->getTargetLanguages());
    }

    /**
     * The account's usage counter.
     *
     * Read it as a period total, NOT as the cost of the call you just made:
     * DeepL's usage endpoint lags behind translation requests by an unspecified
     * amount (measured here: a 92-character batch left the counter unchanged,
     * and it caught up on a later poll). `characters_submitted` from
     * {@see spend()} is the per-call number.
     *
     * @return array{character_count: int, character_limit: int|null, limit_reached: bool}
     *
     * @throws \DeepL\DeepLException
     */
    public function accountUsage(): array
    {
        $usage = $this->deepl()->getUsage();
        // An account plan without a character quota reports no detail at all.
        $character = $usage->character;

        return [
            'character_count' => $character !== null ? $character->count : 0,
            'character_limit' => $character?->limit,
            'limit_reached' => $usage->anyLimitReached(),
        ];
    }

    /**
     * Splits the unique texts into API-sized requests.
     *
     * @param list<string> $texts
     *
     * @return list<list<string>>
     */
    private function chunk(array $texts): array
    {
        $chunks = [];
        $current = [];
        $currentChars = 0;

        foreach ($texts as $text) {
            $len = mb_strlen($text);

            if ($current !== [] && (\count($current) >= self::MAX_TEXTS_PER_REQUEST || $currentChars + $len > self::MAX_CHARS_PER_REQUEST)) {
                $chunks[] = $current;
                $current = [];
                $currentChars = 0;
            }

            $current[] = $text;
            $currentChars += $len;
        }

        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    private function deepl(): DeepLClient
    {
        return $this->client ??= new DeepLClient((string) $this->apiKey());
    }

    /**
     * The host bundle's key parameter, or null when it cannot be resolved.
     *
     * The default value numero2 ships is `%env(DEEPL_API_KEY)%` with no
     * fallback, so reading it throws when the variable is unset — which is a
     * missing configuration, not a crash worth propagating.
     */
    private function apiKey(): ?string
    {
        try {
            $key = $this->parameters->get(self::KEY_PARAMETER);
        } catch (\Throwable) {
            return null;
        }

        return \is_string($key) && trim($key) !== '' ? $key : null;
    }
}
