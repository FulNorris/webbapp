<?php

namespace App\Services\Purchases\Sources;

class BiltemaBreddenSource extends AbstractStockholmHtmlSource
{
    protected string $key = 'biltema_bredden';

    protected string $supplier = 'Biltema';

    protected string $store = 'Bredden';

    protected string $baseUrl = 'https://www.biltema.se';

    protected string $mapsQuery = 'Biltema Bredden';

    protected ?float $latitude = 59.4892;

    protected ?float $longitude = 17.9224;

    protected function buildSearchUrl(string $query): string
    {
        return $this->baseUrl.'/soksida/?query='.rawurlencode($query);
    }
}
