<?php

declare(strict_types=1);

namespace Undkonsorten\Powermailpdf\Storage;

use TYPO3\CMS\Core\Crypto\HashAlgo;
use TYPO3\CMS\Core\Crypto\HashService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Our expiry on top of core's eID=dumpFile token, which never expires on its own: `exp`
 * (unix time) and `sig` (HMAC of "<file uid>|<exp>" under our own purpose string).
 */
final class InvoiceLinkSignature
{
    public const LIFETIME = 86400;
    private const PURPOSE = 'powermailpdfInvoiceLink';

    /** @var \Closure(string): string */
    private \Closure $hmac;

    /** @param (\Closure(string): string)|null $hmac test seam; defaults to TYPO3's HashService */
    public function __construct(?\Closure $hmac = null)
    {
        $this->hmac = $hmac ?? static fn(string $data): string => GeneralUtility::makeInstance(HashService::class)
            ->hmac($data, self::PURPOSE, HashAlgo::SHA3_256);
    }

    /** @return array{exp: int, sig: string} */
    public function sign(int $fileUid, int $now): array
    {
        $exp = $now + self::LIFETIME;
        return ['exp' => $exp, 'sig' => ($this->hmac)($fileUid . '|' . $exp)];
    }

    public function isValid(int $fileUid, string $exp, string $sig, int $now): bool
    {
        if ($sig === '' || !ctype_digit($exp)) {
            return false;
        }
        if ((int)$exp <= $now) {
            return false;
        }
        return hash_equals(($this->hmac)($fileUid . '|' . $exp), $sig);
    }
}
