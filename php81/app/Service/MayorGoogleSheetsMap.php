<?php

declare(strict_types=1);

namespace EKabineti\Service;

final class MayorGoogleSheetsMap
{
    public const REQUIRED_SHEETS = ['Панел','Проекти','Услуги','Буџет','Прашања','Јавни набавки','Правни предмети'];
    public const PROJECT_CAPACITY = 999;
    public const SERVICE_CAPACITY = 499;
    public const BUDGET_CAPACITY = 499;
    public const ISSUE_CAPACITY = 999;
    public const PROCUREMENT_CAPACITY = 1999;
    public const LEGAL_CASE_CAPACITY = 1999;
    public const SERVICE_DIRECTORATE_CAPACITY = 199;
    /** Weekly citizen-service totals per directorate, created by the synchronization when missing. */
    public const SERVICE_DIRECTORATES_SHEET = 'Услуги по сектори';

    /**
     * @param array<string,mixed> $snapshot
     * @return array<int,array{range:string,values:array<int,array<int,int|float|string>>}>
     */
    public function valuesBatch(array $snapshot): array
    {
        $projects = $snapshot['projects'] ?? [];
        $services = $snapshot['services'] ?? [];
        $budget = $snapshot['budget'] ?? [];
        $issues = $snapshot['issues'] ?? [];
        $metrics = $snapshot['metrics'] ?? [];
        $procurement = $snapshot['procurement'] ?? [];
        $legalCases = $snapshot['legal_cases'] ?? [];
        $serviceDirectorates = $snapshot['service_directorates'] ?? [];

        // Forwarded cases are subtracted from the open ones; services delivered (visits) and approved subsidies are apart.
        $serviceDirectorateRows=[['Сектор','Назив','Недела од','Недела до','Примени','Решени','Во постапка (пријавено)','Препратени со пропратен акт','Во постапка по препраќањето','Дадени услуги','Одобрени субвенции (ден.)']];
        foreach(array_slice($serviceDirectorates,0,self::SERVICE_DIRECTORATE_CAPACITY)as$row)$serviceDirectorateRows[]=[
            $this->text($row['directorate_code']??null),$this->text($row['directorate_name']??null),$this->text($row['week_start']??null),$this->text($row['week_end']??null),
            $this->integer($row['received_count']??null),$this->integer($row['resolved_count']??null),$this->integer($row['reported_open_count']??null),$this->integer($row['forwarded_count']??null),
            $this->integer($row['open_after_forwarding']??null),$this->integer($row['volume_count']??null),$this->number($row['approved_subsidy_amount']??null),
        ];

        $legalCaseRows=[['Бр. на предмет','Извор','Вид','Статус','Субјект / опис','Сектор','Одговорно лице / корисник','Датум / рок','Налог','Изворен документ','Буџетска шифра','Јавни набавки','Договори','Нето','Главница','Казна','Судска такса','Камата','Приоритет','Ажурирано']];
        foreach(array_slice($legalCases,0,self::LEGAL_CASE_CAPACITY)as$row)$legalCaseRows[]=[
            $this->text($row['record_number']??null),$this->text($row['source_type']??null),$this->text($row['case_type']??null),$this->text($row['status']??null),
            $this->text($row['summary']??null),$this->text($row['directorate_code']??null),$this->text($row['responsible_party']??null),$this->text($row['reference_date']??null),
            $this->text($row['journal_voucher_number']??null),$this->text($row['source_document_number']??null),$this->text($row['budget_code']??null),$this->text($row['procurement_code']??null),
            $this->text($row['contract_code']??null),$this->number($row['net_amount']??null),$this->number($row['principal_amount']??null),$this->number($row['fine_amount']??null),
            $this->number($row['court_fee_amount']??null),$this->number($row['interest_amount']??null),$this->text($row['priority']??null),$this->text($row['updated_at']??null),
        ];

        $procurementRows=[['Шифра на набавката','Шифра на договорот','Наслов','Вид','Оператор','Менаџери','Надзорници','Статус','Вкупно','Економски шифри','Шифри на проектите','Времетраење (месеци)','Реализирано (ден.)','Платено (ден.)','Извршено (ден.)','Вкупно платено (ден.)','Поврзани проекти','Ажурирано']];
        foreach(array_slice($procurement,0,self::PROCUREMENT_CAPACITY)as$row)$procurementRows[]=[
            $this->text($row['procurement_code']??null),$this->text($row['contract_code']??null),$this->text($row['contract_title']??null),$this->text($row['contract_type']??null),$this->text($row['contracting_party']??null),$this->text($row['managers']??null),$this->text($row['supervisors']??null),$this->text($row['procurement_status']??null),$this->number($row['total']??null),$this->text($row['economic_codes']??null),$this->text($row['project_codes']??null),$this->integer($row['duration_months']??null),$this->number($row['realized_amount']??null),$this->number($row['paid_amount']??null),$this->number($row['enforced_amount']??null),$this->number($row['total_paid_amount']??null),$this->text($row['linked_projects']??null),$this->text($row['last_imported_at']??null),
        ];

        $projectRows = [];
        foreach (array_slice($projects, 0, self::PROJECT_CAPACITY) as $row) {
            $projectRows[] = [
                $this->text($row['name'] ?? null),
                $this->text($row['directorate'] ?? null),
                $this->text($row['responsible'] ?? null),
                $this->number($row['project_budget'] ?? null),
                $this->number($row['spent'] ?? null),
                $this->text($row['executive_status'] ?? null),
                $this->number($row['progress_ratio'] ?? null),
                $this->dateSerial($row['next_deadline'] ?? null),
                $this->text($row['risk_level'] ?? null),
                $this->text($row['citizen_impact'] ?? null),
                $this->text($row['priority'] ?? null),
                $this->text($row['notes'] ?? null),
            ];
        }

        $serviceRows = [];
        foreach (array_slice($services, 0, self::SERVICE_CAPACITY) as $row) {
            $serviceRows[] = [
                $this->text($row['service_name'] ?? null),
                $this->integer($row['received_count'] ?? null),
                $this->integer($row['resolved_count'] ?? null),
                $this->integer($row['open_count'] ?? null),
                $this->number($row['resolution_ratio'] ?? null),
                $this->number($row['average_resolution_hours'] ?? null),
                $this->number($row['sla_target_hours'] ?? null),
            ];
        }

        $budgetRows = [];
        foreach (array_slice($budget, 0, self::BUDGET_CAPACITY) as $row) {
            $annual = $this->number($row['annual_budget'] ?? null);
            $spent = $this->number($row['spent'] ?? null);
            $budgetRows[] = [
                $this->text($row['directorate'] ?? null),
                $annual,
                $this->number($row['committed'] ?? null),
                $spent,
                $annual !== '' && $spent !== '' && $annual > 0 ? $spent / $annual : '',
                $annual !== '' && $spent !== '' ? $annual - $spent : '',
            ];
        }

        $issueRows = [];
        foreach (array_slice($issues, 0, self::ISSUE_CAPACITY) as $row) {
            $issueRows[] = [
                $this->text($row['issue'] ?? null),
                $this->text($row['area'] ?? null),
                $this->text($row['responsible'] ?? null),
                $this->dateSerial($row['raised_at'] ?? null),
                $this->text($row['level'] ?? null),
                $this->text($row['status'] ?? null),
                $this->text($row['next_step'] ?? null),
                $this->dateSerial($row['deadline'] ?? null),
            ];
        }

        $attentionIssues = [];
        foreach (array_slice(array_filter($issues, static fn(array $row): bool => in_array($row['level'] ?? '', ['Critical','High'], true) && ($row['status'] ?? '') === 'Open'), 0, 6) as $row) {
            $attentionIssues[] = [$this->text($row['issue'] ?? null),$this->text($row['area'] ?? null),$this->text($row['level'] ?? null),$this->text($row['responsible'] ?? null),$this->dateSerial($row['deadline'] ?? null)];
        }

        $atRiskProjects = [];
        foreach (array_slice(array_filter($projects, static fn(array $row): bool => ($row['executive_status'] ?? '') === 'At Risk'), 0, 3) as $row) {
            $atRiskProjects[] = [$this->text($row['name'] ?? null),$this->number($row['progress_ratio'] ?? null),$this->text($row['risk_level'] ?? null),$this->dateSerial($row['next_deadline'] ?? null),$this->text($row['responsible'] ?? null)];
        }

        $portfolio = [];
        foreach (array_slice($projects, 0, 12) as $row) {
            $portfolio[] = [$this->text($row['name'] ?? null),$this->text($row['directorate'] ?? null),$this->text($row['executive_status'] ?? null),$this->number($row['progress_ratio'] ?? null),$this->text($row['risk_level'] ?? null),$this->dateSerial($row['next_deadline'] ?? null),$this->text($row['priority'] ?? null)];
        }

        $budgetPanel = [];
        foreach (array_slice($budget, 0, 9) as $row) {
            $annual = $this->number($row['annual_budget'] ?? null);$spent = $this->number($row['spent'] ?? null);
            $budgetPanel[] = [$this->text($row['directorate'] ?? null),$annual,$spent,$annual !== '' && $spent !== '' && $annual > 0 ? $spent / $annual : '',$annual !== '' && $spent !== '' ? $annual-$spent : ''];
        }
        $annualTotal=$this->number($metrics['annual_budget']??null);$spentTotal=$this->number($metrics['spent']??null);
        $budgetPanelTotal = [[
            'ВКУПНО',$annualTotal,$spentTotal,$this->number($metrics['budget_execution']??null),
            $annualTotal !== '' && $spentTotal !== '' ? $annualTotal-$spentTotal : '',
        ]];

        $servicePanel = [];
        foreach (array_slice($services, 0, 8) as $row) {
            $servicePanel[] = [$this->text($row['service_name'] ?? null),$this->integer($row['resolved_count'] ?? null),$this->integer($row['open_count'] ?? null),$this->number($row['resolution_ratio'] ?? null),$this->number($row['average_resolution_hours'] ?? null)];
        }
        $resolvedTotal = $this->sumNullable($services,'resolved_count');
        $openTotal = $this->sumNullable($services,'open_count');
        $servicePanelTotal = [['ВКУПНО',$resolvedTotal,$openTotal,$this->number($metrics['service_resolution']??null),$this->number($metrics['average_resolution_hours']??null)]];

        return [
            ['range'=>"'Јавни набавки'!A1:R2000",'values'=>$this->padded($procurementRows,2000,18)],
            ['range'=>"'Правни предмети'!A1:T2000",'values'=>$this->padded($legalCaseRows,2000,20)],
            ['range'=>"'Проекти'!A2:L1000",'values'=>$this->padded($projectRows,self::PROJECT_CAPACITY,12)],
            ['range'=>"'Услуги'!A2:G500",'values'=>$this->padded($serviceRows,self::SERVICE_CAPACITY,7)],
            ['range'=>"'".self::SERVICE_DIRECTORATES_SHEET."'!A1:K200",'values'=>$this->padded($serviceDirectorateRows,self::SERVICE_DIRECTORATE_CAPACITY+1,11)],
            ['range'=>"'Буџет'!A2:F500",'values'=>$this->padded($budgetRows,self::BUDGET_CAPACITY,6)],
            ['range'=>"'Прашања'!A2:H1000",'values'=>$this->padded($issueRows,self::ISSUE_CAPACITY,8)],
            ['range'=>"'Панел'!L4:M5",'values'=>[['СИНХРОНИЗАЦИЈА','ВРЕМЕНСКА ЗОНА'],[$this->text($snapshot['synchronized_at']??null),$this->text($snapshot['synchronization_timezone']??null)]]],
            ['range'=>"'Панел'!B5",'values'=>[[$this->integer($metrics['municipal_pulse']??null)]]],
            ['range'=>"'Панел'!D5",'values'=>[[$this->number($metrics['average_project_progress']??null)]]],
            ['range'=>"'Панел'!F5",'values'=>[[(int)($metrics['on_track_projects'] ?? 0).' / '.(int)($metrics['total_projects'] ?? 0)]]],
            ['range'=>"'Панел'!H5",'values'=>[[$this->number($metrics['budget_execution']??null)]]],
            ['range'=>"'Панел'!J5",'values'=>[[(int)($metrics['critical_open_issues'] ?? 0)]]],
            ['range'=>"'Панел'!B10",'values'=>[[$this->number($metrics['service_resolution']??null)]]],
            ['range'=>"'Панел'!D10",'values'=>[[$this->number($metrics['average_resolution_hours']??null)]]],
            ['range'=>"'Панел'!F10",'values'=>[[(int)($metrics['active_projects'] ?? 0)]]],
            ['range'=>"'Панел'!H10",'values'=>[[(int)($metrics['deadlines_14_days'] ?? 0)]]],
            ['range'=>"'Панел'!J10",'values'=>[[(int)($metrics['at_risk_projects'] ?? 0)]]],
            ['range'=>"'Панел'!B15:F20",'values'=>$this->padded($attentionIssues,6,5)],
            ['range'=>"'Панел'!H15:L17",'values'=>$this->padded($atRiskProjects,3,5)],
            ['range'=>"'Панел'!H18:L18",'values'=>[['▲ '.(int)($metrics['at_risk_projects'] ?? 0).' проекти моментално се во ризик','','','','']]],
            ['range'=>"'Панел'!B24:H35",'values'=>$this->padded($portfolio,12,7)],
            ['range'=>"'Панел'!B39:F47",'values'=>$this->padded($budgetPanel,9,5)],
            ['range'=>"'Панел'!B48:F48",'values'=>$budgetPanelTotal],
            ['range'=>"'Панел'!H39:L46",'values'=>$this->padded($servicePanel,8,5)],
            ['range'=>"'Панел'!H47:L47",'values'=>$servicePanelTotal],
        ];
    }

