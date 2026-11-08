<?php

declare(strict_types=1);

namespace Undkonsorten\Powermailpdf\Tests\Unit\Alert;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use TYPO3\CMS\Core\Mail\MailerInterface;
use Undkonsorten\Powermailpdf\Alert\InvoicePdfFailureAlert;

final class InvoicePdfFailureAlertTest extends TestCase
{
    private function fakeMailer(): MailerInterface
    {
        return new class () implements MailerInterface {
            /** @var list<RawMessage> */
            public array $sent = [];

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                $this->sent[] = $message;
            }

            public function getSentMessage(): ?SentMessage
            {
                return null;
            }

            public function getTransport(): \Symfony\Component\Mailer\Transport\TransportInterface
            {
                throw new \LogicException('not used');
            }

            public function getRealTransport(): \Symfony\Component\Mailer\Transport\TransportInterface
            {
                throw new \LogicException('not used');
            }
        };
    }

    public function testAlertNamesTheFormTheInvoiceAndTheError(): void
    {
        $mailer = $this->fakeMailer();
        $alert = new InvoicePdfFailureAlert($mailer, 'admin@example.com', 'forms@example.com');

        $sent = $alert->send(
            'Dziecko w ubezpieczeniach 2026 (form 504)',
            '010/11/F/2026',
            'DWU26-11-F010',
            new \RuntimeException('Invoice template not found: EXT:x/Proforma.html')
        );

        self::assertTrue($sent);
        self::assertCount(1, $mailer->sent);
        $mail = $mailer->sent[0];
        self::assertInstanceOf(Email::class, $mail);
        self::assertSame('admin@example.com', $mail->getTo()[0]->getAddress());
        self::assertSame('forms@example.com', $mail->getFrom()[0]->getAddress());
        self::assertStringContainsString('DWU26-11-F010', $mail->getSubject());
        $body = $mail->getTextBody();
        self::assertStringContainsString('Dziecko w ubezpieczeniach 2026 (form 504)', $body);
        self::assertStringContainsString('010/11/F/2026', $body);
        self::assertStringContainsString('DWU26-11-F010', $body);
        self::assertStringContainsString('Invoice template not found: EXT:x/Proforma.html', $body);
    }

    public function testNoRecipientConfiguredSendsNothing(): void
    {
        $mailer = $this->fakeMailer();
        $previous = $GLOBALS['TYPO3_CONF_VARS']['BE']['warning_email_addr'] ?? null;
        $GLOBALS['TYPO3_CONF_VARS']['BE']['warning_email_addr'] = '';
        try {
            $sent = (new InvoicePdfFailureAlert($mailer))->send('form 504', '', '', new \RuntimeException('x'));
        } finally {
            $GLOBALS['TYPO3_CONF_VARS']['BE']['warning_email_addr'] = $previous;
        }

        self::assertFalse($sent);
        self::assertSame([], $mailer->sent);
    }
}
