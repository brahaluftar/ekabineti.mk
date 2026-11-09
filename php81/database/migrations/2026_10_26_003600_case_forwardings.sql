-- Release 2026.10.26.11: administrative cases forwarded to another directorate with a cover letter (aktpërcjellës).
-- Forward-only, idempotent. Each forwarding records who sent the case, from which directorate to which, the cover
-- letter number and date, and (optionally) the scanned cover letter. The receiving directorate confirms receipt.
-- The weekly citizen-services report counts these forwardings per directorate instead of a manual figure.

IF OBJECT_ID('dbo.case_forwardings','U') IS NULL
BEGIN
    CREATE TABLE dbo.case_forwardings(
        id bigint IDENTITY(1,1) NOT NULL,
        case_id bigint NOT NULL,
        from_directorate_id bigint NOT NULL,
        to_directorate_id bigint NOT NULL,
        cover_letter_number nvarchar(100) NOT NULL,
        forwarded_on date NOT NULL,
        reason nvarchar(2000) NULL,
        attachment_id bigint NULL,
        status nvarchar(20) NOT NULL CONSTRAINT DF_case_forwardings_status DEFAULT(N'forwarded'),
        forwarded_by bigint NOT NULL,
        forwarded_at datetime2(0) NOT NULL CONSTRAINT DF_case_forwardings_forwarded_at DEFAULT(SYSUTCDATETIME()),
        received_by bigint NULL,
        received_at datetime2(0) NULL,
        CONSTRAINT PK_case_forwardings PRIMARY KEY CLUSTERED(id),
        CONSTRAINT FK_case_forwardings_case FOREIGN KEY(case_id) REFERENCES dbo.administrative_cases(id),
        CONSTRAINT FK_case_forwardings_from FOREIGN KEY(from_directorate_id) REFERENCES dbo.directorates(id),
        CONSTRAINT FK_case_forwardings_to FOREIGN KEY(to_directorate_id) REFERENCES dbo.directorates(id),
        CONSTRAINT FK_case_forwardings_attachment FOREIGN KEY(attachment_id) REFERENCES dbo.attachments(id),
        CONSTRAINT FK_case_forwardings_by FOREIGN KEY(forwarded_by) REFERENCES dbo.users(id),
        CONSTRAINT FK_case_forwardings_received_by FOREIGN KEY(received_by) REFERENCES dbo.users(id),
        CONSTRAINT CK_case_forwardings_directorates CHECK(from_directorate_id <> to_directorate_id),
        CONSTRAINT CK_case_forwardings_status CHECK(status IN (N'forwarded',N'received'))
    );
END;
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.case_forwardings') AND name = 'IX_case_forwardings_case')
    CREATE INDEX IX_case_forwardings_case ON dbo.case_forwardings(case_id,forwarded_at DESC);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.case_forwardings') AND name = 'IX_case_forwardings_week')
    CREATE INDEX IX_case_forwardings_week ON dbo.case_forwardings(forwarded_on,from_directorate_id,to_directorate_id);
GO
