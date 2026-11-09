-- Release 2026.11.04.01: weekly work reports of the Legal Office officers (Word), imported and grouped. Forward-only,
-- idempotent.
-- * legal_work_reports: one report (officer, report date, period). report_key = officer + period: importing the same
--   report again replaces its activities instead of adding them twice.
-- * legal_work_activities: one line of the report as written (item_text) and what was read from it: section, activity,
--   parties, normalised case number (C.nr.2257/26), themes (shujta, inflacion …), domain (paga, pronë …), hearing date,
--   and the Legal Office case (and hearing) it was linked to or created for.

IF OBJECT_ID(N'dbo.legal_work_reports', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.legal_work_reports (
        id bigint IDENTITY(1,1) NOT NULL,
        report_key nvarchar(300) NOT NULL,
        import_batch_id uniqueidentifier NOT NULL,
        officer_name nvarchar(200) NOT NULL,
        officer_title nvarchar(200) NULL,
        officer_user_id bigint NULL,
        report_date date NULL,
        period_start date NOT NULL,
        period_end date NOT NULL,
        subject nvarchar(300) NULL,
        source_file_name nvarchar(255) NULL,
        activities int NOT NULL CONSTRAINT DF_legal_work_reports_activities DEFAULT (0),
        sections nvarchar(max) NULL,
        warnings nvarchar(max) NULL,
        created_by bigint NOT NULL,
        created_at datetime2 NOT NULL CONSTRAINT DF_legal_work_reports_created DEFAULT (SYSUTCDATETIME()),
        updated_at datetime2 NOT NULL CONSTRAINT DF_legal_work_reports_updated DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_legal_work_reports PRIMARY KEY (id),
        CONSTRAINT UQ_legal_work_reports_key UNIQUE (report_key),
        CONSTRAINT FK_legal_work_reports_batch FOREIGN KEY (import_batch_id) REFERENCES dbo.import_batches(id),
        CONSTRAINT FK_legal_work_reports_officer FOREIGN KEY (officer_user_id) REFERENCES dbo.users(id),
        CONSTRAINT FK_legal_work_reports_creator FOREIGN KEY (created_by) REFERENCES dbo.users(id),
        CONSTRAINT CK_legal_work_reports_period CHECK (period_end >= period_start)
    );
    CREATE INDEX IX_legal_work_reports_period ON dbo.legal_work_reports(period_start,period_end);
END;
GO
IF OBJECT_ID(N'dbo.legal_work_activities', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.legal_work_activities (
        id bigint IDENTITY(1,1) NOT NULL,
        report_id bigint NOT NULL,
        sort_order int NOT NULL,
        section_code nvarchar(40) NOT NULL,
        section_label nvarchar(200) NOT NULL,
        item_text nvarchar(2000) NOT NULL,
        activity nvarchar(250) NOT NULL,
        parties nvarchar(500) NULL,
        case_reference nvarchar(60) NULL,
        subject nvarchar(500) NULL,
        themes nvarchar(500) NULL,
        theme_codes nvarchar(300) NULL,
        domain nvarchar(30) NOT NULL,
        hearing_date date NULL,
        case_id bigint NULL,
        case_link nvarchar(20) NULL,
        hearing_id bigint NULL,
        created_at datetime2 NOT NULL CONSTRAINT DF_legal_work_activities_created DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_legal_work_activities PRIMARY KEY (id),
        CONSTRAINT FK_legal_work_activities_report FOREIGN KEY (report_id) REFERENCES dbo.legal_work_reports(id) ON DELETE CASCADE,
        CONSTRAINT FK_legal_work_activities_case FOREIGN KEY (case_id) REFERENCES dbo.administrative_cases(id),
        CONSTRAINT FK_legal_work_activities_hearing FOREIGN KEY (hearing_id) REFERENCES dbo.legal_case_hearings(id),
        CONSTRAINT CK_legal_work_activities_link CHECK (case_link IS NULL OR case_link IN (N'linked',N'created'))
    );
    CREATE INDEX IX_legal_work_activities_report ON dbo.legal_work_activities(report_id,sort_order);
    CREATE INDEX IX_legal_work_activities_group ON dbo.legal_work_activities(section_code,domain);
    CREATE INDEX IX_legal_work_activities_case ON dbo.legal_work_activities(case_reference) WHERE case_reference IS NOT NULL;
END;
GO
