<?php

declare(strict_types=1);

namespace EKabineti\Service;

final class OpenAIConfig
{
    public static function fromApplication(array $applicationConfig): array
    {
        $configured=is_array($applicationConfig['openai']??null)?$applicationConfig['openai']:[];
        $enabled=getenv('OPENAI_ENABLED');
        return[
            'enabled'=>$enabled!==false?in_array(strtolower(trim($enabled)),['1','true','yes','on'],true):(bool)($configured['enabled']??false),
            'api_key'=>self::environment('OPENAI_API_KEY',(string)($configured['api_key']??'')),
            'model'=>self::environment('OPENAI_MODEL',(string)($configured['model']??'gpt-5-mini')),
            'timeout_seconds'=>(int)self::environment('OPENAI_TIMEOUT_SECONDS',(string)($configured['timeout_seconds']??45)),
            'max_output_tokens'=>(int)self::environment('OPENAI_MAX_OUTPUT_TOKENS',(string)($configured['max_output_tokens']??2500)),
        ];
    }

    private static function environment(string $key,string $fallback): string
    {
        $value=getenv($key);return$value===false?$fallback:trim($value);
    }
}
