<?php

declare(strict_types=1);

namespace Undkonsorten\Powermailpdf\Alert;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Address;
use TYPO3\CMS\Core\Mail\MailerInterface;
use TYPO3\CMS\Core\Mail\MailMessage;
use TYPO3\CMS\Core\Utility\MailUtility;

/**
 * Admin Alert when a submission's invoice PDF could not be generated: the registration went
 * through and the mails went out without the attachment, so someone has to send the invoice
 * PDF by hand. Goes to $GLOBALS['TYPO3_CONF_VARS']['BE']['warning_email_addr'].
 *
 * The mail record has no uid yet at this point (powermail saves it after the event), so the
 * alert names the submission by form, Invoice Number and Invoice Code.
 *
 * No personal data: the error is reported by class and code only, because its message may
 * quote submitted values; the full message stays in the log. A failing mailer is logged and
 * never reaches the submission.
 */
final class InvoicePdfFailureAlert
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly ?string $recipient = null,
        private readonly ?string $sender = null,
    ) {
    }

    /** @return bool true when the alert was handed to the mailer */
    public function send(string $form, string $invoiceNumber, string $invoiceCode, \Throwable $error): bool
    {
        $recipient = trim((string)($this->recipient ?? $GLOBALS['TYPO3_CONF_VARS']['BE']['warning_email_addr'] ?? ''));
        if ($recipient === '') {
            $this->logger->warning('No warning_email_addr: invoice PDF failure alert not sent', ['form' => $form]);
            return false;
        }

        $email = (new MailMessage())
            ->from($this->sender !== null ? new Address($this->sender) : $this->systemSender())
            ->to($recipient)
            ->subject('Nie wygenerowano faktury PDF: ' . ($invoiceCode !== '' ? $invoiceCode : $form))
            ->text(implode("\n", [
                'Zgłoszenie zostało zapisane, a maile wysłano bez faktury PDF.',
                'Wyślij fakturę PDF osobie, która się zarejestrowała, ręcznie.',
                '',
                'Formularz: ' . $form,
                'Numer faktury (Invoice Number): ' . ($invoiceNumber !== '' ? $invoiceNumber : '(brak)'),
                'Kod zgłoszenia (Invoice Code): ' . ($invoiceCode !== '' ? $invoiceCode : '(brak)'),
                'Błąd: ' . get_class($error) . ' ' . $error->getCode() . ' (szczegóły w logu TYPO3)',
            ]));

        try {
            $this->mailer->send($email);
        } catch (\Throwable $mailError) {
            $this->logger->error('Invoice PDF failure alert could not be sent', ['exception' => $mailError]);
            return false;
        }
        return true;
    }

    private function systemSender(): Address
    {
        $from = MailUtility::getSystemFrom();
        $address = is_array($from) ? (string)array_key_first($from) : (string)$from;
        $name = is_array($from) ? (string)reset($from) : '';
        return new Address($address, $name);
    }
}
