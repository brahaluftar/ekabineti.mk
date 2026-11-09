<?php

declare(strict_types=1);

namespace EKabineti\Service;

final class OpenAIResponsesClient
{
    public function __construct(private readonly array $config, private readonly ?\Closure $transport = null) {}

    public function isConfigured(): bool
    {
        return ($this->config['enabled'] ?? false) === true && trim((string) ($this->config['api_key'] ?? '')) !== '';
    }

    public function model(): string
    {
        return trim((string) ($this->config['model'] ?? '')) ?: 'gpt-5-mini';
    }

    /** $maxOutputTokens: a larger budget for long outputs (reasoning tokens count towards it); never below the configured one. */
    public function structured(string $instructions, string $input, string $schemaName, array $schema, ?int $maxOutputTokens = null): array
    {
        if (!$this->isConfigured()) throw new \RuntimeException('OpenAI API is not configured or is disabled.');
        if (!preg_match('/^[a-z0-9_]{1,64}$/', $schemaName)) throw new \InvalidArgumentException('Invalid structured output schema name.');

        $payload = [
            'model'=>$this->model(),
            'store'=>false,
            'instructions'=>$instructions,
            'input'=>$input,
            'max_output_tokens'=>max(500, min(8000, max((int) ($this->config['max_output_tokens'] ?? 2500), (int) $maxOutputTokens))),
            'text'=>['format'=>['type'=>'json_schema','name'=>$schemaName,'strict'=>true,'schema'=>$schema]],
        ];
        $response = $this->transport !== null ? ($this->transport)($payload) : $this->post($payload);
        if (!is_array($response)) throw new \RuntimeException('OpenAI returned an invalid response.');
        if (isset($response['error'])) {
            $message=is_array($response['error'])?(string)($response['error']['message']??'API error'):'API error';
            throw new \RuntimeException('OpenAI request failed: '.mb_substr($message,0,500));
        }

        $text='';
        foreach (($response['output'] ?? []) as $item) {
            if (!is_array($item)) continue;
            foreach (($item['content'] ?? []) as $content) {
                if (!is_array($content)) continue;
                if (($content['type'] ?? '') === 'refusal') throw new \RuntimeException('OpenAI refused the request.');
                if (($content['type'] ?? '') === 'output_text') $text.=(string)($content['text']??'');
            }
        }
        if ($text === '' && is_string($response['output_text'] ?? null)) $text=$response['output_text'];
        $decoded=json_decode($text,true,64,JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) throw new \RuntimeException('OpenAI structured output was not an object.');
        return $decoded;
    }

    private function post(array $payload): array
    {
        if (!function_exists('curl_init')) throw new \RuntimeException('The cURL PHP extension is required for OpenAI.');
        $curl=curl_init('https://api.openai.com/v1/responses');
        if ($curl === false) throw new \RuntimeException('Could not initialize the OpenAI connection.');
        $body=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        curl_setopt_array($curl,[
            CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>false,
            CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>max(10,min(120,(int)($this->config['timeout_seconds']??45))),
            CURLOPT_HTTPHEADER=>['Authorization: Bearer '.trim((string)$this->config['api_key']),'Content-Type: application/json'],
            CURLOPT_POSTFIELDS=>$body,
        ]);
        $raw=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$error=curl_error($curl);curl_close($curl);
        if (!is_string($raw)) throw new \RuntimeException('OpenAI connection failed: '.mb_substr($error,0,300));
        $decoded=json_decode($raw,true);
        if (!is_array($decoded)) throw new \RuntimeException('OpenAI returned malformed JSON (HTTP '.$status.').');
        if ($status<200||$status>=300) {
            $message=(string)($decoded['error']['message']??'request failed');
            throw new \RuntimeException('OpenAI request failed (HTTP '.$status.'): '.mb_substr($message,0,500));
        }
        return $decoded;
    }
}
