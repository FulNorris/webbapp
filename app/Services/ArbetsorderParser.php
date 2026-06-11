<?php

namespace App\Services;

use Illuminate\Support\Str;

class ArbetsorderParser
{
    private const FIELD_MAP = [
        'Utskriftsdatum' => 'utskriftsdatum',
        'Intern arbetsorder nr' => 'arbetsorder_nr',
        'Handläggare' => 'handlaggare',
        'Färdig datum' => 'fardig_datum',
        'Ordernr' => 'ordernr',
        'Projekt' => 'projekt',
        'Startdatum' => 'startdatum_text',
        'Kontaktperson' => 'kontaktperson',
        'Telefon' => 'telefon',
        'Arbetsplats' => 'arbetsplats',
        'Postadress' => 'postadress',
    ];

    public function parseBlocks(string $source): array
    {
        $source = str_replace(["\r\n", "\r"], "\n", trim($source));
        $blocks = preg_split('/\n(?=Block\s+\d+\b)/u', $source) ?: [];

        return array_values(array_filter(array_map(fn (string $block) => $this->parseBlock($block), $blocks)));
    }

    public function parseBlock(string $block): ?array
    {
        $rawText = trim(str_replace(["\r\n", "\r"], "\n", $block));
        if ($rawText === '' || ! preg_match('/Intern arbetsorder nr:\s*(\d+)/u', $rawText, $numberMatch)) {
            return null;
        }

        $data = [
            'arbetsorder_nr' => (int) $numberMatch[1],
            'utskriftsdatum' => null,
            'handlaggare' => null,
            'fardig_datum' => null,
            'ordernr' => null,
            'projekt' => null,
            'startdatum' => null,
            'startdatum_text' => null,
            'kontaktperson' => null,
            'telefon' => null,
            'arbetsplats' => null,
            'postadress' => null,
            'arbetsbeskrivning_raw' => null,
            'handskrivet_raw' => null,
            'is_kopia' => false,
            'raw_text' => $rawText,
            'resurser' => [],
            'rader' => [],
        ];

        $lines = preg_split('/\n/u', $rawText) ?: [];
        $section = null;
        $resources = [];
        $description = [];
        $handwritten = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/^Block\s+\d+\b/u', $line) || $line === 'Intern arbetsorder') {
                continue;
            }

            if ($line === 'KOPIA') {
                $data['is_kopia'] = true;
                $section = null;
                continue;
            }

            if ($line === 'Fasta resurser') {
                $section = 'resources';
                continue;
            }

            if ($line === 'Arbetsbeskrivning:') {
                $section = 'description';
                continue;
            }

            if ($line === 'Handskrivet:') {
                $section = 'handwritten';
                continue;
            }

            if ($field = $this->parseField($line)) {
                $section = null;
                [$key, $value] = $field;
                $this->applyField($data, $key, $value);
                continue;
            }

