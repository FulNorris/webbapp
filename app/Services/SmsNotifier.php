<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SmsNotifier
{
    public function send(string $to, string $message): array
    {
        $to = $this->normalizeSwedishPhone($to);
        $message = $this->normalizeMessage($message);
        $provider = strtolower((string) config('services.sms.provider', 'log'));

        return match ($provider) {
            '46elks' => $this->sendWith46Elks($to, $message),
            'http' => $this->sendWithHttpProvider($to, $message),
            default => $this->logSms($to, $message),
        };
    }

    private function sendWith46Elks(string $to, string $message): array
    {
        $username = (string) config('services.sms.elks_username', '');
        $password = (string) config('services.sms.elks_password', '');
        $from = (string) config('services.sms.from', 'Stuckbema');

        if ($username === '' || $password === '') {
            return $this->logSms($to, $message, '46elks saknar användarnamn/lösenord.');
        }

        $response = Http::asForm()
            ->withBasicAuth($username, $password)
            ->timeout(5)
            ->connectTimeout(2)
            ->post('https://api.46elks.com/a1/sms', [
                'from' => $from,
                'to' => $to,
                'message' => $message,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('46elks SMS misslyckades med HTTP '.$response->status().'.');
        }

        return [
            'provider' => '46elks',
            'status' => 'sent',
            'to' => $to,
            'response' => $response->json() ?: ['body' => $response->body()],
        ];
    }

    private function sendWithHttpProvider(string $to, string $message): array
    {
        $url = (string) config('services.sms.url', '');
        $token = (string) config('services.sms.token', '');
        $from = (string) config('services.sms.from', 'Stuckbema');

        if ($url === '') {
            return $this->logSms($to, $message, 'HTTP SMS-provider saknar SMS_URL.');
        }

        $headers = ['Accept' => 'application/json'];
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        $response = Http::withHeaders($headers)
            ->timeout(5)
            ->connectTimeout(2)
            ->post($url, [
                'from' => $from,
                'to' => $to,
                'message' => $message,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('HTTP SMS-provider misslyckades med HTTP '.$response->status().'.');
        }

        return [
            'provider' => 'http',
            'status' => 'sent',
            'to' => $to,
            'response' => $response->json() ?: ['body' => $response->body()],
        ];
    }

    private function logSms(string $to, string $message, ?string $reason = null): array
    {
        Log::info('SMS loggat.', [
            'to' => $to,
            'message' => $message,
            'reason' => $reason,
        ]);

        return [
            'provider' => 'log',
            'status' => 'logged',
            'to' => $to,
            'reason' => $reason,
        ];
    }

    private function normalizeSwedishPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: '';

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '0')) {
            $digits = '46'.substr($digits, 1);
        }

        if (! str_starts_with($digits, '46')) {
            throw new \InvalidArgumentException('SMS-numret måste vara svenskt.');
        }

        return '+'.$digits;
    }

    private function normalizeMessage(string $message): string
    {
        return Str::of($message)
            ->replaceMatches('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ')
            ->replaceMatches('/\s+/u', ' ')
            ->trim()
            ->limit(900, '')
            ->toString();
    }
}
