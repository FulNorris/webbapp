<?php

namespace App\Services\Purchases\Sources;

class XlbyggStockholmSource extends AbstractStockholmHtmlSource
{
    protected string $key = 'xlbygg_stockholm';

    protected string $supplier = 'XL-Bygg';

    protected string $store = 'Stockholm';

    protected string $baseUrl = 'https://www.xlbygg.se';

    protected string $mapsQuery = 'XL-Bygg Stockholm';

    protected ?string $address = 'XL-Bygg Stockholm';

    protected ?float $latitude = 59.3293;

    protected ?float $longitude = 18.0686;

    protected function buildSearchUrl(string $query): string
    {
        return $this->baseUrl.'/privat/sok/?q='.rawurlencode($query);
    }
}