            match ($section) {
                'resources' => $resources[] = $this->parseResourceLine($line),
                'description' => $description[] = $line,
                'handwritten' => $handwritten[] = $line,
                default => null,
            };
        }

        $data['resurser'] = array_values(array_filter($resources));
        $data['arbetsbeskrivning_raw'] = trim(implode("\n", $description)) ?: null;
        $data['handskrivet_raw'] = trim(implode("\n", $handwritten)) ?: null;
        $data['rader'] = $this->parseArticleRows($description);

        return $data;
    }

    public function parseArticleRows(array|string $description): array
    {
        $lines = is_array($description) ? $description : preg_split('/\n/u', $description);
        $rows = [];
        $rowNumber = 1;

        foreach ($lines ?: [] as $line) {
            foreach ($this->splitArticleLine((string) $line) as $part) {
                $parsed = $this->parseArticleLine($part);
                if (! $parsed) {
                    continue;
                }

                $parsed['rad_nr'] = $rowNumber++;
                $rows[] = $parsed;
            }
        }

        return $rows;
    }

    public function parseArticleLine(string $line): ?array
    {
        $rawLine = trim($line);
        if ($rawLine === '' || preg_match('/^(tillverka|prio\s*\d*|leverans på pall till kund):?$/iu', $rawLine)) {
            return null;
        }

        $line = preg_replace('/^tillverka\s+/iu', '', $rawLine) ?: $rawLine;

        if (preg_match('/^(\d+(?:[,.]\d+)?)\s*x\s+(.+)$/iu', $line, $matches)) {
            return $this->articleRow($rawLine, $matches[2], $matches[1], 'st');
        }

        if (preg_match('/^(\d+(?:[,.]\d+)?)\s*(lpm|mm|kg|m)\s+(.+)$/iu', $line, $matches)) {
            return $this->articleRow($rawLine, $matches[3], $matches[1], mb_strtolower($matches[2], 'UTF-8'));
        }

        if (preg_match('/^(\d+(?:[,.]\d+)?)\s+(hörn|kvartar)\s+(.+)$/iu', $line, $matches)) {
            return $this->articleRow($rawLine, $matches[3], $matches[1], 'st');
        }

        if (preg_match('/^(.+?)\s+x\s*(\d+(?:[,.]\d+)?)\s*(lpm|mm|kg|m)\b.*$/iu', $line, $matches)) {
            return $this->articleRow($rawLine, $matches[1], $matches[2], mb_strtolower($matches[3], 'UTF-8'));
        }

        return null;
    }

    public static function normalizeArticle(?string $article): string
    {
        $value = Str::of((string) $article)
            ->trim()
            ->replaceMatches('/\s+/u', ' ')
            ->replaceMatches('/[^[:alnum:]ÅÄÖåäö]+/u', ' ')
            ->trim()
            ->upper()
            ->toString();

        return str_replace(' ', '', $value);
    }

    public static function articleCandidates(?string $article): array
    {
        $raw = trim((string) $article);
        $normalized = self::normalizeArticle($raw);
        $compact = preg_replace('/[^A-Z0-9ÅÄÖ]/u', '', mb_strtoupper($raw, 'UTF-8')) ?: '';
        $candidates = [$normalized, $compact];

        if (preg_match_all('/\b(SLH|NLP|TLP|TL|SL|RP|R|D)\s*-?\s*(\d+[A-Z]?)\b/iu', $raw, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $prefix = mb_strtoupper($match[1], 'UTF-8');
                $number = mb_strtoupper($match[2], 'UTF-8');
                $candidates[] = $prefix.$number;
            }
        }

        if (preg_match('/nytt namn\s+(SLH\d+[A-Z]?)/iu', $raw, $alias)) {
            $candidates[] = mb_strtoupper($alias[1], 'UTF-8');
        }

        if (str_starts_with($normalized, 'NLP')) {
            $candidates[] = 'SLH'.substr($normalized, 3);
        }

        if (str_starts_with($normalized, 'SLH')) {
            $candidates[] = 'NLP'.substr($normalized, 3);
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    private function splitArticleLine(string $line): array
    {
        $line = trim($line);
        if (! str_contains($line, '+')) {
            return [$line];
        }

        return array_map('trim', preg_split('/\s+\+\s+/u', $line) ?: [$line]);
    }

    private function articleRow(string $rawLine, string $articleText, string $quantity, string $unit): array
    {
        $article = $this->extractArticle($articleText);
        $normalized = self::normalizeArticle($this->normalizationArticle($articleText, $article));

        return [
            'artikel' => $article,
            'artikel_normalized' => $normalized,
            'antal' => (float) str_replace(',', '.', $quantity),
            'enhet' => $unit === 'x' ? 'st' : $unit,
            'raw_line' => $rawLine,
            'kommentar' => trim($articleText) !== trim($article) ? trim($articleText) : null,
        ];
    }

    private function normalizationArticle(string $articleText, string $article): string
    {
        if (preg_match('/nytt namn\s+(SLH\d+[A-Z]?)/iu', $articleText, $alias)) {
            return $alias[1];
        }

        return $article;
    }

    private function extractArticle(string $text): string
    {
        $text = trim($text);

        if (preg_match('/\b(SLH|NLP|TLP|TL|SL|RP|R|D)\s*-?\s*(\d+[A-Z]?|X)\b/iu', $text, $matches)) {
            return mb_strtoupper($matches[1].$matches[2], 'UTF-8');
        }

        $text = preg_replace('/\([^)]*\)/u', '', $text) ?: $text;
        $text = preg_replace('/\b(i längderna|längd|diam|diameter|med|efter|enligt|ska|återkommer|finns)\b.*$/iu', '', $text) ?: $text;
        $text = trim($text, " \t\n\r\0\x0B,.:;");

        return $text !== '' ? $text : trim($text);
    }

    private function parseField(string $line): ?array
    {
        if (! preg_match('/^([^:]+):\s*(.*)$/u', $line, $matches)) {
            return null;
        }

        $label = trim($matches[1]);
        if (! array_key_exists($label, self::FIELD_MAP)) {
            return null;
        }

        return [self::FIELD_MAP[$label], trim($matches[2])];
    }

    private function applyField(array &$data, string $key, string $value): void
    {
        if (in_array($key, ['utskriftsdatum', 'fardig_datum'], true)) {
            $data[$key] = $this->parseDate($value);
            return;
        }

        if ($key === 'startdatum_text') {
            $data['startdatum_text'] = $value ?: null;
            $data['startdatum'] = $this->parseDate($value);
            return;
        }

        if (in_array($key, ['arbetsorder_nr', 'ordernr', 'projekt'], true)) {
            $data[$key] = preg_match('/\d+/', $value, $matches) ? (int) $matches[0] : null;
            return;
        }

        $data[$key] = $value !== '' ? $value : null;
    }

    private function parseDate(string $value): ?string
    {
        if (preg_match('/(\d{4}-\d{2}-\d{2})/u', $value, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function parseResourceLine(string $line): ?array
    {
        if ($line === '' || str_contains($line, ':')) {
            return null;
        }

        [$name, $phone] = array_pad(array_map('trim', explode(',', $line, 2)), 2, null);

        return $name !== '' ? ['namn' => $name, 'telefon' => $phone ?: null] : null;
    }
}
