<?php declare(strict_types=1);

namespace Frosh\CmsImportExport\Command;

use Frosh\CmsImportExport\Service\CmsPageImportService;
use Shopware\Core\Framework\Context;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'frosh:cms:import',
    description: 'Imports a CMS page from a ZIP archive created by frosh:cms:export',
)]
class ImportCmsPageCommand extends Command
{
    /**
     * @internal
     */
    public function __construct(private readonly CmsPageImportService $importService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Path to the ZIP archive');
        $this->addOption('name', null, InputOption::VALUE_REQUIRED, 'Name for the imported layout, defaults to the exported one');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $name = $input->getOption('name');
        $result = $this->importService->import(
            (string) $input->getArgument('file'),
            Context::createCLIContext(),
            \is_string($name) ? $name : null
        );

        foreach ($result->warnings as $warning) {
            $io->warning($warning);
        }

        $io->success(\sprintf(
            'Imported layout "%s" (%s) with %d media file(s)',
            $result->name,
            $result->cmsPageId,
            $result->mediaCount
        ));

        return self::SUCCESS;
    }
}
