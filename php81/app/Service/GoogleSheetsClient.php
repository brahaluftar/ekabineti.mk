<?php

declare(strict_types=1);

namespace EKabineti\Service;

final class GoogleSheetsClient
{
    private const API_BASE = 'https://sheets.googleapis.com/v4/spreadsheets/';
    private const SCOPE = 'https://www.googleapis.com/auth/spreadsheets';
    private ?string $accessToken = null;
    private array $credentials;

    public function __construct(private readonly array $config)
    {
        if (!extension_loaded('curl') || !extension_loaded('openssl')) {
            throw new \RuntimeException('Google Sheets sync requires curl and openssl.');
        }
        $spreadsheetId = trim((string)($config['spreadsheet_id'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{20,}$/',$spreadsheetId)) {
            throw new \RuntimeException('GOOGLE_SHEETS_SPREADSHEET_ID is missing or invalid.');
        }
        $this->credentials = $this->loadCredentials($config);
    }

    public function spreadsheetId(): string { return (string)$this->config['spreadsheet_id']; }
    public function serviceAccountEmail(): string { return (string)$this->credentials['client_email']; }

    /** @return array<string,mixed> */
    public function metadata(): array
    {
        $fields = 'properties(title,locale,timeZone),sheets(properties(sheetId,title,index,hidden,gridProperties(rowCount,columnCount)),protectedRanges(protectedRangeId,description,warningOnly,editors))';
        return $this->request('GET',self::API_BASE.rawurlencode($this->spreadsheetId()).'?includeGridData=false&fields='.rawurlencode($fields));
    }

    /** @param array<int,array{range:string,values:array}> $data */
    public function updateValues(array $data): array
    {
        $data = self::normalizeValuesBatch($data);
        return $this->request('POST',self::API_BASE.rawurlencode($this->spreadsheetId()).'/values:batchUpdate',[
            'valueInputOption'=>'USER_ENTERED',
            'includeValuesInResponse'=>false,
            'data'=>$data,
        ]);
    }

    /**
     * Final Google Sheets values payload boundary. Reporting and MSSQL values may
     * remain null internally, but the Sheets API must receive an empty cell.
     *
     * @param array<int,array{range:string,values:array}> $data
     * @return array<int,array{range:string,values:array}>
     */
    public static function normalizeValuesBatch(array $data): array
    {
        $normalize = static function (mixed $value) use (&$normalize): mixed {
            if ($value === null) return '';
            if (!is_array($value)) return $value;
            foreach ($value as $key => $nested) $value[$key] = $normalize($nested);
            return $value;
        };

        return $normalize($data);
    }

    /** @param array<int,array<string,mixed>> $requests */
    public function batchUpdate(array $requests): array
    {
        return $this->request('POST',self::API_BASE.rawurlencode($this->spreadsheetId()).':batchUpdate',['requests'=>$requests]);
    }

    /** @param array<string,mixed> $metadata @return array<string,mixed> */
    public function ensureSheet(array $metadata,string $title,int $rows,int $columns): array
    {
        foreach($metadata['sheets']??[]as$sheet)if((string)($sheet['properties']['title']??'')===$title)return$metadata;
        $this->batchUpdate([['addSheet'=>['properties'=>['title'=>$title,'gridProperties'=>['rowCount'=>$rows,'columnCount'=>$columns]]]]]);
        return $this->metadata();
    }

    /** @return array<string,mixed> */
    public function configureTemplate(): array
    {
        $metadata=$this->ensureSheet($this->metadata(),'Јавни набавки',2000,18);
        $metadata=$this->ensureSheet($metadata,'Правни предмети',2000,20);$this->assertTemplate($metadata);
        $ids=[];$rowCounts=[];$existing=[];
        foreach($metadata['sheets']??[]as$sheet){$properties=$sheet['properties']??[];$title=(string)($properties['title']??'');$ids[$title]=(int)($properties['sheetId']??0);$rowCounts[$title]=(int)($properties['gridProperties']['rowCount']??1000);foreach($sheet['protectedRanges']??[]as$protected){$existing[(string)($protected['description']??'')]=true;}}
        $requests=[
            ['updateSpreadsheetProperties'=>['properties'=>['locale'=>(string)($this->config['locale']??'mk_MK'),'timeZone'=>(string)($this->config['timezone']??'Europe/Skopje')],'fields'=>'locale,timeZone']],
            ['repeatCell'=>['range'=>['sheetId'=>$ids['Панел'],'startRowIndex'=>3,'endRowIndex'=>4,'startColumnIndex'=>11,'endColumnIndex'=>13],'cell'=>['userEnteredFormat'=>['backgroundColor'=>['red'=>0.02,'green'=>0.55,'blue'=>0.49],'textFormat'=>['foregroundColor'=>['red'=>1,'green'=>1,'blue'=>1],'bold'=>true,'fontSize'=>8],'horizontalAlignment'=>'CENTER','verticalAlignment'=>'MIDDLE']],'fields'=>'userEnteredFormat(backgroundColor,textFormat,horizontalAlignment,verticalAlignment)']],
            ['repeatCell'=>['range'=>['sheetId'=>$ids['Панел'],'startRowIndex'=>4,'endRowIndex'=>5,'startColumnIndex'=>11,'endColumnIndex'=>13],'cell'=>['userEnteredFormat'=>['backgroundColor'=>['red'=>0.82,'green'=>0.98,'blue'=>0.94],'textFormat'=>['foregroundColor'=>['red'=>0.04,'green'=>0.28,'blue'=>0.26],'bold'=>true,'fontSize'=>8],'horizontalAlignment'=>'CENTER','verticalAlignment'=>'MIDDLE','wrapStrategy'=>'WRAP']],'fields'=>'userEnteredFormat(backgroundColor,textFormat,horizontalAlignment,verticalAlignment,wrapStrategy)']],
        ];
        $email=$this->serviceAccountEmail();
        $protections=[
            'e-kabineti:paneli'=>['sheetId'=>$ids['Панел'],'startRowIndex'=>0,'endRowIndex'=>56,'startColumnIndex'=>0,'endColumnIndex'=>13],
            'e-kabineti:projektet-header'=>['sheetId'=>$ids['Проекти'],'startRowIndex'=>0,'endRowIndex'=>1,'startColumnIndex'=>0,'endColumnIndex'=>14],
            'e-kabineti:projektet-helpers'=>['sheetId'=>$ids['Проекти'],'startRowIndex'=>1,'endRowIndex'=>min(1000,$rowCounts['Проекти']),'startColumnIndex'=>12,'endColumnIndex'=>14],
            'e-kabineti:sherbimet-header'=>['sheetId'=>$ids['Услуги'],'startRowIndex'=>0,'endRowIndex'=>1,'startColumnIndex'=>0,'endColumnIndex'=>8],
            'e-kabineti:sherbimet-helper'=>['sheetId'=>$ids['Услуги'],'startRowIndex'=>1,'endRowIndex'=>min(500,$rowCounts['Услуги']),'startColumnIndex'=>7,'endColumnIndex'=>8],
            'e-kabineti:buxheti-header'=>['sheetId'=>$ids['Буџет'],'startRowIndex'=>0,'endRowIndex'=>1,'startColumnIndex'=>0,'endColumnIndex'=>6],
            'e-kabineti:ceshtjet-header'=>['sheetId'=>$ids['Прашања'],'startRowIndex'=>0,'endRowIndex'=>1,'startColumnIndex'=>0,'endColumnIndex'=>8],
        ];
        foreach($protections as$description=>$range){if(isset($existing[$description]))continue;$requests[]=['addProtectedRange'=>['protectedRange'=>['range'=>$range,'description'=>$description,'warningOnly'=>false,'editors'=>['users'=>[$email]]]]];}
        $response=$this->batchUpdate($requests);return['status'=>'configured','requests'=>count($requests),'spreadsheet_id_suffix'=>substr($this->spreadsheetId(),-8),'response'=>$response];
    }

    /** @param array<string,mixed> $metadata */
    public function assertTemplate(array $metadata): void
    {
        $sheets = $metadata['sheets'] ?? [];
        $visible = [];
        foreach ($sheets as $sheet) {
            $properties = $sheet['properties'] ?? [];
            if (($properties['hidden'] ?? false) !== true) $visible[] = (string)($properties['title'] ?? '');
        }
        if (array_slice($visible,0,5) !== array_slice(MayorGoogleSheetsMap::REQUIRED_SHEETS,0,5) || !in_array('Јавни набавки',$visible,true) || !in_array('Правни предмети',$visible,true)) {
            throw new \RuntimeException('Google spreadsheet does not contain the required visible sheets.');
        }
        $panel = $sheets[array_search('Панел',array_column(array_column($sheets,'properties'),'title'),true)] ?? null;
        $grid = $panel['properties']['gridProperties'] ?? [];
        if ((int)($grid['rowCount'] ?? 0) < 56 || (int)($grid['columnCount'] ?? 0) < 13) {
            throw new \RuntimeException('Панел must retain at least A1:M56.');
        }
    }

    /** @return array<string,mixed> */
    private function request(string $method,string $url,?array $body=null): array
    {
        $attempts = 4;
        for ($attempt=0;$attempt<$attempts;$attempt++) {
            $curl = curl_init($url);
            if ($curl === false) throw new \RuntimeException('Unable to initialize Google API request.');
            $headers = ['Authorization: Bearer '.$this->token(),'Accept: application/json'];
            if ($body !== null) $headers[] = 'Content-Type: application/json; charset=UTF-8';
            curl_setopt_array($curl,[
                CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,
                CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>60,CURLOPT_HEADER=>false,
            ]);
            if ($body !== null) curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
            $response = curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$error=curl_error($curl);curl_close($curl);
            if (is_string($response) && $status>=200 && $status<300) {
                if ($response === '') return [];
                $decoded=json_decode($response,true,512,JSON_THROW_ON_ERROR);
                return is_array($decoded)?$decoded:[];
            }
            if (($status===429||$status>=500) && $attempt<$attempts-1) {
                usleep((int)((250*(2**$attempt)+random_int(0,250))*1000));
                continue;
            }
            $message=$error!==''?$error:$this->apiError(is_string($response)?$response:'');
            throw new \RuntimeException('Google Sheets API request failed (HTTP '.$status.'): '.$message);
        }
        throw new \RuntimeException('Google Sheets API retry limit reached.');
    }

    private function token(): string
    {
        if ($this->accessToken !== null) return $this->accessToken;
        $now=time();$tokenUri=(string)($this->credentials['token_uri']??'https://oauth2.googleapis.com/token');
        $header=$this->base64Url(json_encode(['alg'=>'RS256','typ'=>'JWT'],JSON_THROW_ON_ERROR));
        $claims=$this->base64Url(json_encode(['iss'=>$this->credentials['client_email'],'scope'=>self::SCOPE,'aud'=>$tokenUri,'iat'=>$now,'exp'=>$now+3600],JSON_THROW_ON_ERROR));
        $unsigned=$header.'.'.$claims;$signature='';
        if (!openssl_sign($unsigned,$signature,(string)$this->credentials['private_key'],OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Unable to sign the Google service-account assertion.');
        }
        $assertion=$unsigned.'.'.$this->base64Url($signature);
        $curl=curl_init($tokenUri);if($curl===false)throw new \RuntimeException('Unable to initialize Google token request.');
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded'],CURLOPT_POSTFIELDS=>http_build_query(['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$assertion])]);
        $response=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$error=curl_error($curl);curl_close($curl);
        if (!is_string($response)||$status<200||$status>=300) throw new \RuntimeException('Google service-account token request failed (HTTP '.$status.'): '.($error!==''?$error:$this->apiError(is_string($response)?$response:'')));
        $decoded=json_decode($response,true,512,JSON_THROW_ON_ERROR);$token=(string)($decoded['access_token']??'');
        if ($token==='')throw new \RuntimeException('Google token response did not contain an access token.');
        return $this->accessToken=$token;
    }

    /** @return array<string,mixed> */
    private function loadCredentials(array $config): array
    {
        $source=trim((string)($config['credentials']??''));$file=trim((string)($config['credentials_file']??''));
        if ($source!=='' && !str_starts_with($source,'{') && is_file($source)) $file=$source;
        if ($source==='' && $file!=='') {
            if (!is_readable($file)) throw new \RuntimeException('Google service-account credentials file is not readable.');
            $source=(string)file_get_contents($file);
        }
        if ($source==='') throw new \RuntimeException('Google service-account credentials are not configured.');
        $credentials=json_decode($source,true,512,JSON_THROW_ON_ERROR);
        if (!is_array($credentials)||($credentials['type']??'')!=='service_account'||trim((string)($credentials['client_email']??''))===''||trim((string)($credentials['private_key']??''))==='') {
            throw new \RuntimeException('Google service-account credentials are incomplete.');
        }
        return $credentials;
    }

    private function apiError(string $response): string
    {
        $decoded=json_decode($response,true);$message=is_array($decoded)?(string)($decoded['error']['message']??''):'';
        $message=preg_replace('/[A-Za-z0-9_-]{40,}/','[redacted]',$message)??'';
        return mb_strimwidth($message!==''?$message:'Remote request failed.',0,300,'…');
    }

    private function base64Url(string $value): string { return rtrim(strtr(base64_encode($value),'+/','-_'),'='); }
}
