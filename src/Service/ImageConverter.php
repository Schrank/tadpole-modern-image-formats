<?php
declare(strict_types=1);

namespace Tadpole\ModernImageFormats\Service;

use Shopware\Core\Content\Media\Aggregate\MediaThumbnail\MediaThumbnailEntity;
use Shopware\Core\Content\Media\Exception\ThumbnailNotSupportedException;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Content\Media\Pathname\UrlGeneratorInterface;
use Shopware\Core\Framework\Adapter\Console\ShopwareStyle;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\RepositoryIterator;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ImageConverter
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    )
    {

    }

    private function convertToWebp(): void
    {
        $context = Context::createDefaultContext();
        $mediaIterator = new RepositoryIterator($this->mediaRepository, $context, $this->createCriteria());

        $totalMediaCount = $mediaIterator->getTotal();
        $this->io->comment(sprintf('Generating avif and webp images for %d files. This may take some time...', $totalMediaCount));
        $this->io->progressStart($totalMediaCount);

        $result = $this->generateWebpImages($mediaIterator, $context);

        $this->io->progressFinish();
        $this->io->table(
            ['Action', 'Number of Media Entities'],
            [
                ['Generated', $result['generated']],
                ['Skipped', $result['skipped']],
                ['Errors', $result['errored']],
            ]
        );

        if (is_countable($result['errors']) ? \count($result['errors']) : 0) {
            if ($this->io->isVerbose()) {
                $this->io->table(
                    ['Error messages'],
                    $result['errors']
                );
            } else {
                $this->io->warning(\sprintf('Thumbnail generation for %d file(s) failed. Use -v to show the files', is_countable($result['errors']) ? \count($result['errors']) : 0));
            }
        }
    }

    private function createCriteria(): Criteria
    {
        $criteria = new Criteria();
        $criteria->setOffset(0);
        $criteria->setLimit($this->batchSize);
        $criteria->addFilter(new EqualsFilter('media.mediaFolder.configuration.createThumbnails', true));
        $criteria->addAssociation('thumbnails');
        $criteria->addAssociation('mediaFolder.configuration.mediaThumbnailSizes');

        return $criteria;
    }

    private function generateWebpImages(RepositoryIterator $iterator, Context $context): array
    {
        while (($result = $iterator->fetch()) !== null) {
            /** @var MediaEntity $media */
            foreach ($result->getEntities() as $media) {
                $filePath = $this->urlGenerator->getRelativeMediaUrl($media);
                /** @var string $file */
                $file = $this->getFileSystem($media)->read($filePath);
                $image = @imagecreatefromstring($file);
                if ($image === false) {
                    throw new ThumbnailNotSupportedException($media->getId());
                }
                $url = $this->urlGenerator->getRelativeThumbnailUrl(
                    $media,
                    (new MediaThumbnailEntity())->assign(['width' => , 'height' => $size->getHeight()])
                );
                imagewebp($image, $thumbnailPath);

            }
            $this->io->progressAdvance($result->count());


            // TODO fix return to be array

        }
        return [];


        return 1;

        $generated = 0;
        $skipped = 0;
        $errored = 0;
        $errors = [];

        while (($result = $iterator->fetch()) !== null) {
            /** @var MediaEntity $media */
            foreach ($result->getEntities() as $media) {
                try {
                    if ($this->thumbnailService->updateThumbnails($media, $context, $this->isStrict) > 0) {
                        ++$generated;
                    } else {
                        ++$skipped;
                    }
                } catch (\Throwable $e) {
                    ++$errored;
                    $errors[] = [sprintf('Cannot process file %s (id: %s) due error: %s', $media->getFileName(), $media->getId(), $e->getMessage())];
                }
            }
            $this->io->progressAdvance($result->count());
        }

        return [
            'generated' => $generated,
            'skipped' => $skipped,
            'errored' => $errored,
            'errors' => $errors,
        ];

    }
}
