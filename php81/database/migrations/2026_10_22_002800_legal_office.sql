SET XACT_ABORT ON;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.directorates WHERE code=N'PRAV')
    INSERT INTO dbo.directorates(code,name,active,created_at,updated_at) VALUES(N'PRAV',N'Правна служба',1,SYSUTCDATETIME(),SYSUTCDATETIME());
ELSE
    UPDATE dbo.directorates SET name=N'Правна служба',active=1,updated_at=SYSUTCDATETIME() WHERE code=N'PRAV';

DECLARE @legal_roles TABLE(code nvarchar(50),name nvarchar(120));
INSERT INTO @legal_roles VALUES
(N'legal_director',N'Раководител на Правната служба'),
(N'senior_legal_officer',N'Виш правен службеник'),
(N'legal_officer',N'Правен службеник'),
(N'legal_assistant',N'Асистент на Правната служба');
INSERT INTO dbo.roles(code,name,financial_role,created_at,updated_at)
SELECT source.code,source.name,0,SYSUTCDATETIME(),SYSUTCDATETIME() FROM @legal_roles source
WHERE NOT EXISTS(SELECT 1 FROM dbo.roles target WHERE target.code=source.code);

IF NOT EXISTS (SELECT 1 FROM dbo.permissions WHERE action=N'view' AND resource_type=N'legal_office')
    INSERT INTO dbo.permissions(action,resource_type,description,created_at,updated_at) VALUES(N'view',N'legal_office',N'View Legal Office registry',SYSUTCDATETIME(),SYSUTCDATETIME());
IF NOT EXISTS (SELECT 1 FROM dbo.permissions WHERE action=N'create' AND resource_type=N'legal_office')
    INSERT INTO dbo.permissions(action,resource_type,description,created_at,updated_at) VALUES(N'create',N'legal_office',N'Work with Legal Office cases',SYSUTCDATETIME(),SYSUTCDATETIME());
IF NOT EXISTS (SELECT 1 FROM dbo.permissions WHERE action=N'manage' AND resource_type=N'legal_office')
    INSERT INTO dbo.permissions(action,resource_type,description,created_at,updated_at) VALUES(N'manage',N'legal_office',N'Manage Legal Office assignments and users',SYSUTCDATETIME(),SYSUTCDATETIME());
IF NOT EXISTS (SELECT 1 FROM dbo.permissions WHERE action=N'export' AND resource_type=N'legal_office')
    INSERT INTO dbo.permissions(action,resource_type,description,created_at,updated_at) VALUES(N'export',N'legal_office',N'Export Legal Office reports',SYSUTCDATETIME(),SYSUTCDATETIME());

INSERT INTO dbo.permission_role(permission_id,role_id)
SELECT p.id,r.id FROM dbo.permissions p CROSS JOIN dbo.roles r
WHERE r.code IN(N'legal_director',N'senior_legal_officer',N'legal_officer',N'legal_assistant',N'legal_office')
  AND ((p.resource_type=N'legal_office' AND p.action IN(N'view',N'create',N'export')) OR (p.resource_type=N'attachment' AND p.action IN(N'view',N'create')))
  AND NOT EXISTS(SELECT 1 FROM dbo.permission_role pr WHERE pr.permission_id=p.id AND pr.role_id=r.id);
INSERT INTO dbo.permission_role(permission_id,role_id)
SELECT p.id,r.id FROM dbo.permissions p JOIN dbo.roles r ON r.code=N'legal_director'
WHERE p.resource_type=N'legal_office' AND p.action=N'manage'
  AND NOT EXISTS(SELECT 1 FROM dbo.permission_role pr WHERE pr.permission_id=p.id AND pr.role_id=r.id);

DECLARE @zligj bigint=(SELECT id FROM dbo.directorates WHERE code=N'PRAV');
UPDATE urs SET directorate_id=@zligj,updated_at=SYSUTCDATETIME()
FROM dbo.user_role_scopes urs JOIN dbo.roles r ON r.id=urs.role_id
WHERE r.code=N'legal_office' AND urs.directorate_id IS NULL;
GO

