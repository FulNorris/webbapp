<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

class WebPushNotifier
{
    public function configured(): bool
    {
        return filled(env('VAPID_PUBLIC_KEY')) && filled(env('VAPID_PRIVATE_KEY'));
    }

    public function enabled(): bool
    {
        if (! $this->configured()) {
            return false;
        }

        $settings = DB::table('system_settings')->where('id', 1)->first();

        return (bool) ($settings?->allow_push_notifications ?? true);
    }

    public function sendToUsers(array $userIds, array $message): array
    {
        $userIds = array_values(array_unique(array_filter($userIds)));
        if (! $userIds || ! $this->enabled()) {
            return ['sent' => 0, 'failed' => 0, 'expired' => 0];
        }

        $subscriptions = DB::table('push_subscriptions')
            ->whereIn('user_id', $userIds)
            ->where('enabled', true)
            ->whereNull('revoked_at')
            ->get();

        return $this->send($subscriptions, $message);
    }

    public function sendToAdmins(array $message): array
    {
        $userIds = DB::table('users')
            ->whereIn('role', ['owner', 'firmatecknare', 'manager', 'arbetsledare', 'admin'])
            ->where('active', true)
            ->pluck('id')
            ->all();

        return $this->sendToUsers($userIds, $message);
    }

    private function send(iterable $subscriptions, array $message): array
    {
        if (! $this->enabled()) {
            return ['sent' => 0, 'failed' => 0, 'expired' => 0];
        }

        try {
            $payload = json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => env('VAPID_SUBJECT', 'mailto:no-reply@example.test'),
                    'publicKey' => env('VAPID_PUBLIC_KEY'),
                    'privateKey' => env('VAPID_PRIVATE_KEY'),
                ],
            ], [
                'TTL' => 3600,
                'urgency' => 'normal',
            ]);
        } catch (Throwable $exception) {
            Log::warning('Web Push kunde inte initieras.', ['message' => $exception->getMessage()]);

            return ['sent' => 0, 'failed' => 0, 'expired' => 0];
        }

        $byEndpoint = [];
        foreach ($subscriptions as $subscription) {
            if (! $subscription->endpoint || ! $subscription->p256dh || ! $subscription->auth) {
                continue;
            }

            $byEndpoint[$subscription->endpoint] = $subscription;
            $webPush->queueNotification(Subscription::create([
                'endpoint' => $subscription->endpoint,
                'keys' => [
                    'p256dh' => $subscription->p256dh,
                    'auth' => $subscription->auth,
                ],
                'contentEncoding' => 'aes128gcm',
            ]), $payload);
        }

        $result = ['sent' => 0, 'failed' => 0, 'expired' => 0];
        try {
            foreach ($webPush->flush() as $report) {
                $endpoint = $report->getEndpoint();
                $subscription = $byEndpoint[$endpoint] ?? null;

                if ($report->isSuccess()) {
                    $result['sent']++;
                    DB::table('push_subscriptions')->where('endpoint', $endpoint)->update([
                        'failure_count' => 0,
                        'last_success_at' => now(),
                        'last_seen_at' => now(),
                        'updated_at' => now(),
                    ]);
                    continue;
                }

                $result['failed']++;
                $expired = $report->isSubscriptionExpired();
                if ($expired) {
                    $result['expired']++;
                }

                DB::table('push_subscriptions')->where('endpoint', $endpoint)->update([
                    'enabled' => ! $expired,
                    'failure_count' => (int) ($subscription?->failure_count ?? 0) + 1,
                    'last_failure_at' => now(),
                    'revoked_at' => $expired ? now() : null,
                    'updated_at' => now(),
                ]);
            }
        } catch (Throwable $exception) {
            Log::warning('Web Push kunde inte skickas.', ['message' => $exception->getMessage()]);

            return ['sent' => $result['sent'], 'failed' => count($byEndpoint), 'expired' => $result['expired']];
        }

        return $result;
    }
}
