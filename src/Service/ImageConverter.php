<?php
declare(strict_types=1);

namespace Tadpole\ModernImageFormats\Service;

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
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\RepositoryIterator;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

class ImageConverter
{
    private int $batchSize = 50;

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly FilesystemOperator    $filesystemPublic,
        private readonly FilesystemOperator    $filesystemPrivate,
    )
    {

    }

    public function generateWebpImages(RepositoryIterator $iterator, $io = null): array
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
                $expectedThumbnailSize = new MediaThumbnailSizeEntity();
                $mediaFolderConfig = $this->getMediaConfiguration($mediaThumbnail);
                if ($mediaFolderConfig === null) {
                    continue;
                }
                $expectedThumbnailSize->assign([
                        'height' => $mediaThumbnail->getHeight(),
                        'width' => $mediaThumbnail->getWidth(),
                        'mediaFolderConfigurations' => $mediaFolderConfig]
                );
                $thumbnailSize = $this->calculateThumbnailSize(
                    $originalImageSize,
                    $expectedThumbnailSize,
                    $mediaFolderConfig
                );
                $mediaType = $this->getMediaType($mediaThumbnail);
                if ($mediaType === null) {
                    continue;
                }
                $webpImage = $this->createNewImage($image, $mediaType, $originalImageSize, $thumbnailSize);
                $webpFilePath = $this->urlGenerator->getRelativeThumbnailUrl(
                        $mediaThumbnail->getMedia(),
                        $mediaThumbnail
                    ) . '.webp';

                $this->writeThumbnail($webpImage, $mediaThumbnail->getMedia(), $webpFilePath, 80);
            }
            if ($io) {
                $io->progressAdvance($result->count());
            }
        }
        return [];
    }

    private function getMediaType(MediaThumbnailEntity $mediaThumbnail): ?MediaType
    {
        $media = $mediaThumbnail->getMedia();
        return ($media) ? $media->getMediaType() : null;
    }

    private function getMediaConfiguration(MediaThumbnailEntity $mediaThumbnail): ?MediaFolderConfigurationEntity
    {
        $media = $mediaThumbnail->getMedia();
        if ($media) {
            $mediaFolder = $media->getMediaFolder();
            if ($mediaFolder) {
                $mediaFolderConfiguration = $mediaFolder->getConfiguration();
            }
        }

        return $mediaFolderConfiguration ?? null;
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
        if (
            !$config->getKeepAspectRatio() ||
            $preferredThumbnailSize->getWidth() !== $preferredThumbnailSize->getHeight()
        ) {
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

    public function createThumbnailCriteria(array $ids = null): Criteria
    {
        $criteria = new Criteria($ids);
        $criteria->setOffset(0);
        $criteria->setLimit($this->batchSize);
        $criteria->addAssociation('media.mediaFolder.configuration.mediaThumbnailSizes');

        return $criteria;
    }
}
