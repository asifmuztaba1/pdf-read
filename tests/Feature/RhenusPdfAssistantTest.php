<?php

namespace Tests\Feature;

use App\Assistants\RhenusPdfAssistant;
use Illuminate\Support\Arr;
use Tests\TestCase;

class RhenusPdfAssistantTest extends TestCase
{
    public function testProcessesSingleShipmentDocument(): void
    {
        $path = storage_path('pdf_client_test/pdfs/1671617 on transport 176577.pdf');
        $this->assertFileExists($path);

        $assistant = new RhenusPdfAssistant();
        $result = $assistant->processPath($path);

        $this->assertSame('126002860176577', $result['order_reference']);
        $this->assertSame('Rhenus Logistics Ltd', $result['customer']['details']['company']);
        $this->assertSame('cannockfinance@uk.rhenus.com', $result['customer']['details']['email']);

        $this->assertSame(1975.0, $result['freight_price']);
        $this->assertSame('EUR', $result['freight_currency']);

        $this->assertCount(1, $result['loading_locations']);
        $loading = $result['loading_locations'][0]['company_address'];
        $this->assertSame('Qualipac c/o Delisle', $loading['company']);
        $this->assertSame('FR', $loading['country']);
        $this->assertSame('77320', $loading['postal_code']);

        $this->assertCount(1, $result['destination_locations']);
        $destination = $result['destination_locations'][0]['company_address'];
        $this->assertSame('MILSPEED INTERNATIONAL LIMITED', $destination['company']);
        $this->assertSame('GB', $destination['country']);

        $this->assertCount(1, $result['cargos']);
        $cargo = $result['cargos'][0];
        $this->assertSame('1671617 - SURLYN', $cargo['title']);
        $this->assertSame(16, $cargo['package_count']);
        $this->assertSame('other', $cargo['package_type']);
        $this->assertSame(8260.0, $cargo['weight']);
        $this->assertSame(28.8, $cargo['volume']);
        $this->assertSame('1671617', Arr::first(explode(' / ', $cargo['number'])));
    }

    public function testProcessesMultiShipmentDocument(): void
    {
        $path = storage_path('pdf_client_test/pdfs/Transport_instruction_172111.pdf');
        $this->assertFileExists($path);

        $assistant = new RhenusPdfAssistant();
        $result = $assistant->processPath($path);

        $this->assertSame('126350940172111', $result['order_reference']);
        $this->assertSame('Rhenus Logistics Ltd', $result['customer']['details']['company']);
        $this->assertSame('uknorthadmin@rhenus.com', $result['customer']['details']['email']);

        $this->assertSame(2400.0, $result['freight_price']);
        $this->assertSame('EUR', $result['freight_currency']);

        $this->assertCount(1, $result['loading_locations']);
        $loadCompanies = array_map(fn ($location) => $location['company_address']['company'], $result['loading_locations']);
        $this->assertContains('CARRIER TRANSICOLD FRANCE', $loadCompanies);

        $this->assertCount(4, $result['destination_locations']);
        $destCompanies = array_map(fn ($location) => $location['company_address']['company'], $result['destination_locations']);
        $this->assertContains('Paneltex Ltd', $destCompanies);
        $this->assertContains('SDC TRAILERS LTD', $destCompanies);
        $this->assertContains('Solomon commercials', $destCompanies);
        $this->assertContains('GRAY & ADAMS', $destCompanies);

        $this->assertCount(4, $result['cargos']);
        $numbers = array_map(fn ($cargo) => $cargo['number'], $result['cargos']);
        $this->assertContains('1668085 / UK09092502', $numbers);
        $this->assertContains('1668086 / UK09092502', $numbers);
    }

    public function testProcessesDocumentWithTransportNumbers(): void
    {
        $path = storage_path('pdf_client_test/pdfs/Transport_instruction_172225.pdf');
        $this->assertFileExists($path);

        $assistant = new RhenusPdfAssistant();
        $result = $assistant->processPath($path);

        $this->assertSame('126350940172225', $result['order_reference']);
        $this->assertSame('MIG089 / OJ066', $result['transport_numbers']);
        $this->assertSame(3000.0, $result['freight_price']);
        $this->assertSame('EUR', $result['freight_currency']);

        $this->assertCount(1, $result['loading_locations']);
        $this->assertCount(2, $result['destination_locations']);
        $destCompanies = array_map(fn ($location) => $location['company_address']['company'], $result['destination_locations']);
        $this->assertContains('Solomon commercials', $destCompanies);

        $this->assertCount(2, $result['cargos']);
    }
}
