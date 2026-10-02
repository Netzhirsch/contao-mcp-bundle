<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Service;

use Contao\CalendarBundle\ContaoCalendarBundle;
use Contao\CommentsBundle\ContaoCommentsBundle;
use Contao\FaqBundle\ContaoFaqBundle;
use Contao\NewsBundle\ContaoNewsBundle;
use Contao\NewsletterBundle\ContaoNewsletterBundle;

/**
 * Contao's optional bundles: whether this installation has them, and the
 * answer a tool gives when it does not.
 *
 * The news, calendar and FAQ tools are registered whether or not their bundle
 * is installed. Without it there is no model class, no DCA and no table, and a
 * call ended in "Class Contao\NewsArchiveModel not found" or in a query against
 * the missing table — an internal error that says nothing about the cause.
 * They answer `extension_not_available` instead, the way the comment and
 * newsletter tools always have.
 */
final class OptionalBundles
{
    public const NEWS = 'contao/news-bundle';
    public const CALENDAR = 'contao/calendar-bundle';
    public const FAQ = 'contao/faq-bundle';
    public const COMMENTS = 'contao/comments-bundle';
    public const NEWSLETTER = 'contao/newsletter-bundle';

    /**
     * Bundle class per package. Naming a class that is not installed is safe:
     * ::class does not autoload.
     */
    private const BUNDLE_CLASSES = [
        self::NEWS => ContaoNewsBundle::class,
        self::CALENDAR => ContaoCalendarBundle::class,
        self::FAQ => ContaoFaqBundle::class,
        self::COMMENTS => ContaoCommentsBundle::class,
        self::NEWSLETTER => ContaoNewsletterBundle::class,
    ];

    /**
     * The tables each package brings. Core tables it only extends (tl_module,
     * tl_layout, tl_user …) are not listed — they exist either way.
     */
    private const TABLES = [
        'tl_news' => self::NEWS,
        'tl_news_archive' => self::NEWS,
        'tl_calendar' => self::CALENDAR,
        'tl_calendar_events' => self::CALENDAR,
        'tl_calendar_feed' => self::CALENDAR,
        'tl_faq' => self::FAQ,
        'tl_faq_category' => self::FAQ,
        'tl_comments' => self::COMMENTS,
        'tl_comments_notify' => self::COMMENTS,
        'tl_newsletter' => self::NEWSLETTER,
        'tl_newsletter_channel' => self::NEWSLETTER,
        'tl_newsletter_deny_list' => self::NEWSLETTER,
        'tl_newsletter_recipients' => self::NEWSLETTER,
    ];

    /**
     * @param array<string, bool> $installed package => installed, overriding
     *                                       the class check (tests only)
     */
    public function __construct(
        private readonly array $installed = [],
    ) {
    }

    public function has(string $package): bool
    {
        if (!isset(self::BUNDLE_CLASSES[$package])) {
            throw new \InvalidArgumentException(sprintf('"%s" is not one of Contao\'s optional bundles.', $package));
        }

        return $this->installed[$package] ?? class_exists(self::BUNDLE_CLASSES[$package]);
    }

    /**
     * @return array{error: string, message: string, required_extension: string}|null
     */
    public function unavailable(string $package): ?array
    {
        if ($this->has($package)) {
            return null;
        }

        return [
            'error' => 'extension_not_available',
            'message' => sprintf('The "%s" extension is not installed in this Contao instance.', $package),
            'required_extension' => $package,
        ];
    }

    /**
     * The refusal for a table whose bundle is missing; null for a table that
     * belongs to the core or to a bundle that is installed.
     *
     * @return array{error: string, message: string, required_extension: string}|null
     */
    public function unavailableForTable(string $table): ?array
    {
        $package = self::TABLES[$table] ?? null;

        return $package === null ? null : $this->unavailable($package);
    }
}
