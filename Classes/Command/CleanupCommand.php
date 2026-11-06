<?php

declare(strict_types=1);

namespace Undkonsorten\Powermailpdf\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * Deletes generated PDFs older than a given age from the target folder.
 *
 * The PDFs carry buyers' names, addresses and NIPs and sit in a public
 * folder, so they must not outlive the retention period. The period has
 * no default: it is a data protection decision, passed on every run.
 */
#[AsCommand(
    name: 'powermailpdf:cleanup',
    description: 'Delete generated PDFs older than the given number of days.',
)]
final class CleanupCommand extends Command
{
    public function __construct(private readonly ResourceFactory $resourceFactory)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('older-than-days', null, InputOption::VALUE_REQUIRED, 'Delete PDFs last modified more than this many days ago (required, at least 1).')
            ->addOption('folder', null, InputOption::VALUE_REQUIRED, 'Combined folder identifier; must match plugin.tx_powermailpdf.settings.target.pdf.', '1:powermailpdf')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be deleted, delete nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $days = $input->getOption('older-than-days');
        if ($days === null || !ctype_digit((string)$days) || (int)$days < 1) {
            $io->error('--older-than-days is required and must be a whole number of at least 1.');
            return Command::INVALID;
        }

        try {
            $folder = $this->resourceFactory->getFolderObjectFromCombinedIdentifier((string)$input->getOption('folder'));
        } catch (\Throwable $e) {
            $io->error('Folder not found: ' . $input->getOption('folder') . ' (' . $e->getMessage() . ')');
            return Command::INVALID;
        }
        $cutoff = time() - (int)$days * 86400;
        $dryRun = (bool)$input->getOption('dry-run');

        $matched = 0;
        $failed = 0;
        foreach ($folder->getFiles() as $file) {
            if (strtolower($file->getExtension()) !== 'pdf' || $file->getModificationTime() >= $cutoff) {
                continue;
            }
            $matched++;
            if ($dryRun) {
                $io->writeln($file->getIdentifier());
                continue;
            }
            try {
                $file->delete();
            } catch (\Throwable $e) {
                $failed++;
                $io->warning($file->getIdentifier() . ': ' . $e->getMessage());
            }
        }

        $summary = sprintf(
            '%s %d PDF(s) older than %d day(s) in %s.',
            $dryRun ? 'Would delete' : 'Deleted',
            $matched - $failed,
            (int)$days,
            $folder->getCombinedIdentifier()
        );
        if ($failed > 0) {
            $io->warning($summary . sprintf(' %d failed.', $failed));
            return Command::FAILURE;
        }
        $io->success($summary);

        return Command::SUCCESS;
    }
}
