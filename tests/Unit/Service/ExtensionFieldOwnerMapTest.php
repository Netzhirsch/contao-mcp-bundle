<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Service;

use Netzhirsch\ContaoMcpBundle\Extension\McpFieldOwnerProviderInterface;
use Netzhirsch\ContaoMcpBundle\Service\ExtensionFieldOwnerMap;
use Netzhirsch\ContaoMcpBundle\Service\FieldOwner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A bundle that hangs a column on a core table knows which of its tools owns
 * it; the core cannot guess, and hardcoding one customer's field into a product
 * every other customer installs is the maintained list this bundle keeps
 * removing. So the owner is declared, and the refusal names the call.
 */
#[CoversClass(ExtensionFieldOwnerMap::class)]
#[CoversClass(FieldOwner::class)]
final class ExtensionFieldOwnerMapTest extends TestCase
{
    /**
     * @param array<string, array<string, string>> $owners
     */
    private static function provider(array $owners): McpFieldOwnerProviderInterface
    {
        return new class($owners) implements McpFieldOwnerProviderInterface {
            /** @param array<string, array<string, string>> $owners */
            public function __construct(private readonly array $owners)
            {
            }

            public function getMcpFieldOwners(): array
            {
                return $this->owners;
            }
        };
    }

    public function testADeclaredOwnerIsCollected(): void
    {
        $map = new ExtensionFieldOwnerMap([self::provider([
            'tl_page.netzhirschPageState' => [
                'write' => 'pagestate_assign(page_id: <id>, state_id: <id>)',
                'read' => 'pagestate_of_page(page_id: <id>)',
            ],
        ])]);

        self::assertSame(
            'pagestate_assign(page_id: <id>, state_id: <id>)',
            FieldOwner::ownerFor('tl_page', 'netzhirschPageState', $map->all(), 'write'),
        );
        self::assertSame(
            'pagestate_of_page(page_id: <id>)',
            FieldOwner::ownerFor('tl_page', 'netzhirschPageState', $map->all(), 'read'),
        );
    }

    /**
     * Declaring only one direction still beats saying nothing — "use this to
     * set it" answers "why can I not read it" well enough to act on.
     */
    public function testOneDirectionAnswersBoth(): void
    {
        $map = new ExtensionFieldOwnerMap([self::provider([
            'tl_page.acmeThing' => ['write' => 'acme_assign(id: <id>)'],
        ])]);

        self::assertSame('acme_assign(id: <id>)', FieldOwner::ownerFor('tl_page', 'acmeThing', $map->all(), 'read'));
    }

    public function testAMalformedKeyIsIgnored(): void
    {
        $map = new ExtensionFieldOwnerMap([self::provider([
            'netzhirschPageState' => ['write' => 'nope'],          // no table
            'tl_page.' => ['write' => 'nope'],                      // no field
            'notatable.field' => ['write' => 'nope'],               // not a tl_ table
        ])]);

        self::assertSame([], $map->all());
    }

    public function testAnEmptyHintIsIgnored(): void
    {
        $map = new ExtensionFieldOwnerMap([self::provider([
            'tl_page.acmeThing' => ['write' => '   '],
        ])]);

        self::assertSame([], $map->all());
    }

    public function testFirstDeclarationWins(): void
    {
        $map = new ExtensionFieldOwnerMap([
            self::provider(['tl_page.acmeThing' => ['write' => 'first']]),
            self::provider(['tl_page.acmeThing' => ['write' => 'second']]),
        ]);

        self::assertSame('first', FieldOwner::ownerFor('tl_page', 'acmeThing', $map->all()));
    }

    /**
     * An extension must not be able to redirect a caller away from a core tool
     * by claiming a column the core already owns.
     */
    public function testCoreBeatsAnExtension(): void
    {
        $map = new ExtensionFieldOwnerMap([self::provider([
            'tl_news_archive.master' => ['write' => 'acme_hijack()'],
        ])]);

        $owner = FieldOwner::ownerFor('tl_news_archive', 'master', $map->all());

        self::assertNotNull($owner);
        self::assertStringContainsString('entity_language_link', $owner);
    }

    public function testProvidersThatDeclareNothingContributeNothing(): void
    {
        $map = new ExtensionFieldOwnerMap([new \stdClass(), self::provider([])]);

        self::assertSame([], $map->all());
    }

    public function testTheHintReachesTheFieldNotWritableMessage(): void
    {
        $map = new ExtensionFieldOwnerMap([self::provider([
            'tl_page.acmeThing' => ['write' => 'acme_assign(id: <id>)'],
        ])]);

        self::assertStringContainsString(
            'acme_assign(id: <id>)',
            FieldOwner::hintFor('tl_page', ['acmeThing'], $map->all()),
        );
    }

    public function testWithoutADeclarationTheSearchPhraseIsStillOffered(): void
    {
        $hint = FieldOwner::hintFor('tl_page', ['acmeThing'], []);

        self::assertStringContainsString('contao_search_tools("acme thing")', $hint);
    }
}
