<?php
declare(strict_types=1);

namespace Tadpole\ModernImageFormats\Command;

use Shopware\Core\Framework\Adapter\Console\ShopwareStyle;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\RepositoryIterator;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Tadpole\ModernImageFormats\Service\ImageConverter as ImageConverterService;

#[AsCommand(
    name: 'media:image:convert',
    description: 'Converts all images to have webp copies',
)]
class ImageConverter extends Command
{

    private int $batchSize = 50;

    public function __construct(
        private readonly EntityRepository      $mediaThumbnailRepository,
        private readonly ImageConverterService $imageConverterService,
        string                                 $name = null)
    {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->addOption(
            'force',
            'f',
            InputOption::VALUE_NONE,
            'recreates all images, even if they already exist in the file system'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new ShopwareStyle($input, $output);

        $context = Context::createDefaultContext();
        $mediaThumbnailIterator = new RepositoryIterator(
            $this->mediaThumbnailRepository,
            $context,
            $this->createThumbnailCriteria()
        );

        $totalMediaThumbnailCount = $mediaThumbnailIterator->getTotal();
        $io->comment(
            sprintf('Generating webp and avif images for %d thumbnails. This may take some time...', $totalMediaThumbnailCount)
        );
        $io->progressStart($totalMediaThumbnailCount);

        $this->imageConverterService->generateImages($mediaThumbnailIterator, $input->getOption('force'), $io);

        $io->progressFinish();

        return 0;
    }

    private function createThumbnailCriteria(): Criteria
    {
        $criteria = new Criteria();
        $criteria->setOffset(0);
        $criteria->setLimit($this->batchSize);
        $criteria->addAssociation('media.mediaFolder.configuration.mediaThumbnailSizes');

        return $criteria;
    }


}
