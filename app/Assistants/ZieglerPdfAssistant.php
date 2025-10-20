<?php

namespace App\Assistants;

use App\GeonamesCountry;
use Carbon\Carbon;
use Illuminate\Support\Str;

class ZieglerPdfAssistant extends PdfClient
{
    public static function validateFormat(array $lines)
    {
        $lines = array_map(fn ($line) => trim($line), $lines);

        $hasCompany = isset($lines[0]) && Str::contains(Str::upper($lines[0]), 'ZIEGLER');
        $hasBooking = array_find_key($lines, fn ($line) => Str::upper(trim($line)) === 'BOOKING') !== null;
        $hasInstruction = array_find_key($lines, fn ($line) => Str::upper(trim($line)) === 'INSTRUCTION') !== null;

        return $hasCompany && $hasBooking && $hasInstruction;
    }

    public function processLines(array $lines, ?string $attachment_filename = null)
    {
        $lines = array_map(fn ($line) => trim($line), $lines);

        $order_reference = $this->extractOrderReference($lines);
        $freight = $this->extractFreight($lines);
        $customer = $this->extractCustomer($lines);

        $sections = $this->splitSections($lines);

        $loading_locations = [];
        $cargos = [];
        foreach ($sections['collection'] ?? [] as $section) {
            $parsed = $this->parseCollectionSection($section);
            if ($parsed['location']) {
                $loading_locations[] = $parsed['location'];
            }
            if ($parsed['cargo']) {
                $cargos[] = $parsed['cargo'];
            }
        }

        $destination_locations = [];
        foreach ($sections['delivery'] ?? [] as $section) {
            if ($location = $this->parseDeliverySection($section)) {
                $destination_locations[] = $location;
            }
        }

        if (!$loading_locations) {
            throw new \RuntimeException('ZieglerPdfAssistant: loading locations not found');
        }

        if (!$destination_locations) {
            throw new \RuntimeException('ZieglerPdfAssistant: destination locations not found');
        }

        if (!$cargos && $loading_locations) {
            $first = $loading_locations[0]['company_address']['company'] ?? 'Cargo';
            $cargos[] = [
                'title' => $first,
                'package_type' => 'other',
                'package_count' => 1,
            ];
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

        $this->createOrder($data);
    }

    protected function extractOrderReference(array $lines): string
    {
        $index = array_find_key($lines, fn ($line) => Str::lower(trim($line)) === 'ziegler ref');
        if ($index === null) {
            throw new \RuntimeException('ZieglerPdfAssistant: order reference not found');
        }

        $next = $this->nextNonEmptyLine($lines, $index + 1);
        if ($next === null) {
            throw new \RuntimeException('ZieglerPdfAssistant: order reference value missing');
        }

        return trim($next['value']);
    }

    protected function extractFreight(array $lines): array
    {
        $output = [];
        $index = array_find_key($lines, fn ($line) => Str::lower(trim($line)) === 'rate');
        if ($index === null) {
            return $output;
        }

        $priceLine = $this->nextNonEmptyLine($lines, $index + 1);
        if ($priceLine === null) {
            return $output;
        }

        $value = $priceLine['value'];
        $numeric = preg_replace('/[^0-9,\.]/', '', $value);
        if (preg_match('/^\d{1,3}(,\d{3})+$/', $numeric)) {
            $numeric = str_replace(',', '', $numeric);
        }
        $output['price'] = uncomma($numeric);

        if (str_contains($value, '€') || str_contains(Str::upper($value), 'EUR')) {
            $output['currency'] = 'EUR';
        } elseif (str_contains($value, '£') || str_contains(Str::upper($value), 'GBP')) {
            $output['currency'] = 'GBP';
        } elseif (str_contains(Str::upper($value), 'USD')) {
            $output['currency'] = 'USD';
        }

        return $output;
    }

    protected function extractCustomer(array $lines): array
    {
        $company = trim($lines[0] ?? '');
        if ($company === '') {
            throw new \RuntimeException('ZieglerPdfAssistant: customer company missing');
        }

        $addressLines = [];
        for ($i = 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if ($line === '') {
                if ($addressLines) {
                    break;
                }
                continue;
            }
            if (Str::upper($line) === 'BOOKING') {
                break;
            }
            $addressLines[] = $line;
        }

        $city = $addressLines ? array_pop($addressLines) : null;
        $street = $addressLines ? implode(', ', $addressLines) : null;

        $postalIndex = array_find_key($lines, fn ($line) => $this->looksLikePostal($line));
        $postalCode = $postalIndex !== null ? trim($lines[$postalIndex]) : null;

        return [
            'side' => 'none',
            'details' => array_filter([
                'company' => $company,
                'title' => $company,
                'street_address' => $street,
                'city' => $city,
                'postal_code' => $postalCode,
                'country' => 'GB',
            ]),
        ];
    }

    protected function parseCollectionSection(array $lines): array
    {
        $info = $this->parseSectionLines($lines);
        if (!$info['company']) {
            return ['location' => null, 'cargo' => null];
        }

        $location = $this->buildLocationFromInfo($info);

        $cargo = array_filter([
            'title' => $info['company'],
            'number' => $info['references'] ? implode(' / ', array_unique($info['references'])) : null,
            'package_count' => $info['package_count'],
        ], fn ($value) => !is_null($value) && $value !== '');
        $cargo['package_type'] = $info['package_count'] ? 'pallet' : 'other';

        if (!$cargo['number'] && !$info['package_count']) {
            $cargo = $info['package_count'] ? $cargo : null;
        }

        return [
            'location' => $location,
            'cargo' => $cargo,
        ];
    }

    protected function parseDeliverySection(array $lines): ?array
    {
        $info = $this->parseSectionLines($lines);
        return $info['company'] ? $this->buildLocationFromInfo($info) : null;
    }

    protected function parseSectionLines(array $lines): array
    {
        $lines = array_values(array_filter(array_map(fn ($line) => trim($line), $lines), fn ($line) => $line !== ''));

        if (!$lines) {
            return [
                'company' => null,
                'date' => null,
                'time_line' => null,
                'package_count' => null,
                'references' => [],
                'address_lines' => [],
                'location' => [],
            ];
        }

        $company = array_shift($lines);
        $date = $this->extractFirstDate($lines);
        $timeLine = $this->extractFirstTimeLine($lines);
        $packageCount = $this->extractPackageCount($lines);
        $references = $this->extractReferences($lines);

        $addressLines = $lines;
        $location = $this->separateLocationFromAddress($addressLines);

        return [
            'company' => $company,
            'date' => $date,
            'time_line' => $timeLine,
            'package_count' => $packageCount,
            'references' => $references,
            'address_lines' => $addressLines,
            'location' => $location,
        ];
    }

    protected function buildLocationFromInfo(array $info): array
    {
        $address = array_filter([
            'company' => $info['company'],
            'title' => $info['company'],
            'street_address' => $info['address_lines'] ? implode(', ', $info['address_lines']) : null,
        ]);
        if ($info['location']) {
            $address = array_merge($address, $info['location']);
        }

        $location = ['company_address' => $address];
        if ($info['date'] instanceof Carbon) {
            $time = $info['time_line']
                ? $this->parseTimeWindow($info['time_line'], $info['date'])
                : null;
            if ($time) {
                $location['time'] = $time;
            } else {
                $location['time'] = [
                    'datetime_from' => $info['date']->startOfDay()->toIso8601String(),
                ];
            }
        }

        return $location;
    }

    protected function extractFirstDate(array &$lines): ?Carbon
    {
        for ($i = 0; $i < count($lines); $i++) {
            if ($date = $this->parseDate($lines[$i])) {
                array_splice($lines, $i, 1);
                return $date;
            }
        }

        return null;
    }

    protected function extractFirstTimeLine(array &$lines): ?string
    {
        for ($i = 0; $i < count($lines); $i++) {
            if ($this->containsTime($lines[$i])) {
                $timeLine = $lines[$i];
                array_splice($lines, $i, 1);
                return $timeLine;
            }
        }

        return null;
    }

    protected function extractPackageCount(array &$lines): ?int
    {
        for ($i = 0; $i < count($lines); $i++) {
            if (preg_match('/(\d+)\s*(?:pal|plt|pallet)/i', $lines[$i], $matches)) {
                array_splice($lines, $i, 1);
                return (int) $matches[1];
            }
        }

        return null;
    }

    protected function extractReferences(array &$lines): array
    {
        $refs = [];
        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];
            $upper = Str::upper($line);
            if ($upper === 'REF') {
                array_splice($lines, $i, 1);
                $i--;
                continue;
            }
            if (Str::startsWith($upper, 'REF ')) {
                $refs[] = trim(Str::after($line, 'REF '));
                array_splice($lines, $i, 1);
                $i--;
                continue;
            }
        }

