<?php

declare(strict_types=1);

namespace Undkonsorten\Powermailpdf\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use Undkonsorten\Powermailpdf\Storage\InvoiceStorage;

/**
 * One-time, idempotent setup of the invoice storage on an environment: creates the directory
 * (on production the Deployer shared dir var/invoice-pdf) and the non-public FAL storage
 * record. Prints the storage uid. Run as the PHP-FPM user.
 */
#[AsCommand(
    name: 'powermailpdf:invoice-storage',
    description: 'Create the non-public FAL storage for invoice PDFs (var/invoice-pdf) if missing.',
)]
final class InvoiceStorageCommand extends Command
{
    public function __construct(private readonly ConnectionPool $connectionPool)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $directory = Environment::getPublicPath() . '/' . InvoiceStorage::BASE_PATH;
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            $io->error('Cannot create ' . $directory);
            return Command::FAILURE;
        }
        $real = realpath($directory) ?: $directory;
        if (!is_writable($real)) {
            $io->error($real . ' is not writable for ' . (get_current_user() ?: 'this user'));
            return Command::FAILURE;
        }

        $connection = $this->connectionPool->getConnectionForTable('sys_file_storage');
        $existing = $connection->select(['uid'], 'sys_file_storage', ['name' => InvoiceStorage::NAME, 'deleted' => 0])
            ->fetchFirstColumn();
        if (count($existing) > 1) {
            $io->error(sprintf('%d storages named "%s"; keep one', count($existing), InvoiceStorage::NAME));
            return Command::FAILURE;
        }
        if (count($existing) === 1) {
            $io->success(sprintf('Storage "%s" exists: uid %d, directory %s', InvoiceStorage::NAME, $existing[0], $real));
            return Command::SUCCESS;
        }

        $configuration = '<?xml version="1.0" encoding="utf-8" standalone="yes" ?>'
            . '<T3FlexForms><data><sheet index="sDEF"><language index="lDEF">'
            . '<field index="basePath"><value index="vDEF">' . InvoiceStorage::BASE_PATH . '</value></field>'
            . '<field index="pathType"><value index="vDEF">relative</value></field>'
            . '<field index="caseSensitive"><value index="vDEF">1</value></field>'
            . '</language></sheet></data></T3FlexForms>';
        $connection->insert('sys_file_storage', [
            'pid' => 0,
            'tstamp' => time(),
            'crdate' => time(),
            'name' => InvoiceStorage::NAME,
            'description' => 'Invoice PDFs (powermailpdf). Not public: served only through signed eID=dumpFile links.',
            'driver' => 'Local',
            'configuration' => $configuration,
            'is_default' => 0,
            // browsable: TYPO3 refuses every read check on a non-browsable storage, its own
            // _processed_ lookup in addFile() included. Only admins (file mounts) see it in Filelist.
            'is_browsable' => 1,
            'is_public' => 0,
            'is_writable' => 1,
            'is_online' => 1,
            'auto_extract_metadata' => 0,
        ]);
        $io->success(sprintf('Created storage "%s": uid %d, directory %s', InvoiceStorage::NAME, (int)$connection->lastInsertId(), $real));
        return Command::SUCCESS;
    }
}
