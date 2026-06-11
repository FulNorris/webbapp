<?php

namespace App\Services\Purchases\Sources;

class BygmaStockholmSource extends AbstractStockholmHtmlSource
{
    protected string $key = 'bygma_stockholm';

    protected string $supplier = 'Bygma';

    protected string $store = 'Bromma';

    protected string $baseUrl = 'https://www.bygma.se';

    protected string $mapsQuery = 'Bygma Bromma';

    protected ?string $address = 'Bygma Bromma';

    protected ?float $latitude = 59.3552;

    protected ?float $longitude = 17.9560;

    protected function buildSearchUrl(string $query): string
    {
        return $this->baseUrl.'/sokresultat/?q='.rawurlencode($query);
    }
}
