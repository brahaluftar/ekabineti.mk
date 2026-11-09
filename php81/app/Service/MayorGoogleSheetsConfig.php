<?php

declare(strict_types=1);

namespace EKabineti\Service;

final class MayorGoogleSheetsConfig
{
    /** @return array<string,mixed> */
    public static function fromApplication(array $applicationConfig): array
    {
        $configured=is_array($applicationConfig['google_sheets']??null)?$applicationConfig['google_sheets']:[];
        $envEnabled=getenv('GOOGLE_SHEETS_SYNC_ENABLED');
        return [
            'enabled'=>$envEnabled!==false?in_array(strtolower(trim($envEnabled)),['1','true','yes','on'],true):(bool)($configured['enabled']??false),
            'spreadsheet_id'=>self::environment('GOOGLE_SHEETS_SPREADSHEET_ID',(string)($configured['spreadsheet_id']??'')),
            'dashboard_gid'=>self::environment('GOOGLE_SHEETS_DASHBOARD_GID',(string)($configured['dashboard_gid']??'0')),
            'credentials'=>self::environment('GOOGLE_SERVICE_ACCOUNT_CREDENTIALS',(string)($configured['credentials']??'')),
            'credentials_file'=>self::environment('GOOGLE_APPLICATION_CREDENTIALS',(string)($configured['credentials_file']??'')),
            'timezone'=>self::environment('GOOGLE_SHEETS_SYNC_TIMEZONE',(string)($configured['timezone']??'Europe/Skopje')),
            'locale'=>(string)($configured['locale']??'mk_MK'),
        ];
    }

    private static function environment(string $key,string $fallback): string
    {
        $value=getenv($key);return$value===false?$fallback:trim($value);
    }
}
