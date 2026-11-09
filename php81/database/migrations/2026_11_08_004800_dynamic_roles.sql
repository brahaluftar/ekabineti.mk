-- Release 2026.11.08.01: dynamic roles with chosen permissions (forward-only, idempotent).
-- * roles.base_role_code: a role created in the application is built on a system role. It behaves like that role where
--   the application checks roles by code (agenda, Legal Office, briefings, directorate scope …); its permissions
--   (permission_role) are chosen one by one. NULL for the system roles.
-- * roles.is_system: the roles delivered with the application (their code and base cannot change, they cannot be
--   deleted). roles.active: an inactive role gives no access and cannot be assigned. roles.description.
-- * Every permission the application checks exists in dbo.permissions, so it can be chosen for a role.

IF COL_LENGTH('dbo.roles','base_role_code') IS NULL ALTER TABLE dbo.roles ADD base_role_code nvarchar(50) NULL;
GO
IF COL_LENGTH('dbo.roles','description') IS NULL ALTER TABLE dbo.roles ADD description nvarchar(500) NULL;
GO
IF COL_LENGTH('dbo.roles','is_system') IS NULL
BEGIN
    ALTER TABLE dbo.roles ADD is_system bit NOT NULL CONSTRAINT DF_roles_is_system DEFAULT (0);
    EXEC(N'UPDATE dbo.roles SET is_system=1');
END;
GO
IF COL_LENGTH('dbo.roles','active') IS NULL ALTER TABLE dbo.roles ADD active bit NOT NULL CONSTRAINT DF_roles_active DEFAULT (1);
GO

DECLARE @permissions TABLE(action nvarchar(50),resource_type nvarchar(100),description nvarchar(255));
INSERT INTO @permissions VALUES
    (N'view',N'project',N'Прегледај проекти'),(N'create',N'project',N'Создавај и уредувај проекти'),(N'create',N'project_report',N'Известувај за напредокот на проектите'),(N'manage',N'project_share',N'Споделувај проекти и задачи со корисници'),
    (N'view',N'risk',N'Прегледај ризици'),(N'create',N'risk',N'Создавај и уредувај ризици'),
    (N'view',N'budget',N'Прегледај го буџетот'),(N'create',N'allocation',N'Создавај и уредувај буџетски распределби'),(N'view',N'execution',N'Прегледај извршување (обврски, расходи)'),(N'post',N'commitment',N'Евидентирај обврски'),(N'post',N'expenditure',N'Евидентирај расходи'),
    (N'view',N'budget_change',N'Прегледај ги буџетските промени'),(N'create',N'budget_change',N'Барај буџетски промени'),(N'approve',N'budget_change',N'Одобрувај буџетски промени'),
    (N'view',N'workflow',N'Прегледај текови на одобрување'),(N'decide',N'workflow',N'Одлучувај во тековите на одобрување'),
    (N'view',N'case',N'Прегледај управни предмети'),(N'create',N'case',N'Создавај и обработувај управни предмети'),
    (N'view',N'attachment',N'Прегледај документи'),(N'create',N'attachment',N'Прикачувај документи'),(N'view',N'document_link',N'Отворај документи преку врска'),
    (N'view',N'report',N'Прегледај извештаи'),(N'export',N'report',N'Извезувај извештаи'),
    (N'view',N'import',N'Прегледај увози'),(N'create',N'import',N'Увезувај податоци'),
    (N'view',N'legal_office',N'Прегледај го регистарот на Правната служба'),(N'create',N'legal_office',N'Работи со предметите на Правната служба'),(N'manage',N'legal_office',N'Администрирај ја Правната служба'),(N'export',N'legal_office',N'Извезувај од Правната служба'),
    (N'manage',N'user',N'Администрирај корисници и пристапи'),(N'view',N'audit',N'Прегледај ја ревизијата'),(N'view',N'system_log',N'Прегледај системски логови');
INSERT INTO dbo.permissions(action,resource_type,description,created_at,updated_at)
SELECT p.action,p.resource_type,p.description,SYSUTCDATETIME(),SYSUTCDATETIME() FROM @permissions p
WHERE NOT EXISTS (SELECT 1 FROM dbo.permissions x WHERE x.action=p.action AND x.resource_type=p.resource_type);
GO
