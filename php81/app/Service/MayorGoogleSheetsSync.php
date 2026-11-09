<?php

declare(strict_types=1);

namespace EKabineti\Service;

use EKabineti\Core\Logger;

final class MayorGoogleSheetsSync
{
    public function __construct(
        private readonly MayorDashboardReport $report,
        private readonly MayorGoogleSheetsMap $map,
        private readonly Logger $logger,
        private readonly array $config,
        private readonly string $storagePath,
        private readonly ?\Closure $clientFactory=null,
    ) {}

    /** @return array<string,mixed> */
    public function run(bool $dryRun=false,bool $force=false): array
    {
        $started=microtime(true);$attemptedAt=gmdate('c');
        $timezoneName=trim((string)($this->config['timezone']??'Europe/Skopje'));
        try{$timezone=new \DateTimeZone($timezoneName);}catch(\Throwable){throw new \RuntimeException('Google Sheets synchronization timezone is invalid.');}
        $synchronizedAt=(new \DateTimeImmutable($attemptedAt))->setTimezone($timezone)->format('d.m.Y H:i:s');
        $snapshot=$this->report->snapshot();$snapshot['synchronized_at']=$synchronizedAt;$snapshot['synchronization_timezone']=$timezoneName;
        $data=GoogleSheetsClient::normalizeValuesBatch($this->map->valuesBatch($snapshot));
        $timestampData=array_values(array_filter($data,static fn(array$row):bool=>($row['range']??'')==="'Панел'!L4:M5"));
        if(count($timestampData)!==1)throw new \LogicException('Google Sheets synchronization timestamp range is missing.');
        $hashData=array_values(array_filter($data,static fn(array$row):bool=>($row['range']??'')!=="'Панел'!L4:M5"));
        $hash=hash('sha256',json_encode(['schema'=>4,'timezone'=>$timezoneName,'data'=>$hashData],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR));
        $summary=[
            'status'=>$dryRun?'dry-run':'pending','generated_at'=>$snapshot['generated_at'],'as_of_date'=>$snapshot['as_of_date'],
            'synchronized_at'=>$synchronizedAt,'synchronization_timezone'=>$timezoneName,
            'rows'=>['projects'=>count($snapshot['projects']),'services'=>count($snapshot['services']),'budget'=>count($snapshot['budget']),'issues'=>count($snapshot['issues']),'procurement'=>count($snapshot['procurement']),'legal_cases'=>count($snapshot['legal_cases']),'service_directorates'=>count($snapshot['service_directorates']??[])],
            'metrics'=>$snapshot['metrics'],'target_ranges'=>array_column($data,'range'),'payload_hash'=>$hash,
            'data_gaps'=>array_values(array_filter($snapshot['data_gaps'])),
        ];
        if($dryRun)return array_merge($summary,['payload'=>$data]);
        if(($this->config['enabled']??false)!==true)return array_merge($summary,['status'=>'disabled']);

        $lockPath=$this->storagePath.'/mayor-google-sheets-sync.lock';$lock=fopen($lockPath,'c+');
        if($lock===false||!flock($lock,LOCK_EX|LOCK_NB))return array_merge($summary,['status'=>'busy']);
        try{
            $state=$this->readState();$suffix=$this->spreadsheetSuffix();
            $client=$this->clientFactory!==null?($this->clientFactory)($this->config):new GoogleSheetsClient($this->config);$metadata=$client->metadata();if(method_exists($client,'ensureSheet')){$metadata=$client->ensureSheet($metadata,'Јавни набавки',2000,18);$metadata=$client->ensureSheet($metadata,'Правни предмети',2000,20);$metadata=$client->ensureSheet($metadata,MayorGoogleSheetsMap::SERVICE_DIRECTORATES_SHEET,200,11);}$client->assertTemplate($metadata);
            if(!$force&&hash_equals((string)($state['payload_hash']??''),$hash)){
                $client->updateValues($timestampData);
                $state=['last_attempted_at'=>$attemptedAt,'last_successful_at'=>gmdate('c'),'payload_hash'=>$hash,'spreadsheet_id_suffix'=>$suffix,'result'=>'timestamp_updated'];
                $duration=(int)round((microtime(true)-$started)*1000);$this->writeState($state);$this->logger->write('info','mayor_google_sheets.timestamp_updated',$state+['rows'=>$summary['rows'],'duration_ms'=>$duration]);
                return array_merge($summary,['status'=>'timestamp_updated','written_ranges'=>["'Панел'!L4:M5"],'duration_ms'=>$duration,'spreadsheet_id_suffix'=>$suffix]);
            }
            $client->updateValues($data);
            $state=['last_attempted_at'=>$attemptedAt,'last_successful_at'=>gmdate('c'),'payload_hash'=>$hash,'spreadsheet_id_suffix'=>$suffix,'result'=>'updated'];
            $this->writeState($state);$this->logger->write('info','mayor_google_sheets.synced',$state+['rows'=>$summary['rows'],'duration_ms'=>(int)round((microtime(true)-$started)*1000)]);
            return array_merge($summary,['status'=>'updated','written_ranges'=>array_column($data,'range'),'duration_ms'=>(int)round((microtime(true)-$started)*1000),'spreadsheet_id_suffix'=>$suffix]);
        }catch(\Throwable$exception){
            $state=$this->readState();$state['last_attempted_at']=$attemptedAt;$state['result']='failed';$state['error_class']=get_class($exception);$this->writeState($state);
            $this->logger->write('error','mayor_google_sheets.failed',['exception'=>get_class($exception),'error'=>$exception->getMessage(),'spreadsheet_id_suffix'=>$this->spreadsheetSuffix(),'duration_ms'=>(int)round((microtime(true)-$started)*1000)]);
            throw$exception;
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }

    /** @return array<string,mixed> */
    private function readState(): array
    {
        $path=$this->statePath();if(!is_file($path))return[];$decoded=json_decode((string)file_get_contents($path),true);return is_array($decoded)?$decoded:[];
    }

    private function writeState(array $state): void
    {
        $directory=dirname($this->statePath());if(!is_dir($directory)&&!mkdir($directory,0770,true)&&!is_dir($directory))throw new \RuntimeException('Unable to create Google Sheets sync state directory.');
        $temp=tempnam($directory,'mayor-sync-');if($temp===false)throw new \RuntimeException('Unable to create Google Sheets sync state file.');
        file_put_contents($temp,json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX);chmod($temp,0660);
        if(!rename($temp,$this->statePath())){@unlink($temp);throw new \RuntimeException('Unable to replace Google Sheets sync state.');}
    }

    private function statePath(): string{return$this->storagePath.'/private/mayor-google-sheets-sync.json';}
    private function spreadsheetSuffix(): string{$id=(string)($this->config['spreadsheet_id']??'');return$id===''?'unset':substr($id,-8);}
}
