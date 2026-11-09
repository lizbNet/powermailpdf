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
 * of the lines. Gross, net and VAT totals must match the lines and net + VAT the gross (each line
 * too), within 0.01, else the PDF fails. A configured net/VAT marker with an empty answer counts as
 * not configured (derived). Currency: fluid.currency (default PLN), the form's single account; a
 * submission in another currency fails the PDF.
 *
 * Per-participant prices: a line with participantGross = p{nn}_price prints one row per distinct
 * participant price (quantity x unit price) instead of an average; its net/VAT markers are then
 * not used (derived per row).
 * fluid.event { name, date }: "{event.name}" in a line name or itemName. fluid.markers.<role>
 * renames a marker (DEFAULT_MARKERS lists the roles); an unknown role fails the PDF.
 */
final class InvoiceDataMapper
{
    /**
     * Role => marker. fluid.markers.<role> replaces one for forms whose fields are named differently;
     * gross, grossFallback and participants feed the single line (without fluid.items).
     */
    private const DEFAULT_MARKERS = [
        'buyerName' => 'invoice_name_all',
        'firstName' => 'name',
        'lastName' => 'surname',
        'street' => 'invoice_address',
        'postalCode' => 'invoice_postal',
        'city' => 'invoice_city',
        'nip' => 'invoice_nip',
        'number' => 'invoice_number',
        'date' => 'invoice_date',
        'code' => 'invoice_code_all',
        'amountInWords' => 'invoice_price_text',
        'currency' => 'price_currency',
        'gross' => 'invoice_price_all',
        'grossFallback' => 'invoice_price',
        'participants' => 'event_participants_number',
    ];

    /**
     * @param array<string, mixed> $answers marker => value (string or list for checkboxes)
     * @param array<string, mixed> $config plain renderer settings: itemName, vatRate, seller{...}
     * @return array{invoice: array<string, mixed>, seller: array<string, string>}
     */
    public function map(array $answers, array $config): array
    {
        $markers = $this->markers($config);
        $vatRate = (int)($config['vatRate'] ?? 23);
        $event = array_map('strval', is_array($config['event'] ?? null) ? $config['event'] : []);
        $items = is_array($config['items'] ?? null) && $config['items'] !== []
            ? $this->configuredItems($answers, $config['items'], $vatRate)
            : [$this->singleItem($answers, $markers, (string)($config['itemName'] ?? ''), $vatRate)];
        $items = array_map(fn(array $item): array => ['name' => $this->withEvent($item['name'], $event)] + $item, array_values(array_filter($items)));

        $totals = is_array($config['totals'] ?? null) ? $config['totals'] : [];
        $totalGross = $this->markerAmount($answers, $totals['gross'] ?? null) ?? $this->sum($items, 'gross');
        // Issue 05: a missing, unreadable or zero amount is a failure (Admin Alert, mails without the
        // PDF), never an invoice PDF with 0,00 totals. Free registrations get no PDF at all (skipWhen).
        if ($items === [] || $totalGross <= 0.0) {
            throw new \RuntimeException('No invoice amount (gross of the line items or total)', 1762800001);
        }
        $totalNet = $this->markerAmount($answers, $totals['net'] ?? null) ?? $this->sum($items, 'net');
        $totalVat = $this->markerAmount($answers, $totals['vat'] ?? null) ?? $this->sum($items, 'vat');
        // Contradicting numbers on one document are worse than no document: fail into the alert path.
        $this->assertConsistent($items, $totalGross, $totalNet, $totalVat);
        $vatSummary = $this->vatSummary($items);
        // One rate: the summary row is the total, so rounding per line can't make the two rows differ.
        if (count($vatSummary) === 1) {
            $vatSummary[0] = ['rate' => $vatSummary[0]['rate'], 'net' => $totalNet, 'vat' => $totalVat, 'gross' => $totalGross];
        }

        $buyerName = $this->text($answers, $markers['buyerName']);
        if ($buyerName === '') {
            $buyerName = trim($this->text($answers, $markers['firstName']) . ' ' . $this->text($answers, $markers['lastName']));
        }

        return [
            'invoice' => [
                'number' => $this->text($answers, $markers['number']),
                'issueDate' => $this->issueDate($this->text($answers, $markers['date'])),
                'code' => $this->text($answers, $markers['code']),
                'buyer' => [
                    'name' => $buyerName,
                    'street' => $this->text($answers, $markers['street']),
                    'postalCode' => $this->text($answers, $markers['postalCode']),
                    'city' => $this->text($answers, $markers['city']),
                    'nip' => $this->nip($this->text($answers, $markers['nip'])),
                ],
                'items' => $items,
                'vatSummary' => $vatSummary,
                'vatRate' => $vatRate,
                'totalNet' => $totalNet,
                'totalVat' => $totalVat,
                'totalGross' => $totalGross,
                'currency' => $this->currency($answers, $config, $markers['currency']),
                'amountInWords' => $this->text($answers, $markers['amountInWords']),
                'notes' => (string)($config['notes'] ?? ''),
                'event' => $event,
            ],
            'seller' => array_map('strval', (array)($config['seller'] ?? [])),
        ];
    }

