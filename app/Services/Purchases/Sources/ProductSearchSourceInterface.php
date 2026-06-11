<?php

namespace App\Services\Purchases\Sources;

use Illuminate\Http\Client\Response;

interface ProductSearchSourceInterface
{
    public function key(): string;

    public function supplier(): string;

    public function store(): string;

    public function city(): string;

    public function isActive(): bool;

    public function searchUrl(string $query): string;

    /** @return array{method:string,url:string,headers:array<string,string>,json?:array<string,mixed>} */
    public function searchRequest(string $query): array;

    public function mapsLabel(): string;

    public function mapsUrl(): string;

    public function normalizeResponse(string $query, Response $response, \DateTimeInterface $fetchedAt): array;
}
