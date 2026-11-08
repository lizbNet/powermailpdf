<?php

declare(strict_types=1);

namespace Undkonsorten\Powermailpdf\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Crypto\HashService;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ResponseFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Resource\Event\ModifyFileDumpEvent;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use Undkonsorten\Powermailpdf\EventListener\InvoiceFileDumpGuard;
use Undkonsorten\Powermailpdf\Storage\InvoiceFileStorage;
use Undkonsorten\Powermailpdf\Storage\InvoiceLinkBuilder;
use Undkonsorten\Powermailpdf\Storage\InvoiceLinkSignature;

/**
 * The link builder and the eID=dumpFile guard together: a link the builder hands out passes the
 * guard; tampered, expired or foreign links don't. (That core accepts the builder's token was
 * proven against a live ddev request: 200 with the PDF.)
 */
final class InvoiceLinkTest extends TestCase
{
    private const INVOICE_STORAGE = 5;
    private ?string $previousKey = null;

    protected function setUp(): void
    {
        $this->previousKey = $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? null;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'unit-test-encryption-key-0123456789abcdef';
        Environment::initialize(
            new ApplicationContext('Testing'),
            true,
            true,
            '/project',
            '/project/public',
            '/project/var',
            '/project/config',
            '/project/public/index.php',
            'UNIX'
        );
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = $this->previousKey;
    }

    private function storageFake(): InvoiceFileStorage
    {
        return new class (self::INVOICE_STORAGE) implements InvoiceFileStorage {
            public function __construct(private readonly int $uid)
            {
            }

            public function contains(FileInterface $file): bool
            {
                return $file->getStorage()->getUid() === $this->uid;
            }
        };
    }

    private function signature(): InvoiceLinkSignature
    {
        return new InvoiceLinkSignature(static fn(string $data): string => hash_hmac('sha256', $data, 'sig-key'));
    }

    private function file(int $uid, int $storageUid, bool $missing = false): File
    {
        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getUid')->willReturn($storageUid);
        $storage->method('streamFile')->willReturn(new Response('php://temp', 200, ['Content-Type' => 'application/pdf']));
        $file = $this->createMock(File::class);
        $file->method('getUid')->willReturn($uid);
        $file->method('getStorage')->willReturn($storage);
        $file->method('isMissing')->willReturn($missing);
        return $file;
    }

    private function builder(?File $file): InvoiceLinkBuilder
    {
        $factory = $this->createMock(ResourceFactory::class);
        if ($file === null) {
            $factory->method('getFileObject')->willThrowException(new \RuntimeException('no such file'));
        } else {
            $factory->method('getFileObject')->willReturn($file);
        }
        return new InvoiceLinkBuilder($this->storageFake(), $this->signature(), new HashService(), $factory);
    }

    /** @return array<string, string> */
    private function query(string $url): array
    {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        return $query;
    }

    private function guard(File $file, array $query): ?ResponseInterface
    {
        $event = new ModifyFileDumpEvent($file, (new ServerRequest('https://forms.example/index.php'))->withQueryParams($query));
        (new InvoiceFileDumpGuard($this->storageFake(), $this->signature(), new ResponseFactory()))($event);
        return $event->getResponse();
    }

    public function testBuilderLinksAnInvoiceFileThroughDumpFileWithExpiry(): void
    {
        $query = $this->query($this->builder($this->file(42, self::INVOICE_STORAGE))->forFileUid('42'));

        self::assertSame('dumpFile', $query['eID']);
        self::assertSame('f', $query['t']);
        self::assertSame('42', $query['f']);
        self::assertSame('1', $query['dl']);
        self::assertNotSame('', $query['token']);
        self::assertGreaterThan(time() + 86000, (int)$query['exp']);
        self::assertLessThanOrEqual(time() + 86400, (int)$query['exp']);
    }

    public function testBuilderRefusesFilesOutsideTheInvoiceStorage(): void
    {
        self::assertSame('', $this->builder($this->file(42, 1))->forFileUid(42));
    }

    public function testBuilderRefusesMissingUnknownOrEmptyUids(): void
    {
        self::assertSame('', $this->builder($this->file(42, self::INVOICE_STORAGE, true))->forFileUid(42));
        self::assertSame('', $this->builder(null)->forFileUid(999));
        self::assertSame('', $this->builder($this->file(42, self::INVOICE_STORAGE))->forFileUid(''));
        self::assertSame('', $this->builder($this->file(42, self::INVOICE_STORAGE))->forFileUid('abc'));
        self::assertSame('', $this->builder($this->file(42, self::INVOICE_STORAGE))->forFileUid(null));
    }

    public function testGuardStreamsABuiltLinkWithNoStore(): void
    {
        $file = $this->file(42, self::INVOICE_STORAGE);
        $query = $this->query($this->builder($file)->forFileUid(42));

        $response = $this->guard($file, $query);

        self::assertNotNull($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testGuardRejectsAChangedExpiryOrMissingSignature(): void
    {
        $file = $this->file(42, self::INVOICE_STORAGE);
        $query = $this->query($this->builder($file)->forFileUid(42));

        self::assertSame(403, $this->guard($file, ['exp' => (string)((int)$query['exp'] + 3600)] + $query)->getStatusCode());
        $withoutSig = $query;
        unset($withoutSig['sig']);
        self::assertSame(403, $this->guard($file, $withoutSig)->getStatusCode());
    }

    public function testGuardRejectsAnExpiredLinkWithACorrectSignature(): void
    {
        $file = $this->file(42, self::INVOICE_STORAGE);
        $expired = $this->signature()->sign(42, time() - 90000);

        self::assertSame(403, $this->guard($file, ['exp' => (string)$expired['exp'], 'sig' => $expired['sig']])->getStatusCode());
    }

    public function testGuardRejectsASignatureMadeForAnotherFile(): void
    {
        $other = $this->query($this->builder($this->file(43, self::INVOICE_STORAGE))->forFileUid(43));

        self::assertSame(403, $this->guard($this->file(42, self::INVOICE_STORAGE), $other)->getStatusCode());
    }

    public function testGuardLeavesFilesInOtherStoragesToCore(): void
    {
        self::assertNull($this->guard($this->file(42, 1), []));
    }
}
