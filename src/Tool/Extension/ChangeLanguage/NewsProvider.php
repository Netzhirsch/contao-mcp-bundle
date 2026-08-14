<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Extension\ChangeLanguage;

use Contao\Model;
use Contao\NewsModel;
use Netzhirsch\ContaoMcpBundle\Tool\Contract\FieldProvider;

/**
 * Adds the single tl_news column that terminal42/contao-changelanguage attaches via
 * its loadDataContainer hook (NewsListener / AbstractChildTableListener):
 *
 *   languageMain (int unsigned) — id of the master-language news entry this one
 *                                  translates from.
 *
 * Always registered as a Symfony service. isAvailable() flips to true once the
 * changelanguage bundle is autoloadable.
 */
final class NewsProvider implements FieldProvider
{
    private const MARKER_CLASS = 'Terminal42\\ChangeLanguage\\EventListener\\CallbackSetupListener';

    public function getTable(): string
    {
        return 'tl_news';
    }

    public function getRequiredExtension(): string
    {
        return 'terminal42/contao-changelanguage';
    }

    public function isAvailable(): bool
    {
        return class_exists(self::MARKER_CLASS);
    }

    public function getDeclaredFields(): array
    {
        return ['languageMain'];
    }

    public function getAllowedFields(?string $type): array
    {
        // tl_news has no DCA "type" concept — languageMain is universally valid.
        return $this->getDeclaredFields();
    }

    public function serialize(Model $model): array
    {
        if (!$this->isAvailable() || !$model instanceof NewsModel) {
            return [];
        }

        return [
            'languageMain' => (int) $model->languageMain,
        ];
    }

    public function apply(Model $model, array $input, bool $detectChanges): array
    {
        if (!$model instanceof NewsModel) {
            return [];
        }

        if (!\array_key_exists('languageMain', $input) || $input['languageMain'] === null) {
            return [];
        }

        $new = (int) $input['languageMain'];
        if ($detectChanges && (int) $model->languageMain === $new) {
            return [];
        }

        $model->languageMain = $new;

        return ['languageMain'];
    }
}
