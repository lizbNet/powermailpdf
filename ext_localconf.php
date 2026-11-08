<?php

use Undkonsorten\Powermailpdf\ViewHelpers\Misc\VariablesViewHelper;

if (!defined('TYPO3')) {
    die ('Access denied.');
}

$GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][VariablesViewHelper::class] = [
    'className' => VariablesViewHelper::class
];

// TCPDF (used by pdfviewhelpers for renderer = fluid) calls die() on errors unless told to throw.
// A die() would end the request after sw_connector reserved the Invoice ID, losing the submission;
// an exception is caught by the listener (mails without the PDF + Admin Alert). The external-config
// flag stops TCPDF from loading its bundled config, which defines the constant as false; every
// other TCPDF constant then gets its default from tcpdf_autoconfig.php.
if (!defined('K_TCPDF_EXTERNAL_CONFIG')) {
    define('K_TCPDF_EXTERNAL_CONFIG', true);
}
if (!defined('K_TCPDF_THROW_EXCEPTION_ERROR')) {
    define('K_TCPDF_THROW_EXCEPTION_ERROR', true);
}
