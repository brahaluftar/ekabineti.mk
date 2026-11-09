<?php

declare(strict_types=1);

namespace EKabineti\Service;

use EKabineti\Core\Database;
use EKabineti\Core\Logger;

final class AiInterventionExecutor
{
    public const ACTIONS=['none','run_scheduler','run_migrations','sync_mayor_google_sheets'];

    public function __construct(
        private readonly Database $db,
        private readonly Notifications $notifications,
        private readonly Logger $logger,
        private readonly array $config,
        private readonly string $storagePath,
    ) {}

    public function execute(string $action): array
    {
        if (!in_array($action,self::ACTIONS,true)) throw new \LogicException('The intervention is not allowlisted.');
        return match($action) {
            'none'=>['status'=>'no_action','message'=>'No automated intervention was requested.'],
            'run_scheduler'=>(new Scheduler($this->db,$this->notifications,$this->logger,$this->storagePath,($this->config['uploads']['clamav_binary']??null)?:null,$this->config))->run(),
            'run_migrations'=>(new MigrationRunner($this->db,APP_ROOT.'/database/migrations'))->run(),
            'sync_mayor_google_sheets'=>$this->syncMayorSheet(),
        };
    }

    private function syncMayorSheet(): array
    {
        $sheets=MayorGoogleSheetsConfig::fromApplication($this->config);
        return (new MayorGoogleSheetsSync(
            new MayorDashboardReport($this->db),new MayorGoogleSheetsMap(),$this->logger,$sheets,$this->storagePath
        ))->run(false,false);
    }
}
