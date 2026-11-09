SET XACT_ABORT ON;
GO

IF OBJECT_ID(N'dbo.ai_technical_proposals', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.ai_technical_proposals (
        id bigint IDENTITY(1,1) NOT NULL,
        requested_by bigint NOT NULL,
        request_text nvarchar(2000) NOT NULL,
        diagnostic_snapshot nvarchar(max) NOT NULL,
        proposal_json nvarchar(max) NOT NULL,
        action_key nvarchar(60) NOT NULL,
        risk_level nvarchar(20) NOT NULL,
        status nvarchar(20) NOT NULL CONSTRAINT DF_ai_technical_proposals_status DEFAULT (N'proposed'),
        reviewed_by bigint NULL,
        review_reason nvarchar(2000) NULL,
        reviewed_at datetime2(0) NULL,
        executed_at datetime2(0) NULL,
        execution_result nvarchar(max) NULL,
        created_at datetime2(0) NOT NULL CONSTRAINT DF_ai_technical_proposals_created DEFAULT (SYSUTCDATETIME()),
        updated_at datetime2(0) NOT NULL CONSTRAINT DF_ai_technical_proposals_updated DEFAULT (SYSUTCDATETIME()),
        row_version rowversion NOT NULL,
        CONSTRAINT PK_ai_technical_proposals PRIMARY KEY CLUSTERED (id),
        CONSTRAINT FK_ai_technical_proposals_requester FOREIGN KEY (requested_by) REFERENCES dbo.users(id),
        CONSTRAINT FK_ai_technical_proposals_reviewer FOREIGN KEY (reviewed_by) REFERENCES dbo.users(id),
        CONSTRAINT CK_ai_technical_proposals_action CHECK (action_key IN (N'none',N'run_scheduler',N'run_migrations',N'sync_mayor_google_sheets')),
        CONSTRAINT CK_ai_technical_proposals_risk CHECK (risk_level IN (N'low',N'medium',N'high')),
        CONSTRAINT CK_ai_technical_proposals_status CHECK (status IN (N'proposed',N'approved',N'rejected',N'executed',N'failed')),
        CONSTRAINT CK_ai_technical_proposals_review CHECK (
            (status=N'proposed' AND reviewed_by IS NULL AND reviewed_at IS NULL)
            OR (status<>N'proposed' AND reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL)
        )
    );
    CREATE INDEX IX_ai_technical_proposals_status_created ON dbo.ai_technical_proposals(status,created_at DESC);
END;
GO

IF OBJECT_ID(N'dbo.ai_reports', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.ai_reports (
        id bigint IDENTITY(1,1) NOT NULL,
        requested_by bigint NOT NULL,
        request_text nvarchar(2000) NOT NULL,
        report_type nvarchar(40) NOT NULL,
        directorate_scope nvarchar(max) NULL,
        specification_json nvarchar(max) NOT NULL,
        result_json nvarchar(max) NOT NULL,
        created_at datetime2(0) NOT NULL CONSTRAINT DF_ai_reports_created DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_ai_reports PRIMARY KEY CLUSTERED (id),
        CONSTRAINT FK_ai_reports_requester FOREIGN KEY (requested_by) REFERENCES dbo.users(id),
        CONSTRAINT CK_ai_reports_type CHECK (report_type IN (N'executive_overview',N'projects',N'risks',N'budget_execution',N'citizen_services'))
    );
    CREATE INDEX IX_ai_reports_requester_created ON dbo.ai_reports(requested_by,created_at DESC);
END;
GO
