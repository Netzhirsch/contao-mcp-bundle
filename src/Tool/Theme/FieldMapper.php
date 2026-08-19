<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Theme;

use Contao\FilesModel;
use Contao\StringUtil;
use Contao\ThemeModel;
use Netzhirsch\ContaoMcpBundle\Service\ProviderFields;

/**
 * Maps the MCP-facing parameter dict to ThemeModel column writes.
 *
 * - name / author / templates: stored verbatim (strings).
 * - folders[]:                  list of paths → list of binary UUIDs → serialized.
 * - screenshot:                 single path → binary UUID.
 *
 * Returns a normalized `{errors: list, applied: int}` result so the Tool layer
 * can short-circuit on validation errors before persisting.
 */
final class FieldMapper
{
    /**
     * The columns this mapper owns itself. Extensions add more through field
     * providers — see allowedFields().
     */
    private const FIXED_FIELDS = ['name', 'author', 'templates', 'folders', 'screenshot'];

    public function __construct(
        private readonly ProviderFields $providerFields,
    ) {
    }

    /**
     * Everything a caller may submit, including provider-contributed columns.
     * Providers are listed whether or not their extension is installed: an
     * uninstalled one earns a precise error, not "unknown field".
     *
     * @return list<string>
     */
    public function allowedFields(): array
    {
        return array_values(array_unique(array_merge(
            self::FIXED_FIELDS,
            $this->providerFields->declaredFor('tl_theme'),
        )));
    }

    /**
     * @return array{errors: list<string>, applied: int, applied_keys: list<string>}
     */
    public function apply(ThemeModel $theme, array $input): array
    {
        $errors = [];
        $applied = 0;
        $appliedKeys = [];

        if (\array_key_exists('name', $input)) {
            $value = (string) $input['name'];
            if ($value === '') {
                $errors[] = 'name must not be empty';
            } else {
                $theme->name = mb_substr($value, 0, 128);
                ++$applied;
                $appliedKeys[] = 'name';
            }
        }

        if (\array_key_exists('author', $input)) {
            $value = (string) $input['author'];
            if ($value === '') {
                $errors[] = 'author must not be empty';
            } else {
                $theme->author = mb_substr($value, 0, 128);
                ++$applied;
                $appliedKeys[] = 'author';
            }
        }

        if (\array_key_exists('templates', $input)) {
            $theme->templates = (string) $input['templates'];
            ++$applied;
            $appliedKeys[] = 'templates';
        }

        if (\array_key_exists('folders', $input)) {
            [$serialized, $foldersErr] = $this->mapFolders($input['folders']);
            if ($foldersErr !== null) {
                $errors[] = $foldersErr;
            } else {
                $theme->folders = $serialized;
                ++$applied;
                $appliedKeys[] = 'folders';
            }
        }

        if (\array_key_exists('screenshot', $input)) {
            [$uuid, $screenshotErr] = $this->mapScreenshot($input['screenshot']);
            if ($screenshotErr !== null) {
                $errors[] = $screenshotErr;
            } else {
                $theme->screenshot = $uuid;
                ++$applied;
                $appliedKeys[] = 'screenshot';
            }
        }

        // Providers last: their apply() may reject the value (the bootstrap
        // provider compiles the SCSS it is handed), and the Tool layer refuses
        // to save when errors came back — so a rejected value writes nothing,
        // not even the fields that mapped cleanly above.
        $fromProviders = $this->providerFields->apply('tl_theme', $theme, $input);
        $applied += \count($fromProviders['applied']);
        $appliedKeys = array_merge($appliedKeys, $fromProviders['applied']);
        $errors = array_merge($errors, $fromProviders['errors']);

        return ['errors' => $errors, 'applied' => $applied, 'applied_keys' => $appliedKeys];
    }

    /**
     * @return array{0: ?string, 1: ?string} [serialized-blob, error]
     */
    private function mapFolders(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [null, null];
        }
        if (!\is_array($value) || !array_is_list($value)) {
            return [null, 'folders must be a list of file paths'];
        }

        $uuids = [];
        foreach ($value as $path) {
            if (!\is_string($path) || $path === '') {
                return [null, 'folders entries must be non-empty strings (file paths)'];
            }
            $model = FilesModel::findByPath($path);
            if ($model === null) {
                return [null, sprintf('folders entry "%s" does not exist in tl_files — register it first via file_upload / folder_create', $path)];
            }
            $uuids[] = $model->uuid;
        }

        return [serialize($uuids), null];
    }

    /**
     * @return array{0: ?string, 1: ?string} [binary-uuid, error]
     */
    private function mapScreenshot(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [null, null];
        }
        if (!\is_string($value)) {
            return [null, 'screenshot must be a file path or null'];
        }
        $model = FilesModel::findByPath($value);
        if ($model === null) {
            return [null, sprintf('screenshot file "%s" does not exist in tl_files', $value)];
        }
        if ($model->type !== 'file') {
            return [null, 'screenshot must point at a file, not a folder'];
        }

        return [(string) $model->uuid, null];
    }

    /**
     * Helper for callers — serializes a list of UUIDs the same way Contao expects.
     */
    public static function uuidListToBlob(array $uuids): string
    {
        return serialize($uuids);
    }

    /**
     * Helper for callers — deserializes a blob back into a list.
     *
     * @return list<string>
     */
    public static function blobToUuidList(mixed $blob): array
    {
        $list = StringUtil::deserialize($blob, true);

        return array_values(array_filter(
            $list,
            static fn ($v): bool => \is_string($v) && $v !== '',
        ));
    }
}
