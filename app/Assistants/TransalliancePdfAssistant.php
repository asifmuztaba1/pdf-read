<?php

namespace App\Assistants;

use App\GeonamesCountry;
use Carbon\Carbon;
use Illuminate\Support\Str;

class TransalliancePdfAssistant extends PdfClient
{
    public static function validateFormat(array $lines)
    {
        $lines = array_map(fn ($line) => trim($line), $lines);

        $hasHeader = array_find_key(
            $lines,
            fn ($line) => Str::contains(Str::upper($line), 'CHARTERING CONFIRMATION')
        );

        $hasCompany = array_find_key(
            $lines,
            fn ($line) => Str::contains(Str::upper($line), 'TRANSALLIANCE TS LTD')
        );

        return $hasHeader !== null && $hasCompany !== null;
    }

    public function processLines(array $lines, ?string $attachment_filename = null)
    {
        $lines = array_map(fn ($line) => trim($line), $lines);

        $order_reference = $this->extractOrderReference($lines);
        $freight = $this->extractFreight($lines);
        $customer = $this->extractCustomer($lines);

        $loading_block = $this->extractSection($lines, 'Loading', 'Delivery');
        $delivery_block = $this->extractSection($lines, 'Delivery', 'Observations :');

        $loading_locations = array_values(array_filter([
            $this->buildLocation($loading_block),
        ]));
        $destination_locations = array_values(array_filter([
            $this->buildLocation($delivery_block),
        ]));

        $cargos = $this->buildCargos($loading_block, $delivery_block, $order_reference);

        if (!$loading_locations) {
            throw new \RuntimeException('TransalliancePdfAssistant: loading locations not found');
        }

        if (!$destination_locations) {
            throw new \RuntimeException('TransalliancePdfAssistant: destination locations not found');
        }

        if (!$cargos) {
            throw new \RuntimeException('TransalliancePdfAssistant: cargo data missing');
        }

        $attachment_filenames = [mb_strtolower($attachment_filename ?? '')];

        $data = [
            'customer' => $customer,
            'loading_locations' => $loading_locations,
            'destination_locations' => $destination_locations,
            'cargos' => $cargos,
            'order_reference' => $order_reference,
            'attachment_filenames' => $attachment_filenames,
        ];

        if (isset($freight['price'])) {
            $data['freight_price'] = $freight['price'];
        }
        if (isset($freight['currency'])) {
            $data['freight_currency'] = $freight['currency'];
        }

        if ($transport_numbers = $this->extractTransportNumbers($lines)) {
            $data['transport_numbers'] = $transport_numbers;
        }

        if ($comment = $this->extractComment($lines)) {
            $data['comment'] = $comment;
        }

        $this->createOrder($data);
    }

    protected function extractOrderReference(array $lines): string
    {
        $index = array_find_key($lines, fn ($line) => Str::startsWith(Str::upper($line), 'REF.:'));

        if ($index === null) {
            throw new \RuntimeException('TransalliancePdfAssistant: order reference not found');
        }

        return trim(Str::after($lines[$index], 'REF.:'));
    }

    protected function extractFreight(array $lines): array
    {
        $output = [];
        $index = array_find_key($lines, fn ($line) => Str::contains(Str::upper($line), 'SHIPPING PRICE'));
        if ($index === null) {
            return $output;
        }

        $price_line = $this->nextNonEmptyLine($lines, $index + 1);
        if ($price_line !== null) {
            $output['price'] = uncomma(preg_replace('/[^0-9,\.]/', '', $price_line['value']));
        }

        $currency_start = isset($price_line['index']) ? $price_line['index'] + 1 : $index + 1;
        $currency_line = $this->nextNonEmptyLine($lines, $currency_start);
        if ($currency_line !== null && preg_match('/([A-Z]{3})/', Str::upper($currency_line['value']), $matches)) {
            $output['currency'] = $matches[1];
        }

        return $output;
    }

