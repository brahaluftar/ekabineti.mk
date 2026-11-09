<?php

declare(strict_types=1);

namespace EKabineti\Core;

use EKabineti\Controller\ReadController;
use EKabineti\Controller\EvidenceController;
use EKabineti\Controller\CitizenServiceCatalogController;
use EKabineti\Controller\DocumentIntakeController;
use EKabineti\Controller\ExecutiveAreasController;
use EKabineti\Controller\LegalWorkController;
use EKabineti\Controller\MayorBriefingController;
use EKabineti\Controller\RoleController;
use EKabineti\Controller\RevenueController;
use EKabineti\Controller\WriteController;
use EKabineti\Security\Auth;
use EKabineti\Security\Scope;
use EKabineti\Service\Audit;
use EKabineti\Service\GraphMail;
use EKabineti\Service\LoginThrottle;
use EKabineti\Service\MigrationRunner;
use EKabineti\Service\Notifications;
use EKabineti\Service\Scheduler;

final class Application
{
    private Database $db;
    private Request $request;
    private Auth $auth;
    private Scope $scope;
    private Logger $logger;
    private Notifications $notifications;

    public function __construct(private readonly array $config)
    {
        Request::$trustedProxies = array_values(array_filter(array_map('strval', (array) ($config['app']['trusted_proxies'] ?? []))));
        $this->request = new Request();
        $this->logger = new Logger(APP_ROOT.'/storage/logs');
    }

    public function run(): never
    {
        try {
            $this->boot();
            $requestedLocale=(string)$this->request->input('lang','');if(in_array($requestedLocale,['mk','en'],true))$_SESSION['_locale']=$requestedLocale;
            header('X-Correlation-ID: '.$this->request->correlationId);
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header("Referrer-Policy: strict-origin-when-cross-origin");
            header("Permissions-Policy: camera=(), microphone=(), geolocation=(self)");
            header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data: https://tile.openstreetmap.org; connect-src 'self'; frame-src https://docs.google.com; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
            if ($this->request->path === '/health/details') $this->health();
            if ($this->request->method === 'POST' && $this->request->path === '/internal/migrate') $this->runMigrations();
            if ($this->request->method === 'POST' && $this->request->path === '/internal/scheduler') $this->runScheduler();
            if (!$this->auth->user()) {
                if ($this->request->method === 'GET' && $this->request->path === '/login') $this->read()->login();
                if ($this->request->method === 'POST' && $this->request->path === '/login') { $this->verifyCsrf(); $this->write()->login(); }
                if ($this->request->method === 'GET' && $this->request->path === '/register') $this->read()->register();
                if ($this->request->method === 'POST' && $this->request->path === '/register') { $this->verifyCsrf(); $this->rateLimitRegistration(); $this->write()->register(); }
                header('Location: /login', true, 302); exit;
            }
            if ($this->request->method === 'POST') $this->verifyCsrf();
            $this->dispatch();
        } catch (HttpException $e) {
            $this->error($e->status, $e->getMessage());
        } catch (\PDOException $e) {
            // Unique/foreign-key violations are user errors (duplicate code, record in use), not server faults.
            if ((string) $e->getCode() === '23000') {
                $reference = (bool) preg_match('/FOREIGN KEY|REFERENCE constraint/i', $e->getMessage());
                $this->logger->write('warning', 'request.constraint_violation', ['correlation_id'=>$this->request->correlationId,'path'=>$this->request->path,'error'=>$e->getMessage()]);
                $this->error($reference ? 409 : 422, $reference ? 'Записот има врски со други податоци и не може да се промени на овој начин.' : 'Веќе постои запис со истите единствени податоци (шифра, број или референца).');
            }
            $this->logger->write('error', 'request.failed', ['correlation_id'=>$this->request->correlationId,'actor_id'=>isset($this->auth)?($this->auth->user()['id']??null):null,'method'=>$this->request->method,'path'=>$this->request->path,'exception'=>get_class($e),'error'=>$e->getMessage()]);
            $this->error(500, 'Настана внатрешна грешка. Референца: '.$this->request->correlationId);
        } catch (\Throwable $e) {
            $this->logger->write('error', 'request.failed', ['correlation_id'=>$this->request->correlationId,'actor_id'=>isset($this->auth)?($this->auth->user()['id']??null):null,'method'=>$this->request->method,'path'=>$this->request->path,'exception'=>get_class($e),'error'=>$e->getMessage()]);
            $this->error(500, 'Настана внатрешна грешка. Референца: '.$this->request->correlationId);
        }
    }

    public function database(): Database { $this->boot(); return $this->db; }
    public function notifications(): Notifications { $this->boot(); return $this->notifications; }
    public function logger(): Logger { return $this->logger; }
    public function config(): array { return $this->config; }

    private function boot(): void
    {
        if (isset($this->db)) return;
        $this->db = new Database($this->config['database'] ?? []);
        $this->auth = new Auth($this->db, $this->config['app'] ?? []);
        $this->scope = new Scope($this->db);
        $this->notifications = new Notifications($this->db, new GraphMail($this->config['graph'] ?? []), $this->logger);
    }

