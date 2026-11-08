<?php

declare(strict_types=1);

namespace Undkonsorten\Powermailpdf\Storage;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Crypto\HashAlgo;
use TYPO3\CMS\Core\Crypto\HashService;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Download link for an invoice PDF: core's eID=dumpFile (t=f, f=<uid>, dl=1, core token) plus
 * our exp/sig (InvoiceLinkSignature), valid for 24 h. Built fresh at render time for the
 * thank-you page and the Powermail admin. Only for files in the invoice storage.
 */
final class InvoiceLinkBuilder
{
    public function __construct(
        private readonly InvoiceFileStorage $invoiceStorage,
        private readonly InvoiceLinkSignature $signature,
        private readonly HashService $hashService,
        private readonly ResourceFactory $resourceFactory,
    ) {
    }

    /** '' when the uid is empty, unknown or not a file in the invoice storage */
    public function forFileUid(int|string|null $fileUid, ?ServerRequestInterface $request = null): string
    {
        if (!is_numeric($fileUid) || (int)$fileUid <= 0) {
            return '';
        }
        try {
            $file = $this->resourceFactory->getFileObject((int)$fileUid);
        } catch (\Throwable) {
            return '';
        }
        if (!$file instanceof File || $file->isMissing() || !$this->invoiceStorage->contains($file)) {
            return '';
        }

        // Same parameter order as FileDumpController::buildParametersFromRequest().
        $core = ['eID' => 'dumpFile', 't' => 'f', 'f' => $file->getUid(), 'dl' => 1];
        $core['token'] = $this->hashService->hmac(implode('|', $core), 'resourceStorageDumpFile', HashAlgo::SHA3_256);
        $query = $core + $this->signature->sign($file->getUid(), time());

        $request ??= $GLOBALS['TYPO3_REQUEST'] ?? null;
        // The site root, not /index.php: the forms nginx marks the index.php location `internal`,
        // so a direct /index.php request gets nginx's 404; / reaches TYPO3 through try_files.
        $base = PathUtility::getAbsoluteWebPath(Environment::getPublicPath() . '/');
        if ($request instanceof ServerRequestInterface) {
            // absolute URL on the current host; without a request (CLI) the link stays relative
            $base = GeneralUtility::locationHeaderUrl($base, $request);
        }
        return $base . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