    /** @param array<int,array<int,int|float|string>> $rows @return array<int,array<int,int|float|string>> */
    private function padded(array $rows, int $height, int $width): array
    {
        $blank = array_fill(0,$width,'');
        while (count($rows) < $height) $rows[] = $blank;
        return array_slice($rows,0,$height);
    }

    private function text(mixed $value): string { return $value === null ? '' : trim((string)$value); }

    private function number(mixed $value): float|string
    {
        return $value === null || trim((string)$value) === '' ? '' : (float)$value;
    }

    private function integer(mixed $value): int|string
    {
        return $value === null || trim((string)$value) === '' ? '' : (int)$value;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function sumNullable(array $rows,string $key): int|string
    {
        $found=false;$total=0;
        foreach($rows as$row){$value=$row[$key]??null;if($value===null||trim((string)$value)==='')continue;$found=true;$total+=(int)$value;}
        return$found?$total:'';
    }

    private function dateSerial(mixed $value): int|string
    {
        if ($value === null || trim((string)$value) === '') return '';
        $date = new \DateTimeImmutable(substr((string)$value,0,10),new \DateTimeZone('UTC'));
        $epoch = new \DateTimeImmutable('1899-12-30',new \DateTimeZone('UTC'));
        return (int)$epoch->diff($date)->format('%r%a');
    }
}
