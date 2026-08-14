<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\News;

use Contao\NewsModel;
use Netzhirsch\ContaoMcpBundle\Service\FieldProviderRegistry;

/**
 * Flattens a NewsModel into a JSON-friendly array. Output is symmetric with what
 * News\Tool's create/update accept: every field a user can write, they can also read.
 *
 * Extension fields from available FieldProviders (e.g. terminal42/contao-changelanguage's
 * languageMain) are merged in at the end so reads stay in sync with what writes
 * accept.
 */
final class Serializer
{
    public function __construct(
        private readonly FieldProviderRegistry $providers,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(NewsModel $n): array
    {
        $core = [
            'id' => (int) $n->id,
            'archive_id' => (int) $n->pid,

            // Title
            'headline' => (string) $n->headline,
            'featured' => (bool) $n->featured,
            'alias' => (string) $n->alias,
            'author_id' => (int) $n->author,

            // Date (date and time columns hold the same combined unix timestamp post-save)
            'date' => $n->date ? date('Y-m-d', (int) $n->date) : null,
            'time' => $n->time ? date('H:i:s', (int) $n->time) : null,

            // Source/Link
            'source' => (string) $n->source,
            'jumpTo' => (int) $n->jumpTo,
            'articleId' => (int) $n->articleId,
            'url' => (string) $n->url,
            'target' => (bool) $n->target,
            'linkText' => (string) $n->linkText,
            'canonicalLink' => (string) $n->canonicalLink,

            // SEO
            'pageTitle' => (string) $n->pageTitle,
            'robots' => (string) $n->robots,
            'description' => (string) $n->description,

            // Teaser
            'subheadline' => (string) $n->subheadline,
            'teaser' => (string) $n->teaser,

            // Image
            'addImage' => (bool) $n->addImage,
            'overwriteMeta' => (bool) $n->overwriteMeta,
            'singleSRC' => $n->singleSRC ? bin2hex($n->singleSRC) : null,
            'alt' => (string) $n->alt,
            'imageTitle' => (string) $n->imageTitle,
            'imageUrl' => (string) $n->imageUrl,
            'fullsize' => (bool) $n->fullsize,
            'caption' => (string) $n->caption,
            'floating' => (string) $n->floating,

            // Enclosure
            'addEnclosure' => (bool) $n->addEnclosure,

            // Expert
            'cssClass' => (string) $n->cssClass,
            'searchIndexer' => (string) $n->searchIndexer,

            // Publish
            'published' => (bool) $n->published,
            'start' => $n->start ? date(\DATE_ATOM, (int) $n->start) : null,
            'stop' => $n->stop ? date(\DATE_ATOM, (int) $n->stop) : null,
        ];

        foreach ($this->providers->availableForTable('tl_news') as $provider) {
            $core = array_merge($core, $provider->serialize($n));
        }

        return $core;
    }
}
