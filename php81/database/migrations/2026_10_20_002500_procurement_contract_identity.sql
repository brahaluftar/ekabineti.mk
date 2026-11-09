IF EXISTS(SELECT 1 FROM sys.key_constraints WHERE parent_object_id=OBJECT_ID(N'dbo.procurement_projects') AND name=N'UQ_procurement_projects_code')
    ALTER TABLE dbo.procurement_projects DROP CONSTRAINT UQ_procurement_projects_code;

IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.procurement_projects') AND name=N'UX_procurement_projects_procurement_contract')
    CREATE UNIQUE INDEX UX_procurement_projects_procurement_contract ON dbo.procurement_projects(procurement_code,contract_code);
