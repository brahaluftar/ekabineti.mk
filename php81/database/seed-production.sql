SET XACT_ABORT ON;
GO

INSERT INTO dbo.roles (code,name,financial_role,created_at,updated_at) VALUES
(N'mayor',N'Градоначалник',1,SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'mayor_admin',N'Администратор на Градоначалникот',0,SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'deputy_mayor',N'Заменик-градоначалник',1,SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'director',N'Раководител на сектор',1,SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'budget_officer',N'Буџетски службеник',1,SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'project_manager',N'Менаџер на проект',0,SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'reporter',N'Известувач',0,SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'finance',N'Финансии',1,SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'procurement_officer',N'Службеник за јавни набавки',0,SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'procurement_manager',N'Менаџер за јавни набавки',0,SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'legal_office',N'Правна служба',0,SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'auditor',N'Ревизор',0,SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'system_admin',N'Администратор на системот',0,SYSUTCDATETIME(),SYSUTCDATETIME());
GO

INSERT INTO dbo.permissions (action,resource_type,description,created_at,updated_at) VALUES
(N'view',N'project',N'view project',SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'create',N'project',N'create project',SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'view',N'budget',N'view budget',SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'create',N'allocation',N'create allocation',SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'view',N'execution',N'view execution',SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'post',N'commitment',N'post commitment',SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'post',N'expenditure',N'post expenditure',SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'view',N'budget_change',N'view budget_change',SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'create',N'budget_change',N'create budget_change',SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'approve',N'budget_change',N'approve budget_change',SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'view',N'workflow',N'view workflow',SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'decide',N'workflow',N'decide workflow',SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'view',N'risk',N'view risk',SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'create',N'risk',N'create risk',SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'view',N'report',N'view report',SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'create',N'project_report',N'create project_report',SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'export',N'report',N'export report',SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'view',N'import',N'view import',SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'create',N'import',N'create import',SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'view',N'attachment',N'view attachment',SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'create',N'attachment',N'create attachment',SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'view',N'audit',N'view audit',SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'view',N'system_log',N'view system_log',SYSUTCDATETIME(),SYSUTCDATETIME());
GO

INSERT INTO dbo.permission_role (permission_id,role_id)
SELECT p.id,r.id FROM dbo.permissions p CROSS JOIN dbo.roles r
WHERE r.code IN (N'mayor',N'finance') AND NOT (p.action=N'view' AND p.resource_type=N'system_log');
GO

DECLARE @grants TABLE(role_code nvarchar(50),action nvarchar(80),resource_type nvarchar(80));
INSERT INTO @grants VALUES
(N'mayor_admin',N'view',N'project'),(N'mayor_admin',N'create',N'project'),(N'mayor_admin',N'view',N'budget'),(N'mayor_admin',N'view',N'budget_change'),(N'mayor_admin',N'create',N'budget_change'),(N'mayor_admin',N'view',N'workflow'),(N'mayor_admin',N'decide',N'workflow'),(N'mayor_admin',N'view',N'risk'),(N'mayor_admin',N'create',N'risk'),(N'mayor_admin',N'view',N'report'),(N'mayor_admin',N'create',N'project_report'),(N'mayor_admin',N'export',N'report'),(N'mayor_admin',N'view',N'import'),(N'mayor_admin',N'create',N'import'),(N'mayor_admin',N'view',N'attachment'),(N'mayor_admin',N'create',N'attachment'),(N'mayor_admin',N'view',N'audit'),
(N'deputy_mayor',N'view',N'project'),(N'deputy_mayor',N'create',N'project'),(N'deputy_mayor',N'view',N'budget'),(N'deputy_mayor',N'view',N'execution'),(N'deputy_mayor',N'view',N'budget_change'),(N'deputy_mayor',N'approve',N'budget_change'),(N'deputy_mayor',N'view',N'workflow'),(N'deputy_mayor',N'decide',N'workflow'),(N'deputy_mayor',N'view',N'risk'),(N'deputy_mayor',N'create',N'risk'),(N'deputy_mayor',N'view',N'report'),(N'deputy_mayor',N'export',N'report'),
(N'director',N'view',N'project'),(N'director',N'create',N'project'),(N'director',N'view',N'budget'),(N'director',N'view',N'execution'),(N'director',N'view',N'budget_change'),(N'director',N'create',N'budget_change'),(N'director',N'view',N'workflow'),(N'director',N'decide',N'workflow'),(N'director',N'view',N'risk'),(N'director',N'create',N'risk'),(N'director',N'view',N'report'),(N'director',N'create',N'project_report'),(N'director',N'export',N'report'),(N'director',N'view',N'import'),(N'director',N'create',N'import'),(N'director',N'view',N'attachment'),(N'director',N'create',N'attachment'),
(N'budget_officer',N'view',N'project'),(N'budget_officer',N'view',N'budget'),(N'budget_officer',N'create',N'allocation'),(N'budget_officer',N'view',N'execution'),(N'budget_officer',N'post',N'commitment'),(N'budget_officer',N'post',N'expenditure'),(N'budget_officer',N'view',N'budget_change'),(N'budget_officer',N'create',N'budget_change'),(N'budget_officer',N'view',N'workflow'),(N'budget_officer',N'view',N'risk'),(N'budget_officer',N'view',N'report'),(N'budget_officer',N'export',N'report'),(N'budget_officer',N'view',N'import'),(N'budget_officer',N'create',N'import'),(N'budget_officer',N'view',N'attachment'),(N'budget_officer',N'create',N'attachment'),
(N'project_manager',N'view',N'project'),(N'project_manager',N'create',N'project'),(N'project_manager',N'view',N'budget'),(N'project_manager',N'view',N'risk'),(N'project_manager',N'create',N'risk'),(N'project_manager',N'view',N'report'),(N'project_manager',N'create',N'project_report'),(N'project_manager',N'view',N'attachment'),(N'project_manager',N'create',N'attachment'),
(N'reporter',N'view',N'project'),(N'reporter',N'view',N'risk'),(N'reporter',N'create',N'risk'),(N'reporter',N'view',N'report'),(N'reporter',N'create',N'project_report'),(N'reporter',N'view',N'attachment'),(N'reporter',N'create',N'attachment'),
(N'auditor',N'view',N'project'),(N'auditor',N'view',N'budget'),(N'auditor',N'view',N'execution'),(N'auditor',N'view',N'budget_change'),(N'auditor',N'view',N'workflow'),(N'auditor',N'view',N'risk'),(N'auditor',N'view',N'report'),(N'auditor',N'export',N'report'),(N'auditor',N'view',N'attachment'),(N'auditor',N'view',N'audit'),
(N'system_admin',N'view',N'audit'),(N'system_admin',N'view',N'system_log');
INSERT INTO dbo.permission_role(permission_id,role_id)
SELECT p.id,r.id FROM @grants g JOIN dbo.roles r ON r.code=g.role_code JOIN dbo.permissions p ON p.action=g.action AND p.resource_type=g.resource_type;
GO

