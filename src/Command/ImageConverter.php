<?php
declare(strict_types=1);

namespace Tadpole\ModernImageFormats\Command;

use League\Flysystem\FilesystemOperator;
use Shopware\Core\Content\Media\Aggregate\MediaFolderConfiguration\MediaFolderConfigurationEntity;
use Shopware\Core\Content\Media\Aggregate\MediaThumbnail\MediaThumbnailEntity;
use Shopware\Core\Content\Media\Aggregate\MediaThumbnailSize\MediaThumbnailSizeEntity;
use Shopware\Core\Content\Media\Exception\ThumbnailCouldNotBeSavedException;
use Shopware\Core\Content\Media\Exception\ThumbnailNotSupportedException;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Content\Media\MediaType\ImageType;
use Shopware\Core\Content\Media\MediaType\MediaType;
use Shopware\Core\Content\Media\Pathname\UrlGeneratorInterface;
use Shopware\Core\Framework\Adapter\Console\ShopwareStyle;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\RepositoryIterator;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'media:image:convert',
    description: 'Converts all images to have webp copies',
)]
class ImageConverter extends Command
{
    private int $batchSize = 50;
    private ShopwareStyle $io;

    public function __construct(
        private readonly EntityRepository      $mediaThumbnailRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly FilesystemOperator    $filesystemPublic,
        private readonly FilesystemOperator    $filesystemPrivate,
        string                                 $name = null)
    {
        parent::__construct($name);
    }

    protected function configure(): void
    {

    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new ShopwareStyle($input, $output);
        $this->convertToWebp();

        return 0;
    }

    private function convertToWebp(): void
    {
        $context = Context::createDefaultContext();
        $mediaThumbnailIterator = new RepositoryIterator($this->mediaThumbnailRepository, $context, $this->createCriteria());

        $totalMediaThumbnailCount = $mediaThumbnailIterator->getTotal();
        $this->io->comment(
            sprintf('Generating webp images for %d thumbnails. This may take some time...', $totalMediaThumbnailCount)
        );
        $this->io->progressStart($totalMediaThumbnailCount);

        $result = $this->generateWebpImages($mediaThumbnailIterator, $context);

        $this->io->progressFinish();
    }

    private function createCriteria(): Criteria
    {
        $criteria = new Criteria();
        $criteria->setOffset(0);
        $criteria->setLimit($this->batchSize);
        $criteria->addAssociation('media.mediaFolder.configuration.mediaThumbnailSizes');

        return $criteria;
    }

    private function generateWebpImages(RepositoryIterator $iterator, Context $context): array
    {
        while (($result = $iterator->fetch()) !== null) {
            /** @var MediaThumbnailEntity $mediaThumbnail */
            foreach ($result->getEntities() as $mediaThumbnail) {
                $filePath = $this->urlGenerator->getRelativeMediaUrl($mediaThumbnail->getMedia());
                /** @var string $file */
                $file = $this->getFileSystem($mediaThumbnail->getMedia())->read($filePath);
                $image = @imagecreatefromstring($file);
                if ($image === false) {
                    throw new ThumbnailNotSupportedException($mediaThumbnail->getId());
                }
                $originalImageSize = $this->getOriginalImageSize($image);
                $expectedthumbnailSize = new MediaThumbnailSizeEntity();
                $mediaFolder = $mediaThumbnail->getMedia()->getMediaFolder();
                $expectedthumbnailSize->assign([
                        'height' => $mediaThumbnail->getHeight(),
                        'width' => $mediaThumbnail->getWidth(),
                        'mediaFolderConfigurations' => $mediaFolder->getConfiguration()]
                );
                $thumbnailSize = $this->calculateThumbnailSize($originalImageSize, $expectedthumbnailSize, $mediaFolder->getConfiguration());
                $webpImage = $this->createNewImage($image, $mediaThumbnail->getMedia()->getMediaType(), $originalImageSize, $thumbnailSize);
                $webpFilePath = $this->urlGenerator->getRelativeThumbnailUrl(
                        $mediaThumbnail->getMedia(),
                        $mediaThumbnail
                    ) . '.webp';

                $this->writeThumbnail($webpImage, $mediaThumbnail->getMedia(), $webpFilePath, 80);
            }
            $this->io->progressAdvance($result->count());
        }
        return [];
    }

