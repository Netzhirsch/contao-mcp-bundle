<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Extension\OpenGraph;

/**
 * A refusal that knows its own name.
 *
 * The provider is reached through two very different doors: the generic write
 * path, which catches `\InvalidArgumentException` and reports `invalid_input`,
 * and the opengraph_* tools, which can do better than that. Extending
 * InvalidArgumentException keeps the generic path working unchanged, while the
 * carried code lets the dedicated tools answer with the distinction they
 * promise in their description — "this property needs another og_type" is a
 * different problem from "that is not a field", and a caller deciding what to
 * do next should not have to parse prose to tell them apart.
 */
final class Refusal extends \InvalidArgumentException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
