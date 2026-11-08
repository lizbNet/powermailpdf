<?php

declare(strict_types=1);

namespace Undkonsorten\Powermailpdf\Tests\Unit\Alert;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use TYPO3\CMS\Core\Mail\MailerInterface;
use Undkonsorten\Powermailpdf\Alert\InvoicePdfFailureAlert;

final class InvoicePdfFailureAlertTest extends TestCase
{
    private function fakeMailer(?\Throwable $failWith = null): MailerInterface
    {
        return new class ($failWith) implements MailerInterface {
            /** @var list<RawMessage> */
            public array $sent = [];

            public function __construct(private readonly ?\Throwable $failWith)
            {
            }

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                if ($this->failWith !== null) {
                    throw $this->failWith;
                }
                $this->sent[] = $message;
            }

            public function getSentMessage(): ?SentMessage
            {
                return null;
            }

            public function getTransport(): TransportInterface
            {
                throw new \LogicException('not used');
            }

            public function getRealTransport(): TransportInterface
            {
                throw new \LogicException('not used');
            }
        };
    }

    private function alert(MailerInterface $mailer, ?string $recipient = 'admin@example.com'): InvoicePdfFailureAlert
    {
        return new InvoicePdfFailureAlert($mailer, new NullLogger(), $recipient, 'forms@example.com');
    }

    public function testAlertNamesTheFormTheInvoiceAndTheErrorType(): void
    {
        $mailer = $this->fakeMailer();

        $sent = $this->alert($mailer)->send(
            'Dziecko w ubezpieczeniach 2026 (form 504)',
            '010/11/F/2026',
            'DWU26-11-F010',
            new \RuntimeException('Invoice template not found: EXT:x/Proforma.html', 1762632001)
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
        self::assertStringContainsString('RuntimeException', $body);
        self::assertStringContainsString('1762632001', $body);
    }

    public function testErrorMessageIsNotMailedBecauseItMayQuoteSubmittedData(): void
    {
        $mailer = $this->fakeMailer();

        $this->alert($mailer)->send('form 504', '001/11/K/2026', 'DWU26-11-K001', new \TypeError('Jan Kowalski, ul. Prywatna 1'));

        self::assertStringNotContainsString('Jan Kowalski', $mailer->sent[0]->getTextBody());
        self::assertStringNotContainsString('Jan Kowalski', $mailer->sent[0]->getSubject());
    }

    public function testFailingMailerDoesNotThrow(): void
    {
        $sent = $this->alert($this->fakeMailer(new \RuntimeException('SMTP down')))
            ->send('form 504', '001/11/K/2026', 'DWU26-11-K001', new \RuntimeException('x'));

        self::assertFalse($sent);
    }

    public function testNoRecipientConfiguredSendsNothing(): void
    {
        $mailer = $this->fakeMailer();
        $previous = $GLOBALS['TYPO3_CONF_VARS']['BE']['warning_email_addr'] ?? null;
        $GLOBALS['TYPO3_CONF_VARS']['BE']['warning_email_addr'] = '';
        try {
            $sent = $this->alert($mailer, null)->send('form 504', '', '', new \RuntimeException('x'));
        } finally {
            $GLOBALS['TYPO3_CONF_VARS']['BE']['warning_email_addr'] = $previous;
        }

        self::assertFalse($sent);
        self::assertSame([], $mailer->sent);
    }
}
