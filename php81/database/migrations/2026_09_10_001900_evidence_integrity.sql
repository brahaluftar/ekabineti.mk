IF OBJECT_ID('dbo.case_user_access','U') IS NULL
CREATE TABLE dbo.case_user_access(
 case_id bigint NOT NULL REFERENCES dbo.administrative_cases(id),
 user_id bigint NOT NULL REFERENCES dbo.users(id),
 granted_by bigint NOT NULL REFERENCES dbo.users(id),
 created_at datetime2 NOT NULL DEFAULT SYSUTCDATETIME(),
 PRIMARY KEY(case_id,user_id)
);
GO
IF COL_LENGTH('dbo.case_tasks','parent_task_id') IS NULL
 ALTER TABLE dbo.case_tasks ADD parent_task_id bigint NULL REFERENCES dbo.case_tasks(id);
GO
IF COL_LENGTH('dbo.administrative_acts','latitude') IS NULL
 ALTER TABLE dbo.administrative_acts ADD latitude decimal(10,7) NULL,longitude decimal(10,7) NULL,google_maps_url nvarchar(1000) NULL;
GO
IF COL_LENGTH('dbo.budget_change_requests','latitude') IS NULL
 ALTER TABLE dbo.budget_change_requests ADD latitude decimal(10,7) NULL,longitude decimal(10,7) NULL,google_maps_url nvarchar(1000) NULL;
GO
IF COL_LENGTH('dbo.workflow_instances','latitude') IS NULL
 ALTER TABLE dbo.workflow_instances ADD latitude decimal(10,7) NULL,longitude decimal(10,7) NULL,google_maps_url nvarchar(1000) NULL;
GO
IF NOT EXISTS(SELECT 1 FROM sys.check_constraints WHERE name='CK_projects_geo')
 ALTER TABLE dbo.projects ADD CONSTRAINT CK_projects_geo CHECK((latitude IS NULL AND longitude IS NULL) OR (latitude IS NOT NULL AND longitude IS NOT NULL AND latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180));
GO
IF NOT EXISTS(SELECT 1 FROM sys.check_constraints WHERE name='CK_acts_geo')
 ALTER TABLE dbo.administrative_acts ADD CONSTRAINT CK_acts_geo CHECK((latitude IS NULL AND longitude IS NULL) OR (latitude IS NOT NULL AND longitude IS NOT NULL AND latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180));
GO
CREATE OR ALTER TRIGGER dbo.tr_administrative_acts_immutable ON dbo.administrative_acts INSTEAD OF UPDATE,DELETE AS
BEGIN
 THROW 51001,N'Управните акти не се менуваат и не се бришат. Евидентирајте нов поврзан акт.',1;
END;
GO
CREATE OR ALTER TRIGGER dbo.tr_case_actions_immutable ON dbo.case_actions INSTEAD OF UPDATE,DELETE AS
BEGIN
 THROW 51002,N'Хронологијата не може да се менува или брише.',1;
END;
GO
CREATE OR ALTER TRIGGER dbo.tr_act_relations_immutable ON dbo.administrative_act_relations INSTEAD OF UPDATE,DELETE AS
BEGIN
 THROW 51003,N'Врските меѓу актите не се менуваат и не се бришат.',1;
END;
GO
INSERT INTO dbo.permission_role(permission_id,role_id)
SELECT p.id,r.id FROM dbo.permissions p CROSS JOIN dbo.roles r
WHERE ((p.resource_type='case' AND r.code IN('system_admin','mayor','mayor_admin','director','directors_assistant','project_manager'))
 OR (p.resource_type='project_share' AND r.code IN('system_admin','mayor','director','project_manager'))
 OR (p.resource_type='document_link' AND r.code IN('mayor','director')))
AND NOT EXISTS(SELECT 1 FROM dbo.permission_role pr WHERE pr.permission_id=p.id AND pr.role_id=r.id);
GO
