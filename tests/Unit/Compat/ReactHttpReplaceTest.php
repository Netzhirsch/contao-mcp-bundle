<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Compat;

use PHPUnit\Framework\TestCase;

/**
 * `replace: { "react/http": "*" }` is a claim to Composer that this bundle
 * ships react/http itself. It does not. The entry has to stay anyway.
 *
 * php-mcp/server carried react/http as a `suggest` up to 3.2.2 and turned it
 * into a hard requirement in 3.3.0. react/http in turn has required
 * `psr/http-message ^1.0` since 1.9 (2023) and still does in 1.11.1 — no
 * released version accepts ^2.0. Contao runs on 2.0.
 *
 * Letting react/http in therefore costs the host project its PSR-7 interfaces.
 * Both directions were measured against real resolutions:
 *
 *   - a fresh Contao 6 install silently resolves psr/http-message down to 1.1,
 *   - an existing installation whose lock holds 2.0 cannot update at all.
 *
 * The second one is what the "Upgrade from the latest release" CI job catches,
 * and it is the failure every existing customer would have hit.
 *
 * The replace has a price of its own, and it is a real one: a third-party
 * package that genuinely requires react/http is told the need is met, receives
 * no files, and fails at runtime rather than at install time. That is the
 * smaller of the two evils, not a harmless trade. We never load a line of
 * react/http ourselves — the transport runs through Symfony and the ReactPHP
 * path is dead code here, which is why the smoke test passes with react/http
 * absent from the vendor directory.
 *
 * The only clean fix is upstream: react/http back to `suggest`, where it lived
 * until 3.3.0. Until that lands, this test exists so the replace is not removed
 * a second time. It was removed once, in good faith, by someone who read the
 * manifest but not the commit that put the entry there.
 *
 * @see https://github.com/Netzhirsch/contao-mcp-bundle/commit/de80242dbf1118b5c6e60d9226f6760ac034b1ff
 */
final class ReactHttpReplaceTest extends TestCase
{
    public function testReactHttpStaysReplaced(): void
    {
        self::assertSame(
            '*',
            $this->manifest()['replace']['react/http'] ?? null,
            'react/http must stay replaced: requiring it drags psr/http-message down to 1.x. See this class docblock.',
        );
    }

    public function testReactHttpIsNeverRequired(): void
    {
        $require = $this->manifest()['require'] ?? [];
        self::assertIsArray($require);

        self::assertArrayNotHasKey(
            'react/http',
            $require,
            'Requiring react/http breaks every installation locked to psr/http-message 2.0. See this class docblock.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        $json = file_get_contents(__DIR__.'/../../../composer.json');
        self::assertIsString($json);

        $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);

        /** @var array<string, mixed> $manifest */
        return $manifest;
    }
}