    protected function extractCustomer(array $lines): array
    {
        $company_line = array_find_key($lines, fn ($line) => Str::contains(Str::upper($line), 'TRANSALLIANCE TS LTD'));

        if ($company_line === null) {
            throw new \RuntimeException('TransalliancePdfAssistant: customer block not found');
        }

        $address_lines = [];
        for ($i = $company_line + 1; $i < count($lines); $i++) {
            $line = $lines[$i];
            if ($line === '' || Str::startsWith(Str::upper($line), 'TEL') || Str::startsWith(Str::upper($line), 'VAT ') || Str::startsWith(Str::upper($line), 'CONTACT:') || Str::startsWith(Str::upper($line), 'E-MAIL')) {
                break;
            }
            $address_lines[] = $line;
        }

        $location_line = array_pop($address_lines);
        $street_address = $address_lines ? implode(', ', $address_lines) : null;
        $location = $this->parseLocationLine($location_line, 'GB');

        $contact_person = null;
        $contact_index = array_find_key($lines, fn ($line) => Str::startsWith(Str::upper($line), 'CONTACT:'));
        if ($contact_index !== null) {
            $contact_person = $this->valueAfter($lines, $contact_index);
        }

        $email = null;
        $email_index = array_find_key($lines, fn ($line) => Str::startsWith(Str::upper($line), 'E-MAIL'));
        if ($email_index !== null) {
            $email = $this->valueAfter($lines, $email_index);
        }

        $vat_code = null;
        $vat_index = array_find_key($lines, fn ($line) => Str::startsWith(Str::upper($line), 'VAT NUM'));
        if ($vat_index !== null) {
            $vat_code = $this->valueAfter($lines, $vat_index);
        }

        return [
            'side' => 'none',
            'details' => array_filter([
                'company' => 'TRANSALLIANCE TS LTD',
                'title' => 'TRANSALLIANCE TS LTD',
                'street_address' => $street_address,
                'postal_code' => $location['postal_code'] ?? null,
                'city' => $location['city'] ?? null,
                'country' => $location['country'] ?? null,
                'contact_person' => $contact_person,
                'email' => $email,
                'vat_code' => $vat_code,
            ]),
        ];
    }

    protected function buildLocation(array $block): ?array
    {
        $block = $this->cleanBlock($block);
        if (!$block) {
            return null;
        }

        $date = $this->pullFirstDate($block);
        $time_line = $this->pullFirstTimeLine($block);
        $location_lines = $this->pullLocationLines($block);

        if (!$location_lines) {
            return null;
        }

        $address = $this->buildAddress($location_lines);
        $location = ['company_address' => $address];

        if ($date && $time_line) {
            $window = $this->parseTimeWindow($time_line, $date);
            if ($window) {
                $location['time'] = $window;
            }
        } elseif ($date) {
            $location['time'] = [
                'datetime_from' => $date->startOfDay()->toIso8601String(),
            ];
        }

        return $location;
    }

    protected function buildAddress(array $lines): array
    {
        $lines = array_values(array_filter($lines, fn ($line) => $line !== ''));
        if (!$lines) {
            return [];
        }

        $company = array_shift($lines);

        $location_line = $lines ? array_pop($lines) : null;
        $location = $location_line ? $this->parseLocationLine($location_line) : [];

        $address = array_filter([
            'company' => $company,
            'title' => $company,
            'street_address' => $lines ? implode(', ', $lines) : null,
        ]);

        return array_merge($address, $location);
    }

    protected function buildCargos(array $loadingBlock, array $deliveryBlock, ?string $defaultReference): array
    {
        $info = $this->extractCargoInfo($loadingBlock);
        if (!$info['title'] && $deliveryBlock) {
            $info = array_merge($info, $this->extractCargoInfo($deliveryBlock));
        }

        $numberParts = array_filter([
            $info['number'] ?? null,
            $defaultReference,
        ]);

        $cargo = array_filter([
            'title' => $info['title'] ?? 'Cargo',
            'number' => $numberParts ? implode(' / ', array_unique($numberParts)) : null,
            'package_type' => $info['package_type'] ?? 'other',
            'package_count' => $info['package_count'] ?? null,
            'weight' => $info['weight'] ?? null,
            'ldm' => $info['ldm'] ?? null,
        ], fn ($value) => !is_null($value) && $value !== '');

        if (!isset($cargo['package_type'])) {
            $cargo['package_type'] = 'other';
        }

        return [$cargo];
    }

    protected function extractTransportNumbers(array $lines): ?string
    {
        $tractor = $this->findValueAfterLabel($lines, 'Tract.registr.:');
        $trailer = $this->findValueAfterLabel($lines, 'Trail.registr.:');

        $numbers = array_filter([$tractor, $trailer]);

        return $numbers ? implode(' / ', $numbers) : null;
    }

