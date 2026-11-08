<?php

declare(strict_types=1);

namespace Undkonsorten\Powermailpdf\Alert;

use Symfony\Component\Mime\Email;
use TYPO3\CMS\Core\Mail\MailerInterface;

/**
 * Admin Alert when a submission's invoice PDF could not be generated: the registration went
 * through and the mails went out without the attachment, so someone has to send the PDF by
 * hand. Goes to $GLOBALS['TYPO3_CONF_VARS']['BE']['warning_email_addr'].
 *
 * The mail record has no uid yet at this point (powermail saves it after the event), so the
 * alert names the submission by form, Invoice Number and Invoice Code.
 */
final class InvoicePdfFailureAlert
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly ?string $recipient = null,
        private readonly ?string $sender = null,
    ) {
    }

    /** @return bool true when an alert was handed to the mailer */
    public function send(string $form, string $invoiceNumber, string $invoiceCode, \Throwable $error): bool
    {
        $recipient = trim((string)($this->recipient ?? $GLOBALS['TYPO3_CONF_VARS']['BE']['warning_email_addr'] ?? ''));
        if ($recipient === '') {
            return false;
        }
        $sender = trim((string)($this->sender ?? $GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromAddress'] ?? ''));
        if ($sender === '') {
            $sender = $recipient;
        }

        $email = (new Email())
            ->from($sender)
            ->to($recipient)
            ->subject('Invoice PDF not generated: ' . ($invoiceCode !== '' ? $invoiceCode : $form))
            ->text(implode("\n", [
                'A registration was saved and its mails were sent, but without the invoice PDF.',
                'Send the pro forma to the participant by hand.',
                '',
                'Form: ' . $form,
                'Invoice Number: ' . ($invoiceNumber !== '' ? $invoiceNumber : '(none)'),
                'Invoice Code: ' . ($invoiceCode !== '' ? $invoiceCode : '(none)'),
                'Error: ' . $error->getMessage(),
            ]));

        $this->mailer->send($email);
        return true;
    }
}
