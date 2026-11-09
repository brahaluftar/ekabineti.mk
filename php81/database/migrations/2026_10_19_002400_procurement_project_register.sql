IF OBJECT_ID(N'dbo.procurement_projects', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.procurement_projects (
        id bigint IDENTITY(1,1) NOT NULL PRIMARY KEY,
        procurement_code nvarchar(120) NOT NULL,
        contract_code nvarchar(120) NULL,
        contract_title nvarchar(1000) NULL,
        contract_type nvarchar(200) NULL,
        contracting_party nvarchar(1000) NULL,
        managers nvarchar(1000) NULL,
        supervisors nvarchar(1000) NULL,
        procurement_name nvarchar(1000) NULL,
        currency nvarchar(20) NULL,
        procurement_status nvarchar(100) NULL,
        total decimal(19,4) NULL,
        economic_codes nvarchar(1000) NULL,
        project_codes nvarchar(1000) NULL,
        duration_months int NULL,
        realized_amount decimal(19,4) NULL,
        paid_amount decimal(19,4) NULL,
        enforced_amount decimal(19,4) NULL,
        total_paid_amount decimal(19,4) NULL,
        first_imported_at datetime2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        last_imported_at datetime2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        created_at datetime2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        updated_at datetime2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        row_version rowversion NOT NULL,
        CONSTRAINT UQ_procurement_projects_code UNIQUE(procurement_code),
        CONSTRAINT CK_procurement_projects_duration CHECK(duration_months IS NULL OR duration_months >= 0)
    );
END

IF OBJECT_ID(N'dbo.procurement_project_links', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.procurement_project_links (
        procurement_project_id bigint NOT NULL,
        project_id bigint NOT NULL,
        linked_by bigint NOT NULL,
        linked_at datetime2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        CONSTRAINT PK_procurement_project_links PRIMARY KEY(procurement_project_id,project_id),
        CONSTRAINT FK_procurement_project_links_register FOREIGN KEY(procurement_project_id) REFERENCES dbo.procurement_projects(id),
        CONSTRAINT FK_procurement_project_links_project FOREIGN KEY(project_id) REFERENCES dbo.projects(id),
        CONSTRAINT FK_procurement_project_links_user FOREIGN KEY(linked_by) REFERENCES dbo.users(id)
    );
    CREATE INDEX IX_procurement_project_links_project ON dbo.procurement_project_links(project_id);
END