    private function dispatch(): never
    {
        $m=$this->request->method;$p=$this->request->path;
        if($m==='POST'&&$p==='/logout')$this->write()->logout();
        if($m==='POST'&&$p==='/language')$this->write()->language();
        if($m==='GET'&&$p==='/')$this->read()->dashboard();
        if($m==='GET'&&$p==='/dashboard.xlsx')$this->read()->dashboardExport();
        if($m==='GET'&&$p==='/profile/security')$this->read()->password();
        if($m==='POST'&&$p==='/profile/security'){$this->rateLimitPasswordChange();$this->write()->password();}
        if($m==='GET'&&$p==='/profile/preferences')$this->read()->preferences();
        if($m==='GET'&&$p==='/notifications')$this->read()->notifications();
        if($m==='POST'&&$p==='/notifications/read')$this->write()->notificationsRead();
        if($m==='POST'&&$p==='/profile/preferences')$this->write()->preferences();
        if($m==='GET'&&$p==='/projects'){$this->permit('view','project');$this->read()->projects();}
        if($m==='GET'&&$p==='/procurement-projects'){$this->procurementListAccess();$this->read()->procurementProjects();}
        if(str_starts_with($p,'/revenues')){
            $this->revenuesAccess();
            if($m==='GET'&&$p==='/revenues')$this->revenues()->index();
            if($m==='GET'&&preg_match('#^/revenues\.(csv|xlsx)$#',$p,$x))$this->revenues()->export($x[1]);
            if($m==='GET'&&$p==='/revenues/import')$this->revenues()->importForm();
            if($m==='POST'&&$p==='/revenues/import')$this->revenues()->importPreview();
            if($m==='GET'&&preg_match('#^/revenues/imports/([0-9a-f-]{36})$#i',$p,$x))$this->revenues()->importShow($x[1]);
            if($m==='GET'&&preg_match('#^/revenues/imports/([0-9a-f-]{36})/status$#i',$p,$x))$this->revenues()->importStatus($x[1]);
            if($m==='POST'&&preg_match('#^/revenues/imports/([0-9a-f-]{36})/confirm$#i',$p,$x))$this->revenues()->importConfirm($x[1]);
            if($m==='POST'&&preg_match('#^/revenues/imports/([0-9a-f-]{36})/cancel$#i',$p,$x))$this->revenues()->importCancel($x[1]);
            if($m==='POST'&&preg_match('#^/revenues/imports/([0-9a-f-]{36})/reimport$#i',$p,$x))$this->revenues()->importReimport($x[1]);
            if($m==='GET'&&$p==='/revenues/maintenance')$this->revenues()->maintenance();
            if($m==='POST'&&$p==='/revenues/maintenance/clear')$this->revenues()->maintenanceClear();
            if($m==='GET'&&$p==='/revenues/categories')$this->revenues()->categories();
            if($m==='POST'&&$p==='/revenues/categories')$this->revenues()->categoryCreate();
            if($m==='POST'&&$p==='/revenues/categories/rules')$this->revenues()->ruleCreate();
            if($m==='POST'&&preg_match('#^/revenues/categories/rules/(\d+)/toggle$#',$p,$x))$this->revenues()->ruleToggle((int)$x[1]);
            if($m==='GET'&&preg_match('#^/revenues/(\d+)$#',$p,$x))$this->revenues()->show((int)$x[1]);
            if($m==='POST'&&preg_match('#^/revenues/(\d+)/category$#',$p,$x))$this->revenues()->reclassify((int)$x[1]);
        }
        if($m==='GET'&&$p==='/executed-cases'){$this->executedCasesAccess();$this->read()->executedCases();}
        if($m==='GET'&&preg_match('#^/executed-cases/(\d+)$#',$p,$x)){$this->executedCasesAccess();$this->read()->executedCase((int)$x[1]);}
        if($m==='POST'&&preg_match('#^/executed-cases/(\d+)/finance$#',$p,$x)){$this->executedCasesAccess();$this->write()->executedCaseFinance((int)$x[1]);}
        if($m==='POST'&&preg_match('#^/executed-cases/(\d+)/legal$#',$p,$x)){$this->executedCasesAccess();$this->write()->executedCaseLegal((int)$x[1]);}
        if($m==='GET'&&$p==='/projects/new'){$this->permit('create','project');$this->read()->projectCreate();}
        if($m==='GET'&&preg_match('#^/projects\.(csv|xlsx)$#',$p,$x)){$this->permit('view','project');$this->read()->listExport('projects',$x[1]);}
        if($m==='POST'&&$p==='/projects'){$this->permit('create','project');$this->write()->project();}
        if($m==='GET'&&preg_match('#^/projects/(\d+)/edit$#',$p,$x)){$this->read()->projectEdit((int)$x[1]);}
        if($m==='GET'&&preg_match('#^/projects/(\d+)/tasks/new$#',$p,$x)){$this->permit('create','project');$this->read()->projectTaskCreate((int)$x[1]);}
        if($m==='GET'&&preg_match('#^/projects/(\d+)/budget/new$#',$p,$x)){$this->permit('create','allocation');$this->read()->projectBudgetCreate((int)$x[1]);}
        if($m==='GET'&&preg_match('#^/projects/(\d+)/reports/new$#',$p,$x)){$this->permit('create','project_report');$this->read()->projectReportCreate((int)$x[1]);}
        if($m==='GET'&&preg_match('#^/projects/(\d+)/documents/new$#',$p,$x)){$this->permit('create','attachment');$this->read()->projectDocumentCreate((int)$x[1]);}
        if($m==='GET'&&preg_match('#^/projects/(\d+)/photos/new$#',$p,$x)){$this->permit('create','attachment');$this->read()->projectPhotoCreate((int)$x[1]);}
        if($m==='GET'&&preg_match('#^/projects/(\d+)/share$#',$p,$x)){$this->permit('create','project');$this->read()->projectShareCreate((int)$x[1]);}
        if($m==='POST'&&preg_match('#^/projects/(\d+)/budget$#',$p,$x)){$this->permit('create','allocation');$this->write()->projectBudget((int)$x[1]);}
        if($m==='POST'&&preg_match('#^/projects/(\d+)/tasks$#',$p,$x)){$this->permit('create','project');$this->write()->task((int)$x[1]);}
        if($m==='GET'&&preg_match('#^/projects/(\d+)/tasks/(\d+)/edit$#',$p,$x)){$this->permit('create','project');$this->read()->taskEdit((int)$x[1],(int)$x[2]);}
        if($m==='POST'&&preg_match('#^/projects/(\d+)/tasks/(\d+)$#',$p,$x)){$this->permit('create','project');$this->write()->taskUpdate((int)$x[1],(int)$x[2]);}
        if($m==='POST'&&preg_match('#^/projects/(\d+)$#',$p,$x)){$this->write()->projectUpdate((int)$x[1]);}
        if($m==='GET'&&preg_match('#^/projects/(\d+)$#',$p,$x)){$this->read()->project((int)$x[1]);}
        if($m==='POST'&&preg_match('#^/projects/(\d+)/reports$#',$p,$x)){$this->write()->progress((int)$x[1]);}
        if($m==='POST'&&preg_match('#^/projects/(\d+)/share$#',$p,$x)){$this->write()->projectShare((int)$x[1]);}
        if($m==='POST'&&preg_match('#^/projects/(\d+)/tasks/(\d+)/share$#',$p,$x)){$this->write()->taskShare((int)$x[1],(int)$x[2]);}
        if($m==='POST'&&preg_match('#^/projects/(\d+)/photos$#',$p,$x)){$this->evidence()->upload((int)$x[1]);}
        if($m==='GET'&&$p==='/live/project-reports'){$this->permit('view','project');$this->read()->liveReports();}
        if($m==='GET'&&$p==='/budget'){$this->permit('view','budget');$this->read()->budget();}
        if($m==='GET'&&$p==='/budget/lines'){$this->permit('view','budget');$this->read()->budgetTable();}
        if($m==='GET'&&$p==='/budget/freebalance'){$this->permit('view','budget');$this->read()->freeBalance();}
        if($m==='POST'&&$p==='/budget/freebalance/programs'){$this->permit('view','budget');$this->write()->freeBalancePrograms();}
        if($m==='GET'&&$p==='/budget/allocations'){$this->permit('view','budget');$this->read()->financialList('allocations');}
        if($m==='GET'&&$p==='/budget/lines/new'){$this->permit('create','allocation');$this->read()->budgetLineCreate();}
        if($m==='GET'&&$p==='/budget/allocations/new'){$this->permit('create','allocation');$this->read()->allocationCreate();}
        if($m==='GET'&&preg_match('#^/budget\.(csv|xlsx)$#',$p,$x)){$this->permit('view','budget');$this->read()->listExport('budget',$x[1]);}
        if($m==='POST'&&$p==='/budget/lines'){$this->permit('create','allocation');$this->write()->budgetLine();}
        if($m==='GET'&&preg_match('#^/budget/lines/(\d+)/edit$#',$p,$x)){$this->permit('create','allocation');$this->read()->budgetLineEdit((int)$x[1]);}
        if($m==='POST'&&preg_match('#^/budget/lines/(\d+)$#',$p,$x)){$this->permit('create','allocation');$this->write()->budgetLineUpdate((int)$x[1]);}
        if($m==='POST'&&preg_match('#^/budget/lines/(\d+)/access$#',$p,$x)){$this->permit('create','allocation');$this->write()->budgetLineAccess((int)$x[1]);}
        if($m==='POST'&&$p==='/budget/allocations'){$this->permit('create','allocation');$this->write()->allocation();}
        if($m==='GET'&&$p==='/execution'){$this->permit('view','execution');$this->read()->execution();}
        if($m==='GET'&&$p==='/execution/commitments'){$this->permit('view','execution');$this->read()->financialList('commitments');}
        if($m==='GET'&&$p==='/execution/expenditures'){$this->permit('view','execution');$this->read()->financialList('expenditures');}
        if($m==='GET'&&$p==='/execution/commitments/new'){$this->permit('post','commitment');$this->read()->commitmentCreate();}
        if($m==='GET'&&$p==='/execution/expenditures/new'){$this->permit('post','expenditure');$this->read()->expenditureCreate();}
        if($m==='GET'&&preg_match('#^/execution\.(csv|xlsx)$#',$p,$x)){$this->permit('view','execution');$this->read()->listExport('execution',$x[1]);}
        if($m==='POST'&&preg_match('#^/execution/allocations/(\d+)/project$#',$p,$x)){$this->permit('view','execution');$this->write()->executionAllocationProject((int)$x[1]);}
        if($m==='POST'&&preg_match('#^/execution/lines/(\d+)/project$#',$p,$x)){$this->permit('view','execution');$this->write()->executionLineProject((int)$x[1]);}
        if($m==='POST'&&$p==='/execution/commitments'){$this->permit('post','commitment');$this->write()->commitment();}
        if($m==='POST'&&$p==='/execution/expenditures'){$this->permit('post','expenditure');$this->write()->expenditure();}
        if($m==='GET'&&$p==='/changes'){$this->permit('view','budget_change');$this->read()->changes();}
        if($m==='GET'&&preg_match('#^/changes\.(csv|xlsx)$#',$p,$x)){$this->permit('view','budget_change');$this->read()->listExport('changes',$x[1]);}
        if($m==='POST'&&$p==='/changes'){$this->permit('create','budget_change');$this->write()->change();}
        if($m==='POST'&&preg_match('#^/changes/(\d+)/approve$#',$p,$x)){$this->permit('approve','budget_change');$this->write()->approveChange((int)$x[1]);}
        if($m==='POST'&&preg_match('#^/changes/(\d+)/resubmit$#',$p,$x)){$this->permit('create','budget_change');$this->write()->resubmitChange((int)$x[1]);}
        if($m==='GET'&&$p==='/workflows'){$this->permit('view','workflow');$this->read()->workflows();}
        if($m==='GET'&&preg_match('#^/workflows\.(csv|xlsx)$#',$p,$x)){$this->permit('view','workflow');$this->read()->listExport('workflows',$x[1]);}
        if($m==='POST'&&$p==='/workflows/action'){$this->permit('decide','workflow');$this->write()->workflow();}
        if($m==='GET'&&$p==='/risks'){$this->permit('view','risk');$this->read()->risks();}
        if($m==='GET'&&$p==='/risks/new'){$this->permit('create','risk');$this->read()->riskCreate();}
        if($m==='GET'&&preg_match('#^/risks\.(csv|xlsx)$#',$p,$x)){$this->permit('view','risk');$this->read()->listExport('risks',$x[1]);}
        if($m==='POST'&&$p==='/risks'){$this->permit('create','risk');$this->write()->risk();}
        if($m==='GET'&&preg_match('#^/risks/(\d+)/edit$#',$p,$x)){$this->permit('create','risk');$this->read()->riskEdit((int)$x[1]);}
        if($m==='POST'&&preg_match('#^/risks/(\d+)$#',$p,$x)){$this->permit('create','risk');$this->write()->riskUpdate((int)$x[1]);}
        if($m==='POST'&&preg_match('#^/risks/(\d+)/measures$#',$p,$x)){$this->permit('create','risk');$this->write()->riskMeasure((int)$x[1]);}
        if($m==='GET'&&preg_match('#^/risks/(\d+)/measures/(\d+)/edit$#',$p,$x)){$this->permit('create','risk');$this->read()->riskMeasureEdit((int)$x[1],(int)$x[2]);}
        if($m==='POST'&&preg_match('#^/risks/(\d+)/measures/(\d+)$#',$p,$x)){$this->permit('create','risk');$this->write()->riskMeasureUpdate((int)$x[1],(int)$x[2]);}
        if($m==='GET'&&$p==='/reports/multiline'){$this->permit('view','report');$this->read()->report();}
        if($m==='GET'&&$p==='/reports/multiline.csv'){$this->permit('export','report');$this->read()->report(true);}
        if($m==='GET'&&$p==='/reports/multiline.xlsx'){$this->permit('export','report');$this->read()->listExport('reports','xlsx');}
        if($m==='GET'&&$p==='/reports/inspection'){$this->inspectionReportingAccess();$this->read()->inspectionReports();}
        if($m==='GET'&&$p==='/reports/inspection.csv'){$this->inspectionReportingAccess();$this->read()->inspectionReports(true);}
        if($m==='POST'&&$p==='/reports/inspection'){$this->inspectionReportingAccess();$this->write()->inspectionReport();}
        if($m==='GET'&&$p==='/imports'){$this->permit('view','import');$this->read()->imports();}
        if($m==='GET'&&preg_match('#^/imports/([a-z-]+)$#',$p,$x)){$this->permit('view','import');$this->read()->imports($x[1]);}
        if($m==='GET'&&preg_match('#^/imports\.(csv|xlsx)$#',$p,$x)){$this->permit('view','import');$this->read()->listExport('imports',$x[1]);}
        if($m==='GET'&&$p==='/imports/template.csv'){$this->permit('view','import');$this->read()->templateCsv();}
        if($m==='GET'&&$p==='/imports/template.xlsx'){$this->permit('view','import');$this->read()->templateXlsx();}
        if($m==='POST'&&$p==='/imports/preview'){$this->permit('create','import');$this->write()->importPreview();}
        if($m==='POST'&&$p==='/imports/confirm'){$this->permit('create','import');$this->write()->importConfirm();}
        if($m==='POST'&&$p==='/imports/cleanup'){$this->write()->importsCleanup();}
        if($m==='POST'&&preg_match('#^/imports/([0-9a-f-]{36})/delete$#i',$p,$x)){$this->write()->importDelete($x[1]);}
        if($m==='GET'&&preg_match('#^/imports/([0-9a-f-]{36})/enforcement-result\.xlsx$#i',$p,$x)){$this->executedCasesAccess();$this->read()->enforcementImportResult($x[1]);}
        if($m==='GET'&&$p==='/audit'){$this->permit('view','audit');$this->read()->audit();}
        if($m==='GET'&&preg_match('#^/audit\.(csv|xlsx)$#',$p,$x)){$this->permit('view','audit');$this->read()->listExport('audit',$x[1]);}
        if($m==='GET'&&$p==='/system-logs'){$this->permit('view','system_log');$this->read()->logs();}
        if($m==='GET'&&preg_match('#^/system-logs\.(csv|xlsx)$#',$p,$x)){$this->permit('view','system_log');$this->read()->listExport('logs',$x[1]);}
        if($m==='GET'&&$p==='/system-logs/feed'){$this->permit('view','system_log');$this->read()->logs(true);}
        if($m==='GET'&&$p==='/ai-technical-assistant'){$this->technicalAssistantAdministrator();$this->read()->aiTechnicalAssistant();}
        if($m==='POST'&&$p==='/ai-technical-assistant/proposals'){$this->technicalAssistantAdministrator();$this->write()->aiTechnicalProposal();}
        if($m==='POST'&&preg_match('#^/ai-technical-assistant/proposals/(\d+)/approve$#',$p,$x)){$this->technicalAssistantAdministrator();$this->write()->aiTechnicalApprove((int)$x[1]);}
        if($m==='POST'&&preg_match('#^/ai-technical-assistant/proposals/(\d+)/reject$#',$p,$x)){$this->technicalAssistantAdministrator();$this->write()->aiTechnicalReject((int)$x[1]);}
        if($m==='GET'&&$p==='/ai-reporting'){$this->aiReportingAccess();$this->read()->aiReporting();}
        if($m==='POST'&&$p==='/ai-reporting'){$this->aiReportingAccess();$this->write()->aiReport();}
        if($m==='GET'&&$p==='/mayor-dashboard'){$this->mayorDashboardAccess();$this->read()->mayorDashboard();}
        if($m==='GET'&&$p==='/citizen-services/by-service')$this->citizenCatalog()->reporting();
        if($m==='GET'&&$p==='/citizen-services/by-service/report')$this->citizenCatalog()->report();
        if($m==='GET'&&$p==='/citizen-services/catalog')$this->citizenCatalog()->catalogPage();
        if($m==='POST'&&preg_match('#^/citizen-services/sectors/(\d+)/weekly$#',$p,$x))$this->citizenCatalog()->save((int)$x[1]);
        if($m==='POST'&&preg_match('#^/citizen-services/sectors/(\d+)/reporters$#',$p,$x))$this->citizenCatalog()->reporter((int)$x[1]);
        if($m==='POST'&&preg_match('#^/citizen-services/catalog/(\d+)/tier$#',$p,$x))$this->citizenCatalog()->tier((int)$x[1]);
        if($m==='GET'&&$p==='/citizen-services/catalog/services/new')$this->citizenCatalog()->serviceForm();
        if($m==='POST'&&$p==='/citizen-services/catalog/services')$this->citizenCatalog()->serviceSave();
        if($m==='GET'&&preg_match('#^/citizen-services/catalog/(\d+)/edit$#',$p,$x))$this->citizenCatalog()->serviceForm((int)$x[1]);
        if($m==='POST'&&preg_match('#^/citizen-services/catalog/(\d+)$#',$p,$x))$this->citizenCatalog()->serviceSave((int)$x[1]);
        if($m==='POST'&&preg_match('#^/citizen-services/catalog/(\d+)/status$#',$p,$x))$this->citizenCatalog()->serviceStatus((int)$x[1]);
        if($m==='POST'&&preg_match('#^/citizen-services/catalog/(\d+)/delete$#',$p,$x))$this->citizenCatalog()->serviceDelete((int)$x[1]);
        if($m==='POST'&&$p==='/citizen-services/catalog/sectors')$this->citizenCatalog()->sectorSave();
        if($m==='POST'&&preg_match('#^/citizen-services/catalog/sectors/(\d+)$#',$p,$x))$this->citizenCatalog()->sectorSave((int)$x[1]);
        if($m==='POST'&&preg_match('#^/citizen-services/catalog/sectors/(\d+)/status$#',$p,$x))$this->citizenCatalog()->sectorStatus((int)$x[1]);
        if($m==='POST'&&preg_match('#^/citizen-services/catalog/sectors/(\d+)/delete$#',$p,$x))$this->citizenCatalog()->sectorDelete((int)$x[1]);
        if($m==='GET'&&$p==='/citizen-services'){$this->citizenServicesAccess();$this->read()->citizenServices();}
        if($m==='POST'&&preg_match('#^/citizen-services/(\d+)/weekly-reports$#',$p,$x)){$this->citizenServicesAccess();$this->write()->citizenServiceWeeklyReport((int)$x[1]);}
        if($m==='POST'&&preg_match('#^/citizen-services/(\d+)/status$#',$p,$x)){$this->citizenServicesManager();$this->write()->citizenServiceStatus((int)$x[1]);}
        if($m==='POST'&&preg_match('#^/citizen-services/(\d+)/transfer$#',$p,$x)){$this->citizenServicesManager();$this->write()->citizenServiceTransfer((int)$x[1]);}
        if($m==='GET'&&$p==='/mayor-briefing')$this->mayorBriefing()->index();
        if($m==='GET'&&$p==='/directorate-briefing')$this->mayorBriefing()->directorateIndex();
        if($m==='POST'&&$p==='/directorate-briefing/generate')$this->mayorBriefing()->directorateGenerate();
        if($m==='POST'&&$p==='/mayor-briefing/generate')$this->mayorBriefing()->generate();
        if($m==='GET'&&preg_match('#^/mayor-agenda/(\d+)/prepare$#',$p,$x))$this->mayorBriefing()->prepare((int)$x[1]);
        if($m==='POST'&&preg_match('#^/mayor-agenda/(\d+)/prepare$#',$p,$x))$this->mayorBriefing()->prepareGenerate((int)$x[1]);
        if($m==='POST'&&preg_match('#^/mayor-agenda/(\d+)/prepare/(\d+)/notes$#',$p,$x))$this->mayorBriefing()->notes((int)$x[1],(int)$x[2]);
        if($m==='POST'&&preg_match('#^/mayor-agenda/(\d+)/prepare/(\d+)/confirm$#',$p,$x))$this->mayorBriefing()->confirm((int)$x[1],(int)$x[2]);
        if($m==='GET'&&$p==='/mayor-agenda'){$this->mayorAgendaAccess();$this->read()->mayorAgendaCurrent();}
        if($m==='GET'&&$p==='/mayor-agenda/directorates'){$this->mayorAgendaAccess();$this->read()->mayorAgendaDirectorates();}
        if($m==='GET'&&$p==='/directorates'){$this->directorateAdministrator();$this->read()->directorates();}
        if($m==='GET'&&preg_match('#^/directorates\.(csv|xlsx)$#',$p,$x)){$this->directorateAdministrator();$this->read()->listExport('directorates',$x[1]);}
        if($m==='POST'&&$p==='/directorates'){$this->directorateAdministrator();$this->write()->directorate();}
        if($m==='GET'&&preg_match('#^/directorates/(\d+)/edit$#',$p,$x)){$this->directorateAdministrator();$this->read()->directorateEdit((int)$x[1]);}
        if($m==='GET'&&preg_match('#^/directorates/(\d+)$#',$p,$x)){$this->mayorAgendaAdministrator((int)$x[1]);$this->read()->directorate((int)$x[1]);}
        if($m==='POST'&&preg_match('#^/directorates/(\d+)/mayor-agenda/selection$#',$p,$x)){$this->mayorAgendaAdministrator((int)$x[1]);$this->write()->mayorAgendaSelection((int)$x[1]);}
        if($m==='POST'&&preg_match('#^/directorates/(\d+)/procurement-projects/link$#',$p,$x)){$this->mayorAgendaAdministrator((int)$x[1]);$this->write()->procurementProjectLink((int)$x[1]);}
        if($m==='POST'&&preg_match('#^/directorates/(\d+)/mayor-agenda/topics$#',$p,$x)){$this->mayorAgendaAdministrator((int)$x[1]);$this->write()->mayorAgendaTopic((int)$x[1]);}
        if($m==='GET'&&preg_match('#^/directorates/(\d+)/mayor-agenda/(\d+)/edit$#',$p,$x)){$this->mayorAgendaAdministrator((int)$x[1]);$this->read()->mayorAgendaEdit((int)$x[1],(int)$x[2]);}
        if($m==='POST'&&preg_match('#^/directorates/(\d+)/mayor-agenda/(\d+)$#',$p,$x)){$this->mayorAgendaAdministrator((int)$x[1]);$this->write()->mayorAgendaUpdate((int)$x[1],(int)$x[2]);}
        if($m==='POST'&&preg_match('#^/directorates/(\d+)/mayor-agenda/(\d+)/status$#',$p,$x)){$this->mayorAgendaAdministrator((int)$x[1]);$this->write()->mayorAgendaStatus((int)$x[1],(int)$x[2]);}
        if($m==='POST'&&preg_match('#^/directorates/(\d+)$#',$p,$x)){$this->directorateAdministrator();$this->write()->directorateUpdate((int)$x[1]);}
        if($m==='POST'&&preg_match('#^/directorates/(\d+)/status$#',$p,$x)){$this->directorateAdministrator();$this->write()->directorateStatus((int)$x[1]);}
        if($m==='POST'&&preg_match('#^/directorates/(\d+)/delete$#',$p,$x)){$this->directorateAdministrator();$this->write()->directorateDelete((int)$x[1]);}
        if($m==='GET'&&$p==='/legal-office'){$this->legalOfficeAccess();$this->read()->legalOffice();}
        if($m==='GET'&&$p==='/legal-office/cases/new'){$this->legalOfficeWork();$this->read()->legalCaseCreate();}
        if($m==='POST'&&$p==='/legal-office/cases'){$this->legalOfficeWork();$this->write()->legalCaseCreate();}
        if($m==='GET'&&preg_match('#^/legal-office/cases/(\d+)$#',$p,$x)){$this->legalOfficeAccess();$this->read()->legalCase((int)$x[1]);}
        if($m==='POST'&&preg_match('#^/legal-office/cases/(\d+)/update$#',$p,$x)){$this->legalOfficeWork();$this->write()->legalCaseUpdate((int)$x[1]);}
        if($m==='POST'&&preg_match('#^/legal-office/cases/(\d+)/parties$#',$p,$x)){$this->legalOfficeWork();$this->write()->legalCaseParty((int)$x[1]);}
        if($m==='POST'&&preg_match('#^/legal-office/cases/(\d+)/procedures$#',$p,$x)){$this->legalOfficeWork();$this->write()->legalCaseProcedure((int)$x[1]);}
        if($m==='POST'&&preg_match('#^/legal-office/cases/(\d+)/hearings$#',$p,$x)){$this->legalOfficeWork();$this->write()->legalCaseHearing((int)$x[1]);}
        if($m==='POST'&&preg_match('#^/legal-office/cases/(\d+)/deadlines$#',$p,$x)){$this->legalOfficeWork();$this->write()->legalCaseDeadline((int)$x[1]);}
        if($m==='POST'&&preg_match('#^/legal-office/cases/(\d+)/deadlines/(\d+)/status$#',$p,$x)){$this->legalOfficeWork();$this->write()->legalCaseDeadlineStatus((int)$x[1],(int)$x[2]);}
        if($m==='POST'&&preg_match('#^/legal-office/cases/(\d+)/procedures/(\d+)/status$#',$p,$x)){$this->legalOfficeWork();$this->write()->legalCaseProcedureStatus((int)$x[1],(int)$x[2]);}
        if($m==='POST'&&preg_match('#^/legal-office/cases/(\d+)/hearings/(\d+)/status$#',$p,$x)){$this->legalOfficeWork();$this->write()->legalCaseHearingStatus((int)$x[1],(int)$x[2]);}
        if($m==='POST'&&preg_match('#^/legal-office/cases/(\d+)/assign$#',$p,$x)){$this->legalOfficeManager();$this->write()->legalCaseAssign((int)$x[1]);}
        if($m==='POST'&&preg_match('#^/legal-office/cases/(\d+)/executions$#',$p,$x)){$this->legalOfficeWork();$this->write()->legalCaseExecutionLink((int)$x[1]);}
        if(str_starts_with($p,'/legal-office/work-reports')){
            $this->legalOfficeAccess();
            if($m==='GET'&&$p==='/legal-office/work-reports')$this->legalWork()->index();
            if($m==='GET'&&$p==='/legal-office/work-reports.xlsx')$this->legalWork()->export();
            if($m==='GET'&&$p==='/legal-office/work-reports/template.xlsx')$this->legalWork()->template();
            if($m==='GET'&&$p==='/legal-office/work-reports/import')$this->legalWork()->importForm();
            if($m==='POST'&&$p==='/legal-office/work-reports/import')$this->legalWork()->importPreview();
            if($m==='GET'&&preg_match('#^/legal-office/work-reports/imports/([0-9a-f-]{36})$#i',$p,$x))$this->legalWork()->importShow($x[1]);
            if($m==='POST'&&preg_match('#^/legal-office/work-reports/imports/([0-9a-f-]{36})/confirm$#i',$p,$x))$this->legalWork()->importConfirm($x[1]);
            if($m==='POST'&&preg_match('#^/legal-office/work-reports/imports/([0-9a-f-]{36})/cancel$#i',$p,$x))$this->legalWork()->importCancel($x[1]);
            if($m==='GET'&&preg_match('#^/legal-office/work-reports/(\d+)$#',$p,$x))$this->legalWork()->show((int)$x[1]);
        }
        if($m==='GET'&&$p==='/legal-office/reports'){$this->legalOfficeAccess();$this->read()->legalOfficeReport(false);}
        if($m==='GET'&&$p==='/legal-office/reports.xlsx'){$this->legalOfficeAccess();$this->read()->legalOfficeReport(true);}
        if($m==='GET'&&$p==='/legal-office/users'){$this->legalOfficeManager();$this->read()->legalOfficeUsers();}
        if($m==='POST'&&$p==='/legal-office/users'){$this->legalOfficeManager();$this->write()->legalOfficeUser();}
        if($m==='POST'&&preg_match('#^/legal-office/users/(\d+)/access$#',$p,$x)){$this->legalOfficeManager();$this->write()->legalOfficeUserAccess((int)$x[1]);}
        if($m==='GET'&&$p==='/executive-areas')$this->executiveAreas()->index();
        if($m==='GET'&&$p==='/executive-areas.xlsx')$this->executiveAreas()->export();
        if($m==='GET'&&$p==='/executive-areas/settings')$this->executiveAreas()->settings();
        if($m==='POST'&&$p==='/executive-areas/areas')$this->executiveAreas()->areaSave();
        if($m==='POST'&&preg_match('#^/executive-areas/areas/(\d+)$#',$p,$x))$this->executiveAreas()->areaSave((int)$x[1]);
        if($m==='POST'&&preg_match('#^/executive-areas/directorates/(\d+)$#',$p,$x))$this->executiveAreas()->assign('directorates',(int)$x[1]);
        if($m==='POST'&&preg_match('#^/executive-areas/projects/(\d+)$#',$p,$x))$this->executiveAreas()->assign('projects',(int)$x[1]);
        if($m==='GET'&&preg_match('#^/executive-areas/(\d+)$#',$p,$x))$this->executiveAreas()->area((int)$x[1]);
        if($m==='GET'&&$p==='/document-intake')$this->documentIntake()->index();
        if($m==='POST'&&$p==='/document-intake')$this->documentIntake()->upload();
        if($m==='GET'&&preg_match('#^/document-intake/(\d+)$#',$p,$x))$this->documentIntake()->show((int)$x[1]);
        if($m==='POST'&&preg_match('#^/document-intake/(\d+)/confirm$#',$p,$x))$this->documentIntake()->confirm((int)$x[1]);
        if($m==='POST'&&preg_match('#^/document-intake/(\d+)/cancel$#',$p,$x))$this->documentIntake()->cancel((int)$x[1]);
        if($m==='GET'&&$p==='/roles')$this->roles()->index();
        if($m==='POST'&&$p==='/roles')$this->roles()->create();
        if($m==='GET'&&preg_match('#^/roles/(\d+)/edit$#',$p,$x))$this->roles()->edit((int)$x[1]);
        if($m==='POST'&&preg_match('#^/roles/(\d+)$#',$p,$x))$this->roles()->update((int)$x[1]);
        if($m==='POST'&&preg_match('#^/roles/(\d+)/status$#',$p,$x))$this->roles()->status((int)$x[1]);
        if($m==='POST'&&preg_match('#^/roles/(\d+)/delete$#',$p,$x))$this->roles()->delete((int)$x[1]);
        if($m==='GET'&&$p==='/users'){$this->permit('manage','user');$this->read()->users();}
        if($m==='GET'&&preg_match('#^/users\.(csv|xlsx)$#',$p,$x)){$this->permit('manage','user');$this->read()->listExport('users',$x[1]);}
        if($m==='POST'&&$p==='/users'){$this->permit('manage','user');$this->write()->user();}
        if($m==='POST'&&$p==='/users/access'){$this->permit('manage','user');$this->write()->userAccess();}
        if($m==='GET'&&preg_match('#^/users/(\d+)/edit$#',$p,$x)){$this->permit('manage','user');$this->read()->userEdit((int)$x[1]);}
        if($m==='POST'&&preg_match('#^/users/(\d+)$#',$p,$x)){$this->permit('manage','user');$this->write()->userUpdate((int)$x[1]);}
        if($m==='POST'&&preg_match('#^/users/(\d+)/password$#',$p,$x)){$this->permit('manage','user');$this->write()->userPasswordReset((int)$x[1]);}
        if($m==='POST'&&preg_match('#^/users/(\d+)/delete$#',$p,$x)){$this->permit('manage','user');$this->write()->userDelete((int)$x[1]);}
        if($m==='POST'&&$p==='/attachments'){$this->evidence()->upload();}
        if($m==='GET'&&preg_match('#^/attachments/(\d+)$#',$p,$x)){$this->evidence()->download((int)$x[1]);}
        if($m==='GET'&&$p==='/cases'){$this->permit('view','case');$this->read()->cases();}
        if($m==='GET'&&$p==='/cases/new'){$this->permit('create','case');$this->read()->caseCreate();}
        if($m==='GET'&&preg_match('#^/cases/(\d+)$#',$p,$x)){$this->permit('view','case');$this->read()->caseView((int)$x[1]);}
        if($m==='POST'&&preg_match('#^/cases/(\d+)/mayor-agenda$#',$p,$x)){$this->mayorAgendaAccess();$this->write()->caseMayorAgenda((int)$x[1]);}
        if($m==='POST'&&$p==='/cases'){$this->permit('create','case');$this->write()->caseCreate();}
        if($m==='POST'&&preg_match('#^/cases/(\d+)/update$#',$p,$x)){$this->permit('create','case');$this->write()->caseUpdate((int)$x[1]);}
        if($m==='POST'&&preg_match('#^/cases/(\d+)/directorates$#',$p,$x)){$this->permit('create','case');$this->write()->caseDirectorate((int)$x[1]);}
        if($m==='POST'&&preg_match('#^/cases/(\d+)/forward$#',$p,$x)){$this->permit('create','case');$this->write()->caseForward((int)$x[1]);}
        if($m==='POST'&&preg_match('#^/cases/(\d+)/forwardings/(\d+)/receive$#',$p,$x)){$this->permit('view','case');$this->write()->caseForwardReceive((int)$x[1],(int)$x[2]);}
        if($m==='POST'&&preg_match('#^/cases/(\d+)/tasks$#',$p,$x)){$this->permit('create','case');$this->write()->caseTask((int)$x[1]);}
        if($m==='POST'&&preg_match('#^/cases/(\d+)/actions$#',$p,$x)){$this->permit('create','case');$this->write()->caseAction((int)$x[1]);}
        if($m==='POST'&&preg_match('#^/cases/(\d+)/acts$#',$p,$x)){$this->permit('create','case');$this->evidence()->act((int)$x[1]);}
        if(in_array($m,['GET','POST'],true)&&preg_match('#^/decisions/(workflow|budget_change)/(\d+)/location$#',$p,$x))$this->evidence()->decisionLocation($x[1],(int)$x[2]);
        if($m==='GET'&&$p==='/shared')$this->evidence()->shared();
        if(in_array($m,['GET','POST'],true)&&preg_match('#^/shared/tasks/(\d+)$#',$p,$x))$this->evidence()->sharedTask((int)$x[1]);
        if($m==='GET'&&$p==='/overview')$this->evidence()->monitoring();
        if($m==='GET'&&$p==='/search')$this->evidence()->search();
        if($m==='POST'&&$p==='/document-links')$this->evidence()->linkCreate();
        if($m==='GET'&&preg_match('#^/document-links/([a-f0-9]{64})$#',$p,$x))$this->evidence()->linkView($x[1]);
        if($m==='POST'&&preg_match('#^/document-links/(\d+)/revoke$#',$p,$x))$this->evidence()->linkRevoke((int)$x[1]);
        if($m==='POST'&&preg_match('#^/cases/(\d+)/users$#',$p,$x))$this->evidence()->caseUser((int)$x[1]);
        if($m==='POST'&&preg_match('#^/cases/(\d+)/tasks/(\d+)/status$#',$p,$x))$this->evidence()->caseTaskStatus((int)$x[1],(int)$x[2]);
        if($m==='GET'&&$p==='/help')$this->read()->help();
        if($m==='GET'&&preg_match('#^/help/([a-z-]+)$#',$p,$x))$this->read()->help($x[1]);
        throw new HttpException(404,'Страницата не е пронајдена.');
    }

