SET NOCOUNT ON;

IF NOT EXISTS (SELECT 1 FROM roles WHERE code='procurement_officer')
    INSERT INTO roles(code,name,financial_role,created_at,updated_at) VALUES('procurement_officer',N'Службеник за јавни набавки',0,SYSUTCDATETIME(),SYSUTCDATETIME());
IF NOT EXISTS (SELECT 1 FROM roles WHERE code='procurement_manager')
    INSERT INTO roles(code,name,financial_role,created_at,updated_at) VALUES('procurement_manager',N'Менаџер за јавни набавки',0,SYSUTCDATETIME(),SYSUTCDATETIME());
IF NOT EXISTS (SELECT 1 FROM roles WHERE code='legal_office')
    INSERT INTO roles(code,name,financial_role,created_at,updated_at) VALUES('legal_office',N'Правна служба',0,SYSUTCDATETIME(),SYSUTCDATETIME());
