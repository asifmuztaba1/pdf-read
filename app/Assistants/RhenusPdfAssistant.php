<?php

namespace App\Assistants;

use App\GeonamesCountry;
use Illuminate\Support\Str;

class RhenusPdfAssistant extends PdfClient
{
    public static function validateFormat(array $lines)
    {
        $lines = array_map(fn ($line) => trim($line), $lines);
        if (!$lines) {
            return false;
        }

        $hasHeader = Str::upper($lines[0]) === 'TRANSPORT INSTRUCTION';
        $hasRhenus = array_find_key($lines, fn ($line) => Str::contains(Str::upper($line), 'RHENUS LOGISTICS LTD')) !== null;
        $hasFreight = array_find_key($lines, fn ($line) => Str::startsWith(Str::upper($line), 'FREIGHT COST')) !== null;

        return $hasHeader && $hasRhenus && $hasFreight;
    }

    public function processLines(array $lines, ?string $attachment_filename = null)
    {
        $lines = array_map(fn ($line) => trim($line), $lines);

        $orderReference = $this->extractTransportNumber($lines);
        $customer = $this->extractCustomer($lines);
        $freight = $this->extractFreight($lines);
        $transportNumbers = $this->extractTransportNumbers($lines);
        $comment = $this->extractRemark($lines);

        $shipments = $this->extractShipments($lines);
        if (!$shipments) {
            throw new \RuntimeException('RhenusPdfAssistant: shipments not found');
        }

        $loadingLocations = $this->uniqueLocations(array_column($shipments, 'loading'));
        $destinationLocations = $this->uniqueLocations(array_column($shipments, 'destination'));
        $cargos = array_values(array_filter(array_column($shipments, 'cargo')));

        if (!$loadingLocations) {
            throw new \RuntimeException('RhenusPdfAssistant: loading locations not found');
        }

        if (!$destinationLocations) {
            throw new \RuntimeException('RhenusPdfAssistant: destination locations not found');
        }

        if (!$cargos) {
            throw new \RuntimeException('RhenusPdfAssistant: cargos not found');
        }

        $data = [
            'customer' => $customer,
            'loading_locations' => $loadingLocations,
            'destination_locations' => $destinationLocations,
            'cargos' => $cargos,
            'order_reference' => $orderReference,
            'attachment_filenames' => [mb_strtolower($attachment_filename ?? '')],
        ];

        if (isset($freight['price'])) {
            $data['freight_price'] = $freight['price'];
        }
        if (isset($freight['currency'])) {
            $data['freight_currency'] = $freight['currency'];
        }
        if ($transportNumbers) {
            $data['transport_numbers'] = $transportNumbers;
        }
        if ($comment) {
            $data['comment'] = $comment;
        }

        $this->createOrder($data);
    }

    protected function extractTransportNumber(array $lines): string
    {
        $index = array_find_key($lines, fn ($line) => Str::startsWith(Str::upper($line), 'TRANSPORT NO'));
        if ($index === null) {
            throw new \RuntimeException('RhenusPdfAssistant: transport number not found');
        }

        for ($i = $index + 1; $i < count($lines); $i++) {
            $value = trim($lines[$i]);
            if ($value === '') {
                continue;
            }

            $upper = Str::upper($value);
            if (Str::startsWith($upper, 'CONTACT')) {
                continue;
            }
            if (Str::startsWith($upper, 'REMARK')) {
                continue;
            }
            if (Str::startsWith($upper, 'LOADING')) {
                continue;
            }
            if (Str::startsWith($upper, 'TRUCK NO')) {
                break;
            }
            if (Str::startsWith($upper, 'TRAILER NO')) {
                break;
            }
            if (Str::startsWith($upper, 'INSTRUCTION NO')) {
                break;
            }

            if (preg_match('/\d/', $value)) {
                return preg_replace('/\s+/', '', $value);
            }
        }

        throw new \RuntimeException('RhenusPdfAssistant: transport number value missing');
    }