    protected function extractComment(array $lines): ?string
    {
        $start = array_find_key($lines, fn ($line) => Str::startsWith(Str::upper($line), 'INSTRUCTIONS'));
        if ($start === null) {
            $start = array_find_key($lines, fn ($line) => Str::startsWith(Str::upper($line), 'OBSERVATIONS'));
        }
        if ($start === null) {
            return null;
        }

        $comment_lines = [];
        for ($i = $start + 1; $i < count($lines); $i++) {
            $line = $lines[$i];
            if (Str::startsWith(Str::upper($line), 'DELIVERY') || Str::startsWith(Str::upper($line), 'CUSTOMS')) {
                break;
            }
            if ($line === '') {
                continue;
            }
            $comment_lines[] = $line;
        }

        return $comment_lines ? implode(' ', $comment_lines) : null;
    }

    protected function extractSection(array $lines, string $startLabel, ?string $endLabel): array
    {
        $start = array_find_key(
            $lines,
            fn ($line) => $this->lineEquals($line, $startLabel)
        );

        if ($start === null) {
            return [];
        }

        $end = null;
        if ($endLabel) {
            $end = array_find_key(
                $lines,
                fn ($line, $index) => $index > $start && $this->lineEquals($line, $endLabel)
            );
        }

        $sliceEnd = $end ?? count($lines);

        return array_slice($lines, $start + 1, $sliceEnd - ($start + 1));
    }

    protected function extractCargoInfo(array $block): array
    {
        $block = $this->cleanBlock($block);

        $title = $this->extractCargoTitle($block);
        $ldmValue = $this->findNumericValueAfter($block, 'LM');
        $weightValue = $this->findNumericValueAfter($block, 'Kgs');
        if ($weightValue === null) {
            $weightValue = $this->findNumericValueAfter($block, 'Weight');
        }
        $packageCount = $this->findNumericValueAfter($block, 'Pal. nb');
        $number = $this->findValueAfterLabel($block, 'OT');

        if (!$number) {
            $number = $this->extractAdditionalReference($block);
        }

        return [
            'title' => $title,
            'ldm' => $ldmValue,
            'weight' => $weightValue,
            'package_count' => $packageCount !== null ? (int) abs($packageCount) : null,
            'package_type' => 'other',
            'number' => $number,
        ];
    }

    protected function extractAdditionalReference(array $block): ?string
    {
        $refs = [];
        foreach ($block as $line) {
            if (preg_match('/^TR[0-9A-Z\- ]+/i', $line)) {
                $refs[] = trim($line);
            }
        }

        return $refs ? implode(' / ', array_unique($refs)) : null;
    }

    protected function findValueAfterLabel(array $lines, string $label): ?string
    {
        $index = array_find_key(
            $lines,
            fn ($line) => Str::startsWith(Str::upper($line), Str::upper($label))
        );

        if ($index === null) {
            return null;
        }

        for ($i = $index + 1; $i < count($lines); $i++) {
            $candidate = trim($lines[$i]);
            if ($candidate === '' || Str::startsWith(Str::upper($candidate), Str::upper($label))) {
                continue;
            }
            if (Str::endsWith($candidate, ':')) {
                continue;
            }
            return preg_replace('/^[:\-]/', '', $candidate);
        }

        return null;
    }

    protected function extractCargoTitle(array $block): ?string
    {
        $end = array_find_key($block, fn ($line) => Str::startsWith(Str::upper($line), 'INSTRUCTIONS'));
        $end = $end ?? count($block);

        for ($i = $end - 1; $i >= 0; $i--) {
            $line = trim($block[$i]);
            if ($line === '') {
                continue;
            }
            if (Str::endsWith($line, ':')) {
                continue;
            }
            if (preg_match('/^[0-9.,\s]+$/', $line)) {
                continue;
            }
            if (Str::startsWith(Str::upper($line), 'REF')) {
                continue;
            }

            return $line;
        }

        return null;
    }

    protected function findNumericValueAfter(array $lines, string $label): ?float
    {
        $value = $this->findValueAfterLabel($lines, $label);
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/[^0-9,\.\-]/', '', $value);
        if ($value === '' || $value === '-') {
            return null;
        }

        return uncomma($value);
    }

