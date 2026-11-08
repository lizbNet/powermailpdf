<?php

namespace Undkonsorten\Powermailpdf\EventListener;

use FPDM;
use In2code\Powermail\Controller\FormController;
use In2code\Powermail\Domain\Model\Answer;
use In2code\Powermail\Domain\Model\Field;
use In2code\Powermail\Domain\Model\Mail;
use TYPO3\CMS\Core\Error\Exception;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3\CMS\Core\TypoScript\Parser\TypoScriptParser;
use TYPO3\CMS\Core\TypoScript\TypoScriptService;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use In2code\Powermail\Events\FormControllerCreateActionBeforeRenderViewEvent;
use Psr\Log\LoggerInterface;
use Undkonsorten\Powermailpdf\Alert\InvoicePdfFailureAlert;
use Undkonsorten\Powermailpdf\Renderer\FluidPdfRenderer;
use Undkonsorten\Powermailpdf\Renderer\InvoiceDataMapper;
use Undkonsorten\Powermailpdf\Storage\InvoiceStorage;


/**
 * PDF handling.
 *
 */
final class CreateActionBeforeRenderView
{
    /** @var ResourceFactory */
    protected $resourceFactory;
    private ViewFactoryInterface $viewFactory;

    protected ?bool $encoding = null;

    public function __construct(
        ResourceFactory $resourceFactory,
        ViewFactoryInterface $viewFactory,
        private readonly InvoiceDataMapper $invoiceDataMapper,
        private readonly FluidPdfRenderer $fluidPdfRenderer,
        private readonly TypoScriptService $typoScriptService,
        private readonly LoggerInterface $logger,
        private readonly InvoicePdfFailureAlert $failureAlert,
        private readonly InvoiceStorage $invoiceStorage,
    ) {
        $this->resourceFactory = $resourceFactory;
        $this->viewFactory = $viewFactory;
    }

    private function answerByMarker(Mail $mail, string $marker): ?Answer
    {
        foreach ($mail->getAnswers() as $answer) {
            if ($answer->getField()?->getMarker() === $marker) {
                return $answer;
            }
        }
        return null;
    }

    /** @return array<string, mixed> marker => submitted value */
    protected function answersByMarker(Mail $mail): array
    {
        $answers = [];
        foreach ($mail->getAnswers() as $answer) {
            $marker = $answer->getField()?->getMarker();
            if ($marker !== null && $marker !== '') {
                $answers[$marker] = $answer->getValue();
            }
        }
        return $answers;
    }

    /**
     * The mail has no uid yet (powermail saves it after this event), so the submission is named
     * by form, Invoice Number and Invoice Code.
     */
    protected function reportFailure(Mail $mail, \Throwable $error): void
    {
        $answers = array_map(
            static fn($value): string => is_array($value) ? implode(', ', $value) : (string)$value,
            $this->answersByMarker($mail)
        );
        $form = $mail->getForm();
        $formName = trim(($form?->getTitle() ?? '') . ' (form ' . ($form?->getUid() ?? '?') . ')');

        $this->logger->error('Invoice PDF could not be generated; mails go without it', [
            'form' => $formName,
            'invoiceNumber' => $answers['invoice_number'] ?? '',
            'invoiceCode' => $answers['invoice_code_all'] ?? '',
            'exception' => $error,
        ]);
        // The alert logs and swallows its own mailer errors.
        $this->failureAlert->send($formName, $answers['invoice_number'] ?? '', $answers['invoice_code_all'] ?? '', $error);
    }

    /**
     * `renderer = fluid`: the PDF comes from a pdfviewhelpers Fluid template (`fluid.template`)
     * fed by InvoiceDataMapper, instead of FPDM filling `sourceFile`. Off by default.
     */
    protected function generateFluidPdf(Mail $mail, array $settings, Folder $folder): File
    {
        $config = $this->typoScriptService->convertTypoScriptArrayToPlainArray($settings['fluid.'] ?? []);

        $variables = $this->invoiceDataMapper->map($this->answersByMarker($mail), $config) + [
            'logo' => (string)($config['logo'] ?? ''),
            'styleSheet' => (string)($config['styleSheet'] ?? ''),
        ];
        $pdf = $this->fluidPdfRenderer->render((string)($config['template'] ?? ''), $variables);

        $tempFile = GeneralUtility::tempnam('invoice_', '.pdf');
        GeneralUtility::writeFile($tempFile, $pdf);

        return $folder->addFile($tempFile);
    }

    /**
     * Picks a template key from `templateSelector.map.` based on the
     * selector field's submitted value (or `templateSelector.default` on
     * no match). Returns null if `templates.`/`templateSelector.` aren't
     * both configured, meaning the caller should fall back to the flat
     * `sourceFile`/`fieldMap` settings.
     */
    protected function resolveTemplateKey(Mail $mail, array $settings): ?string
    {
        $selector = $settings['templateSelector.'] ?? null;
        if (!isset($settings['templates.']) || !$selector) {
            return null;
        }

        $templateKey = $selector['default'] ?? '';

        foreach ($mail->getAnswers() as $answer) {
            if ($answer->getField()->getMarker() !== $selector['field']) {
                continue;
            }

            // check/multiselect fields always return an array from getValue()
            foreach ((array)$answer->getValue() as $value) {
                if (isset($selector['map.'][$value])) {
                    return $selector['map.'][$value];
                }
            }
        }

        return $templateKey;
    }

