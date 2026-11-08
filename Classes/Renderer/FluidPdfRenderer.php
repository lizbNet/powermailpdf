<?php

declare(strict_types=1);

namespace Undkonsorten\Powermailpdf\Renderer;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * Renders a pdfviewhelpers Fluid template to PDF bytes.
 *
 * The template's <pdf:document> must take outputDestination="{outputDestination}"; this class
 * passes "string", so nothing is sent to the browser. The page's TypoScript must include
 * EXT:pdfviewhelpers/Configuration/TypoScript/setup.typoscript.
 */
final class FluidPdfRenderer
{
    public function __construct(private readonly ViewFactoryInterface $viewFactory)
    {
    }

    /**
     * @param array<string, mixed> $variables
     * @throws \RuntimeException when the template is missing or the output is not a PDF
     */
    public function render(string $template, array $variables): string
    {
        $templatePath = GeneralUtility::getFileAbsFileName($template);
        if ($templatePath === '' || !is_file($templatePath)) {
            throw new \RuntimeException('Invoice template not found: ' . $template, 1762632001);
        }

        $view = $this->viewFactory->create(new ViewFactoryData(
            templatePathAndFilename: $templatePath,
            request: $GLOBALS['TYPO3_REQUEST'] ?? null,
            format: 'html',
        ));
        $view->assignMultiple($variables + ['outputDestination' => 'string', 'outputPath' => 'invoice.pdf']);
        $output = $view->render();

        // Whitespace around <pdf:document> in the template ends up around the bytes.
        $start = strpos($output, '%PDF-');
        $end = strrpos($output, '%%EOF');
        if ($start === false || $end === false) {
            throw new \RuntimeException('Invoice template did not produce a PDF: ' . $template, 1762632002);
        }

        return substr($output, $start, $end - $start + 5) . "\n";
    }
}
