<?php

declare(strict_types=1);

namespace Undkonsorten\Powermailpdf\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use Undkonsorten\Powermailpdf\Storage\InvoiceLinkSignature;

final class InvoiceLinkSignatureTest extends TestCase
{
    private function signature(): InvoiceLinkSignature
    {
        // fixed key, so the tests need no TYPO3 encryption key
        return new InvoiceLinkSignature(static fn(string $data): string => hash_hmac('sha256', $data, 'test-key'));
    }

    public function testSignedLinkVerifiesBeforeItExpires(): void
    {
        $params = $this->signature()->sign(42, 1_000_000);

        self::assertSame(1_000_000 + 86400, $params['exp']);
        self::assertTrue($this->signature()->isValid(42, (string)$params['exp'], $params['sig'], 1_000_000 + 86399));
    }

    public function testExpiredLinkIsRejected(): void
    {
        $params = $this->signature()->sign(42, 1_000_000);

        self::assertFalse($this->signature()->isValid(42, (string)$params['exp'], $params['sig'], 1_000_000 + 86400));
    }

    public function testChangedExpiryIsRejected(): void
    {
        $params = $this->signature()->sign(42, 1_000_000);

        self::assertFalse($this->signature()->isValid(42, (string)($params['exp'] + 3600), $params['sig'], 1_000_000));
    }

    public function testSignatureOfAnotherFileIsRejected(): void
    {
        $params = $this->signature()->sign(42, 1_000_000);

        self::assertFalse($this->signature()->isValid(43, (string)$params['exp'], $params['sig'], 1_000_000));
    }

    public function testMissingOrMalformedParametersAreRejected(): void
    {
        $params = $this->signature()->sign(42, 1_000_000);

        self::assertFalse($this->signature()->isValid(42, '', $params['sig'], 1_000_000));
        self::assertFalse($this->signature()->isValid(42, (string)$params['exp'], '', 1_000_000));
        self::assertFalse($this->signature()->isValid(42, 'abc', $params['sig'], 1_000_000));
    }
}