    protected function extractCustomer(array $lines): array
    {
        $details = null;
        $email = null;

        $invoiceIndex = array_find_key($lines, fn ($line) => Str::upper($line) === 'INVOICING ADDRESS');
        if ($invoiceIndex !== null) {
            $block = [];
            for ($i = $invoiceIndex + 1; $i < count($lines); $i++) {
                $line = $lines[$i];
                if ($line === '') {
                    continue;
                }
                if (Str::startsWith(Str::upper($line), 'OUR VAT')) {
                    break;
                }
                if (Str::startsWith(Str::upper($line), 'WHEN INVOICING')) {
                    break;
                }
                if (Str::contains($line, '@')) {
                    $email = strtolower(trim($line));
                    continue;
                }
                $block[] = $line;
            }

            if ($block) {
                $details = $this->parseAddress($block, 'GB');
            }
        }

        if (!$details) {
            $headerIndex = array_find_key($lines, fn ($line) => Str::contains(Str::upper($line), 'RHENUS LOGISTICS LTD'));
            if ($headerIndex === null) {
                throw new \RuntimeException('RhenusPdfAssistant: customer block not found');
            }

            $block = [];
            for ($i = $headerIndex; $i < count($lines); $i++) {
                $line = $lines[$i];
                if ($line === '') {
                    continue;
                }
                if (Str::startsWith(Str::upper($line), 'CARRIER')) {
                    break;
                }
                if (Str::startsWith(Str::upper($line), 'PHONE')) {
                    continue;
                }
                if (Str::startsWith(Str::upper($line), 'FAX')) {
                    continue;
                }
                if (Str::startsWith(Str::upper($line), 'EMAIL')) {
                    $next = $this->nextNonEmpty($lines, $i + 1);
                    if ($next) {
                        $email = strtolower(trim($next['value']));
                    }
                    continue;
                }
                if (Str::contains($line, '@')) {
                    $email = strtolower(trim($line));
                    continue;
                }
                $block[] = $line;
            }

            $details = $this->parseAddress($block, 'GB');
        }

        if (!$details) {
            throw new \RuntimeException('RhenusPdfAssistant: customer address not found');
        }

        $vatIndex = array_find_key($lines, fn ($line) => Str::startsWith(Str::upper($line), 'OUR VAT'));
        if ($vatIndex !== null) {
            $vat = $this->valueAfter($lines, $vatIndex);
            if ($vat) {
                $details['vat_code'] = Str::upper(str_replace(' ', '', $vat));
            }
        }

        if ($email) {
            $details['email'] = $email;
        }

        if (!isset($details['company'])) {
            $details['company'] = 'Rhenus Logistics Ltd';
        }
        $details['title'] = $details['title'] ?? $details['company'];
        $details['country'] = $details['country'] ?? 'GB';

        if ($contact = $this->extractContactPerson($lines)) {
            $details['contact_person'] = $contact;
        }

        return [
            'side' => 'none',
            'details' => $details,
        ];
    }

    protected function extractContactPerson(array $lines): ?string
    {
        $index = array_find_key($lines, fn ($line) => Str::upper($line) === 'BEST REGARDS');
        if ($index === null) {
            return null;
        }

        $next = $this->nextNonEmpty($lines, $index + 1);
        if (!$next) {
            return null;
        }

        $value = trim($next['value']);
        return $value !== '' ? Str::title(Str::lower($value)) : null;
    }

    protected function extractFreight(array $lines): array
    {
        $output = [];
        $index = array_find_key($lines, fn ($line) => Str::startsWith(Str::upper($line), 'FREIGHT COST'));
        if ($index === null) {
            return $output;
        }

        $next = $this->nextNonEmpty($lines, $index + 1);
        if (!$next) {
            return $output;
        }

        $value = trim($next['value']);
        if ($value === '') {
            return $output;
        }

        if (preg_match('/([0-9.,]+)/', $value, $matches)) {
            $output['price'] = uncomma($matches[1]);
        }

        $upper = Str::upper($value);
        if (Str::contains($upper, 'EUR')) {
            $output['currency'] = 'EUR';
        } elseif (Str::contains($upper, 'GBP')) {
            $output['currency'] = 'GBP';
        } elseif (Str::contains($upper, 'USD')) {
            $output['currency'] = 'USD';
        }

        return $output;
    }

