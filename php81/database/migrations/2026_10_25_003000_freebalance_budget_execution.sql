-- Release 2026.10.25.01: FreeBalance Summary Report import (forward-only, idempotent).
-- 1. freebalance_program_directorates maps a FreeBalance program code (PCLASS) to an e-Kabineti directorate.
-- 2. budget_execution_lines keeps the latest allocated / actual / obligations / commitments / balance per detail line.
-- 3. budget_execution_snapshots keeps every imported state per report date, so the execution trend can be shown.
-- Imported figures never create allocations, commitments or expenditures: project data recorded in e-Kabineti
-- keeps priority and FreeBalance values are shown next to it for reconciliation.

IF OBJECT_ID(N'dbo.freebalance_program_directorates', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.freebalance_program_directorates (
        program_code nvarchar(20) NOT NULL,
        program_name nvarchar(250) NOT NULL,
        directorate_id bigint NULL,
        updated_by bigint NULL,
        created_at datetime2 NOT NULL CONSTRAINT DF_freebalance_program_directorates_created_at DEFAULT (SYSUTCDATETIME()),
        updated_at datetime2 NOT NULL CONSTRAINT DF_freebalance_program_directorates_updated_at DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_freebalance_program_directorates PRIMARY KEY (program_code),
        CONSTRAINT FK_freebalance_program_directorates_directorate FOREIGN KEY (directorate_id) REFERENCES dbo.directorates(id),
        CONSTRAINT FK_freebalance_program_directorates_user FOREIGN KEY (updated_by) REFERENCES dbo.users(id)
    );
END;
GO

IF OBJECT_ID(N'dbo.budget_execution_lines', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.budget_execution_lines (
        id bigint IDENTITY(1,1) NOT NULL,
        source_key nvarchar(150) NOT NULL,
        source_hash char(64) NOT NULL,
        fiscal_year int NOT NULL,
        fund_code nvarchar(20) NOT NULL,
        fund_name nvarchar(250) NOT NULL,
        organization_code nvarchar(20) NOT NULL,
        organization_name nvarchar(250) NOT NULL,
        program_code nvarchar(20) NOT NULL,
        program_name nvarchar(250) NOT NULL,
        economic_code nvarchar(20) NOT NULL,
        economic_name nvarchar(250) NOT NULL,
        project_code nvarchar(20) NOT NULL,
        project_name nvarchar(250) NOT NULL,
        function_code nvarchar(20) NOT NULL,
        function_name nvarchar(250) NOT NULL,
        directorate_id bigint NULL,
        project_id bigint NULL,
        budget_line_id bigint NULL,
        allocated_amount decimal(19,4) NOT NULL,
        actual_amount decimal(19,4) NOT NULL,
        obligations_amount decimal(19,4) NOT NULL,
        unobligated_amount decimal(19,4) NOT NULL,
        commitments_amount decimal(19,4) NOT NULL,
        balance_amount decimal(19,4) NOT NULL,
        as_of_date date NOT NULL,
        in_latest_report bit NOT NULL CONSTRAINT DF_budget_execution_lines_in_latest DEFAULT (1),
        import_batch_id uniqueidentifier NOT NULL,
        source_row_number int NOT NULL,
        created_at datetime2 NOT NULL CONSTRAINT DF_budget_execution_lines_created_at DEFAULT (SYSUTCDATETIME()),
        updated_at datetime2 NOT NULL CONSTRAINT DF_budget_execution_lines_updated_at DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_budget_execution_lines PRIMARY KEY (id),
        CONSTRAINT UQ_budget_execution_lines_source_key UNIQUE (source_key),
        CONSTRAINT FK_budget_execution_lines_directorate FOREIGN KEY (directorate_id) REFERENCES dbo.directorates(id),
        CONSTRAINT FK_budget_execution_lines_project FOREIGN KEY (project_id) REFERENCES dbo.projects(id),
        CONSTRAINT FK_budget_execution_lines_budget_line FOREIGN KEY (budget_line_id) REFERENCES dbo.budget_lines(id),
        CONSTRAINT FK_budget_execution_lines_batch FOREIGN KEY (import_batch_id) REFERENCES dbo.import_batches(id),
        CONSTRAINT CK_budget_execution_lines_year CHECK (fiscal_year BETWEEN 2020 AND 2100),
        CONSTRAINT CK_budget_execution_lines_source_row CHECK (source_row_number > 0)
    );
    CREATE INDEX IX_budget_execution_lines_year ON dbo.budget_execution_lines(fiscal_year,directorate_id,program_code);
    CREATE INDEX IX_budget_execution_lines_project ON dbo.budget_execution_lines(project_id) WHERE project_id IS NOT NULL;
    CREATE INDEX IX_budget_execution_lines_project_code ON dbo.budget_execution_lines(fiscal_year,project_code);
END;
GO

IF OBJECT_ID(N'dbo.budget_execution_snapshots', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.budget_execution_snapshots (
        id bigint IDENTITY(1,1) NOT NULL,
        budget_execution_line_id bigint NOT NULL,
        as_of_date date NOT NULL,
        allocated_amount decimal(19,4) NOT NULL,
        actual_amount decimal(19,4) NOT NULL,
        obligations_amount decimal(19,4) NOT NULL,
        unobligated_amount decimal(19,4) NOT NULL,
        commitments_amount decimal(19,4) NOT NULL,
        balance_amount decimal(19,4) NOT NULL,
        import_batch_id uniqueidentifier NOT NULL,
        created_at datetime2 NOT NULL CONSTRAINT DF_budget_execution_snapshots_created_at DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_budget_execution_snapshots PRIMARY KEY (id),
        CONSTRAINT UQ_budget_execution_snapshots_line_date UNIQUE (budget_execution_line_id,as_of_date),
        CONSTRAINT FK_budget_execution_snapshots_line FOREIGN KEY (budget_execution_line_id) REFERENCES dbo.budget_execution_lines(id),
        CONSTRAINT FK_budget_execution_snapshots_batch FOREIGN KEY (import_batch_id) REFERENCES dbo.import_batches(id)
    );
END;
GO
