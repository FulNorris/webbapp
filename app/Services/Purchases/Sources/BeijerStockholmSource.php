<?php

namespace App\Services\Purchases\Sources;

class BeijerStockholmSource extends AbstractStockholmHtmlSource
{
    protected string $key = 'beijer_stockholm';

    protected string $supplier = 'Beijer';

    protected string $store = 'Bromma';

    protected string $baseUrl = 'https://www.beijerbygg.se';

    protected string $mapsQuery = 'Beijer Bromma';

    protected ?string $address = 'Beijer Byggmaterial Bromma';

    protected ?float $latitude = 59.3558;

    protected ?float $longitude = 17.9557;

    protected function buildSearchUrl(string $query): string
    {
        return $this->baseUrl.'/SearchDisplay?sType=SimpleSearch&catalogId=10552&searchType=102&searchSource=Q&resultCatEntryType=1&showResultsPage=true&beginIndex=0&langId=46&storeId=10202&coSearchSkuEnabled=true&searchTerm='.rawurlencode($query);
    }
}
