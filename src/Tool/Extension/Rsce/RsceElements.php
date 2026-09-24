<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Extension\Rsce;

use Contao\CoreBundle\Framework\ContaoFramework;

/**
 * What this bundle knows about RockSolid Custom Elements
 * (madeyourday/contao-rocksolid-custom-elements) — without depending on it.
 *
 * RSCE is common under Contao themes (themore and many agency themes), and it
 * keeps everything that makes an element configurable out of the DCA until the
 * edit mask opens: the palette, the virtual fields, and the one JSON column
 * their values are stored in. The write tools read the DCA, so an RSCE element
 * could be created but not configured. RSCE does this for content elements,
 * frontend modules and form fields alike — an element's config registers it as
 * all three unless it says otherwise — and three places per table need the
 * missing half, all of them from here:
 *
 *   - the table's FieldMapper, for the palette of an RSCE type (RscePalette);
 *   - the table's DataProvider, which writes rsce_data (RsceData);
 *   - the table's *_palette_get, which lists the type's fields ({@see describe()}).
 *
 * The type's config comes from RSCE itself (CustomElements::getConfigByType),
 * so theme template folders, Twig templates and the fallback lookup resolve
 * exactly as in the backend. Every call goes through a string class name: the
 * extension is optional, and nothing here may fail to load without it.
 */
final class RsceElements
{
    public const PACKAGE = 'madeyourday/contao-rocksolid-custom-elements';

    private const CUSTOM_ELEMENTS = 'MadeYourDay\\RockSolidCustomElements\\CustomElements';

    private const SLIDER = 'MadeYourDay\\RockSolidSlider\\Module\\Slider';

    /**
     * Per table, the tool that lists a type's keys (named in refusals) and the
     * tool that reads a record (named when the keys cannot be listed).
     */
    private const TOOLS = [
        'tl_content' => ['content_palette_get', 'content_get'],
        'tl_module' => ['module_palette_get', 'module_get'],
        'tl_form_field' => ['form_field_palette_get', 'form_field_get'],
    ];

    /** @var array<string, array<string, mixed>|null> */
    private array $configs = [];

    public function __construct(
        private readonly ContaoFramework $framework,
    ) {
    }

    public function isAvailable(): bool
    {
        return class_exists(self::CUSTOM_ELEMENTS);
    }

    /**
     * RSCE registers its types under their template name, which always starts
     * with `rsce_` — RSCE itself decides by that prefix whether to build a form.
     */
    public static function isRsceType(string $type): bool
    {
        return str_starts_with($type, 'rsce_');
    }

    public function handles(string $type): bool
    {
        return self::isRsceType($type) && $this->isAvailable();
    }

    /**
     * The type's rsce_*_config.php, or null when RSCE is missing or the file
     * cannot be found or read. Memoised: every write of a tree asks per node.
     *
     * @return array<string, mixed>|null
     */
    public function config(string $type): ?array
    {
        if (!$this->handles($type)) {
            return null;
        }

        if (\array_key_exists($type, $this->configs)) {
            return $this->configs[$type];
        }

        $this->framework->initialize();

        try {
            $config = \call_user_func([self::CUSTOM_ELEMENTS, 'getConfigByType'], $type);
        } catch (\Throwable) {
            // A config file that throws breaks the backend form just as well.
            // Without it the column is still writable, only without key checks.
            $config = null;
        }

        return $this->configs[$type] = \is_array($config) ? $config : null;
    }

    /**
     * The palette the edit mask would build for this type, or null when there
     * is no config to build it from.
     *
     * @param array<string, mixed> $dca   the loaded DCA of $table
     * @param string               $table tl_content, tl_module or tl_form_field
     */
    public function paletteFor(string $type, array $dca, string $table = 'tl_content'): ?string
    {
        $config = $this->config($type);

        return $config === null ? null : RscePalette::build($config, $dca, class_exists(self::SLIDER), $table);
    }

    /**
     * Merges a caller's rsce_data into the stored JSON and returns the new
     * column value.
     *
     * @throws \InvalidArgumentException when the input is not a JSON object, or
     *                                   names a key the type does not have
     */
    public function write(string $type, mixed $input, mixed $stored, string $table = 'tl_content'): string
    {
        $current = RsceData::decode($stored);
        $patch = RsceData::convert(RsceData::parse($input), $this->fieldsOf($type), $current, $type, '', self::tools($table)[0]);

        return RsceData::encode(RsceData::merge($current, $patch));
    }

    /**
     * Whether a new column value differs from the stored one in content, not
     * just in escaping.
     */
    public static function differs(string $new, mixed $stored): bool
    {
        return RsceData::decode($new) !== RsceData::decode($stored);
    }

    /**
     * The rsce_data part of the table's *_palette_get: how the column is
     * written and which keys this type has.
     *
     * @param string $table tl_content, tl_module or tl_form_field
     *
     * @return array<string, mixed>
     */
    public function describe(string $type, string $table = 'tl_content'): array
    {
        $out = [
            'column' => 'rsce_data',
            'format' => 'All settings of this element live in rsce_data, a JSON object with one key per field. '
                .'Pass it as a JSON object (or a JSON string) inside `fields`. It is MERGED into what is stored: '
                .'a key you send replaces that key, a key set to null is removed, keys you leave out stay as they are, and a list is replaced as a whole. '
                .'A field with fixed options takes one of its listed option values, as in the backend. '
                .'Values are stored as the backend stores them — lists of options are serialised, file references become UUIDs, true/false become "1"/"".',
        ];

        $fields = $this->fieldsOf($type);
        if ($fields === null) {
            $out['fields'] = null;
            $out['message'] = sprintf(
                'The config file of "%s" (%s_config.php) could not be read here, so its keys cannot be listed or checked. '
                .'rsce_data is still writable as a JSON object; %s of an existing record shows the keys it stores.',
                $type,
                $type,
                self::tools($table)[1],
            );

            return $out;
        }

        $out['fields'] = RsceData::describe($fields);

        return $out;
    }

    /**
     * @return array{0: string, 1: string} the palette tool and the read tool
     */
    private static function tools(string $table): array
    {
        return self::TOOLS[$table] ?? self::TOOLS['tl_content'];
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function fieldsOf(string $type): ?array
    {
        $config = $this->config($type);
        if ($config === null) {
            return null;
        }

        return \is_array($config['fields'] ?? null) ? $config['fields'] : [];
    }
}
