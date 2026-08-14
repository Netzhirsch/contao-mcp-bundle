<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Extension\ChangeLanguage;

use Contao\FaqModel;
use Contao\Model;
use Netzhirsch\ContaoMcpBundle\Tool\Contract\FieldProvider;

final class FaqProvider implements FieldProvider
{
    private const MARKER_CLASS = 'Terminal42\\ChangeLanguage\\EventListener\\CallbackSetupListener';

    public function getTable(): string
    {
        return 'tl_faq';
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
        return $this->getDeclaredFields();
    }

    public function serialize(Model $model): array
    {
        if (!$this->isAvailable() || !$model instanceof FaqModel) {
            return [];
        }
        return ['languageMain' => (int) $model->languageMain];
    }

    public function apply(Model $model, array $input, bool $detectChanges): array
    {
        if (!$model instanceof FaqModel) {
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
