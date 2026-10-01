<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Extension;

use Netzhirsch\ContaoMcpBundle\Tool\Extension\DeepL\Client;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

/**
 * The glossary rules, which have to match numero2/contao-deepl exactly.
 *
 * Not "closely": the operator configures one list, and a term translated
 * through the Backend button must come out the same when the same text goes
 * through MCP. A divergence here would show up as terminology applied in one
 * place and not the other — the kind of thing a customer finds months later.
 *
 * These assertions are the readable half of that contract; the other half is a
 * probe against the real API, which cannot live in a unit test.
 *
 * @see \numero2\DeepLBundle\Api\DeepLApi::getGlossaryId()
 */
final class DeepLGlossaryTest extends TestCase
{
    public function testAConfiguredPairResolves(): void
    {
        self::assertSame('gid', $this->client(['de-en' => 'gid'])->glossaryFor('DE', 'EN-US'));
    }

    /**
     * en-US and en-GB are the same language for glossary purposes — the host
     * strips the regional part before matching, so we do too.
     */
    public function testRegionalVariantsShareOneGlossary(): void
    {
        $client = $this->client(['de-en' => 'gid']);

        self::assertSame('gid', $client->glossaryFor('DE', 'EN-GB'));
        self::assertSame('gid', $client->glossaryFor('DE', 'EN-US'));
        self::assertSame('gid', $client->glossaryFor('de', 'en'));
    }

    public function testThePairIsMatchedWithoutRegardToCase(): void
    {
        self::assertSame('gid', $this->client(['DE-EN' => 'gid'])->glossaryFor('de', 'EN'));
    }

    public function testAPairThatIsNotConfiguredGetsNoGlossary(): void
    {
        self::assertNull($this->client(['de-en' => 'gid'])->glossaryFor('DE', 'FR'));
    }

    /**
     * Translating a language into itself is not a pair, and DeepL would refuse
     * the glossary anyway.
     */
    public function testTheSameLanguageOnBothSidesGetsNoGlossary(): void
    {
        self::assertNull($this->client(['en-en' => 'gid'])->glossaryFor('EN-GB', 'EN-US'));
    }

    public function testWithoutASourceLanguageThereIsNoPairAndNoGlossary(): void
    {
        self::assertNull($this->client(['de-en' => 'gid'])->glossaryFor(null, 'EN-US'));
    }

    public function testAnEmptyIdIsNotAGlossary(): void
    {
        self::assertNull($this->client(['de-en' => ''])->glossaryFor('DE', 'EN-US'));
    }

    /**
     * An installation still on host 1.0.x has neither parameter, and
     * ParameterBag::get() throws on an unknown name. Our constraint is ^1.0, so
     * that installation is one we promised to keep working.
     */
    public function testAnInstallationWithoutTheNewParametersStillWorks(): void
    {
        $client = new Client(new ParameterBag(['contao.deepl.api_key' => 'k']), new ArrayAdapter());

        self::assertNull($client->glossaryFor('DE', 'EN-US'));
        self::assertSame(['source_lang' => null, 'pairs' => []], $client->glossaryConfig());
    }

    public function testTheReportedConfigurationNamesSourceAndPairs(): void
    {
        $config = $this->client(['de-en' => 'gid', 'de-fr' => 'other'], 'de')->glossaryConfig();

        self::assertSame('DE', $config['source_lang']);
        self::assertSame(['de-en', 'de-fr'], $config['pairs']);
    }

    public function testPairsWithoutAnIdAreNotReportedAsConfigured(): void
    {
        $config = $this->client(['de-en' => 'gid', 'de-fr' => ''], 'de')->glossaryConfig();

        self::assertSame(['de-en'], $config['pairs']);
    }

    /**
     * @param array<string, string> $glossaries
     */
    private function client(array $glossaries, string $sourceLang = ''): Client
    {
        return new Client(
            new ParameterBag([
                'contao.deepl.api_key' => 'k',
                'contao.deepl.source_lang' => $sourceLang,
                'contao.deepl.glossaries' => $glossaries,
            ]),
            new ArrayAdapter(),
        );
    }
}
