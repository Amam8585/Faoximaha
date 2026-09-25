<?php

declare(strict_types=1);

namespace Faoxima\Provisioning;

final class TelegramClient implements TelegramGateway
{
    public function __construct(private readonly string $apiBase = 'https://api.telegram.org', private readonly int $attempts = 4) {}

    public function getMe(string $token): array
    {
        $result = $this->request($token, 'getMe', []);
        if (!is_array($result)) { throw new ProvisioningException('Telegram getMe returned an invalid result.'); }
        $id = $result['id'] ?? null;
        $username = $result['username'] ?? null;
        if (!is_int($id) || !is_string($username) || $username === '') {
            throw new ProvisioningException('Telegram getMe returned incomplete bot identity.');
        }
        return ['id' => $id, 'username' => $username];
    }

    public function setWebhook(string $token, string $url, string $secret): void
    {
        $this->request($token, 'setWebhook', ['url' => $url, 'secret_token' => $secret,
            'allowed_updates' => json_encode(['message', 'callback_query'], JSON_THROW_ON_ERROR), 'max_connections' => '20']);
        $info = $this->getWebhookInfo($token);
        if (str_contains($info['url'], '?child=') || !hash_equals($url, $info['url'])) {
            throw new ProvisioningException('Telegram webhook verification returned a different URL.');
        }
    }

    public function deleteWebhook(string $token): void
    {
        $this->request($token, 'deleteWebhook', ['drop_pending_updates' => 'false']);
        if ($this->getWebhookInfo($token)['url'] !== '') {
            throw new ProvisioningException('Telegram webhook remained configured after deletion.');
        }
    }

    public function getWebhookInfo(string $token): array
    {
        $result = $this->request($token, 'getWebhookInfo', []);
        if (!is_array($result)) { throw new ProvisioningException('Telegram getWebhookInfo returned an invalid result.'); }
        return ['url' => is_string($result['url'] ?? null) ? $result['url'] : '',
            'pending_update_count' => is_int($result['pending_update_count'] ?? null) ? $result['pending_update_count'] : 0,
            'last_error_message' => is_string($result['last_error_message'] ?? null) ? $result['last_error_message'] : ''];
    }

    /** @param array<string,string> $fields */
    private function request(string $token, string $method, array $fields): mixed
    {
        $last = 'unknown error';
        for ($attempt = 1; $attempt <= $this->attempts; $attempt++) {
            $handle = curl_init($this->apiBase . '/bot' . $token . '/' . $method);
            curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
            $body = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $curlError = curl_error($handle);
            curl_close($handle);
            $decoded = is_string($body) ? json_decode($body, true) : null;
            if ($status >= 200 && $status < 300 && is_array($decoded) && ($decoded['ok'] ?? false) === true && array_key_exists('result', $decoded)) {
                return $decoded['result'];
            }
            $description = is_array($decoded) && is_string($decoded['description'] ?? null) ? $decoded['description'] : ($curlError ?: "HTTP {$status}");
            $last = $description;
            $retryable = $status === 0 || $status === 429 || $status >= 500;
            if (!$retryable || $attempt === $this->attempts) { break; }
            $retryAfter = is_array($decoded) ? (int) ($decoded['parameters']['retry_after'] ?? 0) : 0;
            usleep((max($retryAfter * 1_000_000, 150_000 * (2 ** ($attempt - 1)))) + random_int(0, 100_000));
        }
        throw new ProvisioningException("Telegram {$method} failed: {$last}");
    }
}
