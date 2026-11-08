<?php

declare(strict_types=1);

namespace Undkonsorten\Powermailpdf\Tests\Unit\Renderer;

use PHPUnit\Framework\TestCase;
use Undkonsorten\Powermailpdf\Renderer\InvoiceDataMapper;

final class InvoiceDataMapperTest extends TestCase
{
    private const CONFIG = [
        'itemName' => 'Udział w konferencji „Dziecko w ubezpieczeniach” w dn. 10.12.2026',
        'vatRate' => '23',
        'seller' => ['name' => 'NewEvents s.c.', 'nip' => '958-156-38-66'],
    ];

    private function company(array $overrides = []): array
    {
        return $overrides + [
            'registration_type' => 'firma',
            'event_participants_number' => '2',
            'invoice_price' => '700',
            'invoice_price_all' => '700.00',
            'invoice_price_netto' => '1.00', // browser-supplied, must be ignored
            'invoice_vat_amount' => '1.00',  // browser-supplied, must be ignored
            'invoice_price_text' => 'siedemset złotych 00/100',
            'invoice_number' => '001/11/F/2026',
            'invoice_date' => '08.11.2026r.',
            'invoice_code_all' => 'DWU26-11-F001',
            'invoice_name_all' => 'Akademia Łazarskiego',
            'invoice_address' => 'ul. Świeradowska 43',
            'invoice_postal' => '02-662',
            'invoice_city' => 'Warszawa',
            'invoice_nip' => '5270209936',
        ];
    }

    public function testCompanyWithTwoParticipants(): void
    {
        $data = (new InvoiceDataMapper())->map($this->company(), self::CONFIG);
        $invoice = $data['invoice'];

        self::assertSame('001/11/F/2026', $invoice['number']);
        self::assertSame('08.11.2026 r.', $invoice['issueDate']);
        self::assertSame('DWU26-11-F001', $invoice['code']);
        self::assertSame('Akademia Łazarskiego', $invoice['buyer']['name']);
        self::assertSame('527-020-99-36', $invoice['buyer']['nip']);
        self::assertSame(2, $invoice['items'][0]['quantity']);
        self::assertSame(350.0, $invoice['items'][0]['unitGross']);
        self::assertSame(700.0, $invoice['totalGross']);
        self::assertSame(569.11, $invoice['totalNet']);
        self::assertSame(130.89, $invoice['totalVat']);
        self::assertSame(self::CONFIG['itemName'], $invoice['items'][0]['name']);
        self::assertSame('NewEvents s.c.', $data['seller']['name']);
    }

    public function testNetAndVatMatchTheFormScriptForOneParticipant(): void
    {
        $invoice = (new InvoiceDataMapper())->map(
            $this->company(['event_participants_number' => '1', 'invoice_price' => '350', 'invoice_price_all' => '350.00']),
            self::CONFIG
        )['invoice'];

        // registration.js: (350 / 1.23).toFixed(2) = 284.55, 350 - 284.55 = 65.45
        self::assertSame(284.55, $invoice['totalNet']);
        self::assertSame(65.45, $invoice['totalVat']);
        self::assertSame(350.0, $invoice['items'][0]['unitGross']);
    }

    public function testPersonWithoutInvoiceDataFallsBackToTheContactName(): void
    {
        $answers = [
            'registration_type' => 'osoba',
            'event_participants_number' => '1',
            'invoice_price_all' => '350.00',
            'name' => 'Jan',
            'surname' => 'Testowy',
            'invoice_name_all' => ' ',
            'invoice_nip' => '',
        ];

        $invoice = (new InvoiceDataMapper())->map($answers, self::CONFIG)['invoice'];

        self::assertSame('Jan Testowy', $invoice['buyer']['name']);
        self::assertSame('', $invoice['buyer']['nip']);
        self::assertSame('', $invoice['buyer']['street']);
    }

    public function testMissingParticipantCountMeansOne(): void
    {
        $invoice = (new InvoiceDataMapper())->map(
            $this->company(['event_participants_number' => '']),
            self::CONFIG
        )['invoice'];

        self::assertSame(1, $invoice['items'][0]['quantity']);
        self::assertSame(700.0, $invoice['items'][0]['unitGross']);
    }

    public function testAmountsWithCommaOrThousandsSeparators(): void
    {
        $mapper = new InvoiceDataMapper();

        self::assertSame(1234.5, $mapper->map($this->company(['invoice_price_all' => '1 234,50']), self::CONFIG)['invoice']['totalGross']);
        self::assertSame(1234.5, $mapper->map($this->company(['invoice_price_all' => '1,234.50']), self::CONFIG)['invoice']['totalGross']);
        self::assertSame(0.0, $mapper->map($this->company(['invoice_price_all' => 'abc', 'invoice_price' => '']), self::CONFIG)['invoice']['totalGross']);
    }

    public function testUnusualNipIsKeptAsSubmitted(): void
    {
        $invoice = (new InvoiceDataMapper())->map($this->company(['invoice_nip' => 'DE123456789']), self::CONFIG)['invoice'];

        self::assertSame('DE123456789', $invoice['buyer']['nip']);
    }

    public function testCheckboxAnswersAreJoined(): void
    {
        $invoice = (new InvoiceDataMapper())->map($this->company(['invoice_city' => ['Warszawa']]), self::CONFIG)['invoice'];

        self::assertSame('Warszawa', $invoice['buyer']['city']);
    }
}