    /**
     * Default markers with fluid.markers applied. An unknown role is refused: a typo would otherwise
     * fall back to the default marker without anyone noticing.
     *
     * @return array<string, string>
     */
    private function markers(array $config): array
    {
        $configured = array_map('strval', array_filter(is_array($config['markers'] ?? null) ? $config['markers'] : [], static fn($v): bool => $v !== '' && $v !== null));
        $unknown = array_diff_key($configured, self::DEFAULT_MARKERS);
        if ($unknown !== []) {
            throw new \RuntimeException(sprintf('Unknown fluid.markers role(s): %s', implode(', ', array_keys($unknown))), 1762800006);
        }
        return $configured + self::DEFAULT_MARKERS;
    }

    /**
     * DWU's original shape: one line, total from the gross marker (fallback grossFallback), net and
     * VAT derived server-side, unit price = total / participants.
     *
     * @param array<string, string> $markers
     * @return array<string, mixed>|null
     */
    private function singleItem(array $answers, array $markers, string $name, int $vatRate): ?array
    {
        $gross = $this->amount($this->text($answers, $markers['gross']) ?: $this->text($answers, $markers['grossFallback']));
        if ($gross <= 0.0) {
            return null;
        }
        return $this->item($name, max(1, (int)$this->text($answers, $markers['participants'])), $gross, null, null, $vatRate);
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
            $name = (string)($definition['name'] ?? '');
            $rate = (int)($definition['vatRate'] ?? $defaultRate);
            $gross = $this->markerAmount($answers, $definition['gross'] ?? null);
            $perPrice = $this->participantPrices($answers, (string)($definition['participantGross'] ?? ''));
            if ($perPrice !== []) {
                $lineNet = $this->markerAmount($answers, $definition['net'] ?? null);
                array_push($items, ...$this->participantItems($name, $perPrice, $gross, $lineNet, $rate));
                continue;
            }
            $gross ??= 0.0;
            if ($gross <= 0.0) {
                // an optional line (dinner) left empty; a required one makes the whole PDF fail
                if ((bool)($definition['skipWhenEmpty'] ?? false)) {
                    continue;
                }
                throw new \RuntimeException(sprintf('Required invoice line "%s" has no amount', $name), 1762800003);
            }
            $quantity = (string)($definition['quantity'] ?? '1');
            $quantity = ctype_digit($quantity) ? (int)$quantity : (int)$this->text($answers, $quantity);
            $net = $this->markerAmount($answers, $definition['net'] ?? null);
            $vat = $this->markerAmount($answers, $definition['vat'] ?? null);
            $items[] = $this->item($name, max(1, $quantity), $gross, $net, $vat, $rate);
        }
        return $items;
    }

    /**
     * participantGross = p{nn}_price: each participant's price (pricing extension), {nn} = 01..99.
     * Returns unit price in minor units => number of participants, in order of first appearance.
     *
     * @return array<int, int>
     */
    private function participantPrices(array $answers, string $pattern): array
    {
        if (!str_contains($pattern, '{nn}')) {
            return [];
        }
        $counts = [];
        for ($nn = 1; $nn <= 99; $nn++) {
            $price = $this->markerAmount($answers, str_replace('{nn}', sprintf('%02d', $nn), $pattern));
            if ($price !== null && $price > 0.0) {
                $minor = (int)round($price * 100);
                $counts[$minor] = ($counts[$minor] ?? 0) + 1;
            }
        }
        return $counts;
    }

    /**
     * One line per distinct participant price (quantity x unit price), so no average is printed.
     * The line's own gross marker, when answered, must equal the participants' sum. Net is derived
     * per row, then reconciled: the pricing extension splits VAT on the line total, and rows split
     * one by one drift from it (2 grosze from four prices on), which would fail the totals check.
     * The largest row takes the difference against the line's net (its marker, else the split of
     * the sum), so the rows add up to the line.
     *
     * @param array<int, int> $perPrice
     * @return list<array<string, mixed>>
     */
    private function participantItems(string $name, array $perPrice, ?float $lineGross, ?float $lineNet, int $rate): array
    {
        $items = [];
        $sum = 0;
        foreach ($perPrice as $unitMinor => $count) {
            $sum += $unitMinor * $count;
            $items[] = $this->item($name, $count, round($unitMinor * $count / 100, 2), null, null, $rate);
        }
        if ($lineGross !== null && abs((int)round($lineGross * 100) - $sum) > 1) {
            throw new \RuntimeException(sprintf('Participant prices of "%s" (%.2f) do not add up to the line (%.2f)', $name, $sum / 100, $lineGross), 1762800005);
        }
        $targetNet = $lineNet !== null ? (int)round($lineNet * 100) : (int)round($sum / 100 / (1 + $rate / 100) * 100);
        $drift = $targetNet - array_sum(array_map(static fn(array $i): int => (int)round($i['net'] * 100), $items));
        // only rounding drift (under half a grosz per row) is absorbed; anything larger is a wrong net
        if (abs($drift) > intdiv(count($items) + 1, 2)) {
            throw new \RuntimeException(sprintf('Invoice amounts do not add up (net of "%s": %.2f vs participants %.2f)', $name, $targetNet / 100, ($targetNet - $drift) / 100), 1762800002);
        }
        if ($drift !== 0) {
            $largest = array_search(max(array_column($items, 'gross')), array_column($items, 'gross'), true);
            $net = round($items[$largest]['net'] + $drift / 100, 2);
            $items[$largest] = ['net' => $net, 'vat' => round($items[$largest]['gross'] - $net, 2)] + $items[$largest];
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

    /**
     * Amount of the marker named in the settings; null when the setting is absent or the answer is
     * empty, so callers fall back (derive from gross, sum the lines) instead of printing 0,00.
     */
    private function markerAmount(array $answers, mixed $marker): ?float
    {
        $value = is_string($marker) && $marker !== '' ? $this->text($answers, $marker) : '';
        return $value === '' ? null : $this->amount($value);
    }

    /** @param list<array<string, mixed>> $items */
    private function assertConsistent(array $items, float $gross, float $net, float $vat): void
    {
        $checks = [
            'gross total vs lines' => [$gross, $this->sum($items, 'gross')],
            'net total vs lines' => [$net, $this->sum($items, 'net')],
            'VAT total vs lines' => [$vat, $this->sum($items, 'vat')],
            'net + VAT vs gross total' => [round($net + $vat, 2), $gross],
        ];
        foreach ($items as $item) {
            $checks['net + VAT vs gross of "' . $item['name'] . '"'] = [round((float)$item['net'] + (float)$item['vat'], 2), (float)$item['gross']];
        }
        foreach ($checks as $what => [$a, $b]) {
            // Tolerance one grosz/cent, compared in whole minor units: line nets and the total net are
            // rounded separately (pricing pilot), and a float compare would refuse an exact 0.01 gap.
            if (abs((int)round($a * 100) - (int)round($b * 100)) > 1) {
                throw new \RuntimeException(sprintf('Invoice amounts do not add up (%s: %.2f vs %.2f)', $what, $a, $b), 1762800002);
            }
        }
    }

    /**
     * The form's currency (fluid.currency, default PLN): its bank account in fluid.seller is fixed
     * per form. A submission whose price_currency differs gets no PDF (failure path) rather than an
     * invoice naming one currency next to another currency's account.
     */
    private function currency(array $answers, array $config, string $marker): string
    {
        $formCurrency = strtoupper(trim((string)($config['currency'] ?? ''))) ?: 'PLN';
        $submitted = strtoupper(trim($this->text($answers, $marker)));
        if ($submitted !== '' && $submitted !== $formCurrency) {
            throw new \RuntimeException(sprintf('Submission is in %s, the form invoices in %s (one bank account per form)', $submitted, $formCurrency), 1762800004);
        }
        return $formCurrency;
    }

    /**
     * fluid.event { name, date, ... }: "{event.name}" in a line name becomes the event's name, so a
     * new edition changes the event once instead of every line. An unknown placeholder stays as typed.
     *
     * @param array<string, string> $event
     */
    private function withEvent(string $name, array $event): string
    {
        return preg_replace_callback('/\{event\.([A-Za-z0-9_]+)\}/', static fn(array $m): string => $event[$m[1]] ?? $m[0], $name) ?? $name;
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
