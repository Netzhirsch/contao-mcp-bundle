<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Backend\Module;

use Contao\Input;
use Contao\Message;
use Netzhirsch\ContaoMcpBundle\Backend\McpServerConfigStorage;
use Netzhirsch\ContaoMcpBundle\DependencyInjection\Compiler\McpToolProviderPass;
use Netzhirsch\ContaoMcpBundle\Server\ExtensionToolRegistrar;
use Netzhirsch\ContaoMcpBundle\Server\HttpDispatcherFactory;
use Netzhirsch\ContaoMcpBundle\Server\ToolCatalog;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * MCP-Server → Tools: per-tool enable/disable. Core tools are opt-out
 * (`disabled_tools`), extension tools opt-in (`extension_tools_enabled`).
 */
class ModuleMcpTools extends AbstractMcpModule
{
    /**
     * @var string
     */
    protected $strTemplate = 'be_mcp_tools';

    protected function compileModule(ContainerInterface $container, McpServerConfigStorage $configStorage, array $config): void
    {
        // Tool panel data: every tool the instance knows, grouped, with
        // enabled/source/protected flags. Reads the UNPRUNED registry (via
        // getServer()) so disabled tools keep their name + description in the
        // panel — the serving path prunes separately in getDispatcher().
        // A failure here (e.g. broken extension class) must not take down
        // the page, so it degrades to a warning.
        $toolCatalogue = null;
        try {
            /** @var HttpDispatcherFactory $factory */
            $factory = $container->get(HttpDispatcherFactory::class);
            $registry = $factory->getServer()->getRegistry();

            $registryTools = [];
            foreach ($registry->getTools() as $name => $schema) {
                $registryTools[(string) $name] = (string) ($schema->description ?? '');
            }

            $providerClasses = $container->hasParameter(McpToolProviderPass::PARAM)
                ? (array) $container->getParameter(McpToolProviderPass::PARAM)
                : [];
            $candidates = $container->get(ExtensionToolRegistrar::class)
                ->candidates(array_values(array_filter($providerClasses, 'is_string')));

            $toolCatalogue = (new ToolCatalog())->catalogue(
                $registryTools,
                $candidates,
                $config['disabled_tools'],
                $config['extension_tools_enabled'],
            );
        } catch (\Throwable $e) {
            $container->get('monolog.logger.contao.error')->error('MCP tool panel could not load the registry: '.$e->getMessage());
        }
        $this->Template->toolCatalogue = null === $toolCatalogue ? null : self::presentCatalogue($toolCatalogue);

        // Which names the panel renders, per source — posted back with the
        // form so mergeSelection() can keep the state of tools it did not show.
        $renderedCore = [];
        $renderedExt = [];
        foreach ($toolCatalogue ?? [] as $groupData) {
            foreach ($groupData['tools'] as $tool) {
                if ('extension' === $tool['source']) {
                    $renderedExt[] = $tool['name'];
                } else {
                    $renderedCore[] = $tool['name'];
                }
            }
        }
        $this->Template->toolsRenderedCore = implode(',', $renderedCore);
        $this->Template->toolsRenderedExt = implode(',', $renderedExt);
    }

    /**
     * Adds what the template needs per group: the label — translated when an
     * XLF key `tool_group_<group>` exists, otherwise derived from the key
     * ("image_size_item" → "Image size item") — and whether the group holds a
     * protected tool. Groups that do get no "select all": the toggle would
     * flip the disabled system checkboxes visually as well.
     *
     * @param list<array{group: string, tools: list<array{name: string, description: string, enabled: bool, source: string, protected: bool}>}> $catalogue
     *
     * @return list<array{group: string, label: string, has_protected: bool, tools: list<array{name: string, description: string, enabled: bool, source: string, protected: bool}>}>
     */
    private static function presentCatalogue(array $catalogue): array
    {
        $lang = $GLOBALS['TL_LANG']['mcp_server'] ?? [];

        return array_map(
            static fn (array $groupData): array => [
                'group' => $groupData['group'],
                'label' => (string) ($lang['tool_group_'.$groupData['group']] ?? ucfirst(str_replace('_', ' ', $groupData['group']))),
                'has_protected' => \in_array(true, array_column($groupData['tools'], 'protected'), true),
                'tools' => $groupData['tools'],
            ],
            $catalogue,
        );
    }

    /**
     * Persists the tool panel's checkbox selection. Merge semantics live in
     * {@see ToolCatalog::mergeSelection()} including the keep-unrendered rule
     * for temporarily missing bundles. Everything else in the config is
     * passed through untouched.
     */
    protected function handleAction(string $action, ContainerInterface $container, McpServerConfigStorage $configStorage): void
    {
        if ($action !== 'save_tools') {
            return;
        }

        $checkedRaw = Input::post('tools');
        $checked = \is_array($checkedRaw) ? array_values(array_filter($checkedRaw, '\is_string')) : [];

        // The panel tells us which names it actually rendered (comma-joined —
        // tool names can't contain commas) so unrendered names keep state.
        $split = static fn (string $csv): array => array_values(array_filter(explode(',', $csv)));
        $renderedCore = $split((string) Input::post('tools_rendered_core'));
        $renderedExt = $split((string) Input::post('tools_rendered_ext'));

        $current = $configStorage->load();
        $merged = ToolCatalog::mergeSelection(
            $renderedCore,
            $renderedExt,
            $checked,
            $current['disabled_tools'],
            $current['extension_tools_enabled'],
        );

        $result = $configStorage->save([...$current, ...$merged]);

        if ($result['saved']) {
            Message::addConfirmation(\sprintf(
                $this->translate('tools_saved', 'Tool selection saved — %d core tool(s) disabled, %d extension tool(s) enabled.'),
                \count($merged['disabled_tools']),
                \count($merged['extension_tools_enabled']),
            ));
        } else {
            Message::addError($this->translate('config_save_failed'));
        }

        $this->redirectSelf();
    }
}
