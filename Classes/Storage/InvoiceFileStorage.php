<?php

declare(strict_types=1);

namespace Undkonsorten\Powermailpdf\Storage;

use TYPO3\CMS\Core\Resource\FileInterface;

/** Whether a file lives in the invoice storage; InvoiceStorage in production, a fake in tests. */
interface InvoiceFileStorage
{
    public function contains(FileInterface $file): bool;
}
