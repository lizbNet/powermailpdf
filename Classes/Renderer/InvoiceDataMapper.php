<?php

declare(strict_types=1);

namespace Undkonsorten\Powermailpdf\Renderer;

/**
 * Turns a submission's answers (by marker) and the form's renderer settings into the
 * {invoice} and {seller} variables of a Fluid invoice template.
 *
 * Amounts: the gross total is the submitted invoice_price_all (sw_connector copies the
 * total from invoice_price); net and VAT are derived here from the total with the same
 * formula as the form's script (net = round(gross / (1 + rate), 2), VAT = gross - net), so a
 * browser-supplied net or VAT value never reaches the document. The unit price is the total
 * divided by the number of participants.
 */
final class InvoiceDataMapper
{
    /**
     * @param array<string, mixed> $answers marker => value (string or list for checkboxes)
     * @param array<string, mixed> $config plain renderer settings: itemName, vatRate, seller{...}
     * @return array{invoice: array<string, mixed>, seller: array<string, string>}
     */
    public function map(array $answers, array $config): array
    {
        $vatRate = (int)($config['vatRate'] ?? 23);
        $quantity = max(1, (int)$this->text($answers, 'event_participants_number'));
        $gross = $this->amount($this->text($answers, 'invoice_price_all') ?: $this->text($answers, 'invoice_price'));
        $net = round($gross / (1 + $vatRate / 100), 2);
        $vat = round($gross - $net, 2);

        $buyerName = $this->text($answers, 'invoice_name_all');
        if ($buyerName === '') {
            $buyerName = trim($this->text($answers, 'name') . ' ' . $this->text($answers, 'surname'));
        }

        return [
            'invoice' => [
                'number' => $this->text($answers, 'invoice_number'),
                'issueDate' => $this->issueDate($this->text($answers, 'invoice_date')),
                'code' => $this->text($answers, 'invoice_code_all'),
                'buyer' => [
                    'name' => $buyerName,
                    'street' => $this->text($answers, 'invoice_address'),
                    'postalCode' => $this->text($answers, 'invoice_postal'),
                    'city' => $this->text($answers, 'invoice_city'),
                    'nip' => $this->nip($this->text($answers, 'invoice_nip')),
                ],
                'items' => [[
                    'name' => (string)($config['itemName'] ?? ''),
                    'quantity' => $quantity,
                    'unitGross' => round($gross / $quantity, 2),
                    'net' => $net,
                    'vatRate' => $vatRate,
                    'vat' => $vat,
                    'gross' => $gross,
                ]],
                'vatRate' => $vatRate,
                'totalNet' => $net,
                'totalVat' => $vat,
                'totalGross' => $gross,
                'amountInWords' => $this->text($answers, 'invoice_price_text'),
                'notes' => (string)($config['notes'] ?? ''),
            ],
            'seller' => array_map('strval', (array)($config['seller'] ?? [])),
        ];
    }

    /** @param array<string, mixed> $answers */
    private function text(array $answers, string $marker): string
    {
        $value = $answers[$marker] ?? '';
        if (is_array($value)) {
            $value = implode(', ', array_map('strval', $value));
        }
        return trim((string)$value);
    }

    /** "1 234,50", "1234.50", "350" -> float */
    private function amount(string $value): float
    {
        $value = str_replace([' ', "\u{00A0}"], '', $value);
        if (str_contains($value, ',') && !str_contains($value, '.')) {
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '', $value);
        }
        return is_numeric($value) ? round((float)$value, 2) : 0.0;
    }

    /** sw_connector stores "06.11.2026r."; the document shows "06.11.2026 r." */
    private function issueDate(string $value): string
    {
        return preg_replace('/^(\d{2}\.\d{2}\.\d{4})\s*r\.?$/u', '$1 r.', $value) ?? $value;
    }

    /** "5270209936" -> "527-020-99-36"; anything else is kept as submitted */
    private function nip(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';
        if (strlen($digits) !== 10) {
            return $value;
        }
        return substr($digits, 0, 3) . '-' . substr($digits, 3, 3) . '-' . substr($digits, 6, 2) . '-' . substr($digits, 8, 2);
    }
}
