<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\CalendarEvent;

use Contao\CalendarEventsModel;
use Netzhirsch\ContaoMcpBundle\Service\FieldProviderRegistry;

final class Serializer
{
    public function __construct(
        private readonly FieldProviderRegistry $providers,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(CalendarEventsModel $e): array
    {
        $core = [
            'id' => (int) $e->id,
            'calendar_id' => (int) $e->pid,

            'title' => (string) $e->title,
            'featured' => (bool) $e->featured,
            'alias' => (string) $e->alias,
            'author_id' => (int) $e->author,

            // Date / time
            'addTime' => (bool) $e->addTime,
            'startDate' => $e->startDate ? date('Y-m-d', (int) $e->startDate) : null,
            'endDate' => $e->endDate ? date('Y-m-d', (int) $e->endDate) : null,
            'startTime' => $e->startTime ? date('H:i:s', (int) $e->startTime) : null,
            'endTime' => $e->endTime ? date('H:i:s', (int) $e->endTime) : null,

            // Recurring
            'recurring' => (bool) $e->recurring,
            'repeatEach' => (string) $e->repeatEach,
            'repeatEnd' => $e->repeatEnd ? date('Y-m-d', (int) $e->repeatEnd) : null,
            'recurrences' => (int) $e->recurrences,

            // Location
            'location' => (string) $e->location,
            'address' => (string) $e->address,

            // SEO / meta
            'pageTitle' => (string) $e->pageTitle,
            'robots' => (string) $e->robots,
            'description' => $e->description !== null ? (string) $e->description : null,
            'canonicalLink' => (string) $e->canonicalLink,

            // Teaser
            'teaser' => $e->teaser !== null ? (string) $e->teaser : null,

            // Image
            'addImage' => (bool) $e->addImage,
            'overwriteMeta' => (bool) $e->overwriteMeta,
            'singleSRC' => $e->singleSRC ? bin2hex($e->singleSRC) : null,
            'alt' => $e->alt !== null ? (string) $e->alt : null,
            'imageTitle' => $e->imageTitle !== null ? (string) $e->imageTitle : null,
            'imageUrl' => $e->imageUrl !== null ? (string) $e->imageUrl : null,
            'size' => (string) $e->size,
            'fullsize' => (bool) $e->fullsize,
            'caption' => $e->caption !== null ? (string) $e->caption : null,
            'floating' => (string) $e->floating,

            // Enclosure
            'addEnclosure' => (bool) $e->addEnclosure,

            // Source / link
            'source' => (string) $e->source,
            'jumpTo' => (int) $e->jumpTo,
            'articleId' => (int) $e->articleId,
            'url' => (string) $e->url,
            'target' => (bool) $e->target,
            'linkText' => (string) $e->linkText,

            // Expert
            'cssClass' => (string) $e->cssClass,
            'noComments' => (bool) $e->noComments,
            'searchIndexer' => (string) $e->searchIndexer,

            // Publish
            'published' => (bool) $e->published,
            'start' => $e->start ? date(\DATE_ATOM, (int) $e->start) : null,
            'stop' => $e->stop ? date(\DATE_ATOM, (int) $e->stop) : null,
        ];

        foreach ($this->providers->availableForTable('tl_calendar_events') as $provider) {
            $core = array_merge($core, $provider->serialize($e));
        }

        return $core;
    }
}
