<?php

declare(strict_types=1);

namespace Undkonsorten\Powermailpdf\Storage;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * The non-public FAL storage that holds generated invoice PDFs (issue 03, ADR 0014): Local
 * driver, base path ../var/invoice-pdf/ relative to public/, so outside the web root on every
 * environment. Found by its name, because its uid differs per environment and the
 * eID=dumpFile request that serves the files runs without TypoScript.
 */
final class InvoiceStorage implements InvoiceFileStorage
{
    public const NAME = 'invoice-pdf';
    public const BASE_PATH = '../var/invoice-pdf/';
    private const TABLE = 'sys_file_storage';

    private ?int $uid = null;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly StorageRepository $storageRepository,
    ) {
    }

    /** @throws \RuntimeException unless exactly one storage has the name */
    public function uid(): int
    {
        if ($this->uid !== null) {
            return $this->uid;
        }
        $uids = $this->connectionPool->getQueryBuilderForTable(self::TABLE)
            ->select('uid')
            ->from(self::TABLE)
            ->where('name = :name')
            ->setParameter('name', self::NAME)
            ->executeQuery()
            ->fetchFirstColumn();
        if (count($uids) !== 1) {
            throw new \RuntimeException(sprintf(
                'Expected exactly one FAL storage named "%s", found %d; run powermailpdf:invoice-storage',
                self::NAME,
                count($uids)
            ), 1762700001);
        }
        return $this->uid = (int)$uids[0];
    }

    public function rootFolder(): Folder
    {
        return $this->storageRepository->findByUid($this->uid())->getRootLevelFolder(false);
    }

    /** False when the storage doesn't exist (yet): such a file is simply not an invoice file. */
    public function contains(FileInterface $file): bool
    {
        try {
            return $file->getStorage()->getUid() === $this->uid();
        } catch (\RuntimeException) {
            return false;
        }
    }
}