    private function revenues(): RevenueController { return new RevenueController($this->db,$this->request,$this->auth,$this->scope,new Audit($this->db,$this->request),$this->notifications,$this->logger,$this->config); }
    private function citizenCatalog(): CitizenServiceCatalogController { return new CitizenServiceCatalogController($this->db,$this->request,$this->auth,$this->scope,new Audit($this->db,$this->request),$this->notifications,$this->logger,$this->config); }
    private function executiveAreas(): ExecutiveAreasController { return new ExecutiveAreasController($this->db,$this->request,$this->auth,$this->scope,new Audit($this->db,$this->request),$this->notifications,$this->logger,$this->config); }
    private function documentIntake(): DocumentIntakeController { return new DocumentIntakeController($this->db,$this->request,$this->auth,$this->scope,new Audit($this->db,$this->request),$this->notifications,$this->logger,$this->config); }
    private function roles(): RoleController { return new RoleController($this->db,$this->request,$this->auth,$this->scope,new Audit($this->db,$this->request),$this->notifications,$this->logger,$this->config); }
    private function mayorBriefing(): MayorBriefingController { return new MayorBriefingController($this->db,$this->request,$this->auth,$this->scope,new Audit($this->db,$this->request),$this->notifications,$this->logger,$this->config); }
    private function legalWork(): LegalWorkController { return new LegalWorkController($this->db,$this->request,$this->auth,$this->scope,new Audit($this->db,$this->request),$this->notifications,$this->logger,$this->config); }
    private function evidence(): EvidenceController { return new EvidenceController($this->db,$this->request,$this->auth,$this->scope,new Audit($this->db,$this->request),$this->notifications,$this->logger,$this->config); }
    private function read(): ReadController { return new ReadController($this->db,$this->request,$this->auth,$this->scope,new Audit($this->db,$this->request),$this->notifications,$this->logger,$this->config); }
    private function write(): WriteController { return new WriteController($this->db,$this->request,$this->auth,$this->scope,new Audit($this->db,$this->request),$this->notifications,$this->logger,$this->config); }
    private function permit(string $action,string $resource): void
    {
        $userId=(int)$this->auth->user()['id'];
        if($this->scope->isMayor($userId)){
            if(in_array($resource,['import','audit'],true))throw new HttpException(403,'Овој модул не е достапен за улогата на Градоначалникот.');
            if($resource==='case'&&$action==='create')throw new HttpException(403,'Улогата на Градоначалникот има само пристап за читање до предметите.');
            if(in_array($resource,['allocation','commitment','expenditure'],true)&&$action!=='view')throw new HttpException(403,'Улогата на Градоначалникот има пристап само до финансиските листи.');
        }
        if(!$this->scope->can($userId,$action,$resource))throw new HttpException(403,'Немате дозвола за ова дејство.');
    }
    private function directorateAdministrator(): void { if(!$this->scope->canManageDirectorates((int)$this->auth->user()['id']))throw new HttpException(403,'Само Администраторот на системот и Градоначалникот можат да ги администрираат секторите.'); }
    private function mayorDashboardAccess(): void { if(!$this->scope->canAccessMayorDashboard((int)$this->auth->user()['id']))throw new HttpException(403,'Само сметката на Градоначалникот може да го отвори неделниот панел.'); }
    private function procurementListAccess(): void { if(!$this->scope->canAccessProcurementList((int)$this->auth->user()['id']))throw new HttpException(403,'Немате дозвола за листата на јавни набавки.'); }
    private function revenuesAccess(): void { if(!$this->scope->canAccessRevenues((int)$this->auth->user()['id']))throw new HttpException(403,'Немате дозвола за приходите.'); }
    private function executedCasesAccess(): void { if(!$this->scope->canAccessExecutedCases((int)$this->auth->user()['id']))throw new HttpException(403,'Немате дозвола за извршените предмети.'); }
    private function legalOfficeAccess(): void { if(!$this->scope->canAccessLegalOffice((int)$this->auth->user()['id']))throw new HttpException(403,'Немате пристап до Правната служба.'); }
    private function legalOfficeWork(): void { if(!$this->scope->canWorkLegalOffice((int)$this->auth->user()['id']))throw new HttpException(403,'Само активните корисници на Правната служба можат да го извршат ова дејство.'); }
    private function legalOfficeManager(): void { if(!$this->scope->canManageLegalOffice((int)$this->auth->user()['id']))throw new HttpException(403,'Само Раководителот на Правната служба може да го изврши ова дејство.'); }
    private function citizenServicesAccess(): void { if(!$this->scope->canAccessCitizenServices((int)$this->auth->user()['id']))throw new HttpException(403,'Немате дозвола за известување за услугите за граѓаните.'); }
    private function citizenServicesManager(): void { if(!$this->scope->canManageCitizenServices((int)$this->auth->user()['id']))throw new HttpException(403,'Администрирањето на каталогот не е дозволено од приказот за известување за услугите.'); }
    private function inspectionReportingAccess(): void { if(!$this->scope->canAccessInspectionReporting((int)$this->auth->user()['id']))throw new HttpException(403,'Ова известување е ограничено на Секторот за инспекциски надзор и раководството.'); }
    private function mayorAgendaAccess(): void { if(!$this->scope->canAccessMayorAgenda((int)$this->auth->user()['id']))throw new HttpException(403,'Немате дозвола за агендата на Градоначалникот.'); }
    private function mayorAgendaAdministrator(int $directorateId): void { if(!$this->scope->canManageMayorAgenda((int)$this->auth->user()['id'],$directorateId))throw new HttpException(403,'Немате дозвола за агендата на овој сектор.'); }
    private function verifyCsrf(): void { if(!$this->auth->verifyCsrf(is_string($_POST['_csrf']??null)?$_POST['_csrf']:null))throw new HttpException(419,'Сесијата е истечена. Освежете ја страницата.'); }

