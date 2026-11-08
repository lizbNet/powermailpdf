<?php

declare(strict_types=1);

namespace Undkonsorten\Powermailpdf\ViewHelpers;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use Undkonsorten\Powermailpdf\Storage\InvoiceLinkBuilder;

/**
 * Signed 24 h download URL for an invoice PDF in the invoice storage, '' otherwise.
 *
 *   {namespace pdf=Undkonsorten\Powermailpdf\ViewHelpers}
 *   <f:variable name="url" value="{pdf:invoiceDownloadUri(fileUid: invoice_pdf)}" />
 */
final class InvoiceDownloadUriViewHelper extends AbstractViewHelper
{
    public function __construct(private readonly InvoiceLinkBuilder $linkBuilder)
    {
    }

    public function initializeArguments(): void
    {
        $this->registerArgument('fileUid', 'mixed', 'sys_file uid of the invoice PDF (answer of field invoice_pdf)', true);
    }

    public function render(): string
    {
        $value = $this->arguments['fileUid'];
        if (is_array($value)) {
            $value = reset($value);
        }
        $request = $this->renderingContext->hasAttribute(ServerRequestInterface::class)
            ? $this->renderingContext->getAttribute(ServerRequestInterface::class)
            : null;
        return $this->linkBuilder->forFileUid(is_scalar($value) ? (string)$value : null, $request);
    }
}
