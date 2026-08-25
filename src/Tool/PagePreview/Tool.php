<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\PagePreview;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\PageModel;
use PhpMcp\Server\Attributes\McpTool;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Frontend-side helpers: build a Page URL and fetch its rendered HTML.
 *
 * These tools close the loop after a CRUD change so the LLM can verify
 * what actually came out, instead of just "trust the database".
 */
final class Tool
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly ContentUrlGenerator $contentUrlGenerator,
        private readonly HttpClientInterface $httpClient,
        /**
         * "user:pass" when the site sits behind HTTP basic auth (staging
         * protection on the webserver). Null keeps the request exactly as it
         * was before this option existed.
         */
        private readonly ?string $previewBasicAuth = null,
    ) {
    }

    /**
     * Request options for the preview fetch.
     *
     * Split out so the credential handling is testable without booting Contao:
     * the tool itself needs PageModel and the framework, this decision needs
     * neither.
     */
    public static function requestOptions(?string $basicAuth): array
    {
        $options = [
            'timeout' => 10,
            'max_redirects' => 5,
            'headers' => ['User-Agent' => 'Contao-MCP-Bundle/page_preview'],
        ];

        // Deliberately no shape validation beyond "not empty": silently
        // dropping a typo'd value would surface as a bare 401 with nothing to
        // go on. Symfony accepts "user:pass" and treats a bare "user" as an
        // empty password.
        $basicAuth = null !== $basicAuth ? trim($basicAuth) : '';
        if ('' !== $basicAuth) {
            $options['auth_basic'] = $basicAuth;
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'page_url',
        description: <<<'DESC'
            Returns the frontend URL for a Contao page (tl_page id).

            Resolves the page via PageModel (so language prefix, urlSuffix, dns, etc. are
            applied from the page's root), then asks Contao's ContentUrlGenerator. With
            `absolute=true` (default) you get a full URL like "https://www.example.com/news.html";
            with absolute=false you get a relative path "/news.html".

            Returns {url, absolute, page_id, language, host}. Errors if the page is invisible,
            unpublished, or has no resolvable URL (e.g. error pages, sections).

            This is also the tool to reach for before a VISUAL check: the server renders no
            pixels, so hand this URL to a browser tool on the client side.
        DESC,
    )]
    public function pageUrl(int $page_id, bool $absolute = true): array
    {
        $this->framework->initialize();

        $page = PageModel::findByPk($page_id);
        if ($page === null) {
            return ['error' => 'not_found', 'message' => sprintf('No tl_page with id %d', $page_id)];
        }

        // ContentUrlGenerator needs the model loaded with root + parent
        // relationships. PageModel::findByPk already does that.
        $page->loadDetails();

        try {
            $url = $this->contentUrlGenerator->generate(
                $page,
                [],
                $absolute ? UrlGeneratorInterface::ABSOLUTE_URL : UrlGeneratorInterface::ABSOLUTE_PATH,
            );
        } catch (\Throwable $e) {
            return [
                'error' => 'url_generation_failed',
                'message' => $e->getMessage(),
                'hint' => 'Page might be of a type without a frontend URL (root, error_*, logout, …).',
            ];
        }

        return [
            'url' => $url,
            'absolute' => $absolute,
            'page_id' => $page_id,
            'language' => (string) ($page->language ?: $page->rootLanguage ?: ''),
            'host' => (string) ($page->domain ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'page_preview',
        description: <<<'DESC'
            Fetches the rendered HTML of a Contao frontend page so the LLM can verify the
            output after edits. Internally calls page_url(page_id, absolute=true) then HTTPs
            the URL with a 10-second timeout.

            By default returns the FULL HTML body (capped at 64 KB). Pass `excerpt_only=true`
            to receive a structured summary instead: `{title, h1, meta_description, body_text}`
            — useful for content checks without dragging full markup into the LLM context.

            Markup only, NOT a rendering. Whether a headline is present is answerable here;
            whether it is centred, whether the mobile menu opens, whether a column collapsed
            is not — that needs a browser, and this server has none. For visual checks take
            page_url and open it with a browser tool on the client side. What CAN be checked
            here without pixels is which markup survives Contao's output filter — see
            html_filter_preview.

            Note: the request goes to the page's PUBLIC url (the root page's dns/domain), not
            over loopback. Member-area pages are fetched unauthenticated — the preview shows
            what an anonymous visitor sees. If the site sits behind HTTP basic auth, set
            MCP_PREVIEW_BASIC_AUTH="user:pass" in the instance's .env.local, otherwise the
            webserver answers 401 before Contao runs.
        DESC,
    )]
    public function pagePreview(int $page_id, bool $excerpt_only = false): array
    {
        $this->framework->initialize();

        $urlResult = $this->pageUrl($page_id, absolute: true);
        if (isset($urlResult['error'])) {
            return $urlResult;
        }
        $url = (string) $urlResult['url'];

        try {
            $response = $this->httpClient->request('GET', $url, self::requestOptions($this->previewBasicAuth));
            $status = $response->getStatusCode();
            $contentType = $response->getHeaders(false)['content-type'][0] ?? '';
            $body = $response->getContent(false);
        } catch (\Throwable $e) {
            return [
                'error' => 'fetch_failed',
                'message' => $e->getMessage(),
                'url' => $url,
            ];
        }

        // Hard cap on body length to keep MCP responses small.
        $truncated = false;
        if (\strlen($body) > 65536) {
            $body = substr($body, 0, 65536);
            $truncated = true;
        }

        $result = [
            'url' => $url,
            'status' => $status,
            'content_type' => $contentType,
            'body_size' => \strlen($body),
            'truncated' => $truncated,
        ];

        // A 401/403 here is almost never Contao's doing — Contao answers 403
        // with a rendered error page, not a bare challenge. It is the webserver
        // in front of it, and without this hint the caller sees a status code
        // and an empty-looking body with nothing to act on.
        if (401 === $status || 403 === $status) {
            $result['hint'] = null === $this->previewBasicAuth || '' === trim($this->previewBasicAuth)
                ? 'The target host refused the request before Contao ran — typically HTTP basic auth on the webserver (staging protection). Set MCP_PREVIEW_BASIC_AUTH="user:pass" in the instance .env.local.'
                : 'Credentials are configured but were rejected. Check MCP_PREVIEW_BASIC_AUTH in the instance .env.local, or whether the protection expects something other than basic auth.';
        }

        if ($excerpt_only) {
            $result['summary'] = self::summariseHtml($body);

            return $result;
        }

        $result['body'] = $body;

        return $result;
    }

    // ─────────────────────────── helpers ────────────────────────────

    /**
     * @return array{title: ?string, h1: ?string, meta_description: ?string, body_text: string}
     */
    private static function summariseHtml(string $html): array
    {
        $title = null;
        if (preg_match('#<title>(.*?)</title>#is', $html, $m) === 1) {
            $title = self::cleanText($m[1]);
        }
        $h1 = null;
        if (preg_match('#<h1[^>]*>(.*?)</h1>#is', $html, $m) === 1) {
            $h1 = self::cleanText(strip_tags($m[1]));
        }
        $desc = null;
        if (preg_match('#<meta\s+name=["\']description["\']\s+content=["\'](.*?)["\']\s*/?>#is', $html, $m) === 1) {
            $desc = self::cleanText($m[1]);
        }

        // Strip scripts + styles entirely before extracting body text.
        $clean = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        // Strip remaining tags + collapse whitespace.
        $bodyText = self::cleanText(strip_tags($clean));
        // Cap to ~2 KB so the LLM doesn't drown in main-content.
        if (mb_strlen($bodyText) > 2000) {
            $bodyText = mb_substr($bodyText, 0, 1999).'…';
        }

        return [
            'title' => $title,
            'h1' => $h1,
            'meta_description' => $desc,
            'body_text' => $bodyText,
        ];
    }

    private static function cleanText(string $s): string
    {
        $s = html_entity_decode($s, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    }
}