    private function technicalAssistantAdministrator(): void { if(!$this->scope->isSystemAdmin((int)$this->auth->user()['id']))throw new HttpException(403,'Само Администраторот на системот може да го користи техничкиот AI асистент.'); }
    private function aiReportingAccess(): void { if(!$this->scope->canAccessAiReporting((int)$this->auth->user()['id']))throw new HttpException(403,'AI известувањето е дозволено само за Градоначалникот, Асистентот на Градоначалникот и раководителите на сектори.'); }

    private function rateLimitRegistration(): void
    {
        $throttle=new LoginThrottle($this->db);$buckets=['register:ip:'.($this->request->ip()??'unknown')];
        $throttle->assertAllowed($buckets,3,'Премногу обиди за регистрација. Обидете се по еден час.');$throttle->hit($buckets,3600);
    }

    private function rateLimitPasswordChange(): void
    {
        $throttle=new LoginThrottle($this->db);$buckets=['password:user:'.(int)$this->auth->user()['id']];
        $throttle->assertAllowed($buckets,5,'Премногу обиди за промена на лозинката. Обидете се по 15 минути.');$throttle->hit($buckets,900);
    }

    private function health(): never
    {
        $this->authorizeOperationToken();

        $database = false;
        $schema = false;
        $missingTables = [];
        $requiredTables = [
            'users', 'roles', 'permissions', 'user_role_scopes', 'directorates', 'projects', 'progress_updates',
            'budget_allocations', 'commitments', 'expenditures', 'budget_change_requests',
            'workflow_instances', 'workflow_steps', 'risks', 'risk_actions', 'notifications', 'attachments',
            'import_batches', 'import_rows', 'audit_events', 'mayor_agenda_items',
            'procurement_projects', 'procurement_project_links',
            'enforcement_executions',
            'revenue_categories', 'revenue_category_rules', 'revenue_import_rows', 'revenue_transactions',
            'legal_case_parties', 'legal_case_procedures', 'legal_case_hearings', 'legal_case_assignments',
            'legal_work_reports', 'legal_work_activities', 'mayor_briefings', 'mayor_meeting_briefs', 'citizen_service_sectors', 'citizen_service_catalog', 'citizen_service_sector_reporters', 'citizen_service_item_reports', 'document_intakes', 'policy_areas',
            'citizen_services', 'citizen_service_metrics', 'citizen_service_weekly_reports',
            'inspection_reports',
            'citizen_service_weekly_values', 'citizen_service_transfers',
            'ai_technical_proposals', 'ai_reports', 'project_photos', 'project_user_access', 'task_user_access',
            'administrative_cases', 'case_directorates', 'case_forwardings', 'case_tasks', 'administrative_acts', 'administrative_act_relations',
            'case_actions', 'document_access_links', 'budget_line_directorate_access',
            'freebalance_program_directorates', 'budget_execution_lines', 'budget_execution_snapshots', 'budget_line_code_changes',
        ];

        try {
            $database = (int) $this->db->scalar('SELECT 1') === 1;
            foreach ($requiredTables as $table) {
                $exists = (int) $this->db->scalar(
                    'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME=:table',
                    ['table' => $table]
                ) > 0;
                if (!$exists) {
                    $missingTables[] = $table;
                }
            }
            $this->db->scalar('SELECT TOP 1 username FROM users');
            $this->db->scalar('SELECT TOP 1 provider_request_id FROM notifications');
            foreach ([
                ['budget_lines','budget_category'],['budget_lines','own_revenue'],['budget_lines','government_grant'],
                ['budget_lines','health_grant'],['budget_lines','education_grant'],['budget_lines','total_amount'],
                ['budget_lines','loan_financing'],['budget_lines','source_section_code'],
                ['budget_lines','imported_available_amount'],['budget_lines','imported_available_as_of'],['budget_lines','carryover_revenue'],['projects','budget_category'],['budget_lines','linked_project_id'],['budget_lines','program_code'],['budget_lines','approved_line_key'],
                ['project_tasks','starts_at'],['project_tasks','weight_percent'],['project_tasks','next_step'],
                ['project_photos','task_id'],
                ['mayor_agenda_items','item_type'],['mayor_agenda_items','project_id'],['mayor_agenda_items','risk_id'],['mayor_agenda_items','case_id'],
                ['mayor_agenda_items','topic_title'],['mayor_agenda_items','priority'],['mayor_agenda_items','status'],
                ['risks','residual_probability'],['risks','residual_impact'],
                ['risk_actions','measure_type'],['risk_actions','probability_reduction'],['risk_actions','impact_reduction'],
                ['risk_actions','evidence'],['risk_actions','completed_at'],['risk_actions','row_version'],
                ['users','session_epoch'],['users','last_digest_sent_at'],
            ] as [$table,$column]) {
                if (!(int)$this->db->scalar('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME=:table AND COLUMN_NAME=:column',['table'=>$table,'column'=>$column])) $missingTables[]=$table.'.'.$column;
            }
            $schema = $missingTables === [];
        } catch (\Throwable $exception) {
            $this->logger->write('error', 'health.database_failed', [
                'correlation_id' => $this->request->correlationId,
                'exception' => get_class($exception),
                'error' => $exception->getMessage(),
            ]);
        }

        $storage = is_dir(APP_ROOT.'/storage') && is_writable(APP_ROOT.'/storage');
        $healthy = $database && $schema && $storage;
        http_response_code($healthy ? 200 : 503);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, private');
        echo json_encode([
            'status' => $healthy ? 'ok' : 'degraded',
            'database' => $database,
            'schema' => $schema,
            'missing_tables' => $missingTables,
            'storage' => $storage,
            'php' => PHP_VERSION,
            'release' => trim((string) @file_get_contents(APP_ROOT.'/RELEASE')),
            'time_utc' => gmdate('c'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function runScheduler(): never
    {
        $this->authorizeOperationToken();
        $scheduler = new Scheduler($this->db, $this->notifications, $this->logger, APP_ROOT.'/storage', ($this->config['uploads']['clamav_binary'] ?? null) ?: null, $this->config);
        $result = $scheduler->run();
        http_response_code(($result['status'] ?? '') === 'ok' || ($result['status'] ?? '') === 'busy' ? 200 : 500);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, private');
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function runMigrations(): never
    {
        $this->authorizeOperationToken();
        $result=(new MigrationRunner($this->db,APP_ROOT.'/database/migrations'))->run();
        $this->logger->write('info','database.migrated',['correlation_id'=>$this->request->correlationId]+$result);
        header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store, private');
        echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
    }

    private function authorizeOperationToken(): void
    {
        $expected = trim((string) ($this->config['app']['health_token'] ?? ''));
        if ($expected === '' || !hash_equals($expected, $this->request->bearerToken())) {
            throw new HttpException(403, 'Забрането.');
        }
    }

    private function error(int $status,string $message): never
    {
        $locale=Translator::locale(isset($this->auth)?$this->auth->user():null);$message=Translator::text($message,$locale);
        http_response_code($status);
        if ($this->request->expectsJson()) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['message'=>$message,'correlation_id'=>$this->request->correlationId], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($this->request->method === 'POST' && $status < 500) {
            $_SESSION['_error'] = $message;
            header('Location: '.$this->request->safeReferer('/'), true, 303);
            exit;
        }
        $html='<!doctype html><html lang="mk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>'.View::e((string) $status).' · е-Кабинет</title><link rel="icon" href="/logo.png">'
            .'<link rel="stylesheet" href="/assets/app.css?v=2026.10.23.01"><link rel="stylesheet" href="/assets/public-pages.css?v=2026.10.23.01"></head><body>'
            .'<header class="site-header"><a class="brand" href="/"><img src="/logo.png" alt="" width="30" height="43"><span><strong>е-Кабинет</strong><small>Општина Битола</small></span></a></header>'
            .'<main class="page-shell"><section class="card narrow error-page"><h1>'.View::e((string) $status).'</h1><p>'.View::e($message).'</p>'
            .'<p><a class="button" href="/">Назад кон панелот</a></p></section></main></body></html>';
        echo Translator::html($html,$locale);
        exit;
    }
}
