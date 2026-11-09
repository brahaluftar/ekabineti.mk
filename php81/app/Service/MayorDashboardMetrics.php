<?php

declare(strict_types=1);

namespace EKabineti\Service;

final class MayorDashboardMetrics
{
    /**
     * @param array<int,array<string,mixed>> $projects
     * @param array<int,array<string,mixed>> $services
     * @param array<int,array<string,mixed>> $budget
     * @param array<int,array<string,mixed>> $issues
     * @return array<string,int|float|string|null>
     */
    public function calculate(array $projects, array $services, array $budget, array $issues, string $asOfDate): array
    {
        $projectCount = count($projects);
        $averageProgress = $projectCount === 0
            ? null
            : array_sum(array_map(static fn(array $row): float => (float) ($row['progress_ratio'] ?? 0), $projects)) / $projectCount;
        $onTrack = count(array_filter($projects, static fn(array $row): bool => ($row['executive_status'] ?? '') === 'On Track'));
        $atRisk = count(array_filter($projects, static fn(array $row): bool => ($row['executive_status'] ?? '') === 'At Risk'));
        $active = count(array_filter($projects, static fn(array $row): bool => ($row['executive_status'] ?? '') !== 'Completed'));

        $deadlineEnd = (new \DateTimeImmutable($asOfDate))->modify('+14 days')->format('Y-m-d');
        $deadlines14Days = count(array_filter($projects, static function (array $row) use ($asOfDate, $deadlineEnd): bool {
            $deadline = (string) ($row['next_deadline'] ?? '');
            return $deadline !== '' && $deadline >= $asOfDate && $deadline <= $deadlineEnd;
        }));

        $budgetTotal = array_sum(array_map(static fn(array $row): float => (float) ($row['annual_budget'] ?? 0), $budget));
        $spentTotal = array_sum(array_map(static fn(array $row): float => (float) ($row['spent'] ?? 0), $budget));
        $budgetExecution = $budgetTotal > 0 ? $spentTotal / $budgetTotal : null;

        $received = array_sum(array_map(static fn(array $row): int => (int) ($row['received_count'] ?? 0), $services));
        $resolved = array_sum(array_map(static fn(array $row): int => (int) ($row['resolved_count'] ?? 0), $services));
        $serviceResolution = $received > 0 ? $resolved / $received : null;
        $weightedHours = array_sum(array_map(
            static fn(array $row): float => (float) ($row['average_resolution_hours'] ?? 0) * (int) ($row['resolved_count'] ?? 0),
            $services
        ));
        $averageResolutionHours = $resolved > 0 ? $weightedHours / $resolved : null;

        $criticalOpen = count(array_filter($issues, static fn(array $row): bool => ($row['level'] ?? '') === 'Critical' && ($row['status'] ?? '') === 'Open'));
        $municipalPulse = null;
        if ($averageProgress !== null && $serviceResolution !== null) {
            $issueHealth = 1 - min(1, $criticalOpen / 5);
            $municipalPulse = (int) round(($averageProgress * 0.40 + $serviceResolution * 0.40 + $issueHealth * 0.20) * 100);
        }

        return [
            'municipal_pulse' => $municipalPulse,
            'average_project_progress' => $averageProgress,
            'on_track_projects' => $onTrack,
            'total_projects' => $projectCount,
            'budget_execution' => $budgetExecution,
            'critical_open_issues' => $criticalOpen,
            'service_resolution' => $serviceResolution,
            'average_resolution_hours' => $averageResolutionHours,
            'active_projects' => $active,
            'deadlines_14_days' => $deadlines14Days,
            'at_risk_projects' => $atRisk,
            'annual_budget' => $budgetTotal,
            'spent' => $spentTotal,
        ];
    }
}

