<?php

namespace Tests\Feature;

use App\Assistants\TransalliancePdfAssistant;
use App\Assistants\ZieglerPdfAssistant;
use Illuminate\Support\Str;
use Tests\TestCase;

class NewPdfAssistantsTest extends TestCase
{
    public function testTransallianceAssistantProcessesSample(): void
    {
        $path = storage_path('pdf_client_test/pdfs/FUSM202509111452520001.pdf');
        $this->assertFileExists($path);

        $assistant = new TransalliancePdfAssistant();
        $result = $assistant->processPath($path);

        $this->assertSame('1714403', $result['order_reference']);
        $this->assertSame('TRANSALLIANCE TS LTD', $result['customer']['details']['company']);
        $this->assertSame('EUR', $result['freight_currency']);
        $this->assertSame(950.0, $result['freight_price']);

        $this->assertCount(1, $result['loading_locations']);
        $loading = $result['loading_locations'][0];
        $this->assertSame('ICONEX', $loading['company_address']['company']);
        $this->assertTrue(Str::startsWith($loading['time']['datetime_from'] ?? '', '2025-09-17T'));

        $this->assertCount(1, $result['destination_locations']);
        $destination = $result['destination_locations'][0];
        $this->assertSame('ICONEX FRANCE', $destination['company_address']['company']);

        $this->assertNotEmpty($result['cargos']);
        $cargo = $result['cargos'][0];
        $this->assertSame('PAPER ROLLS', $cargo['title']);
        $this->assertSame('other', $cargo['package_type']);
    }

    public function testZieglerAssistantProcessesSample(): void
    {
        $path = storage_path('pdf_client_test/pdfs/pdfreader-booking.pdf');
        $this->assertFileExists($path);

        $assistant = new ZieglerPdfAssistant();
        $result = $assistant->processPath($path);

        $this->assertSame('187395', $result['order_reference']);
        $this->assertSame('ZIEGLER UK LTD', $result['customer']['details']['company']);
        $this->assertSame(1000.0, $result['freight_price']);
        $this->assertSame('EUR', $result['freight_currency']);

        $this->assertCount(2, $result['loading_locations']);
        $this->assertSame('AKZO NOBEL', $result['loading_locations'][0]['company_address']['company']);

        $this->assertNotEmpty($result['destination_locations']);
        $this->assertSame('ICD8', $result['destination_locations'][0]['company_address']['company']);

        $this->assertNotEmpty($result['cargos']);
        $this->assertSame('pallet', $result['cargos'][0]['package_type']);
    }
}
