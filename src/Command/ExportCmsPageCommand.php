<?php declare(strict_types=1);

namespace Frosh\CmsImportExport\Command;

use Frosh\CmsImportExport\Service\CmsPageExportService;
use Shopware\Core\Framework\Context;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'frosh:cms:export',
    description: 'Exports a CMS page including all referenced images into a ZIP archive',
)]
class ExportCmsPageCommand extends Command
{
    /**
     * @internal
     */
    public function __construct(private readonly CmsPageExportService $exportService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('cmsPageId', InputArgument::REQUIRED, 'Id of the CMS page to export');
        $this->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Target file, defaults to the generated file name in the current directory');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $result = $this->exportService->export((string) $input->getArgument('cmsPageId'), Context::createCLIContext());

        $target = $input->getOption('output');
        $target = \is_string($target) && $target !== '' ? $target : getcwd() . '/' . $result->fileName;

        if (!@rename($result->filePath, $target)) {
            copy($result->filePath, $target);
            unlink($result->filePath);
        }

        foreach ($result->warnings as $warning) {
            $io->warning($warning);
        }

        $io->success(\sprintf('Exported CMS page with %d media file(s) to %s', $result->mediaCount, $target));

        return self::SUCCESS;
    }
}
