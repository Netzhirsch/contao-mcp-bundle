<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Extension\DeepL;

use Netzhirsch\ContaoMcpBundle\Tool\Article\Tool as ArticleTool;
use Netzhirsch\ContaoMcpBundle\Tool\Calendar\Tool as CalendarTool;
use Netzhirsch\ContaoMcpBundle\Tool\CalendarEvent\Tool as CalendarEventTool;
use Netzhirsch\ContaoMcpBundle\Tool\Content\Tool as ContentTool;
use Netzhirsch\ContaoMcpBundle\Tool\Faq\Tool as FaqTool;
use Netzhirsch\ContaoMcpBundle\Tool\FaqCategory\Tool as FaqCategoryTool;
use Netzhirsch\ContaoMcpBundle\Tool\Form\Tool as FormTool;
use Netzhirsch\ContaoMcpBundle\Tool\FormField\Tool as FormFieldTool;
use Netzhirsch\ContaoMcpBundle\Tool\Module\Tool as ModuleTool;
use Netzhirsch\ContaoMcpBundle\Tool\News\Tool as NewsTool;
use Netzhirsch\ContaoMcpBundle\Tool\NewsArchive\Tool as NewsArchiveTool;
use Netzhirsch\ContaoMcpBundle\Tool\Page\Tool as PageTool;

/**
 * Writes translated values back through the table's OWN `*_update` tool.
 *
 * This exists so translation never becomes a second way into the database. The
 * update tools are where field validation, the Versions snapshot, the tl_log
 * entry and the changed-field reporting live; a shortcut straight to the Model
 * would reproduce roughly half of that and quietly lose the rest. The price is
 * this dispatch table — and the dispatch table is visible, whereas a missing
 * version history is not.
 *
 * Two calling conventions, because the update tools have two: most take named
 * arguments per column, the palette-driven ones (`tl_content`, `tl_form*`,
 * `tl_module`) take a `fields` object. Named arguments are spread from a
 * string-keyed array, so a field the target method does not declare fails
 * loudly with an \Error rather than being written blind.
 */
final class RecordSaver
{
    private const NAMED = 'named';
    private const FIELDS = 'fields';

    /**
     * Which table is written how. Public and static so a test can assert that
     * every table {@see TranslatableFields} offers actually has a way back into
     * the database — a translatable table with no updater would only fail at
     * save time, on real content.
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
            ];
        }

        try {
            if (self::CONVENTIONS[$table] === self::FIELDS) {
                return $tool->update($id, $fields);
            }

            return $tool->update(...array_merge(['id' => $id], $fields));
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
            default => null,
        };
    }
}
