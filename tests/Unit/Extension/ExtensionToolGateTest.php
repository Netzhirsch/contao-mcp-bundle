<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Extension;

use Netzhirsch\ContaoMcpBundle\Extension\ExtensionToolGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The gate is the single security-critical decision for the whole extension
 * point: it decides whether a third-party tool becomes LLM-callable. Every
 * branch is pinned here.
 */
#[CoversClass(ExtensionToolGate::class)]
final class ExtensionToolGateTest extends TestCase
{
    public function testEnabledAndFreeNameIsRegistered(): void
    {
        self::assertSame(
            ExtensionToolGate::REGISTER,
            ExtensionToolGate::decide('acme_foo', ['acme_foo'], []),
        );
    }

    public function testNotInAllowlistIsSkippedAsDisabled(): void
    {
        // The core defense: a tool the operator did NOT opt into is never
        // registered, even if nothing else is wrong with it.
        self::assertSame(
            ExtensionToolGate::SKIP_DISABLED,
            ExtensionToolGate::decide('acme_foo', [], []),
        );
        self::assertSame(
            ExtensionToolGate::SKIP_DISABLED,
            ExtensionToolGate::decide('acme_foo', ['acme_bar'], []),
        );
    }

    public function testEnabledButTakenNameIsSkippedAsDuplicate(): void
    {
        // Core (or an earlier extension) holds the name → extension loses.
        self::assertSame(
            ExtensionToolGate::SKIP_DUPLICATE,
            ExtensionToolGate::decide('news_create', ['news_create'], ['news_create' => true]),
        );
    }

    public function testDisabledTakesPrecedenceOverDuplicate(): void
    {
        // A tool that is BOTH not-enabled AND colliding is reported as
        // disabled — the allowlist is checked first. (Either way it isn't
        // registered; this just pins the reported reason.)
        self::assertSame(
            ExtensionToolGate::SKIP_DISABLED,
            ExtensionToolGate::decide('news_create', [], ['news_create' => true]),
        );
    }

    public function testAllowlistMatchIsCaseSensitiveAndExact(): void
    {
        self::assertSame(
            ExtensionToolGate::SKIP_DISABLED,
            ExtensionToolGate::decide('Acme_Foo', ['acme_foo'], []),
        );
        self::assertSame(
            ExtensionToolGate::SKIP_DISABLED,
            ExtensionToolGate::decide('acme_foo', ['acme_foo_bar'], []),
        );
    }
}
