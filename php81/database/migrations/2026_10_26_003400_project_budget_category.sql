-- Release 2026.10.26.05: budget category per project (forward-only, idempotent).
-- A project may only be linked to approved budget lines of its own category (capital investments, goods and services,
-- subsidies, wages, utilities), also when the line is shared by another directorate.
-- Existing projects take the most frequent category of the lines they are already linked to, otherwise capital investments.

IF COL_LENGTH('dbo.projects','budget_category') IS NULL
    ALTER TABLE dbo.projects ADD budget_category nvarchar(40) NULL;
GO
UPDATE p SET budget_category = COALESCE(x.budget_category, N'capital_investments')
FROM dbo.projects p
OUTER APPLY (
    SELECT TOP 1 bl.budget_category
    FROM dbo.budget_allocations ba JOIN dbo.budget_lines bl ON bl.id = ba.budget_line_id
    WHERE ba.project_id = p.id AND bl.budget_category IS NOT NULL
    GROUP BY bl.budget_category
    ORDER BY COUNT(*) DESC, bl.budget_category
) x
WHERE p.budget_category IS NULL;
GO
IF EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.projects') AND name = 'budget_category' AND is_nullable = 1)
    ALTER TABLE dbo.projects ALTER COLUMN budget_category nvarchar(40) NOT NULL;
GO
IF NOT EXISTS (SELECT 1 FROM sys.default_constraints WHERE name = 'DF_projects_budget_category')
    ALTER TABLE dbo.projects ADD CONSTRAINT DF_projects_budget_category DEFAULT (N'capital_investments') FOR budget_category;
GO
IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = 'CK_projects_budget_category')
    ALTER TABLE dbo.projects WITH CHECK ADD CONSTRAINT CK_projects_budget_category CHECK (budget_category IN (N'capital_investments', N'goods_services', N'subsidies', N'wages', N'utilities'));
GO
