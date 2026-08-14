<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Server;

use Netzhirsch\ContaoMcpBundle\Server\ObjectAwareSchemaValidator;
use PhpMcp\Server\Utils\SchemaValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Guards the empty-object marshalling fix. The JSON-RPC body is decoded
 * associatively, so a client-sent `{}` arrives as `[]` and php-mcp's stock
 * validator rejects it against `type: object` with "received unknown". The
 * object-aware validator must accept it while leaving lists, non-empty
 * objects and genuine type errors exactly as the stock validator handles them.
 *
 * @see ObjectAwareSchemaValidator
 */
#[CoversClass(ObjectAwareSchemaValidator::class)]
final class ObjectAwareSchemaValidatorTest extends TestCase
{
    /** Mirrors the real fields/args/filters + list + scalar param shape. */
    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'fields' => ['type' => ['null', 'object'], 'default' => null],
            'tags'   => ['type' => ['null', 'array'], 'default' => null],
            'limit'  => ['type' => ['null', 'integer'], 'default' => null],
        ],
    ];

    private function validator(): ObjectAwareSchemaValidator
    {
        return new ObjectAwareSchemaValidator(new NullLogger());
    }

    public function testStockValidatorRejectsEmptyObjectParam(): void
    {
        // Documents the upstream bug this class works around.
        $errors = (new SchemaValidator(new NullLogger()))
            ->validateAgainstJsonSchema(['fields' => []], self::SCHEMA);

        self::assertNotEmpty($errors, 'Stock validator is expected to reject empty {} here.');
        self::assertSame('type', $errors[0]['keyword']);
    }

    public function testEmptyObjectParamIsAccepted(): void
    {
        self::assertSame([], $this->validator()->validateAgainstJsonSchema(['fields' => []], self::SCHEMA));
    }

    public function testNonEmptyObjectParamStillValidates(): void
    {
        self::assertSame(
            [],
            $this->validator()->validateAgainstJsonSchema(['fields' => ['width' => 1200]], self::SCHEMA),
        );
    }

    public function testEmptyListParamKeptAsList(): void
    {
        // A param that allows `array` must NOT be coerced to an object.
        self::assertSame([], $this->validator()->validateAgainstJsonSchema(['tags' => []], self::SCHEMA));
    }

    public function testNonEmptyListParamValidates(): void
    {
        self::assertSame([], $this->validator()->validateAgainstJsonSchema(['tags' => [1, 2, 3]], self::SCHEMA));
    }

    public function testWrongTypeForObjectParamStillRejected(): void
    {
        $errors = $this->validator()->validateAgainstJsonSchema(['fields' => 'not-an-object'], self::SCHEMA);

        self::assertNotEmpty($errors, 'A string passed to an object param must still be rejected.');
        self::assertSame('type', $errors[0]['keyword']);
    }

    public function testScalarAndEmptyArgumentsValidate(): void
    {
        self::assertSame([], $this->validator()->validateAgainstJsonSchema(['limit' => 5], self::SCHEMA));
        self::assertSame([], $this->validator()->validateAgainstJsonSchema([], self::SCHEMA));
    }

    public function testObjectSchemaInputIsHandled(): void
    {
        // inputSchema may arrive as a stdClass; normaliseSchema must cope.
        $schemaObject = json_decode(json_encode(self::SCHEMA, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

        self::assertSame([], $this->validator()->validateAgainstJsonSchema(['fields' => []], $schemaObject));
    }

    /**
     * Post-NullableUnionFlattener schema: optional params carry a single type
     * (no "null" branch). Mirrors page_create after the CWA-26 rest fix.
     */
    private const FLATTENED_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'title' => ['type' => 'string'],
            'fallback' => ['type' => 'boolean', 'default' => null],
            'language' => ['type' => 'string', 'default' => null],
        ],
        'required' => ['title'],
    ];

    public function testExplicitNullForOptionalParamAccepted(): void
    {
        // "fallback: null" and omitting fallback are semantically identical;
        // a client sending the explicit null must not be rejected just because
        // the flattened type no longer lists "null".
        self::assertSame(
            [],
            $this->validator()->validateAgainstJsonSchema(
                ['title' => 'Pilot', 'fallback' => null, 'language' => null],
                self::FLATTENED_SCHEMA,
            ),
        );
    }

    public function testNullForRequiredParamStillRejected(): void
    {
        $errors = $this->validator()->validateAgainstJsonSchema(['title' => null], self::FLATTENED_SCHEMA);

        self::assertNotEmpty($errors, 'null for a REQUIRED param must stay invalid.');
    }

    public function testWrongValueForOptionalParamStillRejected(): void
    {
        // Only nulls are forgiven — genuine type errors keep failing.
        $errors = $this->validator()->validateAgainstJsonSchema(
            ['title' => 'Pilot', 'fallback' => 'not-a-bool'],
            self::FLATTENED_SCHEMA,
        );

        self::assertNotEmpty($errors);
        self::assertSame('type', $errors[0]['keyword']);
    }
}
