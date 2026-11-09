IF COL_LENGTH('dbo.budget_lines','imported_available_amount') IS NULL
    ALTER TABLE dbo.budget_lines ADD imported_available_amount decimal(19,4) NULL;
GO

IF COL_LENGTH('dbo.budget_lines','imported_available_as_of') IS NULL
    ALTER TABLE dbo.budget_lines ADD imported_available_as_of date NULL;
GO

IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name=N'budget_lines_imported_available_check')
    ALTER TABLE dbo.budget_lines WITH CHECK ADD CONSTRAINT budget_lines_imported_available_check
        CHECK (imported_available_amount IS NULL OR imported_available_amount >= 0);
GO

INSERT INTO dbo.permission_role(permission_id,role_id)
SELECT p.id,r.id
FROM dbo.roles r
CROSS JOIN dbo.permissions p
WHERE r.code=N'budget_officer'
  AND ((p.action=N'view' AND p.resource_type=N'import') OR (p.action=N'create' AND p.resource_type=N'import'))
  AND NOT EXISTS (
      SELECT 1 FROM dbo.permission_role pr WHERE pr.permission_id=p.id AND pr.role_id=r.id
  );
GO