    protected function pullLocationLines(array $block): array
    {
        $index = array_find_key($block, fn ($line) => $this->lineEquals($line, 'ON:'));
        if ($index === null) {
            return [];
        }

        $lines = [];
        for ($i = $index + 1; $i < count($block); $i++) {
            $line = $block[$i];
            $upper = Str::upper($line);
            if ($line === '') {
                continue;
            }
            if (Str::startsWith($upper, 'CONTACT') || Str::startsWith($upper, 'TEL') || Str::startsWith($upper, 'PAYMENT TERMS') || Str::startsWith($upper, 'LM') || Str::startsWith($upper, 'PARC') || Str::startsWith($upper, 'PAL') || Str::startsWith($upper, 'WEIGHT') || Str::startsWith($upper, 'KGS') || Str::startsWith($upper, 'M. NATURE') || Str::startsWith($upper, 'INSTRUCTIONS') || Str::startsWith($upper, 'OT')) {
                break;
            }
            $lines[] = $line;
        }

        return $lines;
    }

    protected function cleanBlock(array $block): array
    {
        return array_values(array_map(fn ($line) => trim($line), $block));
    }

    protected function pullFirstDate(array &$block): ?Carbon
    {
        for ($i = 0; $i < count($block); $i++) {
            $line = $block[$i];
            if ($line === '') {
                continue;
            }
            if ($date = $this->parseDate($line)) {
                array_splice($block, $i, 1);
                return $date;
            }
        }

        return null;
    }

    protected function pullFirstTimeLine(array &$block): ?string
    {
        for ($i = 0; $i < count($block); $i++) {
            $line = $block[$i];
            if ($this->containsTime($line)) {
                array_splice($block, $i, 1);
                return $line;
            }
        }

        return null;
    }

    protected function containsTime(string $line): bool
    {
        $normalized = Str::lower($line);
        return (bool) preg_match('/\d{1,2}[:h]\d{2}/', $normalized)
            || (bool) preg_match('/\d{3,4}\s*[-–]\s*\d{3,4}/', $normalized)
            || (bool) preg_match('/\d{1,2}\s*(am|pm)/', $normalized);
    }

