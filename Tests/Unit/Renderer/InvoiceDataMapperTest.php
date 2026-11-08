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

    /** CEVF shape (issue 07): items name their own amount markers; dinner is a second line. */
    private const CEVF = [
        'currency' => 'EUR',
        'vatRate' => '23',
        'seller' => ['name' => 'NewEvents s.c.'],
        'items' => [
            '10' => ['name' => 'Udział w konferencji CEVF', 'quantity' => 'event_participants_number',
                'gross' => 'invoice_price1_brutto', 'net' => 'invoice_price1_netto', 'vat' => 'invoice_vat1_amount'],
            '20' => ['name' => 'Gala Dinner', 'quantity' => '1',
                'gross' => 'invoice_price2_brutto', 'net' => 'invoice_price2_netto', 'vat' => 'invoice_vat2_amount',
                'skipWhenEmpty' => '1'],
        ],
        'totals' => ['gross' => 'invoice_price_all_brutto', 'net' => 'invoice_price_all_netto', 'vat' => 'invoice_vat_all_amount'],
    ];

    private function cevf(array $overrides = []): array
    {
        return $overrides + [
            'event_participants_number' => '1',
            'invoice_price1_brutto' => '615.00', 'invoice_price1_netto' => '500.00', 'invoice_vat1_amount' => '115.00',
            'invoice_price2_brutto' => '123.00', 'invoice_price2_netto' => '100.00', 'invoice_vat2_amount' => '23.00',
            'invoice_price_all_brutto' => '738.00', 'invoice_price_all_netto' => '600.00', 'invoice_vat_all_amount' => '138.00',
            'invoice_number' => '001/10/F/2026', 'invoice_code_all' => 'CEVF26-10-001', 'invoice_name_all' => 'Firma',
        ];
    }

    public function testConfiguredItemsUseTheirOwnAmountMarkers(): void
    {
        $invoice = (new InvoiceDataMapper())->map($this->cevf(), self::CEVF)['invoice'];

        self::assertCount(2, $invoice['items']);
        self::assertSame('Udział w konferencji CEVF', $invoice['items'][0]['name']);
        self::assertSame(615.0, $invoice['items'][0]['gross']);
        self::assertSame(500.0, $invoice['items'][0]['net']);
        self::assertSame(115.0, $invoice['items'][0]['vat']);
        self::assertSame('Gala Dinner', $invoice['items'][1]['name']);
        self::assertSame(1, $invoice['items'][1]['quantity']);
        self::assertSame(123.0, $invoice['items'][1]['unitGross']);
        self::assertSame(738.0, $invoice['totalGross']);
        self::assertSame(600.0, $invoice['totalNet']);
        self::assertSame(138.0, $invoice['totalVat']);
        self::assertSame('EUR', $invoice['currency']);
    }

    public function testEmptyItemWithSkipWhenEmptyIsLeftOut(): void
    {
        $invoice = (new InvoiceDataMapper())->map(
            $this->cevf(['invoice_price2_brutto' => '', 'invoice_price2_netto' => '', 'invoice_vat2_amount' => '',
                'invoice_price_all_brutto' => '615.00', 'invoice_price_all_netto' => '500.00', 'invoice_vat_all_amount' => '115.00']),
            self::CEVF
        )['invoice'];

        self::assertCount(1, $invoice['items']);
        self::assertSame(615.0, $invoice['totalGross']);
    }

    public function testItemWithoutNetAndVatMarkersDerivesThemFromGross(): void
    {
        $config = self::CEVF;
        unset($config['items']['10']['net'], $config['items']['10']['vat'], $config['totals']);
        $invoice = (new InvoiceDataMapper())->map($this->cevf(['invoice_price2_brutto' => '']), $config)['invoice'];

        // 615 / 1.23 = 500.00, VAT 115.00; no totals markers → sums of the items
        self::assertSame(500.0, $invoice['items'][0]['net']);
        self::assertSame(115.0, $invoice['items'][0]['vat']);
        self::assertSame(615.0, $invoice['totalGross']);
        self::assertSame(500.0, $invoice['totalNet']);
    }

    public function testVatSummaryGroupsItemsByRate(): void
    {
        $config = self::CEVF;
        $config['items']['20']['vatRate'] = '8';
        $invoice = (new InvoiceDataMapper())->map($this->cevf(), $config)['invoice'];

        self::assertSame([
            ['rate' => 23, 'net' => 500.0, 'vat' => 115.0, 'gross' => 615.0],
            ['rate' => 8, 'net' => 100.0, 'vat' => 23.0, 'gross' => 123.0],
        ], $invoice['vatSummary']);
    }

    public function testConfiguredItemsWithoutAnyAmountAreRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        (new InvoiceDataMapper())->map($this->cevf(['invoice_price1_brutto' => '', 'invoice_price2_brutto' => '',
            'invoice_price_all_brutto' => '']), self::CEVF);
    }

    public function testRequiredItemWithoutAmountFailsEvenIfAnotherHasOne(): void
    {
        $this->expectException(\RuntimeException::class);
        // the conference line is required; only the optional dinner has an amount
        (new InvoiceDataMapper())->map($this->cevf(['invoice_price1_brutto' => '']), self::CEVF);
    }

    public function testSingleRateRowEqualsTheTotalsFromMarkers(): void
    {
        // two 25.00 lines: per-line nets 20.33 + 20.33 = 40.66, but the form's total net is 40.65
        $answers = $this->cevf([
            'invoice_price1_brutto' => '25.00', 'invoice_price1_netto' => '20.33', 'invoice_vat1_amount' => '4.67',
            'invoice_price2_brutto' => '25.00', 'invoice_price2_netto' => '20.33', 'invoice_vat2_amount' => '4.67',
            'invoice_price_all_brutto' => '50.00', 'invoice_price_all_netto' => '40.65', 'invoice_vat_all_amount' => '9.35',
        ]);
        $invoice = (new InvoiceDataMapper())->map($answers, self::CEVF)['invoice'];

        self::assertSame([['rate' => 23, 'net' => 40.65, 'vat' => 9.35, 'gross' => 50.0]], $invoice['vatSummary']);
    }

    public function testTotalsThatDoNotMatchTheLinesAreRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        (new InvoiceDataMapper())->map($this->cevf(['invoice_price_all_brutto' => '999.00']), self::CEVF);
    }

    public function testCurrencyComesFromTheSubmittedPriceCurrencyFirst(): void
    {
        $config = self::CEVF;
        $config['currency'] = 'PLN';
        $invoice = (new InvoiceDataMapper())->map($this->cevf(['price_currency' => ['eur']]), $config)['invoice'];

        self::assertSame('EUR', $invoice['currency']);
    }

    public function testBlankCurrencyFallsBackToPln(): void
    {
        $config = self::CONFIG;
        $config['currency'] = '   ';
        self::assertSame('PLN', (new InvoiceDataMapper())->map($this->company(), $config)['invoice']['currency']);
    }

    public function testDefaultsWithoutItemsConfigStayAsForDwu(): void
    {
        $invoice = (new InvoiceDataMapper())->map($this->company(), self::CONFIG)['invoice'];

        self::assertSame('PLN', $invoice['currency']);
        self::assertSame([['rate' => 23, 'net' => 569.11, 'vat' => 130.89, 'gross' => 700.0]], $invoice['vatSummary']);
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
    }

    /** Issue 05: no invoice PDF with 0,00 totals; a missing amount takes the failure path (alert, mails without PDF). */
    public function testMissingAmountIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        (new InvoiceDataMapper())->map($this->company(['invoice_price_all' => '', 'invoice_price' => '']), self::CONFIG);
    }

    public function testNonNumericAmountIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        (new InvoiceDataMapper())->map($this->company(['invoice_price_all' => 'abc', 'invoice_price' => '']), self::CONFIG);
    }

    public function testZeroAmountIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        (new InvoiceDataMapper())->map($this->company(['invoice_price_all' => '0.00', 'invoice_price' => '0']), self::CONFIG);
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