    protected function extractTransportNumbers(array $lines): ?string
    {
        $numbers = [];
        $truckIndex = array_find_key($lines, fn ($line) => Str::startsWith(Str::upper($line), 'TRUCK NO'));
        $trailerIndex = array_find_key($lines, fn ($line) => Str::startsWith(Str::upper($line), 'TRAILER NO'));

        if ($truckIndex !== null) {
            $value = $this->valueAfter($lines, $truckIndex, ['TRAILER NO', 'TRAILER NO:', 'INSTRUCTION NO', 'INSTRUCTION NO:']);
            if ($value && preg_match('/[A-Z0-9]/i', $value)) {
                $numbers[] = $value;
            }
        }

        if ($trailerIndex !== null) {
            $value = $this->valueAfter($lines, $trailerIndex, ['INSTRUCTION NO', 'INSTRUCTION NO:']);
            if ($value && preg_match('/[A-Z0-9]/i', $value)) {
                $numbers[] = $value;
            }
        }

        if (!$numbers) {
            return null;
        }

        return implode(' / ', array_unique($numbers));
    }

    protected function extractRemark(array $lines): ?string
    {
        $index = array_find_key($lines, fn ($line) => Str::startsWith(Str::upper($line), 'REMARK'));
        if ($index === null) {
            return null;
        }

        $parts = [];
        $initial = trim(Str::after($lines[$index], 'Remark'));
        if ($initial && $initial !== $lines[$index]) {
            $parts[] = $initial;
        }

        for ($i = $index + 1; $i < count($lines); $i++) {
            $value = trim($lines[$i]);
            if ($value === '') {
                continue;
            }
            $upper = Str::upper($value);
            if (Str::startsWith(Str::upper($value), 'CONTACT')) {
                break;
            }
            if (Str::startsWith($upper, 'PRINCIPAL REF')) {
                break;
            }
            if (Str::startsWith($upper, 'TRANSPORT NO')) {
                break;
            }
            if (Str::startsWith($upper, 'TRUCK NO')) {
                break;
            }
            if (Str::startsWith($upper, 'TRAILER NO')) {
                break;
            }
            if (Str::startsWith($upper, 'INSTRUCTION NO')) {
                break;
            }
            if (Str::startsWith($upper, 'WE HEREBY REQUEST')) {
                break;
            }
            $parts[] = $value;
        }

        $text = trim(implode(' ', $parts));
        return $text !== '' ? $text : null;
    }

    protected function extractShipments(array $lines): array
    {
        $indices = [];
        foreach ($lines as $i => $line) {
            if (Str::startsWith(Str::upper($line), 'SHIPMENT NO')) {
                $indices[] = $i;
            }
        }

        if (!$indices) {
            throw new \RuntimeException('RhenusPdfAssistant: shipment markers not found');
        }

        $indices[] = count($lines);

        $shipments = [];
        for ($i = 0; $i < count($indices) - 1; $i++) {
            $start = $indices[$i];
            $end = $indices[$i + 1];
            $block = array_slice($lines, $start, $end - $start);
            $parsed = $this->parseShipmentBlock($block);
            if ($parsed) {
                $shipments[] = $parsed;
            }
        }

        return $shipments;
    }

