<?php

declare(strict_types=1);

namespace Undkonsorten\Powermailpdf\Renderer;

/**
 * Turns a submission's answers (by marker) and the form's renderer settings into the
 * {invoice} and {seller} variables of a Fluid invoice template.
 *
 * Default (DWU): one line; the gross total is the submitted invoice_price_all (sw_connector
 * copies the total from invoice_price); net and VAT are derived here with the form script's
 * formula (net = round(gross / (1 + rate), 2), VAT = gross - net), so a browser-supplied net or
 * VAT never reaches the document; unit price = total / participants.
 *
 * fluid.items (issue 07, e.g. CEVF): several lines, each naming its own amount markers; an
 * optional line (skipWhenEmpty) without amount is left out. Net and VAT come from the named
 * markers when configured (CEVF fills them server-side with the pricing pilot), else they are
 * derived from gross as above. fluid.totals names the total markers, else the totals are the sums
 * of the lines; a total that doesn't match its lines fails the PDF. Currency: price_currency
 * answer, then fluid.currency, then PLN. A line's gross is the line total: with participants of
 * different prices the unit price printed is their average (issue 07, open).
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
        $items = is_array($config['items'] ?? null) && $config['items'] !== []
            ? $this->configuredItems($answers, $config['items'], $vatRate)
            : [$this->singleItem($answers, (string)($config['itemName'] ?? ''), $vatRate)];
        $items = array_values(array_filter($items));

        $totals = is_array($config['totals'] ?? null) ? $config['totals'] : [];
        $totalGross = $this->markerAmount($answers, $totals['gross'] ?? null) ?? $this->sum($items, 'gross');
        // Issue 05: a missing, unreadable or zero amount is a failure (Admin Alert, mails without the
        // PDF), never an invoice PDF with 0,00 totals. Free registrations get no PDF at all (skipWhen).
        if ($items === [] || $totalGross <= 0.0) {
            throw new \RuntimeException('No invoice amount (gross of the line items or total)', 1762800001);
        }
        // Contradicting numbers on one document are worse than no document: fail into the alert path.
        if (abs($totalGross - $this->sum($items, 'gross')) > 0.01) {
            throw new \RuntimeException(sprintf('Invoice total %.2f does not match its lines (%.2f)', $totalGross, $this->sum($items, 'gross')), 1762800002);
        }
        $totalNet = $this->markerAmount($answers, $totals['net'] ?? null) ?? $this->sum($items, 'net');
        $totalVat = $this->markerAmount($answers, $totals['vat'] ?? null) ?? $this->sum($items, 'vat');
        $vatSummary = $this->vatSummary($items);
        // One rate: the summary row is the total, so rounding per line can't make the two rows differ.
        if (count($vatSummary) === 1) {
            $vatSummary[0] = ['rate' => $vatSummary[0]['rate'], 'net' => $totalNet, 'vat' => $totalVat, 'gross' => $totalGross];
        }

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
                'items' => $items,
                'vatSummary' => $vatSummary,
                'vatRate' => $vatRate,
                'totalNet' => $totalNet,
                'totalVat' => $totalVat,
                'totalGross' => $totalGross,
                'currency' => $this->currency($answers, $config),
                'amountInWords' => $this->text($answers, 'invoice_price_text'),
                'notes' => (string)($config['notes'] ?? ''),
            ],
            'seller' => array_map('strval', (array)($config['seller'] ?? [])),
        ];
    }

    /**
     * DWU's original shape: one line, total from invoice_price_all (fallback invoice_price), net and
     * VAT derived server-side, unit price = total / participants.
     *
     * @return array<string, mixed>|null
     */
    private function singleItem(array $answers, string $name, int $vatRate): ?array
    {
        $gross = $this->amount($this->text($answers, 'invoice_price_all') ?: $this->text($answers, 'invoice_price'));
        if ($gross <= 0.0) {
            return null;
        }
        return $this->item($name, max(1, (int)$this->text($answers, 'event_participants_number')), $gross, null, null, $vatRate);
    }

    /**
     * fluid.items.<key> { name, quantity (marker or number), gross, net, vat (markers), vatRate,
     * skipWhenEmpty }. Without net/vat markers they are derived from gross.
     *
     * @param array<string|int, mixed> $definitions
     * @return list<array<string, mixed>>
     */
    private function configuredItems(array $answers, array $definitions, int $defaultRate): array
    {
        ksort($definitions, SORT_NATURAL);
        $items = [];
        foreach ($definitions as $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $gross = $this->amount($this->text($answers, (string)($definition['gross'] ?? '')));
            if ($gross <= 0.0) {
                // an optional line (dinner) left empty; a required one makes the whole PDF fail
                if ((bool)($definition['skipWhenEmpty'] ?? false)) {
                    continue;
                }
                return [];
            }
            $quantity = (string)($definition['quantity'] ?? '1');
            $quantity = ctype_digit($quantity) ? (int)$quantity : (int)$this->text($answers, $quantity);
            $net = $this->markerAmount($answers, $definition['net'] ?? null);
            $vat = $this->markerAmount($answers, $definition['vat'] ?? null);
            $items[] = $this->item((string)($definition['name'] ?? ''), max(1, $quantity), $gross, $net, $vat,
                (int)($definition['vatRate'] ?? $defaultRate));
        }
        return $items;
    }

    /** @return array<string, mixed> */
    private function item(string $name, int $quantity, float $gross, ?float $net, ?float $vat, int $vatRate): array
    {
        $net ??= round($gross / (1 + $vatRate / 100), 2);
        $vat ??= round($gross - $net, 2);
        return [
            'name' => $name,
            'quantity' => $quantity,
            'unitGross' => round($gross / $quantity, 2),
            'net' => $net,
            'vatRate' => $vatRate,
            'vat' => $vat,
            'gross' => $gross,
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array{rate: int, net: float, vat: float, gross: float}>
     */
    private function vatSummary(array $items): array
    {
        $byRate = [];
        foreach ($items as $item) {
            $rate = (int)$item['vatRate'];
            $byRate[$rate] ??= ['rate' => $rate, 'net' => 0.0, 'vat' => 0.0, 'gross' => 0.0];
            foreach (['net', 'vat', 'gross'] as $key) {
                $byRate[$rate][$key] = round($byRate[$rate][$key] + (float)$item[$key], 2);
            }
        }
        return array_values($byRate);
    }

    /** Amount of the marker named in the settings; null when the setting is absent. */
    private function markerAmount(array $answers, mixed $marker): ?float
    {
        return is_string($marker) && $marker !== '' ? $this->amount($this->text($answers, $marker)) : null;
    }

    /**
     * The submitted price_currency first (sw_connector decides on the amount in words from it, so
     * code and words agree), then fluid.currency, then PLN.
     */
    private function currency(array $answers, array $config): string
    {
        foreach ([$this->text($answers, 'price_currency'), (string)($config['currency'] ?? '')] as $candidate) {
            $candidate = strtoupper(trim($candidate));
            if ($candidate !== '') {
                return $candidate;
            }
        }
        return 'PLN';
    }

    /** @param list<array<string, mixed>> $items */
    private function sum(array $items, string $key): float
    {
        return round(array_sum(array_map(static fn(array $item): float => (float)$item[$key], $items)), 2);
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
