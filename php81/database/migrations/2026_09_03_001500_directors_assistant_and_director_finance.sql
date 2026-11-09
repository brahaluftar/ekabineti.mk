SET XACT_ABORT ON;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.roles WHERE code=N'directors_assistant')
BEGIN
    INSERT INTO dbo.roles(code,name,financial_role,created_at,updated_at)
    VALUES(N'directors_assistant',N'Асистент на раководителот на сектор',1,SYSUTCDATETIME(),SYSUTCDATETIME());
END;
GO

DECLARE @assistant_role_id bigint=(SELECT id FROM dbo.roles WHERE code=N'directors_assistant');
DECLARE @director_role_id bigint=(SELECT id FROM dbo.roles WHERE code=N'director');

-- The assistant inherits the complete current director permission set.
INSERT INTO dbo.permission_role(permission_id,role_id)
SELECT source.permission_id,@assistant_role_id
FROM dbo.permission_role source
WHERE source.role_id=@director_role_id
  AND NOT EXISTS (
      SELECT 1 FROM dbo.permission_role existing
      WHERE existing.permission_id=source.permission_id AND existing.role_id=@assistant_role_id
  );

-- Directors and their assistants can administer execution inside their assigned directorate.
DECLARE @financial_grants TABLE(action nvarchar(80),resource_type nvarchar(80));
INSERT INTO @financial_grants(action,resource_type) VALUES
(N'create',N'allocation'),
(N'post',N'commitment'),
(N'post',N'expenditure');

INSERT INTO dbo.permission_role(permission_id,role_id)
SELECT p.id,r.id
FROM @financial_grants grant_row
JOIN dbo.permissions p ON p.action=grant_row.action AND p.resource_type=grant_row.resource_type
JOIN dbo.roles r ON r.code IN (N'director',N'directors_assistant')
WHERE NOT EXISTS (
    SELECT 1 FROM dbo.permission_role existing
    WHERE existing.permission_id=p.id AND existing.role_id=r.id
);
GO