    protected function parseShipmentBlock(array $lines): ?array
    {
        $lines = array_values(array_map(fn ($line) => trim($line), $lines));

        $shipmentIndex = array_find_key($lines, fn ($line) => Str::startsWith(Str::upper($line), 'SHIPMENT NO'));
        if ($shipmentIndex === null) {
            return null;
        }

        $shipmentNumber = $this->valueAfter($lines, $shipmentIndex);

        $loadIndex = array_find_key($lines, fn ($line) => Str::startsWith(Str::upper($line), 'LOAD PLACE'));
        $senderRefIndex = array_find_key($lines, fn ($line) => Str::startsWith(Str::upper($line), 'SENDER REF'));
        $pickupIndex = array_find_key($lines, fn ($line) => Str::startsWith(Str::upper($line), 'PICKUP DATE'));
        $pickupInstructionsIndex = array_find_key($lines, fn ($line) => Str::startsWith(Str::upper($line), 'PICKUP INSTRUCTIONS'));
        $deliveryInstructionsIndex = array_find_key($lines, fn ($line) => Str::startsWith(Str::upper($line), 'DELIVERY INSTRUCTIONS'));
        $quantityIndex = array_find_key($lines, fn ($line) => Str::startsWith(Str::upper($line), 'TOTAL QUANTITY'));

        $loadLines = [];
        if ($loadIndex !== null && $senderRefIndex !== null) {
            $loadLines = $this->collectLines($lines, $loadIndex + 1, $senderRefIndex, ['UNLOAD PLACE']);
        }

        $destLines = [];
        if ($senderRefIndex !== null && $pickupIndex !== null) {
            $destLines = $this->collectLines($lines, $senderRefIndex + 1, $pickupIndex, ['CONSIGNEE REF']);
        }

        $senderRef = $senderRefIndex !== null
            ? $this->valueAfter($lines, $senderRefIndex, ['CONSIGNEE REF', 'PICKUP DATE', 'PICKUP DATE/TIME', 'DELIVERY DATE', 'DELIVERY DATE/TIME'])
            : null;

        if ($senderRef && $destLines && Str::upper($senderRef) === Str::upper($destLines[0])) {
            $senderRef = null;
        }

        $consigneeRefIndex = array_find_key($lines, fn ($line) => Str::startsWith(Str::upper($line), 'CONSIGNEE REF'));
        $consigneeRef = $consigneeRefIndex !== null
            ? $this->valueAfter($lines, $consigneeRefIndex, ['PICKUP DATE', 'PICKUP DATE/TIME', 'PICKUP INSTRUCTIONS'])
            : null;

        $pickupInstructions = null;
        if ($pickupInstructionsIndex !== null && $deliveryInstructionsIndex !== null) {
            $pickupLines = $this->collectLines($lines, $pickupInstructionsIndex + 1, $deliveryInstructionsIndex);
            $pickupInstructions = $this->collapseLines($this->truncateInstructionLines($pickupLines));
        }

        $deliveryInstructions = null;
        if ($deliveryInstructionsIndex !== null) {
            $deliveryLines = $this->collectLines(
                $lines,
                $deliveryInstructionsIndex + 1,
                $quantityIndex,
                [
                    'TOTAL QUANTITY',
                    'REGISTERED IN ENGLAND',
                    'BANK DETAILS',
                    'PRINT DATE',
                    'AQ',
                    'PAGE',
                    'TRANSPORT NO',
                    'TRUCK NO',
                    'TRAILER NO',
                    'INSTRUCTION NO',
                ]
            );

            $deliveryInstructions = $this->collapseLines($this->truncateInstructionLines($deliveryLines));
        }

        $loadingAddress = $this->parseAddress($loadLines);
        $destinationAddress = $this->parseAddress($destLines);

        $loadingLocation = $this->buildLocation($loadingAddress, $senderRef, $pickupInstructions);
        $destinationLocation = $this->buildLocation($destinationAddress, $consigneeRef, $deliveryInstructions);

        $cargoValues = $this->extractCargoValues($lines);
        $packageCount = $this->toInt($cargoValues[0] ?? null) ?? $this->toInt($this->valueAfterLabel($lines, 'UNITS'));
        $cargoTitle = $cargoValues[1] ?? $this->valueAfterLabel($lines, 'GOODS DESCRIPTION') ?? 'Cargo';
        $weight = $this->toFloat($cargoValues[2] ?? null);
        $volume = $this->toFloat($cargoValues[3] ?? null);
        $ldm = $this->toFloat($cargoValues[4] ?? null);

        $numberParts = array_filter([$shipmentNumber, $senderRef, $consigneeRef], fn ($value) => $value !== null && $value !== '');

        $cargo = array_filter([
            'title' => $cargoTitle,
            'number' => $numberParts ? implode(' / ', array_unique($numberParts)) : null,
            'package_count' => $packageCount,
            'package_type' => 'other',
            'weight' => $weight,
            'volume' => $volume,
            'ldm' => $ldm,
        ], function ($value) {
            return !is_null($value) && $value !== '';
        });

        return [
            'loading' => $loadingLocation,
            'destination' => $destinationLocation,
            'cargo' => $cargo,
        ];
    }

    protected function buildLocation(?array $address, ?string $reference, ?string $instructions): ?array
    {
        if (!$address) {
            return null;
        }

        $commentParts = [];
        if ($reference) {
            $commentParts[] = 'Ref: ' . $reference;
        }
        if ($instructions) {
            $commentParts[] = $instructions;
        }

        if ($commentParts) {
            $address['comment'] = implode(' | ', $commentParts);
        }

        return [
            'company_address' => $address,
        ];
    }

