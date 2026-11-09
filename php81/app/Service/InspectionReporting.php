<?php
declare(strict_types=1);
namespace EKabineti\Service;
use EKabineti\Core\Database;

final class InspectionReporting
{
    public const METRICS = [
        'complaints_requests'=>['Статистика','Жалба / Барање'],
        'construction_permits_received'=>['Прием','Одобренија за градење'],
        'phase_inspections'=>['Инспекции','Инспекции на фазите'],
        'final_inspections_received'=>['Завршни инспекции','Прием на барањето'],
        'final_inspections_completed'=>['Завршни инспекции','Завршување'],
        'use_certificates_received'=>['Одобрение за употреба','Прием на барањето'],
        'use_certificates_completed'=>['Одобрение за употреба','Завршување'],
        'stop_work_decisions'=>['Градба без одобрение','Решенија за запирање на работите'],
        'demolition_decisions'=>['Градба без одобрение','Решенија за отстранување'],
        'demolition_execution_orders'=>['Градба без одобрение','Наредби за извршување на отстранувањата'],
        'executed_decisions'=>['Градба без одобрение','Извршени решенија'],
    ];
    public function __construct(private readonly Database $db) {}
    public function directorate(): ?array
    {
        return $this->db->one("SELECT TOP 1 id,code,name FROM directorates WHERE active=1 AND (UPPER(code) IN ('INS','DI') OR LOWER(name) LIKE N'%inspek%' OR LOWER(name) LIKE N'%инспек%') ORDER BY CASE WHEN UPPER(code)='INS' THEN 0 ELSE 1 END,id");
    }
}
