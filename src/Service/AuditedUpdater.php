<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Service;

use Netzhirsch\ContaoMcpBundle\Tool\Article\Tool as ArticleTool;
use Netzhirsch\ContaoMcpBundle\Tool\Calendar\Tool as CalendarTool;
use Netzhirsch\ContaoMcpBundle\Tool\CalendarEvent\Tool as CalendarEventTool;
use Netzhirsch\ContaoMcpBundle\Tool\Content\Tool as ContentTool;
use Netzhirsch\ContaoMcpBundle\Tool\Faq\Tool as FaqTool;
use Netzhirsch\ContaoMcpBundle\Tool\FaqCategory\Tool as FaqCategoryTool;
use Netzhirsch\ContaoMcpBundle\Tool\Form\Tool as FormTool;
use Netzhirsch\ContaoMcpBundle\Tool\FormField\Tool as FormFieldTool;
use Netzhirsch\ContaoMcpBundle\Tool\Layout\Tool as LayoutTool;
use Netzhirsch\ContaoMcpBundle\Tool\Member\Tool as MemberTool;
use Netzhirsch\ContaoMcpBundle\Tool\MemberGroup\Tool as MemberGroupTool;
use Netzhirsch\ContaoMcpBundle\Tool\Module\Tool as ModuleTool;
use Netzhirsch\ContaoMcpBundle\Tool\News\Tool as NewsTool;
use Netzhirsch\ContaoMcpBundle\Tool\NewsArchive\Tool as NewsArchiveTool;
use Netzhirsch\ContaoMcpBundle\Tool\Page\Tool as PageTool;
use Netzhirsch\ContaoMcpBundle\Tool\Theme\Tool as ThemeTool;

/**
 * Writes a set of fields to a record through the table's OWN `*_update` tool.
 *
 * This exists so that a tool which changes records for some other reason —
 * translating them, patching one substring — never becomes a second way into
 * the database. The update tools are where field validation, the Versions
 * snapshot, the tl_log entry and the changed-field reporting live; a shortcut
 * straight to the Model would reproduce roughly half of that and quietly lose
 * the rest. The price is this dispatch table — and the dispatch table is
 * visible, whereas a missing version history is not.
 *
 * Two calling conventions, because the update tools have two: most take a
 * `fields` object, the older ones take named arguments per column. Named
 * arguments are spread from a string-keyed array, so a field the target method
 * does not declare fails loudly with an \Error rather than being written blind.
 */
final class AuditedUpdater
{
    private const NAMED = 'named';
    private const FIELDS = 'fields';

    /**
     * Which table is written how. Public and static so a caller (and a test)
     * can ask what is writable without instantiating sixteen tools.
     *
     * @var array<string, string>
     */
    public const CONVENTIONS = [
        'tl_page' => self::NAMED,
        'tl_article' => self::NAMED,
        'tl_news' => self::NAMED,
        'tl_news_archive' => self::NAMED,
        'tl_calendar_events' => self::NAMED,
        'tl_calendar' => self::NAMED,
        'tl_faq' => self::NAMED,
        'tl_faq_category' => self::NAMED,
        'tl_content' => self::FIELDS,
        'tl_form' => self::FIELDS,
        'tl_form_field' => self::FIELDS,
        'tl_module' => self::FIELDS,
        'tl_theme' => self::FIELDS,
        'tl_layout' => self::FIELDS,
        'tl_member' => self::FIELDS,
        'tl_member_group' => self::FIELDS,
    ];

    public function __construct(
        private readonly PageTool $pageTool,
        private readonly ArticleTool $articleTool,
        private readonly ContentTool $contentTool,
        private readonly NewsTool $newsTool,
        private readonly NewsArchiveTool $newsArchiveTool,
        private readonly CalendarEventTool $calendarEventTool,
        private readonly CalendarTool $calendarTool,
        private readonly FaqTool $faqTool,
        private readonly FaqCategoryTool $faqCategoryTool,
        private readonly FormTool $formTool,
        private readonly FormFieldTool $formFieldTool,
        private readonly ModuleTool $moduleTool,
        private readonly ThemeTool $themeTool,
        private readonly LayoutTool $layoutTool,
        private readonly MemberTool $memberTool,
        private readonly MemberGroupTool $memberGroupTool,
    ) {
    }

    /**
     * @return list<string>
     */
    public static function tables(): array
    {
        return array_keys(self::CONVENTIONS);
    }

    public function supports(string $table): bool
    {
        return isset(self::CONVENTIONS[$table]);
    }

    /**
     * @param array<string, mixed> $fields column => new value
     *
     * @return array<string, mixed> the update tool's own result
     */
    public function save(string $table, int $id, array $fields): array
    {
        $tool = $this->toolFor($table);

        if ($tool === null) {
            return [
                'error' => 'table_not_writable',
                'message' => sprintf('No audited update tool is wired for "%s".', $table),
                'writable_tables' => self::tables(),
            ];
        }

        try {
            if (self::CONVENTIONS[$table] === self::FIELDS) {
                return $tool->update($id, $fields);
            }

            // A named-parameter tool has one parameter per core field, so a
            // field another bundle hung on the table has nowhere to go — PHP
            // answers with "Unknown named parameter" and the caller sees
            // `save_failed` after the work was already done. That is what
            // stopped entity_field_patch from writing
            // netzhirsch_megamenu_subtitle even though it read it happily.
            //
            // Anything the signature does not declare goes into the `extras`
            // bag instead, which the page and article tools already have for
            // exactly this purpose. The field is still validated there against
            // the live DCA palette — this only routes it, it does not widen
            // what may be written.
            $declared = array_map(
                static fn (\ReflectionParameter $p): string => $p->getName(),
                (new \ReflectionMethod($tool, 'update'))->getParameters(),
            );

            $named = ['id' => $id];
            $extras = [];

            foreach ($fields as $field => $value) {
                if (\in_array($field, $declared, true)) {
                    $named[$field] = $value;
                } else {
                    $extras[$field] = $value;
                }
            }

            if ($extras !== []) {
                if (!\in_array('extras', $declared, true)) {
                    return [
                        'error' => 'field_not_writable',
                        'message' => sprintf(
                            '"%s" has no parameter for %s and no extras bag to route it through. '
                            .'The field may still be readable — try a dry run.',
                            $table,
                            implode(', ', array_map(static fn (string $f): string => '"'.$f.'"', array_keys($extras))),
                        ),
                        'fields' => array_keys($extras),
                    ];
                }

                $named['extras'] = $extras;
            }

            return $tool->update(...$named);
        } catch (\Throwable $e) {
            return [
                'error' => 'save_failed',
                'message' => $e->getMessage(),
                'class' => $e::class,
            ];
        }
    }

    private function toolFor(string $table): ?object
    {
        return match ($table) {
            'tl_page' => $this->pageTool,
            'tl_article' => $this->articleTool,
            'tl_news' => $this->newsTool,
            'tl_news_archive' => $this->newsArchiveTool,
            'tl_calendar_events' => $this->calendarEventTool,
            'tl_calendar' => $this->calendarTool,
            'tl_faq' => $this->faqTool,
            'tl_faq_category' => $this->faqCategoryTool,
            'tl_content' => $this->contentTool,
            'tl_form' => $this->formTool,
            'tl_form_field' => $this->formFieldTool,
            'tl_module' => $this->moduleTool,
            'tl_theme' => $this->themeTool,
            'tl_layout' => $this->layoutTool,
            'tl_member' => $this->memberTool,
            'tl_member_group' => $this->memberGroupTool,
            default => null,
        };
    }
}