    protected function collectLines(array $lines, int $start, ?int $end, array $exclude = []): array
    {
        $end = $end ?? count($lines);
        $slice = array_slice($lines, $start, max(0, $end - $start));
        $slice = array_map(fn ($line) => trim($line), $slice);

        $excludeUpper = array_map(fn ($item) => Str::upper($item), $exclude);

        return array_values(array_filter($slice, function ($line) use ($excludeUpper) {
            if ($line === '') {
                return false;
            }
            $upper = Str::upper($line);
            foreach ($excludeUpper as $needle) {
                if (Str::startsWith($upper, $needle)) {
                    return false;
                }
            }

            return true;
        }));
    }

    protected function collapseLines(array $lines): ?string
    {
        $value = trim(implode(' ', $lines));
        if ($value === '') {
            return null;
        }

        $upper = Str::upper($value);
        if (in_array($upper, ['LATEST', 'REQUESTED'], true)) {
            return null;
        }

        return $value;
    }

    protected function parseAddress(array $lines, ?string $countryFallback = null): ?array
    {
        $lines = array_values(array_filter(array_map(fn ($line) => trim($line), $lines), fn ($line) => $line !== ''));
        if (!$lines) {
            return null;
        }

        $company = array_shift($lines);
        if (!$company) {
            return null;
        }

        $country = null;
        if ($lines) {
            $maybeCountry = trim(end($lines));
            $iso = $this->resolveCountryIso($maybeCountry);
            if ($iso) {
                $country = $iso;
                array_pop($lines);
            }
        }

        if (!$country && $countryFallback) {
            $country = $countryFallback;
        }

        $city = null;
        $postal = null;
        if ($lines) {
            $cityLine = trim(array_pop($lines));
            [$postal, $city] = $this->splitPostalCity($cityLine);
            if (!$city) {
                $city = $cityLine;
            }
        }

        $street = $lines ? implode(', ', $lines) : null;

        $address = array_filter([
            'company' => $company,
            'title' => $company,
            'street_address' => $street,
            'postal_code' => $postal,
            'city' => $city,
            'country' => $country,
        ]);

        return $address;
    }

    protected function extractCargoValues(array $lines): array
    {
        $quantityIndex = array_find_key($lines, fn ($line) => Str::startsWith(Str::upper($line), 'TOTAL QUANTITY'));
        $ldmIndex = array_find_key($lines, fn ($line) => Str::startsWith(Str::upper($line), 'TOTAL LDM'));
        if ($quantityIndex === null || $ldmIndex === null) {
            return [];
        }

        $values = [];
        for ($i = $ldmIndex + 1; $i < count($lines); $i++) {
            $value = trim($lines[$i]);
            if ($value === '') {
                continue;
            }

            $upper = Str::upper($value);
            if (Str::startsWith($upper, 'PIECE')) {
                break;
            }
            if (Str::startsWith($upper, 'CUSTOMER')) {
                break;
            }
            if (Str::startsWith($upper, 'DIMENSIONS')) {
                break;
            }
            if (Str::startsWith($upper, 'PRINCIPAL REF')) {
                break;
            }
            if (Str::startsWith($upper, 'WE HEREBY')) {
                break;
            }

            $values[] = $value;
            if (count($values) >= 5) {
                break;
            }
        }

        return $values;
    }

    protected function truncateInstructionLines(array $lines): array
    {
        $output = [];
        foreach ($lines as $line) {
            $upper = Str::upper($line);
            if ($upper === '') {
                continue;
            }

            if (Str::startsWith($upper, '(')
                || Str::startsWith($upper, 'PLEASE NOTE')
                || Str::startsWith($upper, 'REGISTERED IN ENGLAND')
                || Str::startsWith($upper, 'BANK DETAILS')
                || Str::startsWith($upper, 'PRINT DATE')
                || Str::startsWith($upper, 'AQ')
                || Str::startsWith($upper, 'PAGE ')
                || Str::startsWith($upper, 'TRANSPORT NO')
                || Str::startsWith($upper, 'TRUCK NO')
                || Str::startsWith($upper, 'TRAILER NO')
                || Str::startsWith($upper, 'INSTRUCTION NO')
                || Str::startsWith($upper, 'BANK')
                || Str::startsWith($upper, 'PRINT')
                || Str::startsWith($upper, 'BACK OF THE TRAILER')
                || preg_match('/^\d{6,}$/', preg_replace('/\s+/', '', $line))
            ) {
                break;
            }

            $output[] = $line;
        }

        return $output;
    }