IF COL_LENGTH('dbo.administrative_cases','case_uuid') IS NULL ALTER TABLE dbo.administrative_cases ADD case_uuid uniqueidentifier NULL;
IF COL_LENGTH('dbo.administrative_cases','internal_sequence') IS NULL ALTER TABLE dbo.administrative_cases ADD internal_sequence bigint NULL;
IF COL_LENGTH('dbo.administrative_cases','is_legal') IS NULL ALTER TABLE dbo.administrative_cases ADD is_legal bit NOT NULL CONSTRAINT DF_administrative_cases_is_legal DEFAULT(0);
IF COL_LENGTH('dbo.administrative_cases','external_reference') IS NULL ALTER TABLE dbo.administrative_cases ADD external_reference nvarchar(150) NULL;
IF COL_LENGTH('dbo.administrative_cases','protocol_number') IS NULL ALTER TABLE dbo.administrative_cases ADD protocol_number nvarchar(120) NULL;
IF COL_LENGTH('dbo.administrative_cases','source_name') IS NULL ALTER TABLE dbo.administrative_cases ADD source_name nvarchar(200) NULL;
IF COL_LENGTH('dbo.administrative_cases','court_reference') IS NULL ALTER TABLE dbo.administrative_cases ADD court_reference nvarchar(150) NULL;
IF COL_LENGTH('dbo.administrative_cases','authority_name') IS NULL ALTER TABLE dbo.administrative_cases ADD authority_name nvarchar(250) NULL;
IF COL_LENGTH('dbo.administrative_cases','procedural_phase') IS NULL ALTER TABLE dbo.administrative_cases ADD procedural_phase nvarchar(30) NULL;
IF COL_LENGTH('dbo.administrative_cases','outcome') IS NULL ALTER TABLE dbo.administrative_cases ADD outcome nvarchar(2000) NULL;
IF COL_LENGTH('dbo.administrative_cases','deputy_user_id') IS NULL ALTER TABLE dbo.administrative_cases ADD deputy_user_id bigint NULL;
IF COL_LENGTH('dbo.administrative_cases','assigned_at') IS NULL ALTER TABLE dbo.administrative_cases ADD assigned_at datetime2 NULL;
IF COL_LENGTH('dbo.administrative_cases','project_id') IS NULL ALTER TABLE dbo.administrative_cases ADD project_id bigint NULL;
IF COL_LENGTH('dbo.administrative_cases','procurement_project_id') IS NULL ALTER TABLE dbo.administrative_cases ADD procurement_project_id bigint NULL;
IF COL_LENGTH('dbo.administrative_cases','budget_line_id') IS NULL ALTER TABLE dbo.administrative_cases ADD budget_line_id bigint NULL;
IF COL_LENGTH('dbo.administrative_cases','financial_direction') IS NULL ALTER TABLE dbo.administrative_cases ADD financial_direction nvarchar(20) NULL;
IF COL_LENGTH('dbo.administrative_cases','claimed_amount') IS NULL ALTER TABLE dbo.administrative_cases ADD claimed_amount decimal(19,4) NULL;
IF COL_LENGTH('dbo.administrative_cases','recognized_amount') IS NULL ALTER TABLE dbo.administrative_cases ADD recognized_amount decimal(19,4) NULL;
IF COL_LENGTH('dbo.administrative_cases','realized_amount') IS NULL ALTER TABLE dbo.administrative_cases ADD realized_amount decimal(19,4) NULL;
IF COL_LENGTH('dbo.administrative_cases','currency') IS NULL ALTER TABLE dbo.administrative_cases ADD currency char(3) NOT NULL CONSTRAINT DF_administrative_cases_currency DEFAULT('MKD');
IF COL_LENGTH('dbo.administrative_cases','confidentiality') IS NULL ALTER TABLE dbo.administrative_cases ADD confidentiality nvarchar(20) NOT NULL CONSTRAINT DF_administrative_cases_confidentiality DEFAULT(N'internal');
GO

UPDATE dbo.administrative_cases SET case_uuid=NEWID() WHERE case_uuid IS NULL;
UPDATE dbo.administrative_cases SET internal_sequence=id WHERE is_legal=1 AND internal_sequence IS NULL;
ALTER TABLE dbo.administrative_cases ALTER COLUMN case_uuid uniqueidentifier NOT NULL;
IF NOT EXISTS(SELECT 1 FROM sys.default_constraints WHERE parent_object_id=OBJECT_ID('dbo.administrative_cases') AND name='DF_administrative_cases_uuid')
    ALTER TABLE dbo.administrative_cases ADD CONSTRAINT DF_administrative_cases_uuid DEFAULT(NEWSEQUENTIALID()) FOR case_uuid;
IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.administrative_cases') AND name='UQ_administrative_cases_uuid') CREATE UNIQUE INDEX UQ_administrative_cases_uuid ON dbo.administrative_cases(case_uuid);
IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.administrative_cases') AND name='UQ_administrative_cases_sequence') CREATE UNIQUE INDEX UQ_administrative_cases_sequence ON dbo.administrative_cases(internal_sequence) WHERE internal_sequence IS NOT NULL;
IF NOT EXISTS(SELECT 1 FROM sys.foreign_keys WHERE name='FK_administrative_cases_deputy') ALTER TABLE dbo.administrative_cases ADD CONSTRAINT FK_administrative_cases_deputy FOREIGN KEY(deputy_user_id) REFERENCES dbo.users(id);
IF NOT EXISTS(SELECT 1 FROM sys.foreign_keys WHERE name='FK_administrative_cases_project') ALTER TABLE dbo.administrative_cases ADD CONSTRAINT FK_administrative_cases_project FOREIGN KEY(project_id) REFERENCES dbo.projects(id);
IF NOT EXISTS(SELECT 1 FROM sys.foreign_keys WHERE name='FK_administrative_cases_procurement') ALTER TABLE dbo.administrative_cases ADD CONSTRAINT FK_administrative_cases_procurement FOREIGN KEY(procurement_project_id) REFERENCES dbo.procurement_projects(id);
IF NOT EXISTS(SELECT 1 FROM sys.foreign_keys WHERE name='FK_administrative_cases_budget_line') ALTER TABLE dbo.administrative_cases ADD CONSTRAINT FK_administrative_cases_budget_line FOREIGN KEY(budget_line_id) REFERENCES dbo.budget_lines(id);
GO

IF OBJECT_ID('dbo.legal_case_parties','U') IS NULL
BEGIN
 CREATE TABLE dbo.legal_case_parties(id bigint IDENTITY PRIMARY KEY,case_id bigint NOT NULL,party_name nvarchar(300) NOT NULL,party_role nvarchar(40) NOT NULL,external_identifier nvarchar(120) NULL,contact_details nvarchar(500) NULL,representative nvarchar(250) NULL,created_by bigint NOT NULL,created_at datetime2 NOT NULL CONSTRAINT DF_legal_case_parties_created DEFAULT SYSUTCDATETIME(),CONSTRAINT FK_legal_case_parties_case FOREIGN KEY(case_id) REFERENCES dbo.administrative_cases(id),CONSTRAINT FK_legal_case_parties_user FOREIGN KEY(created_by) REFERENCES dbo.users(id));
 CREATE INDEX IX_legal_case_parties_case ON dbo.legal_case_parties(case_id);
END;
IF OBJECT_ID('dbo.legal_case_procedures','U') IS NULL
BEGIN
 CREATE TABLE dbo.legal_case_procedures(id bigint IDENTITY PRIMARY KEY,case_id bigint NOT NULL,procedure_type nvarchar(30) NOT NULL,phase nvarchar(30) NOT NULL,status nvarchar(30) NOT NULL,external_reference nvarchar(150) NULL,authority_name nvarchar(250) NULL,started_at date NULL,ended_at date NULL,outcome nvarchar(2000) NULL,procurement_project_id bigint NULL,budget_line_id bigint NULL,financial_direction nvarchar(20) NULL,obligation_reference nvarchar(150) NULL,principal_amount decimal(19,4) NOT NULL CONSTRAINT DF_legal_procedure_principal DEFAULT(0),interest_amount decimal(19,4) NOT NULL CONSTRAINT DF_legal_procedure_interest DEFAULT(0),cost_amount decimal(19,4) NOT NULL CONSTRAINT DF_legal_procedure_cost DEFAULT(0),realized_amount decimal(19,4) NOT NULL CONSTRAINT DF_legal_procedure_realized DEFAULT(0),created_by bigint NOT NULL,created_at datetime2 NOT NULL CONSTRAINT DF_legal_procedure_created DEFAULT SYSUTCDATETIME(),updated_at datetime2 NOT NULL CONSTRAINT DF_legal_procedure_updated DEFAULT SYSUTCDATETIME(),row_version rowversion NOT NULL,CONSTRAINT FK_legal_procedure_case FOREIGN KEY(case_id) REFERENCES dbo.administrative_cases(id),CONSTRAINT FK_legal_procedure_procurement FOREIGN KEY(procurement_project_id) REFERENCES dbo.procurement_projects(id),CONSTRAINT FK_legal_procedure_budget FOREIGN KEY(budget_line_id) REFERENCES dbo.budget_lines(id),CONSTRAINT FK_legal_procedure_user FOREIGN KEY(created_by) REFERENCES dbo.users(id),CONSTRAINT CK_legal_procedure_amounts CHECK(principal_amount>=0 AND interest_amount>=0 AND cost_amount>=0 AND realized_amount>=0));
 CREATE INDEX IX_legal_procedure_case ON dbo.legal_case_procedures(case_id,status,phase);
