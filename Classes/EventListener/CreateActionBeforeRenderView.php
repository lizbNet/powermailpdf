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

    public function __construct(ResourceFactory $resourceFactory, ViewFactoryInterface $viewFactory)
    {
        $this->resourceFactory = $resourceFactory;
        $this->viewFactory = $viewFactory;
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

        $settings = $GLOBALS['TYPO3_REQUEST']->getAttribute('frontend.typoscript')->getSetupArray()['plugin.']['tx_powermailpdf.']['settings.'];
        $this->encoding = $settings['encoding']??'';

        /** @var Folder $folder */
        $folder = $this->resourceFactory->getFolderObjectFromCombinedIdentifier($settings['target.']['pdf']);

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
        $settings = $GLOBALS['TYPO3_REQUEST']->getAttribute('frontend.typoscript')->getSetupArray()['plugin.']['tx_powermailpdf.']['settings.'];
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
        $settings = $GLOBALS['TYPO3_REQUEST']->getAttribute('frontend.typoscript')->getSetupArray()['plugin.']['tx_powermailpdf.']['settings.'];
        $mail = $event->getMail();
        $formController = $event->getFormController();

        if ($settings['enablePowermailPdf']) {
            $resolvedSourceFile = $this->resolveSourceFile($mail, $settings);
            if ($resolvedSourceFile) {
                if (!file_exists(GeneralUtility::getFileAbsFileName($resolvedSourceFile))) {
                    throw new \Exception("The file does not exist: " . $resolvedSourceFile . " Please set correct path in plugin.tx_powermailpdf.settings.sourceFile (or templates.<key>.sourceFile)", 1417520887);
                }
            }

            if ($settings['fillPdf']) {
                $powermailPdfFile = $this->generatePdf($mail);
            } else {
                $powermailPdfFile = null;
            }

            if ($settings['showDownloadLink']) {
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

            if ($settings['email.']['attachFile']) {
                // set pdf filename for attachment via TypoScript
                $settings = $formController->getSettings();
                $settings['receiver']['addAttachment']['value'] = $powermailPdfFile->getForLocalProcessing(false);
                $settings['sender']['addAttachment']['value'] = $powermailPdfFile->getForLocalProcessing(false);
                $formController->setSettings($settings);
            }
        }
    }
}
