<?php
declare(strict_types=1);

namespace Tadpole\ModernImageFormats\Command;

use Shopware\Core\Framework\Adapter\Console\ShopwareStyle;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\RepositoryIterator;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
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
//        $this->addOption(
//            'strict',
//            's',
//            InputOption::VALUE_NONE,
//            'Additionally checks that physical files for existing thumbnails are present'
//        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new ShopwareStyle($input, $output);

        $context = Context::createDefaultContext();
        $mediaThumbnailIterator = new RepositoryIterator(
            $this->mediaThumbnailRepository,
            $context,
            $this->imageConverterService->createThumbnailCriteria()
        );

        $totalMediaThumbnailCount = $mediaThumbnailIterator->getTotal();
        $io->comment(
            sprintf('Generating webp images for %d thumbnails. This may take some time...', $totalMediaThumbnailCount)
        );
        $io->progressStart($totalMediaThumbnailCount);

        $this->imageConverterService->generateWebpImages($mediaThumbnailIterator, $io);

        $io->progressFinish();

        return 0;
    }


}