    /**
     * True when the answer of field `skipWhen.marker` equals `skipWhen.value`. Such a submission
     * gets no PDF, no download link and no attachment (first use: registrations paid online,
     * which finance invoices after payment). Not configured, or an empty value: never skipped.
     */
    protected function isSkipped(Mail $mail, array $settings): bool
    {
        $skipWhen = $settings['skipWhen.'] ?? null;
        if (!is_array($skipWhen) || ($skipWhen['marker'] ?? '') === '' || ($skipWhen['value'] ?? '') === '') {
            return false;
        }

        foreach ($mail->getAnswers() as $answer) {
            if ($answer->getField()?->getMarker() !== $skipWhen['marker']) {
                continue;
            }

            foreach ((array)$answer->getValue() as $value) {
                if ((string)$value === (string)$skipWhen['value']) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function resolveSourceFile(Mail $mail, array $settings): string
    {
        $templateKey = $this->resolveTemplateKey($mail, $settings);
        if ($templateKey === null) {
            return $settings['sourceFile'] ?? '';
        }

        return $settings['templates.'][$templateKey . '.']['sourceFile'] ?? $settings['sourceFile'] ?? '';
    }

    /**
     * Field map to use for this submission: `templates.<key>.fieldMap.` when
     * a matching template defines its own override, otherwise the shared
     * flat `fieldMap.` setting.
     */
    protected function resolveFieldMap(Mail $mail, array $settings): array
    {
        $templateKey = $this->resolveTemplateKey($mail, $settings);
        if ($templateKey !== null) {
            $templateFieldMap = $settings['templates.'][$templateKey . '.']['fieldMap.'] ?? null;
            if ($templateFieldMap !== null) {
                return $templateFieldMap;
            }
        }

        return $settings['fieldMap.'] ?? [];
    }

    /**
     * @param Mail $mail
     * @return File
     * @throws Exception
     */
    protected function generatePdf(Mail $mail)
    {

        $settings = $GLOBALS['TYPO3_REQUEST']->getAttribute('frontend.typoscript')->getSetupArray()['plugin.']['tx_powermailpdf.']['settings.'] ?? [];
        $this->encoding = $settings['encoding']??'';

        /** @var Folder $folder */
        // target.useInvoiceStorage = 1: the non-public invoice storage (issue 03), found by name;
        // a missing or duplicated storage throws into the failure path, never falls back to fileadmin.
        $folder = ($settings['target.']['useInvoiceStorage'] ?? false)
            ? $this->invoiceStorage->rootFolder()
            : $this->resourceFactory->getFolderObjectFromCombinedIdentifier($settings['target.']['pdf']);

        if (($settings['renderer'] ?? 'fpdm') === 'fluid') {
            return $this->generateFluidPdf($mail, $settings, $folder);
        }

        // Include \FPDM library from phar file, if not included already (e.g. composer installation)
        if (!class_exists('\FPDM')) {
            @include 'phar://' . ExtensionManagementUtility::extPath('powermailpdf') . 'Resources/Private/PHP/fpdm.phar/vendor/autoload.php';
        }

        //Normal Fields
        $fieldMap = $this->resolveFieldMap($mail, $settings);

        $answers = $mail->getAnswers();

        $fdfDataStrings = array();
        foreach ($fieldMap as $key => $value) {
            foreach ($answers as $answer) {
                if ($value == $answer->getField()->getMarker()) {
                    $fdfDataStrings[$key] = $answer->getValue();
                }
            }
        }

        $pdfOriginal = GeneralUtility::getFileAbsFileName($this->resolveSourceFile($mail, $settings));

        if (!empty($pdfOriginal)) {
            $pdfFlatTempFile = (string) null;
            $info = pathinfo($pdfOriginal);
            $pdfFilename = basename($pdfOriginal, '.' . $info['extension']) . '_';
            $pdfTempFile = GeneralUtility::tempnam($pdfFilename, '.pdf');

            $pdf = new \FPDM($pdfOriginal);
            $pdf->Load($fdfDataStrings, !$this->encoding); // second parameter: false if field values are in ISO-8859-1, true if UTF-8
            $pdf->Merge();
            $pdf->Output("F", GeneralUtility::getFileAbsFileName($pdfTempFile));

            if (isset($settings['flatten']) && isset($settings['flattenTool'])) {
                $pdfFlatTempFile = GeneralUtility::tempnam($pdfFilename, '.pdf');
                $tempFile = GeneralUtility::tempnam($pdfFilename, '.pdf');
                switch ($settings['flattenTool']) {
                    case 'gs':
                        // Flatten PDF with ghostscript
                        @shell_exec("gs -sDEVICE=pdfwrite -dSubsetFonts=false -dPDFSETTINGS=/default -dNOPAUSE -dBATCH -sOutputFile=" . $pdfFlatTempFile . " " . $pdfTempFile);
                        break;
                    case 'pdftocairo':
                        // Flatten PDF with pdftocairo
                        @shell_exec('pdftocairo -pdf ' . $pdfTempFile . ' ' . $pdfFlatTempFile);
                        break;
                    case 'pdftk':
                        // Flatten PDF with pdftk
                        @shell_exec('pdftk ' . $pdfTempFile . ' generate_fdf output ' . $tempFile);
                        @shell_exec('pdftk ' . $pdfTempFile . ' fill_form ' . $tempFile . ' output ' . $pdfFlatTempFile . ' flatten');
                        break;
                }
            }
        } else {
            throw new Exception("No pdf file is set in Typoscript. Please set tx_powermailpdf.settings.sourceFile if you want to use the filling feature.", 1417432239);
        }

        if (file_exists($pdfFlatTempFile)) {
            return $folder->addFile($pdfFlatTempFile);
        }

        return $folder->addFile($pdfTempFile);
    }

    /**
     * @param File $file
     * @param $label
     * @return mixed
     */
    protected function render(File $file, $label)
    {
        $settings = $GLOBALS['TYPO3_REQUEST']->getAttribute('frontend.typoscript')->getSetupArray()['plugin.']['tx_powermailpdf.']['settings.'] ?? [];
        $templatePath = GeneralUtility::getFileAbsFileName($settings['template']);
        $view = $this->viewFactory->create(
            new ViewFactoryData(
                templatePathAndFilename: $templatePath,
                request: $GLOBALS['TYPO3_REQUEST'] ?? null,
                format: 'html',
            )
        );
        $view->assignMultiple([
            'link' => $file->getPublicUrl(),
            'label' => $label
        ]);

        return $view->render();
    }

    /**
     *
     * @param FormControllerCreateActionBeforeRenderViewEvent $event
     * @throws Exception
     *
     */
    public function __invoke(FormControllerCreateActionBeforeRenderViewEvent $event): void
    {
        $settings = $GLOBALS['TYPO3_REQUEST']->getAttribute('frontend.typoscript')->getSetupArray()['plugin.']['tx_powermailpdf.']['settings.'] ?? [];
        $mail = $event->getMail();
        $formController = $event->getFormController();

        // invoice_pdf is a hidden field: whatever the browser posted is discarded before anything
        // else, so a forged file uid never ends up on the mail or behind a signed link.
        $invoicePdfAnswer = $this->answerByMarker($mail, 'invoice_pdf');
        $invoicePdfAnswer?->setValue('');

        if (($settings['enablePowermailPdf'] ?? false) && !$this->isSkipped($mail, $settings)) {
            $resolvedSourceFile = ($settings['renderer'] ?? 'fpdm') === 'fluid' ? '' : $this->resolveSourceFile($mail, $settings);
            if ($resolvedSourceFile) {
                if (!file_exists(GeneralUtility::getFileAbsFileName($resolvedSourceFile))) {
                    throw new \Exception("The file does not exist: " . $resolvedSourceFile . " Please set correct path in plugin.tx_powermailpdf.settings.sourceFile (or templates.<key>.sourceFile)", 1417520887);
                }
            }

            $powermailPdfFile = null;
            if ($settings['fillPdf'] ?? false) {
                // A failed render must not lose the submission or its Invoice ID: the mails go
                // without the attachment and the error is logged (FPDM's die() can't be caught).
                try {
                    $powermailPdfFile = $this->generatePdf($mail);
                } catch (\Throwable $e) {
                    $this->reportFailure($mail, $e);
                }
            }

            // The mail keeps a reference to its PDF (sys_file uid), set only once the file exists.
            if ($powermailPdfFile !== null && $invoicePdfAnswer !== null) {
                $invoicePdfAnswer->setValue((string)$powermailPdfFile->getUid());
            }

            // Forms with invoice_pdf build the link in their template (pdf:invoiceDownloadUri), so no
            // download-link field is created on the fly for them.
            if ($powermailPdfFile !== null && $invoicePdfAnswer === null && ($settings['showDownloadLink'] ?? false)) {
                $label = LocalizationUtility::translate("download", "powermailpdf");
                //Adds a field for the download link at the thx site
                /* @var $answer Answer */
                $answer = GeneralUtility::makeInstance(Answer::class);
                /* @var $field Field */
                $field = GeneralUtility::makeInstance(Field::class);
                $field->setTitle(LocalizationUtility::translate('downloadLink', 'powermailpdf'));
                $field->setMarker('downloadLink');
                $field->setType('downloadLink');
                $answer->setField($field);
                $answer->setValue($this->render($powermailPdfFile, $label));
                $mail->addAnswer($answer);
            }

            if ($powermailPdfFile !== null && ($settings['email.']['attachFile'] ?? false)) {
                // set pdf filename for attachment via TypoScript
                $settings = $formController->getSettings();
                $settings['receiver']['addAttachment']['value'] = $powermailPdfFile->getForLocalProcessing(false);
                $settings['sender']['addAttachment']['value'] = $powermailPdfFile->getForLocalProcessing(false);
                $formController->setSettings($settings);
            }
        }
    }
}