        return array_values(array_filter($refs));
    }

    protected function separateLocationFromAddress(array &$lines): array
    {
        if (!$lines) {
            return [];
        }

        $candidate = end($lines);
        $location = $this->parseLocationLine($candidate);
        if ($location) {
            array_pop($lines);
        }

        return $location;
    }

    protected function splitSections(array $lines): array
    {
        $sections = ['collection' => [], 'delivery' => []];
        $current = null;

        foreach ($lines as $line) {
            $normalized = Str::lower(trim($line));
            if (in_array($normalized, ['collection', 'delivery'], true)) {
                $current = $normalized;
                $sections[$current][] = [];
                continue;
            }

            if ($current === null) {
                continue;
            }

            if ($normalized === '') {
                continue;
            }

            if (in_array($normalized, ['collection', 'delivery', 'clearance'], true) || Str::startsWith($normalized, '-')) {
                $current = null;
                continue;
            }

            $lastIndex = array_key_last($sections[$current]);
            if ($lastIndex === null) {
                $sections[$current][] = [$line];
            } else {
                $sections[$current][$lastIndex][] = $line;
            }
        }

        return $sections;
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

    protected function containsTime(string $line): bool
    {
        $normalized = Str::lower($line);
        return (bool) preg_match('/\d{1,2}[:h]\d{2}/', $normalized)
            || (bool) preg_match('/\d{3,4}\s*[-–]\s*\d{3,4}/', $normalized)
            || (bool) preg_match('/\d{1,2}\s*(am|pm)/', $normalized);
    }

    protected function parseTimeWindow(string $timeLine, Carbon $date): ?array
    {
        $normalized = Str::lower($timeLine);
        $normalized = str_replace(['time to', 'booked'], [' ', ' '], $normalized);
        $normalized = preg_replace('/(?<=\d)h(?=\d{2})/', ':', $normalized);

        preg_match_all('/\b\d{1,2}(?::\d{2})?\s*(?:am|pm)?\b|\b\d{3,4}\b/', $normalized, $matches);
        $tokens = array_values(array_filter(array_map('trim', $matches[0])));

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

        try {
            if (str_contains($token, 'am') || str_contains($token, 'pm')) {
                return Carbon::parse($date->format('Y-m-d') . ' ' . $token);
            }
            if (preg_match('/^\d{1,2}:\d{2}$/', $token)) {
                return Carbon::createFromFormat('Y-m-d H:i', $date->format('Y-m-d') . ' ' . $token);
            }
            if (preg_match('/^\d{1,2}$/', $token)) {
                return Carbon::createFromFormat('Y-m-d H', $date->format('Y-m-d') . ' ' . $token);
            }
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
            $firstTwo = strtoupper($parts[0] . (isset($parts[1]) ? ' ' . $parts[1] : ''));
            if ($this->looksLikePostal($firstTwo)) {
                $postal = implode(' ', array_slice($parts, 0, 2));
                $city = implode(' ', array_slice($parts, 2));
            } elseif ($this->looksLikePostal($parts[0])) {
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
        $normalized = str_replace([' ', '-'], '', $value);

        return preg_match('/^\d{4,5}$/', preg_replace('/\D/', '', $normalized))
            || preg_match('/^[A-Z]{1,2}\d{1,2}[A-Z]?\d[A-Z]{2}$/', $normalized)
            || preg_match('/^[A-Z]{2}\d{3,}$/', $normalized);
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
}
