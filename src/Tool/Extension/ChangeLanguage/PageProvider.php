<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Extension\ChangeLanguage;

use Contao\Model;
use Contao\PageModel;
use Netzhirsch\ContaoMcpBundle\Tool\Contract\FieldProvider;

/**
 * Adds the three tl_page columns introduced by terminal42/contao-changelanguage:
 *
 *   languageMain  (int)  — id of the master-language page this one translates
 *   languageRoot  (int)  — fallback-language root page reference
 *   languageQuery (str)  — URL query parameter for language mapping
 *
 * The provider is always registered with Symfony. isAvailable() only returns true when
 * the bundle's marker class is autoloadable, so on installations that don't have the
 * extension the FieldMapper rejects these fields with extension_not_available.
 *
 * Per the changelanguage DCA, languageMain/languageQuery don't apply to error pages or
 * root pages — those aren't part of the language-tree concept. We mirror that here.
 */
final class PageProvider implements FieldProvider
{
    /**
     * Class that ships only with terminal42/contao-changelanguage.
     * Used as a presence probe — no need to instantiate it.
     */
    private const MARKER_CLASS = 'Terminal42\\ChangeLanguage\\EventListener\\CallbackSetupListener';

    public function getTable(): string
    {
        return 'tl_page';
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
        return ['languageMain', 'languageRoot', 'languageQuery'];
    }

    public function getAllowedFields(?string $type): array
    {
        if ($type === null) {
            return $this->getDeclaredFields();
        }

        return match ($type) {
            'root' => ['languageRoot'],
            'error_401', 'error_403', 'error_404', 'error_503', 'logout' => [],
            default => ['languageMain', 'languageRoot', 'languageQuery'],
        };
    }

    public function serialize(Model $model): array
    {
        if (!$this->isAvailable() || !$model instanceof PageModel) {
            return [];
        }

        return [
            'languageMain' => (int) $model->languageMain,
            'languageRoot' => (int) $model->languageRoot,
            'languageQuery' => (string) $model->languageQuery,
        ];
    }

    public function apply(Model $model, array $input, bool $detectChanges): array
    {
        if (!$model instanceof PageModel) {
            return [];
        }

        $changed = [];

        if (\array_key_exists('languageMain', $input) && $input['languageMain'] !== null) {
            $new = (int) $input['languageMain'];
            if (!$detectChanges || (int) $model->languageMain !== $new) {
                $model->languageMain = $new;
                $changed[] = 'languageMain';
            }
        }

        if (\array_key_exists('languageRoot', $input) && $input['languageRoot'] !== null) {
            $new = (int) $input['languageRoot'];
            if (!$detectChanges || (int) $model->languageRoot !== $new) {
                $model->languageRoot = $new;
                $changed[] = 'languageRoot';
            }
        }

        if (\array_key_exists('languageQuery', $input) && $input['languageQuery'] !== null) {
            $new = (string) $input['languageQuery'];
            if (!$detectChanges || (string) $model->languageQuery !== $new) {
                $model->languageQuery = $new;
                $changed[] = 'languageQuery';
            }
        }

        return $changed;
    }
}
