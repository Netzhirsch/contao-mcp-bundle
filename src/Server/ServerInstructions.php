<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Server;

use Netzhirsch\ContaoMcpBundle\Backend\McpServerConfigStorage;
use Netzhirsch\ContaoMcpBundle\Security\OperatorPolicy;

/**
 * The text a client receives as server instructions on `initialize`: the
 * operator's house rules and the switches that are currently narrowing what
 * an AI may do here.
 *
 * The switches are repeated here although the server enforces them anyway.
 * An agent that knows the installation is read-only does not plan ten writes
 * and fail at the first; one that knows new content starts unpublished does
 * not tell the user a page is live. contao_guide carries the same text.
 *
 * In English, like every other text addressed to the model; the house rules
 * are passed on as the operator wrote them.
 */
final class ServerInstructions
{
    /** @var array<string, string> */
    private const RESTRICTIONS = [
        'read_only' => 'Read-only: no tool may create, change or delete anything. Answer questions and draft text; a person makes the changes.',
        'no_delete' => 'Deleting is switched off. Ask a person to delete things in the backend.',
        'no_publish' => 'Publishing is switched off. New content is created unpublished, and nothing may be made visible. Tell the user it waits for a person to publish it.',
        'create_unpublished' => 'New content is created unpublished. Publish it in a separate step with the matching *_update tool only when the user asks for that.',
        'privacy_mode' => 'Privacy mode: members, newsletter recipients, comments, form submissions and backend users are not available, and IBAN/BIC never are.',
        'no_admins' => 'Administrator accounts may not connect; you work with the rights of a regular backend account.',
    ];

    public function __construct(
        private readonly McpServerConfigStorage $configStorage,
        private readonly OperatorPolicy $operatorPolicy,
    ) {
    }

    /**
     * The instructions, or null when there is nothing to say.
     */
    public function text(): ?string
    {
        $sections = [];

        $rules = trim((string) ($this->configStorage->load()['house_rules'] ?? ''));
        if ($rules !== '') {
            $sections[] = "House rules of this installation, set by its administrator — follow them:\n".$rules;
        }

        $lines = [];
        foreach ($this->operatorPolicy->activeRestrictions() as $code) {
            if (isset(self::RESTRICTIONS[$code])) {
                $lines[] = '- '.self::RESTRICTIONS[$code];
            }
        }
        if ($lines !== []) {
            $sections[] = "Restrictions the server enforces on this installation:\n".implode("\n", $lines);
        }

        return $sections === [] ? null : implode("\n\n", $sections);
    }
}
