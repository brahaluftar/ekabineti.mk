<?php

declare(strict_types=1);

namespace EKabineti\Service;

use EKabineti\Core\Database;
use EKabineti\Core\HttpException;
use EKabineti\Core\Request;

final class PrivateAttachmentStorage
{
    /** Single catalogue of document categories used by every upload form and by validation. */
    public const DOCUMENT_TYPES = [
        'other'=>'Друго','contract'=>'Договор','decision'=>'Решение','plan'=>'План','report'=>'Извештај','invoice'=>'Фактура',
        'correspondence'=>'Кореспонденција','court_document'=>'Судски документ','ruling'=>'Решение','legal_opinion'=>'Правно мислење',
        'enforcement_decision'=>'Решение за извршување','cover_letter'=>'Пропратен акт',
    ];
    public const CLASSIFICATIONS = ['internal'=>'Внатрешен','confidential'=>'Доверливо','restricted'=>'Ограничен'];

    public function __construct(
        private readonly Database $db,
        private readonly Audit $audit,
        private readonly int $userId,
        private readonly array $config
    ) {}

    public static function files(array $input): array
    {
        if (!isset($input['name'])) return [];
        if (!is_array($input['name'])) return (int)($input['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE ? [] : [$input];
        $files=[];
        foreach ($input['name'] as $i=>$name) {
            $error=(int)($input['error'][$i]??UPLOAD_ERR_NO_FILE);
            if ($error===UPLOAD_ERR_NO_FILE) continue;
            $files[]=['name'=>$name,'type'=>$input['type'][$i]??'','tmp_name'=>$input['tmp_name'][$i]??'','error'=>$error,'size'=>$input['size'][$i]??0];
        }
        return $files;
    }

    public function store(array $file,string $entityType,int $entityId,array $metadata,?int $projectId=null,?int $taskId=null): int
    {
        $photo=(bool)($metadata['photo']??false);
        if (($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name']??''))) throw new HttpException(422,'Изберете датотеки во рамките на лимитот за прикачување.');
        $size=(int)($file['size']??0);$limit=(int)($this->config['uploads']['requested_max_mb']??20)*1048576;
        if($size<1||$size>$limit)throw new HttpException(422,'Големината на една од датотеките не е дозволена.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
        $allowed=$photo?['image/jpeg','image/png']:($this->config['uploads']['allowed_mime_types']??['application/pdf','image/jpeg','image/png']);
        if(!in_array($mime,$allowed,true))throw new HttpException(422,'Форматот на една од датотеките не е дозволен.');
        if($photo&&!getimagesize((string)$file['tmp_name']))throw new HttpException(422,'Една фотографија не е валидна.');
        $title=trim((string)($metadata['title']??''));$maxTitle=$photo?1000:250;
        if(mb_strlen($title)>$maxTitle)throw new HttpException(422,$photo?'Описот на фотографијата е предолг.':'Насловот на документот е предолг.');
        $category=$photo?'photo':(string)($metadata['document_type']??'other');
        if($category!=='photo'&&!isset(self::DOCUMENT_TYPES[$category]))throw new HttpException(422,'Категоријата на документот не е валидна.');
        $classification=$photo?'internal':(string)($metadata['classification']??'internal');
        if(!isset(self::CLASSIFICATIONS[$classification]))throw new HttpException(422,'Класификацијата не е валидна.');
        $date=$this->date($metadata['document_date']??null);
        $latitude=$metadata['latitude']??null;$longitude=$metadata['longitude']??null;
        $key='quarantine/'.Request::uuid().'/content.bin';$path=APP_ROOT.'/storage/private/'.$key;
        if(!is_dir(dirname($path))&&!mkdir(dirname($path),0770,true))throw new \RuntimeException('Private storage unavailable.');
        if(!move_uploaded_file((string)$file['tmp_name'],$path))throw new \RuntimeException('Upload failed.');
        try {
            return $this->db->transaction(function()use($file,$entityType,$entityId,$key,$path,$mime,$classification,$category,$title,$date,$photo,$projectId,$taskId,$latitude,$longitude){
                $attachment=$this->db->insertId("INSERT INTO attachments(entity_type,entity_id,original_name,storage_key,checksum,size_bytes,mime_type,version,classification,scan_status,uploaded_by,document_type,title,document_date,created_at,updated_at) VALUES(:type,:entity,:name,:key,:checksum,:size,:mime,1,:classification,'pending',:user,:category,:title,:date,SYSUTCDATETIME(),SYSUTCDATETIME())",['type'=>$entityType,'entity'=>$entityId,'name'=>mb_substr(basename((string)$file['name']),0,250),'key'=>$key,'checksum'=>hash_file('sha256',$path),'size'=>filesize($path),'mime'=>$mime,'classification'=>$classification,'user'=>$this->userId,'category'=>$category,'title'=>$title?:null,'date'=>$date]);
                if($photo){if($projectId===null)throw new \LogicException('Project photo requires a project.');$this->db->execute('INSERT INTO project_photos(project_id,task_id,attachment_id,caption,taken_at,latitude,longitude,uploaded_by,created_at,updated_at) VALUES(:project,:task,:attachment,:caption,:taken,:latitude,:longitude,:user,SYSUTCDATETIME(),SYSUTCDATETIME())',['project'=>$projectId,'task'=>$taskId,'attachment'=>$attachment,'caption'=>$title?:null,'taken'=>$date,'latitude'=>$latitude,'longitude'=>$longitude,'user'=>$this->userId]);}
                $this->audit->record($this->userId,'attachment.quarantined','Attachment',$attachment,[],['entity_type'=>$entityType,'entity_id'=>$entityId,'project_id'=>$projectId,'task_id'=>$taskId,'category'=>$category]);
                return $attachment;
            });
        } catch(\Throwable $e) {if(is_file($path))unlink($path);throw $e;}
    }

    private function date(mixed $value): ?string
    {
        $value=trim((string)$value);if($value==='')return null;$date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if(!$date||$date->format('Y-m-d')!==$value)throw new HttpException(422,'Датумот на документот не е валиден.');
        return $value;
    }
}
