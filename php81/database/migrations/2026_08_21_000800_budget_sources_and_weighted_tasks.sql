IF COL_LENGTH('dbo.budget_lines', 'budget_category') IS NULL
    ALTER TABLE dbo.budget_lines ADD budget_category nvarchar(40) NULL;
GO

IF COL_LENGTH('dbo.budget_lines', 'own_revenue') IS NULL
    ALTER TABLE dbo.budget_lines ADD own_revenue decimal(19,4) NOT NULL CONSTRAINT DF_budget_lines_own_revenue DEFAULT ((0)) WITH VALUES;
GO

IF COL_LENGTH('dbo.budget_lines', 'government_grant') IS NULL
    ALTER TABLE dbo.budget_lines ADD government_grant decimal(19,4) NOT NULL CONSTRAINT DF_budget_lines_government_grant DEFAULT ((0)) WITH VALUES;
GO

IF COL_LENGTH('dbo.budget_lines', 'health_grant') IS NULL
    ALTER TABLE dbo.budget_lines ADD health_grant decimal(19,4) NOT NULL CONSTRAINT DF_budget_lines_health_grant DEFAULT ((0)) WITH VALUES;
GO

IF COL_LENGTH('dbo.budget_lines', 'education_grant') IS NULL
    ALTER TABLE dbo.budget_lines ADD education_grant decimal(19,4) NOT NULL CONSTRAINT DF_budget_lines_education_grant DEFAULT ((0)) WITH VALUES;
GO

IF COL_LENGTH('dbo.budget_lines', 'total_amount') IS NULL
    ALTER TABLE dbo.budget_lines ADD total_amount AS (CONVERT(decimal(19,4), own_revenue + government_grant + health_grant + education_grant)) PERSISTED;
GO

IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'budget_lines_source_amounts_check')
    ALTER TABLE dbo.budget_lines WITH CHECK ADD CONSTRAINT budget_lines_source_amounts_check
        CHECK (own_revenue >= 0 AND government_grant >= 0 AND health_grant >= 0 AND education_grant >= 0);
GO

IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'budget_lines_category_check')
    ALTER TABLE dbo.budget_lines WITH CHECK ADD CONSTRAINT budget_lines_category_check
        CHECK (budget_category IS NULL OR budget_category IN (N'goods_services', N'capital_investments', N'wages', N'subsidies'));
GO

IF COL_LENGTH('dbo.project_tasks', 'starts_at') IS NULL
    ALTER TABLE dbo.project_tasks ADD starts_at date NULL;
GO

IF COL_LENGTH('dbo.project_tasks', 'weight_percent') IS NULL
    ALTER TABLE dbo.project_tasks ADD weight_percent decimal(5,2) NOT NULL CONSTRAINT DF_project_tasks_weight_percent DEFAULT ((0)) WITH VALUES;
GO

IF COL_LENGTH('dbo.project_tasks', 'next_step') IS NULL
    ALTER TABLE dbo.project_tasks ADD next_step nvarchar(max) NULL;
GO

IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'tasks_weight_percent_check')
    ALTER TABLE dbo.project_tasks WITH CHECK ADD CONSTRAINT tasks_weight_percent_check
        CHECK (weight_percent >= 0 AND weight_percent <= 100);
GO

IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'tasks_date_range_check')
    ALTER TABLE dbo.project_tasks WITH CHECK ADD CONSTRAINT tasks_date_range_check
        CHECK (starts_at IS NULL OR due_at IS NULL OR due_at >= starts_at);
GO
