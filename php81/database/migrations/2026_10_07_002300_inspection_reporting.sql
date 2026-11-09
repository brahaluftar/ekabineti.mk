IF OBJECT_ID(N'dbo.inspection_reports', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.inspection_reports (
        id bigint IDENTITY(1,1) NOT NULL PRIMARY KEY, directorate_id bigint NOT NULL,
        period_start date NOT NULL, period_end date NOT NULL,
        complaints_requests int NOT NULL DEFAULT 0, construction_permits_received int NOT NULL DEFAULT 0,
        phase_inspections int NOT NULL DEFAULT 0, final_inspections_received int NOT NULL DEFAULT 0,
        final_inspections_completed int NOT NULL DEFAULT 0, use_certificates_received int NOT NULL DEFAULT 0,
        use_certificates_completed int NOT NULL DEFAULT 0, stop_work_decisions int NOT NULL DEFAULT 0,
        demolition_decisions int NOT NULL DEFAULT 0, demolition_execution_orders int NOT NULL DEFAULT 0,
        executed_decisions int NOT NULL DEFAULT 0, mobility_annual_plan nvarchar(4000) NULL,
        notes nvarchar(4000) NULL, submitted_by bigint NOT NULL,
        submitted_at datetime2(0) NOT NULL DEFAULT SYSUTCDATETIME(), updated_at datetime2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        CONSTRAINT FK_inspection_reports_directorate FOREIGN KEY (directorate_id) REFERENCES dbo.directorates(id),
        CONSTRAINT FK_inspection_reports_user FOREIGN KEY (submitted_by) REFERENCES dbo.users(id),
        CONSTRAINT CK_inspection_reports_period CHECK (period_end >= period_start),
        CONSTRAINT CK_inspection_reports_nonnegative CHECK (complaints_requests>=0 AND construction_permits_received>=0 AND phase_inspections>=0 AND final_inspections_received>=0 AND final_inspections_completed>=0 AND use_certificates_received>=0 AND use_certificates_completed>=0 AND stop_work_decisions>=0 AND demolition_decisions>=0 AND demolition_execution_orders>=0 AND executed_decisions>=0),
        CONSTRAINT UQ_inspection_reports_period UNIQUE (directorate_id,period_start,period_end)
    );
    CREATE INDEX IX_inspection_reports_period ON dbo.inspection_reports(directorate_id,period_end DESC);
END
