-- Release 2026.11.09.01: reports uploaded by the directorates / sectors (Word, PDF, Excel) and read by the AI into
-- proposals (progress reports, tasks, projects, cases, risks) that a person checks and registers (forward-only,
-- idempotent). The file stays in the private storage; proposals and the registered records are kept for audit.

IF OBJECT_ID(N'dbo.document_intakes', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.document_intakes (
        id bigint IDENTITY(1,1) NOT NULL,
        directorate_id bigint NOT NULL,
        sector_id bigint NULL,
        file_name nvarchar(255) NOT NULL,
        file_type nvarchar(10) NOT NULL,
        file_size bigint NOT NULL,
        file_hash char(64) NOT NULL,
        storage_key nvarchar(300) NOT NULL,
        text_chars int NOT NULL CONSTRAINT DF_document_intakes_chars DEFAULT (0),
        text_truncated bit NOT NULL CONSTRAINT DF_document_intakes_truncated DEFAULT (0),
        redactions nvarchar(400) NULL,
        status nvarchar(20) NOT NULL,
        summary nvarchar(2000) NULL,
        proposal_json nvarchar(max) NULL,
        ai_model nvarchar(100) NULL,
        error nvarchar(1000) NULL,
        result_json nvarchar(max) NULL,
        uploaded_by bigint NOT NULL,
        created_at datetime2(0) NOT NULL CONSTRAINT DF_document_intakes_created DEFAULT (SYSUTCDATETIME()),
        confirmed_by bigint NULL,
        confirmed_at datetime2(0) NULL,
        CONSTRAINT PK_document_intakes PRIMARY KEY (id),
        CONSTRAINT FK_document_intakes_directorate FOREIGN KEY (directorate_id) REFERENCES dbo.directorates(id),
        CONSTRAINT FK_document_intakes_sector FOREIGN KEY (sector_id) REFERENCES dbo.citizen_service_sectors(id),
        CONSTRAINT FK_document_intakes_uploader FOREIGN KEY (uploaded_by) REFERENCES dbo.users(id),
        CONSTRAINT FK_document_intakes_confirmer FOREIGN KEY (confirmed_by) REFERENCES dbo.users(id),
        CONSTRAINT CK_document_intakes_status CHECK (status IN (N'ready',N'failed',N'confirmed',N'cancelled')),
        CONSTRAINT CK_document_intakes_type CHECK (file_type IN (N'docx',N'doc',N'pdf',N'xlsx'))
    );
    CREATE INDEX IX_document_intakes_directorate ON dbo.document_intakes(directorate_id,created_at);
    CREATE INDEX IX_document_intakes_hash ON dbo.document_intakes(file_hash,directorate_id,status);
END;
GO
