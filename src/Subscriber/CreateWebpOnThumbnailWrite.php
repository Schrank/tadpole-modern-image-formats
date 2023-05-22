<?php
declare(strict_types=1);

namespace Tadpole\ModernImageFormats\Subscriber;

use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CreateWebpOnThumbnailWrite implements EventSubscriberInterface
{

    public static function getSubscribedEvents(): array
    {
        return [EntityWrittenContainerEvent::class => 'createWebp'];
    }

    public function createWebp(EntityWrittenContainerEvent $event): void
    {
        $event = $event->getEventByEntityName('media_thumbnail');
        if (!$event) {
            return;
        }
        foreach ($event->getWriteResults() as $result) {
            /** @var $result EntityWriteResult */


        }
    }
}