    protected function resolveCountryIso(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        $iso = GeonamesCountry::getIso($value);
        if ($iso) {
            return $iso;
        }

        $normalized = Str::title(Str::lower($value));
        return GeonamesCountry::getIso($normalized);
    }

    protected function splitPostalCity(string $line): array
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $line));
        if ($normalized === '') {
            return [null, null];
        }

        if (preg_match('/^(?P<postal>[A-Z]{1,3}\d[A-Z\d]?(?:\s*\d[A-Z]{2}))\s+(?P<city>.+)$/i', $normalized, $matches)) {
            $postal = strtoupper(preg_replace('/\s+/', '', $matches['postal']));
            if (strlen($postal) > 3) {
                $postal = substr($postal, 0, -3) . ' ' . substr($postal, -3);
            }
            return [$postal, trim($matches['city'])];
        }

        if (preg_match('/^(?P<city>.+?)\s+(?P<postal>[A-Z]{1,3}\d[A-Z\d]?(?:\s*\d[A-Z]{2}))$/i', $normalized, $matches)) {
            $postal = strtoupper(preg_replace('/\s+/', '', $matches['postal']));
            if (strlen($postal) > 3) {
                $postal = substr($postal, 0, -3) . ' ' . substr($postal, -3);
            }
            return [$postal, trim($matches['city'])];
        }

        if (preg_match('/^(?P<postal>\d{4,6})\s+(?P<city>.+)$/', $normalized, $matches)) {
            return [$matches['postal'], trim($matches['city'])];
        }

        if (preg_match('/^(?P<city>.+?)\s+(?P<postal>\d{4,6})$/', $normalized, $matches)) {
            return [$matches['postal'], trim($matches['city'])];
        }

        return [null, trim($normalized)];
    }

    protected function valueAfterLabel(array $lines, string $needle): ?string
    {
        $index = array_find_key($lines, function ($line) use ($needle) {
            return Str::startsWith(Str::upper($line), Str::upper($needle));
        });
        if ($index === null) {
            return null;
        }

        return $this->valueAfter($lines, $index);
    }

    protected function valueAfter(array $lines, int $index, array $stopWords = []): ?string
    {
        if (!isset($lines[$index])) {
            return null;
        }

        $line = $lines[$index];
        $after = trim(Str::after($line, ':'));
        if ($after !== '' && $after !== $line) {
            if ($this->isStopWord($after, $stopWords)) {
                return null;
            }
            return $after;
        }

        $next = $this->nextNonEmpty($lines, $index + 1);
        if (!$next) {
            return null;
        }

        $value = trim($next['value']);
        return $this->isStopWord($value, $stopWords) ? null : $value;
    }

    protected function isStopWord(string $value, array $stopWords): bool
    {
        $valueUpper = Str::upper($value);
        foreach ($stopWords as $word) {
            if ($valueUpper === Str::upper($word)) {
                return true;
            }
        }

        return false;
    }

    protected function nextNonEmpty(array $lines, int $startIndex): ?array
    {
        for ($i = $startIndex; $i < count($lines); $i++) {
            if (trim($lines[$i]) !== '') {
                return ['value' => $lines[$i], 'index' => $i];
            }
        }

        return null;
    }

    protected function toInt(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/[^0-9-]/', '', $value);
        if ($digits === '' || $digits === '-') {
            return null;
        }

        return (int) $digits;
    }

    protected function toFloat(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $numeric = preg_replace('/[^0-9,.-]/', '', $value);
        if ($numeric === '' || $numeric === '-' || $numeric === '.' || $numeric === ',') {
            return null;
        }

        return uncomma($numeric);
    }

    protected function uniqueLocations(array $locations): array
    {
        $output = [];
        $seen = [];
        foreach ($locations as $location) {
            if (!$location) {
                continue;
            }
            $hash = md5(json_encode($location));
            if (isset($seen[$hash])) {
                continue;
            }
            $seen[$hash] = true;
            $output[] = $location;
        }

        return $output;
    }
}
