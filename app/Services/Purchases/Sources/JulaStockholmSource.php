<?php

namespace App\Services\Purchases\Sources;

class JulaStockholmSource extends AbstractStockholmHtmlSource
{
    protected string $key = 'jula_stockholm';

    protected string $supplier = 'Jula';

    protected string $store = 'Kungens Kurva';

    protected string $baseUrl = 'https://www.jula.se';

    protected string $mapsQuery = 'Jula Kungens Kurva';

    protected ?string $address = 'Jula Kungens Kurva';

    protected ?float $latitude = 59.2699;

    protected ?float $longitude = 17.9201;

    protected function buildSearchUrl(string $query): string
    {
        return $this->baseUrl.'/search/?query='.rawurlencode($query);
    }
}
