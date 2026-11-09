IF EXISTS(SELECT 1 FROM sys.columns WHERE object_id=OBJECT_ID(N'dbo.projects') AND name=N'manager_id' AND is_nullable=1)
BEGIN
    UPDATE p
       SET manager_id=COALESCE(local_manager.id,global_manager.id,active_user.id)
      FROM dbo.projects p
      OUTER APPLY (
          SELECT TOP 1 u.id
            FROM dbo.users u
            JOIN dbo.user_role_scopes urs ON urs.user_id=u.id
            JOIN dbo.roles r ON r.id=urs.role_id
           WHERE u.status=N'active'
             AND r.code IN (N'director',N'directors_assistant',N'project_manager')
             AND urs.directorate_id=p.directorate_id
             AND (urs.valid_from IS NULL OR urs.valid_from<=SYSUTCDATETIME())
             AND (urs.valid_to IS NULL OR urs.valid_to>=SYSUTCDATETIME())
           ORDER BY CASE r.code WHEN N'director' THEN 0 WHEN N'project_manager' THEN 1 ELSE 2 END,u.id
      ) local_manager
      OUTER APPLY (
          SELECT TOP 1 u.id
            FROM dbo.users u
            JOIN dbo.user_role_scopes urs ON urs.user_id=u.id
            JOIN dbo.roles r ON r.id=urs.role_id
           WHERE u.status=N'active' AND r.code IN (N'mayor',N'mayor_admin',N'system_admin')
             AND (urs.valid_from IS NULL OR urs.valid_from<=SYSUTCDATETIME())
             AND (urs.valid_to IS NULL OR urs.valid_to>=SYSUTCDATETIME())
           ORDER BY CASE r.code WHEN N'system_admin' THEN 0 WHEN N'mayor' THEN 1 ELSE 2 END,u.id
      ) global_manager
      OUTER APPLY (SELECT TOP 1 id FROM dbo.users WHERE status=N'active' ORDER BY id) active_user
     WHERE p.manager_id IS NULL;

    IF EXISTS(SELECT 1 FROM dbo.projects WHERE manager_id IS NULL)
        THROW 51031, 'Projects without an owner remain because no active user exists.', 1;

    ALTER TABLE dbo.projects ALTER COLUMN manager_id bigint NOT NULL;
END;
GO

IF COL_LENGTH(N'dbo.mayor_agenda_items',N'case_id') IS NULL
    ALTER TABLE dbo.mayor_agenda_items ADD case_id bigint NULL;
GO

IF EXISTS(SELECT 1 FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID(N'dbo.mayor_agenda_items') AND name=N'mayor_agenda_items_type_check')
    ALTER TABLE dbo.mayor_agenda_items DROP CONSTRAINT mayor_agenda_items_type_check;
GO

IF NOT EXISTS(SELECT 1 FROM sys.foreign_keys WHERE parent_object_id=OBJECT_ID(N'dbo.mayor_agenda_items') AND name=N'FK_mayor_agenda_items_case')
    ALTER TABLE dbo.mayor_agenda_items ADD CONSTRAINT FK_mayor_agenda_items_case FOREIGN KEY(case_id) REFERENCES dbo.administrative_cases(id);
GO

IF NOT EXISTS(SELECT 1 FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID(N'dbo.mayor_agenda_items') AND name=N'mayor_agenda_items_type_check')
    ALTER TABLE dbo.mayor_agenda_items ADD CONSTRAINT mayor_agenda_items_type_check CHECK (
        (item_type=N'project' AND project_id IS NOT NULL AND risk_id IS NULL AND case_id IS NULL AND topic_title IS NULL)
        OR (item_type=N'risk' AND project_id IS NULL AND risk_id IS NOT NULL AND case_id IS NULL AND topic_title IS NULL)
        OR (item_type=N'case' AND project_id IS NULL AND risk_id IS NULL AND case_id IS NOT NULL AND topic_title IS NULL)
        OR (item_type=N'topic' AND project_id IS NULL AND risk_id IS NULL AND case_id IS NULL AND topic_title IS NOT NULL)
    );
GO

IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.mayor_agenda_items') AND name=N'UX_mayor_agenda_items_open_case')
    CREATE UNIQUE INDEX UX_mayor_agenda_items_open_case ON dbo.mayor_agenda_items(case_id) WHERE item_type=N'case' AND status=N'open';
GO