-- Почетна организациска поставеност на Општина Битола (може да се менува во Администрација → Сектори).
-- Шифрите FIN, PRAV и INS ги користи апликацијата: FIN = финансиска улога на ниво на Општина, PRAV = Правна служба,
-- INS = известување на инспекцијата. Другите шифри се слободни и може да се преименуваат или деактивираат.
INSERT INTO dbo.directorates(code,name,active,created_at,updated_at) VALUES
(N'KAB',N'Кабинет на Градоначалникот',1,SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'FIN',N'Сектор за финансиски прашања',1,SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'PRAV',N'Сектор за правни и општи работи – Правна служба',1,SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'URB',N'Сектор за урбанизам и заштита на животната средина',1,SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'KOM',N'Сектор за комунални работи',1,SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'KI',N'Сектор за капитални инвестиции и инфраструктура',1,SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'LER',N'Сектор за локален економски развој',1,SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'JD',N'Сектор за јавни дејности',1,SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'IMOT',N'Сектор за имотно-правни работи',1,SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'INS',N'Сектор за инспекциски надзор',1,SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'JN',N'Одделение за јавни набавки',1,SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'IT',N'Одделение за информатичка технологија',1,SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'CUG',N'Центар за услуги на граѓаните',1,SYSUTCDATETIME(),SYSUTCDATETIME()),
(N'REV',N'Одделение за внатрешна ревизија',1,SYSUTCDATETIME(),SYSUTCDATETIME());
GO

-- Пример буџетска програма (пример; вистинските програми се внесуваат или увезуваат од одобрениот буџет).
DECLARE @it bigint=(SELECT id FROM dbo.directorates WHERE code=N'IT');
INSERT INTO dbo.programs(directorate_id,parent_id,type,code,name,valid_from,valid_to,active,created_at,updated_at)
VALUES(@it,NULL,N'program',N'D0',N'Дигитализација на општинските услуги',NULL,NULL,1,SYSUTCDATETIME(),SYSUTCDATETIME());
DECLARE @parent bigint=SCOPE_IDENTITY();
INSERT INTO dbo.programs(directorate_id,parent_id,type,code,name,valid_from,valid_to,active,created_at,updated_at)
VALUES(@it,@parent,N'subcategory',N'D01',N'Електронски услуги',NULL,NULL,1,SYSUTCDATETIME(),SYSUTCDATETIME());
GO

INSERT INTO dbo.migrations(migration,batch) VALUES
(N'0001_01_01_000000_create_users_table',1),
(N'0001_01_01_000001_create_cache_table',1),
(N'0001_01_01_000002_create_jobs_table',1),
(N'2026_08_20_000100_create_identity_and_organization_tables',1),
(N'2026_08_20_000200_create_budget_and_project_tables',1),
(N'2026_08_20_000300_create_execution_workflow_and_risk_tables',1),
(N'2026_08_20_000400_create_reporting_notification_and_audit_tables',1),
(N'2026_08_20_000500_add_directorate_scope_to_workflows',1),
(N'2026_08_21_000600_use_username_or_email_for_authentication',1),
(N'2026_08_21_000700_add_delivery_tracking_to_notifications',1),
(N'2026_08_21_000800_budget_sources_and_weighted_tasks',1),
(N'2026_08_21_000900_mayor_discussion_agenda',1),
(N'2026_08_21_001000_risk_measures_and_residual_scoring',1);
GO
