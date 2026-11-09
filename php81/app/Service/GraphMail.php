<?php

declare(strict_types=1);

namespace EKabineti\Service;

final class GraphMail
{
    private ?array $token = null;

    public function __construct(private readonly array $config) {}

    public function enabled(): bool { return (bool) ($this->config['enabled'] ?? false); }

    public function send(string $recipient, string $subject, string $plainText): ?string
    {
        $this->assertConfigured();
        $sender = rawurlencode((string) $this->config['sender']);
        [$status, $headers] = $this->request('https://graph.microsoft.com/v1.0/users/'.$sender.'/sendMail', [
            'message' => [
                'subject' => $subject,
                'body' => ['contentType' => 'HTML', 'content' => nl2br(htmlspecialchars($plainText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))],
                'toRecipients' => [['emailAddress' => ['address' => $recipient]]],
            ],
            'saveToSentItems' => true,
        ], ['Authorization: Bearer '.$this->accessToken()]);
        if ($status !== 202) throw new \RuntimeException('Microsoft Graph refused sendMail with HTTP '.$status.'.');
        return $headers['request-id'] ?? $headers['client-request-id'] ?? null;
    }

    private function accessToken(): string
    {
        if ($this->token && $this->token['expires'] > time() + 60) return $this->token['value'];
        $tenant = rawurlencode((string) $this->config['tenant_id']);
        $ch = curl_init('https://login.microsoftonline.com/'.$tenant.'/oauth2/v2.0/token');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => $this->config['client_id'], 'client_secret' => $this->config['client_secret'], 'scope' => 'https://graph.microsoft.com/.default', 'grant_type' => 'client_credentials',
        ])]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        $json = json_decode((string) $body, true);
        if ($status !== 200 || !is_array($json) || !is_string($json['access_token'] ?? null)) throw new \RuntimeException('Microsoft identity token failed (HTTP '.$status.'): '.$error);
        $this->token = ['value' => $json['access_token'], 'expires' => time() + (int) ($json['expires_in'] ?? 3600)];
        return $this->token['value'];
    }

    private function request(string $url, array $payload, array $extraHeaders): array
    {
        $headers = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => (int) ($this->config['timeout'] ?? 20), CURLOPT_HTTPHEADER => array_merge(['Accept: application/json', 'Content-Type: application/json'], $extraHeaders), CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE), CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
            $parts = explode(':', $line, 2); if (count($parts) === 2) $headers[strtolower(trim($parts[0]))] = trim($parts[1]); return strlen($line);
        }]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($status === 0) throw new \RuntimeException('Microsoft Graph connection failed: '.$error);
        return [$status, $headers];
    }

    private function assertConfigured(): void
    {
        if (!$this->enabled()) throw new \RuntimeException('Microsoft Graph mail is disabled.');
        foreach (['tenant_id', 'client_id', 'client_secret', 'sender'] as $key) if (trim((string) ($this->config[$key] ?? '')) === '') throw new \RuntimeException('Missing GRAPH_'.strtoupper($key).'.');
    }
}

