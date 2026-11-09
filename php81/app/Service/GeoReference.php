<?php
declare(strict_types=1);
namespace EKabineti\Service;
use EKabineti\Core\HttpException;
final class GeoReference
{
    public static function parse(array $input): array
    {
        $lat=trim((string)($input['latitude']??''));$lng=trim((string)($input['longitude']??''));
        $url=trim((string)($input['google_maps_url']??''));
        if($url!==''){
            $parts=parse_url($url);$host=strtolower($parts['host']??'');
            if(($parts['scheme']??'')!=='https'||!in_array($host,['www.google.com','google.com','maps.google.com'],true))throw new HttpException(422,'Користете целосна HTTPS врска од Google Maps.');
            if($lat===''&&$lng===''){
                parse_str($parts['query']??'',$q);$coords=$q['query']??$q['q']??'';
                if(preg_match('/^(-?\d+(?:\.\d+)?),\s*(-?\d+(?:\.\d+)?)$/',$coords,$m)||preg_match('/@(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/',$parts['path']??'',$m)){$lat=$m[1];$lng=$m[2];}
                else throw new HttpException(422,'Изберете точка на мапата или внесете ги координатите.');
            }
        }
        if($lat===''&&$lng==='')return ['latitude'=>null,'longitude'=>null,'google_maps_url'=>null];
        if(!is_numeric($lat)||!is_numeric($lng)||!is_finite((float)$lat)||!is_finite((float)$lng)||abs((float)$lat)>90||abs((float)$lng)>180)throw new HttpException(422,'Координатите мора да бидат валиден пар latitude/longitude.');
        $lat=number_format((float)$lat,7,'.','');$lng=number_format((float)$lng,7,'.','');
        return ['latitude'=>$lat,'longitude'=>$lng,'google_maps_url'=>'https://www.google.com/maps/search/?api=1&query='.$lat.','.$lng];
    }
}