    protected function parseDate(string $value): ?Carbon
    {
        $value = trim($value);
        try {
            if (preg_match('/^\d{2}\/\d{2}\/\d{2}$/', $value)) {
                return Carbon::createFromFormat('d/m/y', $value);
            }
            if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $value)) {
                return Carbon::createFromFormat('d/m/Y', $value);
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    protected function parseTimeWindow(string $timeLine, Carbon $date): ?array
    {
        $normalized = Str::lower($timeLine);
        $normalized = str_replace(['time to', 'booked'], [' ', ' '], $normalized);
        $normalized = preg_replace('/(?<=\d)h(?=\d{2})/', ':', $normalized);

        preg_match_all('/\b\d{1,2}(?::\d{2})?\s*(?:am|pm)?\b|\b\d{3,4}\b/', $normalized, $matches);
        $tokens = array_map('trim', $matches[0]);
        $tokens = array_values(array_filter($tokens));

        if (!$tokens) {
            return null;
        }

        $moments = [];
        foreach ($tokens as $token) {
            $moment = $this->buildDateTimeFromToken($token, $date);
            if ($moment) {
                $moments[] = $moment;
            }
            if (count($moments) === 2) {
                break;
            }
        }

        if (!$moments) {
            return null;
        }

        $output = ['datetime_from' => $moments[0]->toIso8601String()];
        if (isset($moments[1]) && !$moments[1]->equalTo($moments[0])) {
            $output['datetime_to'] = $moments[1]->toIso8601String();
        }

        return $output;
    }

    protected function buildDateTimeFromToken(string $token, Carbon $date): ?Carbon
    {
        $token = Str::lower(trim($token));
        $token = str_replace('h', ':', $token);
        if (preg_match('/^\d{3,4}$/', $token)) {
            $token = str_pad(substr($token, 0, -2), 2, '0', STR_PAD_LEFT) . ':' . substr($token, -2);
        }
        $token = preg_replace('/\s+/', ' ', $token);
        if (str_contains($token, 'pm') || str_contains($token, 'am')) {
            try {
                return Carbon::parse($date->format('Y-m-d') . ' ' . $token);
            } catch (\Throwable $e) {
                return null;
            }
        }
        if (preg_match('/^\d{1,2}:\d{2}$/', $token)) {
            try {
                return Carbon::createFromFormat('Y-m-d H:i', $date->format('Y-m-d') . ' ' . $token);
            } catch (\Throwable $e) {
                return null;
            }
        }
        if (preg_match('/^\d{1,2}$/', $token)) {
            try {
                return Carbon::createFromFormat('Y-m-d H', $date->format('Y-m-d') . ' ' . $token);
            } catch (\Throwable $e) {
                return null;
            }
        }

        try {
            return Carbon::parse($date->format('Y-m-d') . ' ' . $token);
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function parseLocationLine(?string $line, ?string $defaultCountry = null): array
    {
        if (!$line) {
            return [];
        }

        $line = trim(preg_replace('/\s+/', ' ', $line));
        $country = $defaultCountry;

        if (preg_match('/^([A-Z]{2})-/', $line, $matches)) {
            $country = GeonamesCountry::getIso($matches[1]) ?? $matches[1];
            $line = substr($line, 3);
        }

        $line = ltrim($line, '- ');

        $city = null;
        $postal = null;

        if (Str::contains($line, ',')) {
            [$first, $second] = array_map('trim', explode(',', $line, 2));
            if ($this->looksLikePostal($second)) {
                $city = $first;
                $postal = $second;
            } elseif ($this->looksLikePostal($first)) {
                $postal = $first;
                $city = $second;
            } else {
                $city = $first;
                $postal = $second;
            }
        } else {
            $parts = explode(' ', $line);
            $candidate = strtoupper($parts[0]);
            $combined = strtoupper($parts[0] . (isset($parts[1]) ? ' ' . $parts[1] : ''));

            if ($this->looksLikePostal($combined)) {
                $postal = $parts[0] . ' ' . ($parts[1] ?? '');
                $city = implode(' ', array_slice($parts, 2));
            } elseif ($this->looksLikePostal($candidate)) {
                $postal = $parts[0];
                $city = implode(' ', array_slice($parts, 1));
            } elseif ($this->looksLikePostal(end($parts))) {
                $postal = array_pop($parts);
                $city = implode(' ', $parts);
            } else {
                $city = $line;
            }
        }

        if (!$country && $postal) {
            $country = $this->guessCountryFromPostal($postal);
        }

        return array_filter([
            'postal_code' => $postal ? trim($postal) : null,
            'city' => $city ? trim($city) : null,
            'country' => $country,
        ]);
    }

    protected function looksLikePostal(string $value): bool
    {
        $value = strtoupper(trim($value));
        $valueNormalized = str_replace([' ', '-'], '', $value);

        return preg_match('/^\d{4,5}$/', preg_replace('/\D/', '', $valueNormalized))
            || preg_match('/^[A-Z]{1,2}\d{1,2}[A-Z]?\d[A-Z]{2}$/', $valueNormalized)
            || preg_match('/^[A-Z]{2}\d{3,}$/', $valueNormalized);
    }

    protected function guessCountryFromPostal(string $postal): ?string
    {
        $normalized = strtoupper(str_replace([' ', '-'], '', $postal));
        if (preg_match('/^[A-Z]{1,2}\d/', $normalized)) {
            return 'GB';
        }
        if (preg_match('/^\d{5}$/', preg_replace('/\D/', '', $postal))) {
            return 'FR';
        }
        return null;
    }

    protected function nextNonEmptyLine(array $lines, int $startIndex): ?array
    {
        for ($i = $startIndex; $i < count($lines); $i++) {
            if (trim($lines[$i]) !== '') {
                return ['value' => $lines[$i], 'index' => $i];
            }
        }

        return null;
    }

    protected function lineEquals(string $line, string $target): bool
    {
        return Str::upper(trim($line)) === Str::upper(trim($target));
    }

    protected function valueAfter(array $lines, int $index): ?string
    {
        if (!isset($lines[$index])) {
            return null;
        }

        $line = $lines[$index];
        $value = trim(Str::after($line, ':'));
        if ($value === '' || $value === $line) {
            $next = $this->nextNonEmptyLine($lines, $index + 1);
            return $next['value'] ?? null;
        }

        return $value;
    }
}
