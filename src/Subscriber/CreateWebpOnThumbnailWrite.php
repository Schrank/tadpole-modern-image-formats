<?php
declare(strict_types=1);

namespace Tadpole\ModernImageFormats\Subscriber;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\RepositoryIterator;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Tadpole\ModernImageFormats\Service\ImageConverter as ImageConverterService;

class CreateWebpOnThumbnailWrite implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityRepository      $mediaThumbnailRepository,
        private readonly ImageConverterService $imageConverterService,
    )
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [EntityWrittenContainerEvent::class => 'createWebp'];
    }

    public function createWebp(EntityWrittenContainerEvent $event): void
    {
        $mediaThumbnailEvent = $event->getEventByEntityName('media_thumbnail');
        if ($mediaThumbnailEvent) {
            $this->createThumbnailsWebp($mediaThumbnailEvent);
        }
    }

    private function createThumbnailsWebp($event): void
    {
        $thumbnailIds = [];
        foreach ($event->getWriteResults() as $result) {
            /** @var $result EntityWriteResult */
            $payload = $result->getPayload();
            if (isset($payload['media_id'])) {
                $thumbnailIds[] = $payload['media_id'];
            }
        }

        $context = Context::createDefaultContext();
        $mediaThumbnailIterator = new RepositoryIterator(
            $this->mediaThumbnailRepository,
            $context,
            $this->imageConverterService->createThumbnailCriteria($thumbnailIds)
        );

        $this->imageConverterService->generateWebpImages($mediaThumbnailIterator);
    }
}
