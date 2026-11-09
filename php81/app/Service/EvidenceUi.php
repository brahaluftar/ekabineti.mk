<?php
declare(strict_types=1);
namespace EKabineti\Service;
use EKabineti\Core\Database;
use EKabineti\Core\HttpException;
use EKabineti\Core\View;
use EKabineti\Security\Scope;
final class EvidenceUi
{
    public static function geo(array $row=[]): string
    {
        return '<fieldset class="geo-picker wide"><legend>Локација (опционално)</legend><label>Географска ширина<input name="latitude" inputmode="decimal" value="'.View::e($row['latitude']??'').'"></label><label>Географска должина<input name="longitude" inputmode="decimal" value="'.View::e($row['longitude']??'').'"></label><label>Google Maps URL<input name="google_maps_url" value="'.View::e($row['google_maps_url']??'').'" placeholder="https://www.google.com/maps/search/?api=1&amp;query=41.0297,21.3292"></label><button type="button" data-geo-locate>Мојата локација</button> <button type="button" data-geo-map>Избери на мапата</button> <button type="button" data-geo-clear>Исчисти</button><p data-geo-status aria-live="polite"></p><div class="geo-map" hidden></div></fieldset>';
    }
    public static function documentFields(array $types=[]): string
    {
        $types=$types?:array_keys(PrivateAttachmentStorage::DOCUMENT_TYPES);$options='';foreach($types as$type)$options.='<option value="'.View::e($type).'">'.View::e(PrivateAttachmentStorage::DOCUMENT_TYPES[$type]??$type).'</option>';
        return '<label>Наслов<input name="title" maxlength="250"></label><label>Категорија<select name="document_type">'.$options.'</select></label><label>Датум на документот<input type="date" name="document_date"></label>';
    }
    /**
     * File field with drag and drop: files dropped or chosen are added to the list (several at once when $multiple) and
     * submitted with the form as usual; without JavaScript it is a plain file field.
     */
    /**
     * File field with drag and drop. The prompt is a <label for> the input: a tap on it opens the file picker natively,
     * also on iOS Safari, where a transparent input laid over the box (the earlier approach) does not open it reliably,
     * above all inside a modal dialog. $accept limits the picker (extensions and MIME types, e.g. for the imports).
     */
    public static function dropzone(string $name='files[]',bool $multiple=true,bool $required=true,string $hint='',string $label='Документи',string $accept=''): string
    {
        static $n=0;$id='dropzone-'.(++$n);
        return '<div class="wide dropzone-field"><span class="dropzone-field__label" id="'.$id.'-label">'.View::e($label).'</span><div class="dropzone" data-dropzone>'
            .'<input type="file" class="dropzone__input" id="'.$id.'" name="'.View::e($name).'"'.($multiple?' multiple':'').($required?' required':'').($accept!==''?' accept="'.View::e($accept).'"':'').' aria-labelledby="'.$id.'-label '.$id.'-prompt">'
            .'<label class="dropzone__prompt" for="'.$id.'" id="'.$id.'-prompt"><span class="button button-secondary dropzone__button">'.($multiple?'Изберете датотеки':'Изберете датотека').'</span>'
            .'<span class="dropzone__hint">или повлечете ги тука</span>'.($hint!==''?'<small>'.View::e($hint).'</small>':'').'</label></div><ul class="dropzone__list" aria-live="polite"></ul></div>';
    }
    public static function classificationField(string $name='classification'): string
    {
        $options='';foreach(PrivateAttachmentStorage::CLASSIFICATIONS as$value=>$label)$options.='<option value="'.View::e($value).'">'.View::e($label).'</option>';
        return '<label>Класификација<select name="'.View::e($name).'">'.$options.'</select></label>';
    }
    public static function extras(Database $db,Scope $scope,int $user,View $view,string $type,int $id,bool $includeProjectSharing=true): string
    {
        $access=new EvidenceAccess($db,$scope,$user);$html='';$options='';
        foreach($db->all("SELECT u.id,u.name,CASE WHEN EXISTS(SELECT 1 FROM user_role_scopes s JOIN roles r ON r.id=s.role_id WHERE s.user_id=u.id AND COALESCE(r.base_role_code,r.code)='public_relations' AND (s.valid_from IS NULL OR s.valid_from<=SYSUTCDATETIME()) AND (s.valid_to IS NULL OR s.valid_to>=SYSUTCDATETIME())) THEN ' · Public Relations' ELSE '' END role_label FROM users u WHERE u.status='active' ORDER BY u.name") as $u)$options.='<option value="'.$u['id'].'">'.View::e($u['name'].$u['role_label']).'</option>';
        try{$access->leadership();$html.='<section class="card"><h2>Врска до сите документи</h2><p>Само раководителите на сектори и Градоначалникот; бара најава и истекува по 7 дена.</p><form method="post" action="/document-links">'.$view->csrf().'<input type="hidden" name="entity_type" value="'.View::e($type).'"><input type="hidden" name="entity_id" value="'.$id.'"><button>Создај врска</button></form></section>';}catch(HttpException $e){}
        if($type==='App\\Models\\Project'&&$includeProjectSharing){
            try{$access->project($id,true,true);}catch(HttpException $e){return $html;}
            $html.='<section class="card"><h2>Сподели го проектот со корисник</h2><form method="post" action="/projects/'.$id.'/share">'.$view->csrf().'<label>Корисник<select name="user_id">'.$options.'</select></label><label>Пристап<select name="access_level"><option value="viewer">Преглед</option><option value="editor">Уредување</option><option value="manager">Управување</option></select></label><button>Сподели го проектот</button></form>';
            foreach($db->all('SELECT a.user_id,u.name,a.access_level FROM project_user_access a JOIN users u ON u.id=a.user_id WHERE a.project_id=:id',['id'=>$id]) as $a)$html.='<p>'.View::e($a['name'].' · '.$a['access_level']).'</p>';
            $html.='<h2>Сподели само една задача / Односи со јавноста</h2>';
            foreach($db->all('SELECT id,title FROM project_tasks WHERE project_id=:id',['id'=>$id]) as $t)$html.='<form method="post" action="/projects/'.$id.'/tasks/'.$t['id'].'/share">'.$view->csrf().'<strong>'.View::e($t['title']).'</strong><label>Корисник<select name="user_id">'.$options.'</select></label><label>Пристап<select name="access_level"><option value="viewer">Преглед</option><option value="editor">Уредување</option></select></label><button>Сподели ја задачата</button></form>';
            return $html.'</section>';
        }
        if($type==='AdministrativeCase'){
            try{$access->caseFile($id,true,true);$html.='<section class="card"><h2>Сподели го предметот со корисник</h2><form method="post" action="/cases/'.$id.'/users">'.$view->csrf().'<label>Корисник<select name="user_id">'.$options.'</select></label><button>Сподели и извести</button></form></section>';}catch(HttpException $e){}
            $html.='<section class="card"><h2>Ажурирај ги задачите</h2>';
            foreach($db->all('SELECT id,title,status FROM case_tasks WHERE case_id=:id',['id'=>$id]) as $t){$opts='';foreach(['open','in_progress','completed','blocked'] as $s)$opts.='<option'.($s===$t['status']?' selected':'').'>'.$s.'</option>';$html.='<form method="post" action="/cases/'.$id.'/tasks/'.$t['id'].'/status">'.$view->csrf().'<strong>#'.$t['id'].' · '.View::e($t['title']).'</strong><label>Статус<select name="status">'.$opts.'</select></label><button>Зачувај</button></form>';}
            $html.='</section>';
        }
        return $html;
    }
}
