<?php

declare(strict_types=1);

namespace EKabineti\Service;

use DateTimeImmutable;
use EKabineti\Core\Database;

final class MayorDashboardReport
{
    public function __construct(private readonly Database $db) {}

    /** @return array<string,mixed> */
    public function snapshot(?DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new DateTimeImmutable('today');
        $date = $asOf->format('Y-m-d');
        $projects = $this->projects($date);
        $services = $this->services($date);
        $serviceDirectorates = $this->serviceDirectorates($date);
        $budget = $this->budget();
        $issues = $this->issues();
        $procurement = $this->procurement();
        $legalCases = $this->legalCases();
        $metrics = (new MayorDashboardMetrics())->calculate($projects, $services, $budget, $issues, $date);

        return [
            'generated_at' => gmdate('c'),
            'as_of_date' => $date,
            'projects' => $projects,
            'services' => $services,
            'service_directorates' => $serviceDirectorates,
            'budget' => $budget,
            'issues' => $issues,
            'procurement' => $procurement,
            'legal_cases' => $legalCases,
            'metrics' => $metrics,
            'data_gaps' => [
                'civil_request_kpis' => $services === []
                    ? 'No citizen-service weekly report is available on or before the selected date.'
                    : null,
                'project_citizen_impact' => 'No authoritative project-level citizen-impact field exists; output remains blank.',
            ],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function legalCases(): array
    {
        return $this->db->all("SELECT legal.* FROM (
            SELECT ac.id,ac.case_number record_number,N'administrative_case' source_type,ac.case_type,ac.status,
                CASE WHEN ac.confidentiality=N'internal' THEN ac.subject ELSE N'Доверлив предмет' END summary,d.code directorate_code,u.name responsible_party,
                CONVERT(varchar(10),COALESCE((SELECT MIN(ct.due_at) FROM case_tasks ct WHERE ct.case_id=ac.id AND ct.status NOT IN ('completed','cancelled')),ac.opened_at),23) reference_date,
                CAST(NULL AS nvarchar(120)) journal_voucher_number,CAST(NULL AS nvarchar(120)) source_document_number,
                CAST(NULL AS nvarchar(120)) budget_code,CAST(NULL AS nvarchar(120)) procurement_code,CAST(NULL AS nvarchar(120)) contract_code,
                CAST(NULL AS decimal(19,4)) net_amount,CAST(NULL AS decimal(19,4)) principal_amount,CAST(NULL AS decimal(19,4)) fine_amount,
                CAST(NULL AS decimal(19,4)) court_fee_amount,CAST(NULL AS decimal(19,4)) interest_amount,ac.priority,
                CONVERT(varchar(19),ac.updated_at,120) updated_at
            FROM administrative_cases ac JOIN directorates d ON d.id=ac.originating_directorate_id LEFT JOIN users u ON u.id=ac.responsible_user_id WHERE ac.is_legal=1
            UNION ALL
            SELECT ee.id,CONCAT(N'E-',ee.id),N'enforcement_execution',N'execution',ee.classification_status,
                COALESCE(NULLIF(ee.description,''),ee.beneficiary),d.code,ee.beneficiary,CONVERT(varchar(10),ee.document_date,23),
                ee.journal_voucher_number,ee.source_document_number,bl.code,pp.procurement_code,pp.contract_code,
                ee.net_amount,ee.principal_amount,ee.fine_amount,ee.court_fee_amount,ee.interest_amount,CAST(NULL AS nvarchar(20)),
                CONVERT(varchar(19),ee.updated_at,120)
            FROM enforcement_executions ee JOIN budget_lines bl ON bl.id=ee.budget_line_id JOIN directorates d ON d.id=bl.directorate_id LEFT JOIN procurement_projects pp ON pp.id=ee.procurement_project_id
        ) legal ORDER BY legal.updated_at DESC,legal.id DESC");
    }

    /** @return array<int,array<string,mixed>> */
    private function procurement(): array
    {
        return $this->db->all("SELECT pp.procurement_code,pp.contract_code,pp.contract_title,pp.contract_type,pp.contracting_party,pp.managers,pp.supervisors,pp.procurement_status,pp.total,pp.economic_codes,pp.project_codes,pp.duration_months,pp.realized_amount,pp.paid_amount,pp.enforced_amount,pp.total_paid_amount,CONVERT(varchar(19),pp.last_imported_at,120) last_imported_at,
            STUFF((SELECT ', '+p.code FROM procurement_project_links ppl JOIN projects p ON p.id=ppl.project_id WHERE ppl.procurement_project_id=pp.id ORDER BY p.code FOR XML PATH(''),TYPE).value('.','nvarchar(max)'),1,2,'') linked_projects
            FROM procurement_projects pp ORDER BY pp.last_imported_at DESC,pp.procurement_code,pp.contract_code");
    }

    /** @return array<int,array<string,mixed>> */
    private function projects(string $asOfDate): array
    {
        $rows = $this->db->all(
            "SELECT p.id,p.code,p.name,d.name directorate,u.name responsible,p.status source_status,p.progress,
                    COALESCE(fin.project_budget,0) project_budget,COALESCE(fin.spent,0) spent,
                    next_due.next_deadline,COALESCE(risk_summary.risk_score,0) risk_score,
                    agenda.priority agenda_priority,latest_update.notes
             FROM projects p
             JOIN directorates d ON d.id=p.directorate_id
             LEFT JOIN users u ON u.id=p.manager_id
             OUTER APPLY (
                 SELECT SUM(ba.current_amount) project_budget,
                        SUM(COALESCE(expense.spent,0)) spent
                 FROM budget_allocations ba
                 OUTER APPLY (SELECT SUM(e.amount) spent FROM expenditures e WHERE e.budget_allocation_id=ba.id) expense
                 WHERE ba.project_id=p.id
             ) fin
             OUTER APPLY (
                 SELECT MIN(candidate.due_at) next_deadline
                 FROM (
                     SELECT t.due_at FROM project_tasks t
                     WHERE t.project_id=p.id AND t.due_at>=:task_as_of AND t.status NOT IN ('completed','cancelled')
                     UNION ALL
                     SELECT m.due_at FROM milestones m
                     WHERE m.project_id=p.id AND m.due_at>=:milestone_as_of AND m.status NOT IN ('completed','cancelled')
                 ) candidate
             ) next_due
             OUTER APPLY (
                 SELECT MAX(COALESCE(r.residual_score,r.inherent_score)) risk_score
                 FROM risks r WHERE r.project_id=p.id AND r.status NOT IN ('closed','mitigated')
             ) risk_summary
             OUTER APPLY (
                 SELECT TOP 1 mai.priority
                 FROM mayor_agenda_items mai
                 WHERE mai.project_id=p.id AND mai.status='open'
                 ORDER BY CASE mai.priority WHEN 'critical' THEN 0 WHEN 'high' THEN 1 ELSE 2 END,mai.id DESC
             ) agenda
             OUTER APPLY (
                 SELECT TOP 1 CONCAT(pu.narrative,CASE WHEN NULLIF(pu.blockers,'') IS NULL THEN '' ELSE CONCAT(' | ',pu.blockers) END) notes
                 FROM progress_updates pu WHERE pu.project_id=p.id ORDER BY pu.period DESC,pu.id DESC
             ) latest_update
             ORDER BY CASE p.status WHEN 'active' THEN 0 WHEN 'on_hold' THEN 1 WHEN 'completed' THEN 2 ELSE 3 END,
                      COALESCE(risk_summary.risk_score,0) DESC,next_due.next_deadline,p.updated_at DESC,p.id",
            ['task_as_of'=>$asOfDate,'milestone_as_of'=>$asOfDate]
        );

        foreach ($rows as &$row) {
            $riskScore = (int) $row['risk_score'];
            $sourceStatus = (string) $row['source_status'];
            $row['executive_status'] = $sourceStatus === 'completed'
                ? 'Completed'
                : (($sourceStatus === 'on_hold' || $riskScore >= 15) ? 'At Risk' : 'On Track');
            $row['progress_ratio'] = min(1, max(0, (float) $row['progress'] / 100));
            $row['risk_level'] = $riskScore >= 15 ? 'High' : ($riskScore >= 6 ? 'Medium' : 'Low');
            $row['citizen_impact'] = null;
            $row['priority'] = match ((string) ($row['agenda_priority'] ?? '')) {
                'critical', 'high' => 'High',
                'normal' => 'Medium',
                default => null,
            };
        }
        unset($row);
        return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    private function services(string $asOfDate): array
    {
        $rows = $this->db->all(
            'SELECT service_key,service_name,CONVERT(varchar(10),period_start,23) period_start,CONVERT(varchar(10),period_end,23) period_end,received_count,resolved_count,open_count,average_resolution_hours,sla_target_hours,source_system,CONVERT(varchar(19),source_as_of,120) source_as_of FROM dbo.vw_mayor_civil_request_kpis WHERE period_start=(SELECT MAX(period_start) FROM dbo.vw_mayor_civil_request_kpis WHERE period_start<=:as_of) ORDER BY service_name,service_key',
            ['as_of'=>$asOfDate]
        );
        foreach ($rows as &$row) {
            $received = (int) ($row['received_count'] ?? 0);
            $row['resolution_ratio'] = $received > 0 ? (int) $row['resolved_count'] / $received : null;
        }
        unset($row);
        return $rows;
    }

    /**
     * Totals per directorate for the latest reported week on or before the date: requests, subsidies and complaints,
     * cases forwarded with a cover letter (subtracted from the open ones), services delivered and approved subsidies.
     * @return array<int,array<string,mixed>>
     */
    private function serviceDirectorates(string $asOfDate): array
    {
        return $this->db->all(
            'SELECT directorate_code,directorate_name,CONVERT(varchar(10),week_start,23) week_start,CONVERT(varchar(10),week_end,23) week_end,received_count,resolved_count,reported_open_count,forwarded_count,open_after_forwarding,volume_count,approved_subsidy_amount FROM dbo.vw_citizen_service_directorate_weeks WHERE week_start=(SELECT MAX(week_start) FROM dbo.vw_citizen_service_directorate_weeks WHERE week_start<=:as_of) ORDER BY directorate_name',
            ['as_of'=>$asOfDate]
        );
    }

    /** @return array<int,array<string,mixed>> */
    private function budget(): array
    {
        return $this->db->all(
            // Commitments and expenditures default to the imported FreeBalance report; lines with e-Kabineti project data use those.
            "SELECT d.id directorate_id,d.name directorate,
                    SUM(lf.total_amount) annual_budget,
                    SUM(lf.committed) committed,
                    SUM(lf.spent) spent
             FROM (SELECT bl.directorate_id,bl.total_amount,".BudgetExecution::LINE_FIGURES_SELECT."
                   FROM budget_lines bl
                   JOIN budget_versions bv ON bv.id=bl.budget_version_id AND bv.is_official=1".BudgetExecution::LINE_FIGURES_APPLY."
                   WHERE bl.budget_category IS NOT NULL) lf
             JOIN directorates d ON d.id=lf.directorate_id
             GROUP BY d.id,d.name
             ORDER BY d.name"
        );
    }

    /** @return array<int,array<string,mixed>> */
    private function issues(): array
    {
        $riskRows = $this->db->all(
            "SELECT r.id,r.description issue,d.name area,u.name responsible,CONVERT(varchar(10),r.created_at,23) raised_at,
                    COALESCE(r.residual_score,r.inherent_score) score,'Open' status,next_action.action next_step,
                    CONVERT(varchar(10),COALESCE(next_action.due_at,r.review_at),23) deadline
             FROM risks r
             JOIN directorates d ON d.id=r.directorate_id
             LEFT JOIN users u ON u.id=r.owner_id
             OUTER APPLY (
                 SELECT TOP 1 ra.action,ra.due_at FROM risk_actions ra
                 WHERE ra.risk_id=r.id AND ra.status NOT IN ('completed','cancelled')
                 ORDER BY CASE WHEN ra.due_at IS NULL THEN 1 ELSE 0 END,ra.due_at,ra.id
             ) next_action
             WHERE r.status NOT IN ('closed','mitigated') AND COALESCE(r.residual_score,r.inherent_score)>=8"
        );
        $topicRows = $this->db->all(
            "SELECT mai.id,mai.topic_title issue,d.name area,u.name responsible,CONVERT(varchar(10),mai.created_at,23) raised_at,
                    mai.priority,'Open' status,mai.topic_details next_step,CONVERT(varchar(10),mai.discussion_date,23) deadline
             FROM mayor_agenda_items mai
             JOIN directorates d ON d.id=mai.directorate_id
             JOIN users u ON u.id=mai.selected_by
             WHERE mai.item_type='topic' AND mai.status='open' AND mai.priority IN ('critical','high')"
        );

        $issues = [];
        foreach ($riskRows as $row) {
            $row['level'] = (int) $row['score'] >= 15 ? 'Critical' : 'High';
            $row['source_type'] = 'risk';
            $issues[] = $row;
        }
        foreach ($topicRows as $row) {
            $row['level'] = $row['priority'] === 'critical' ? 'Critical' : 'High';
            $row['source_type'] = 'mayor_agenda_topic';
            $issues[] = $row;
        }
        usort($issues, static function (array $left, array $right): int {
            $severity = ['Critical'=>0,'High'=>1];
            return [$severity[$left['level']] ?? 2,$left['deadline'] ?: '9999-12-31',(int)$left['id']]
                <=> [$severity[$right['level']] ?? 2,$right['deadline'] ?: '9999-12-31',(int)$right['id']];
        });
        return $issues;
    }
}