    /**
     * @param array{width: int, height: int} $imageSize
     *
     * @return array{width: int, height: int}
     */
    private function calculateThumbnailSize(
        array                          $imageSize,
        MediaThumbnailSizeEntity       $preferredThumbnailSize,
        MediaFolderConfigurationEntity $config
    ): array
    {
        if (!$config->getKeepAspectRatio() || $preferredThumbnailSize->getWidth() !== $preferredThumbnailSize->getHeight()) {
            $calculatedWidth = $preferredThumbnailSize->getWidth();
            $calculatedHeight = $preferredThumbnailSize->getHeight();

            $useOriginalSizeInThumbnails = $imageSize['width'] < $calculatedWidth || $imageSize['height'] < $calculatedHeight;

            return $useOriginalSizeInThumbnails ? [
                'width' => $imageSize['width'],
                'height' => $imageSize['height'],
            ] : [
                'width' => $calculatedWidth,
                'height' => $calculatedHeight,
            ];
        }

        if ($imageSize['width'] >= $imageSize['height']) {
            $aspectRatio = $imageSize['height'] / $imageSize['width'];

            $calculatedWidth = $preferredThumbnailSize->getWidth();
            $calculatedHeight = (int)ceil($preferredThumbnailSize->getHeight() * $aspectRatio);

            $useOriginalSizeInThumbnails = $imageSize['width'] < $calculatedWidth || $imageSize['height'] < $calculatedHeight;

            return $useOriginalSizeInThumbnails ? [
                'width' => $imageSize['width'],
                'height' => $imageSize['height'],
            ] : [
                'width' => $calculatedWidth,
                'height' => $calculatedHeight,
            ];
        }

        $aspectRatio = $imageSize['width'] / $imageSize['height'];

        $calculatedWidth = (int)ceil($preferredThumbnailSize->getWidth() * $aspectRatio);
        $calculatedHeight = $preferredThumbnailSize->getHeight();

        $useOriginalSizeInThumbnails = $imageSize['width'] < $calculatedWidth || $imageSize['height'] < $calculatedHeight;

        return $useOriginalSizeInThumbnails ? [
            'width' => $imageSize['width'],
            'height' => $imageSize['height'],
        ] : [
            'width' => $calculatedWidth,
            'height' => $calculatedHeight,
        ];
    }

    private function getFileSystem(MediaEntity $media): FilesystemOperator
    {
        if ($media->isPrivate()) {
            return $this->filesystemPrivate;
        }

        return $this->filesystemPublic;
    }

    /**
     * @return array{width: int, height: int}
     */
    private function getOriginalImageSize(\GdImage $image): array
    {
        return [
            'width' => imagesx($image),
            'height' => imagesy($image),
        ];
    }

    private function createNewImage(\GdImage $mediaImage, MediaType $type, array $originalImageSize, array $thumbnailSize): \GdImage
    {
        $thumbnail = imagecreatetruecolor($thumbnailSize['width'], $thumbnailSize['height']);

        if ($thumbnail === false) {
            throw new \RuntimeException('Can not create image handle');
        }

        if (!$type->is(ImageType::TRANSPARENT)) {
            $colorWhite = (int)imagecolorallocate($thumbnail, 255, 255, 255);
            imagefill($thumbnail, 0, 0, $colorWhite);
        } else {
            imagealphablending($thumbnail, false);
        }

        imagesavealpha($thumbnail, true);
        imagecopyresampled(
            $thumbnail,
            $mediaImage,
            0,
            0,
            0,
            0,
            $thumbnailSize['width'],
            $thumbnailSize['height'],
            $originalImageSize['width'],
            $originalImageSize['height']
        );

        return $thumbnail;
    }

    private function writeThumbnail(\GdImage $thumbnail, MediaEntity $media, string $filePath, int $quality): void
    {
        ob_start();

        if (!\function_exists('imagewebp')) {
            throw new ThumbnailCouldNotBeSavedException($filePath);
        }

        imagewebp($thumbnail, null, $quality);

        $imageFile = ob_get_contents();
        ob_end_clean();

        try {
            $this->getFileSystem($media)->write($filePath, (string)$imageFile);
        } catch (\Exception) {
            throw new ThumbnailCouldNotBeSavedException($filePath);
        }
    }
}
