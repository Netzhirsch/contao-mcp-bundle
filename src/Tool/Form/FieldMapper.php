<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Form;

use Contao\FormModel;

/**
 * Maps the MCP `fields` object to FormModel column writes.
 *
 * Most columns are plain strings / booleans. Specials:
 *   - method: enum POST | GET
 *   - format: enum raw | xml — JSON encoding when storeValues=true
 *   - attributes: list<string> of HTML5 attribute names (serialised blob)
 */
final class FieldMapper
{
    private const TEXT_FIELDS = [
        'title' => 'title',
        'alias' => 'alias',
        'confirmation' => 'confirmation',
        'mailer_transport' => 'mailerTransport',
        'recipient' => 'recipient',
        'subject' => 'subject',
        'target_table' => 'targetTable',
        'custom_tpl' => 'customTpl',
        'form_id' => 'formID',
        'allow_tags' => 'allowTags',
    ];

    private const BOOL_FIELDS = [
        'send_via_email' => 'sendViaEmail',
        'skip_empty' => 'skipEmpty',
        'store_values' => 'storeValues',
        'novalidate' => 'novalidate',
        'ajax' => 'ajax',
    ];

    private const METHOD_VALUES = ['POST', 'GET'];
    private const FORMAT_VALUES = ['raw', 'xml'];

    /**
     * @return array{errors: list<string>, applied: int, applied_keys: list<string>}
     */
    public function apply(FormModel $f, array $input, bool $isCreate): array
    {
        $errors = [];
        $applied = 0;
        $appliedKeys = [];

        if (\array_key_exists('title', $input)) {
            $value = trim((string) $input['title']);
            if ($value === '') {
                $errors[] = 'title must not be empty';
            } else {
                $f->title = mb_substr($value, 0, 255);
                ++$applied;
                $appliedKeys[] = 'title';
            }
        } elseif ($isCreate) {
            $errors[] = 'title is required';
        }

        foreach (self::TEXT_FIELDS as $key => $column) {
            if ($key === 'title') {
                continue;
            }
            if (\array_key_exists($key, $input)) {
                $f->{$column} = (string) ($input[$key] ?? '');
                ++$applied;
                $appliedKeys[] = $key;
            }
        }

        foreach (self::BOOL_FIELDS as $key => $column) {
            if (\array_key_exists($key, $input)) {
                $f->{$column} = (bool) $input[$key] ? 1 : 0;
                ++$applied;
                $appliedKeys[] = $key;
            }
        }

        if (\array_key_exists('jump_to', $input)) {
            $f->jumpTo = (int) $input['jump_to'];
            ++$applied;
            $appliedKeys[] = 'jump_to';
        }

        if (\array_key_exists('method', $input)) {
            $value = strtoupper((string) $input['method']);
            if (!\in_array($value, self::METHOD_VALUES, true)) {
                $errors[] = 'method must be POST or GET';
            } else {
                $f->method = $value;
                ++$applied;
                $appliedKeys[] = 'method';
            }
        }

        if (\array_key_exists('format', $input)) {
            $value = (string) $input['format'];
            if (!\in_array($value, self::FORMAT_VALUES, true)) {
                $errors[] = 'format must be one of: '.implode(', ', self::FORMAT_VALUES);
            } else {
                $f->format = $value;
                ++$applied;
                $appliedKeys[] = 'format';
            }
        }

        if (\array_key_exists('attributes', $input)) {
            $value = $input['attributes'];
            if ($value === null || $value === '') {
                $f->attributes = '';
                ++$applied;
                $appliedKeys[] = 'attributes';
            } elseif (!\is_array($value) || !array_is_list($value)) {
                $errors[] = 'attributes must be a list of strings';
            } else {
                $clean = [];
                $bad = false;
                foreach ($value as $entry) {
                    if (!\is_string($entry)) {
                        $errors[] = 'attributes entries must be strings';
                        $bad = true;
                        break;
                    }
                    $clean[] = $entry;
                }
                if (!$bad) {
                    $f->attributes = $clean === [] ? '' : serialize($clean);
                    ++$applied;
                    $appliedKeys[] = 'attributes';
                }
            }
        }

        return ['errors' => $errors, 'applied' => $applied, 'applied_keys' => $appliedKeys];
    }
}
