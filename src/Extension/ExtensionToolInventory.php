<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Extension;

use Netzhirsch\ContaoMcpBundle\Backend\McpServerConfigStorage;
use Netzhirsch\ContaoMcpBundle\Server\ExtensionToolRegistrar;

/**
 * Which extension tools this installation HAS, and which of them are switched
 * on — the question `tools/list` cannot answer, because a disabled tool is
 * absent from it by design.
 *
 * That absence caused a real misdiagnosis. An agent needed to write component
 * elements, found no matching tools, asked installed_bundles, was told about
 * no additional tools, and reported to the customer that writing them was
 * structurally impossible. The tools existed. They were opt-in and nobody had
 * enabled them — which from the outside looks exactly like "not installed".
 *
 * "Installed but not enabled" is an answer the operator can act on; "nothing
 * here" is not.
 */
final class ExtensionToolInventory
{
    /**
     * @param list<string> $providerClasses FQCNs of `netzhirsch_mcp.tool`-tagged
     *                                      services, collected at build time by
     *                                      {@see \Netzhirsch\ContaoMcpBundle\DependencyInjection\Compiler\McpToolProviderPass}
     */
    public function __construct(
        private readonly ExtensionToolRegistrar $registrar,
        private readonly McpServerConfigStorage $config,
        private readonly array $providerClasses = [],
    ) {
    }

    /**
     * Every extension tool offered here, enabled or not.
     *
     * Note this is what the extensions OFFER, not what the server ended up
     * registering: a tool can be enabled and still not serve, because its name
     * collides with a core tool or because its class failed to reflect. Those
     * two are logged at registration time; they are rare and they are not what
     * this list is for.
     *
     * @return list<array{name: string, description: string, class: string, enabled: bool}>
     */
    public function all(): array
    {
        $enabled = $this->enabledNames();

        $tools = [];
        foreach ($this->registrar->candidates($this->providerClasses) as $candidate) {
            $tools[] = $candidate + ['enabled' => \in_array($candidate['name'], $enabled, true)];
        }

        usort($tools, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $tools;
    }

    /**
     * Names of the tools that are present but switched off — the ones an
     * operator could turn on right now.
     *
     * @return list<string>
     */
    public function disabledNames(): array
    {
        $names = [];
        foreach ($this->all() as $tool) {
            if (!$tool['enabled']) {
                $names[] = $tool['name'];
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private function enabledNames(): array
    {
        $enabled = $this->config->load()['extension_tools_enabled'] ?? [];

        return array_values(array_filter((array) $enabled, 'is_string'));
    }
}