END;
IF OBJECT_ID('dbo.legal_case_hearings','U') IS NULL
BEGIN
 CREATE TABLE dbo.legal_case_hearings(id bigint IDENTITY PRIMARY KEY,case_id bigint NOT NULL,procedure_id bigint NULL,scheduled_at datetime2 NOT NULL,authority_name nvarchar(250) NOT NULL,venue_mode nvarchar(250) NULL,responsible_user_id bigint NULL,status nvarchar(30) NOT NULL,minutes_attachment_id bigint NULL,notes nvarchar(2000) NULL,created_by bigint NOT NULL,created_at datetime2 NOT NULL CONSTRAINT DF_legal_hearing_created DEFAULT SYSUTCDATETIME(),CONSTRAINT FK_legal_hearing_case FOREIGN KEY(case_id) REFERENCES dbo.administrative_cases(id),CONSTRAINT FK_legal_hearing_procedure FOREIGN KEY(procedure_id) REFERENCES dbo.legal_case_procedures(id),CONSTRAINT FK_legal_hearing_responsible FOREIGN KEY(responsible_user_id) REFERENCES dbo.users(id),CONSTRAINT FK_legal_hearing_attachment FOREIGN KEY(minutes_attachment_id) REFERENCES dbo.attachments(id),CONSTRAINT FK_legal_hearing_creator FOREIGN KEY(created_by) REFERENCES dbo.users(id));
 CREATE INDEX IX_legal_hearing_schedule ON dbo.legal_case_hearings(scheduled_at,status);
END;
IF OBJECT_ID('dbo.legal_case_assignments','U') IS NULL
BEGIN
 CREATE TABLE dbo.legal_case_assignments(id bigint IDENTITY PRIMARY KEY,case_id bigint NOT NULL,responsible_user_id bigint NOT NULL,deputy_user_id bigint NULL,valid_from datetime2 NOT NULL,valid_to datetime2 NULL,assigned_by bigint NOT NULL,reason nvarchar(1000) NOT NULL,created_at datetime2 NOT NULL CONSTRAINT DF_legal_assignment_created DEFAULT SYSUTCDATETIME(),CONSTRAINT FK_legal_assignment_case FOREIGN KEY(case_id) REFERENCES dbo.administrative_cases(id),CONSTRAINT FK_legal_assignment_responsible FOREIGN KEY(responsible_user_id) REFERENCES dbo.users(id),CONSTRAINT FK_legal_assignment_deputy FOREIGN KEY(deputy_user_id) REFERENCES dbo.users(id),CONSTRAINT FK_legal_assignment_assigner FOREIGN KEY(assigned_by) REFERENCES dbo.users(id));
 CREATE INDEX IX_legal_assignment_case ON dbo.legal_case_assignments(case_id,valid_to);
END;
GO

IF COL_LENGTH('dbo.case_tasks','task_type') IS NULL ALTER TABLE dbo.case_tasks ADD task_type nvarchar(20) NOT NULL CONSTRAINT DF_case_tasks_type DEFAULT(N'task');
IF COL_LENGTH('dbo.case_tasks','starts_at') IS NULL ALTER TABLE dbo.case_tasks ADD starts_at date NULL;
IF COL_LENGTH('dbo.case_tasks','legal_basis') IS NULL ALTER TABLE dbo.case_tasks ADD legal_basis nvarchar(1000) NULL;
IF COL_LENGTH('dbo.case_tasks','source_attachment_id') IS NULL ALTER TABLE dbo.case_tasks ADD source_attachment_id bigint NULL;
IF NOT EXISTS(SELECT 1 FROM sys.foreign_keys WHERE name='FK_case_tasks_source_attachment') ALTER TABLE dbo.case_tasks ADD CONSTRAINT FK_case_tasks_source_attachment FOREIGN KEY(source_attachment_id) REFERENCES dbo.attachments(id);
IF COL_LENGTH('dbo.enforcement_executions','administrative_case_id') IS NULL ALTER TABLE dbo.enforcement_executions ADD administrative_case_id bigint NULL;
IF COL_LENGTH('dbo.enforcement_executions','legal_procedure_id') IS NULL ALTER TABLE dbo.enforcement_executions ADD legal_procedure_id bigint NULL;
IF NOT EXISTS(SELECT 1 FROM sys.foreign_keys WHERE name='FK_enforcement_execution_case') ALTER TABLE dbo.enforcement_executions ADD CONSTRAINT FK_enforcement_execution_case FOREIGN KEY(administrative_case_id) REFERENCES dbo.administrative_cases(id);
IF NOT EXISTS(SELECT 1 FROM sys.foreign_keys WHERE name='FK_enforcement_execution_procedure') ALTER TABLE dbo.enforcement_executions ADD CONSTRAINT FK_enforcement_execution_procedure FOREIGN KEY(legal_procedure_id) REFERENCES dbo.legal_case_procedures(id);
GO
