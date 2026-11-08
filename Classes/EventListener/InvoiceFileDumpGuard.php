<?php

declare(strict_types=1);

namespace Undkonsorten\Powermailpdf\EventListener;

use Psr\Http\Message\ResponseFactoryInterface;
use TYPO3\CMS\Core\Resource\Event\ModifyFileDumpEvent;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileInterface;
use Undkonsorten\Powermailpdf\Storage\InvoiceLinkSignature;
use Undkonsorten\Powermailpdf\Storage\InvoiceFileStorage;

/**
 * eID=dumpFile for a file in the invoice storage needs our exp/sig on top of core's token:
 * missing, wrong or expired → 403; valid → streamed with no-store. Runs after core checked its
 * own token. Files in other storages are left to core.
 */
final class InvoiceFileDumpGuard
{
    public function __construct(
        private readonly InvoiceFileStorage $invoiceStorage,
        private readonly InvoiceLinkSignature $signature,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function __invoke(ModifyFileDumpEvent $event): void
    {
        $file = $event->getFile();
        if (!$file instanceof FileInterface || !$this->invoiceStorage->contains($file)) {
            return;
        }
        $uid = $file instanceof File ? $file->getUid() : 0;
        $query = $event->getRequest()->getQueryParams();
        if ($file instanceof File && $this->signature->isValid($uid, (string)($query['exp'] ?? ''), (string)($query['sig'] ?? ''), time())) {
            // Stream it ourselves so the response carries no-store: core sends no Cache-Control,
            // and a cached copy would outlive the 24 h link and hold personal data.
            $event->setResponse(
                $file->getStorage()->streamFile($file, true)
                    ->withHeader('Cache-Control', 'private, no-store, max-age=0')
                    ->withHeader('X-Robots-Tag', 'noindex')
            );
            return;
        }
        $response = $this->responseFactory->createResponse(403)
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('Content-Type', 'text/plain; charset=utf-8');
        $response->getBody()->write('Link expired or invalid.');
        $event->setResponse($response);
    }
}
