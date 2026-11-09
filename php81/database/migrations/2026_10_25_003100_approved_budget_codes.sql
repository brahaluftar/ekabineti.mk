-- Release 2026.10.25.02: approved budget workbook import and budget-code alignment (forward-only, idempotent).
-- 1. budget_lines.carryover_revenue holds fund 22 (own revenue carried over from the previous year); total_amount includes it.
-- 2. budget_lines.program_code / project_code / approved_line_key / staff_count tie each line to the FreeBalance coding block.
-- 3. budget_line_code_changes keeps every budget-code change made by the import (old code → new code), next to audit_events.

IF COL_LENGTH('dbo.budget_lines','carryover_revenue') IS NULL
    ALTER TABLE dbo.budget_lines ADD carryover_revenue decimal(19,4) NOT NULL CONSTRAINT DF_budget_lines_carryover_revenue DEFAULT ((0)) WITH VALUES;
GO
IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name='budget_lines_carryover_revenue_check')
    ALTER TABLE dbo.budget_lines WITH CHECK ADD CONSTRAINT budget_lines_carryover_revenue_check CHECK (carryover_revenue>=0);
GO
IF EXISTS (SELECT 1 FROM sys.computed_columns WHERE object_id=OBJECT_ID('dbo.budget_lines') AND name='total_amount' AND definition NOT LIKE '%carryover_revenue%')
    ALTER TABLE dbo.budget_lines DROP COLUMN total_amount;
GO
IF COL_LENGTH('dbo.budget_lines','total_amount') IS NULL
    ALTER TABLE dbo.budget_lines ADD total_amount AS (CONVERT(decimal(19,4),own_revenue+government_grant+health_grant+education_grant+loan_financing+carryover_revenue)) PERSISTED;
GO
IF COL_LENGTH('dbo.budget_lines','program_code') IS NULL
    ALTER TABLE dbo.budget_lines ADD program_code nvarchar(20) NULL;
GO
IF COL_LENGTH('dbo.budget_lines','project_code') IS NULL
    ALTER TABLE dbo.budget_lines ADD project_code nvarchar(20) NULL;
GO
IF COL_LENGTH('dbo.budget_lines','approved_line_key') IS NULL
    ALTER TABLE dbo.budget_lines ADD approved_line_key nvarchar(150) NULL;
GO
IF COL_LENGTH('dbo.budget_lines','staff_count') IS NULL
    ALTER TABLE dbo.budget_lines ADD staff_count int NULL;
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.budget_lines') AND name='UX_budget_lines_version_approved_key')
    CREATE UNIQUE INDEX UX_budget_lines_version_approved_key ON dbo.budget_lines(budget_version_id,approved_line_key) WHERE approved_line_key IS NOT NULL;
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.budget_lines') AND name='IX_budget_lines_program_code')
    CREATE INDEX IX_budget_lines_program_code ON dbo.budget_lines(budget_version_id,program_code) WHERE program_code IS NOT NULL;
GO

IF OBJECT_ID(N'dbo.budget_line_code_changes', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.budget_line_code_changes (
        id bigint IDENTITY(1,1) NOT NULL,
        budget_line_id bigint NOT NULL,
        old_code nvarchar(60) NOT NULL,
        new_code nvarchar(60) NOT NULL,
        matched_by nvarchar(20) NOT NULL,
        import_batch_id uniqueidentifier NULL,
        changed_by bigint NULL,
        changed_at datetime2 NOT NULL CONSTRAINT DF_budget_line_code_changes_changed_at DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_budget_line_code_changes PRIMARY KEY (id),
        CONSTRAINT FK_budget_line_code_changes_line FOREIGN KEY (budget_line_id) REFERENCES dbo.budget_lines(id),
        CONSTRAINT FK_budget_line_code_changes_batch FOREIGN KEY (import_batch_id) REFERENCES dbo.import_batches(id),
        CONSTRAINT FK_budget_line_code_changes_user FOREIGN KEY (changed_by) REFERENCES dbo.users(id)
    );
    CREATE INDEX IX_budget_line_code_changes_line ON dbo.budget_line_code_changes(budget_line_id,changed_at);
END;
GO
