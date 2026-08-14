<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Faq;

use Contao\FaqModel;
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
    public function summary(FaqModel $f): array
    {
        $core = [
            'id' => (int) $f->id,
            'category_id' => (int) $f->pid,
            'sorting' => (int) $f->sorting,

            'question' => (string) $f->question,
            'alias' => (string) $f->alias,
            'author_id' => (int) $f->author,
            'answer' => $f->answer !== null ? (string) $f->answer : null,

            'pageTitle' => (string) $f->pageTitle,
            'robots' => (string) $f->robots,
            'description' => $f->description !== null ? (string) $f->description : null,

            'addImage' => (bool) $f->addImage,
            'overwriteMeta' => (bool) $f->overwriteMeta,
            'singleSRC' => $f->singleSRC ? bin2hex($f->singleSRC) : null,
            'alt' => $f->alt !== null ? (string) $f->alt : null,
            'imageTitle' => $f->imageTitle !== null ? (string) $f->imageTitle : null,
            'imageUrl' => $f->imageUrl !== null ? (string) $f->imageUrl : null,
            'size' => (string) $f->size,
            'fullsize' => (bool) $f->fullsize,
            'caption' => $f->caption !== null ? (string) $f->caption : null,
            'floating' => (string) $f->floating,

            'addEnclosure' => (bool) $f->addEnclosure,

            'noComments' => (bool) $f->noComments,
            'published' => (bool) $f->published,
            'searchIndexer' => (string) $f->searchIndexer,
        ];

        foreach ($this->providers->availableForTable('tl_faq') as $provider) {
            $core = array_merge($core, $provider->serialize($f));
        }

        return $core;
    }
}
