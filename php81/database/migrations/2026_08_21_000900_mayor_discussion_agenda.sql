IF OBJECT_ID(N'dbo.mayor_agenda_items', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.mayor_agenda_items (
        id bigint IDENTITY(1,1) NOT NULL,
        directorate_id bigint NOT NULL,
        item_type nvarchar(20) NOT NULL,
        project_id bigint NULL,
        risk_id bigint NULL,
        topic_title nvarchar(250) NULL,
        topic_details nvarchar(max) NULL,
        priority nvarchar(20) NOT NULL CONSTRAINT DF_mayor_agenda_items_priority DEFAULT (N'normal'),
        discussion_date date NULL,
        status nvarchar(20) NOT NULL CONSTRAINT DF_mayor_agenda_items_status DEFAULT (N'open'),
        selected_by bigint NOT NULL,
        selected_at datetime2(0) NOT NULL CONSTRAINT DF_mayor_agenda_items_selected_at DEFAULT (SYSUTCDATETIME()),
        resolved_by bigint NULL,
        resolved_at datetime2(0) NULL,
        created_at datetime2(0) NOT NULL CONSTRAINT DF_mayor_agenda_items_created_at DEFAULT (SYSUTCDATETIME()),
        updated_at datetime2(0) NOT NULL CONSTRAINT DF_mayor_agenda_items_updated_at DEFAULT (SYSUTCDATETIME()),
        row_version rowversion NOT NULL,
        CONSTRAINT PK_mayor_agenda_items PRIMARY KEY CLUSTERED (id),
        CONSTRAINT FK_mayor_agenda_items_directorate FOREIGN KEY (directorate_id) REFERENCES dbo.directorates(id),
        CONSTRAINT FK_mayor_agenda_items_project FOREIGN KEY (project_id) REFERENCES dbo.projects(id),
        CONSTRAINT FK_mayor_agenda_items_risk FOREIGN KEY (risk_id) REFERENCES dbo.risks(id),
        CONSTRAINT FK_mayor_agenda_items_selected_by FOREIGN KEY (selected_by) REFERENCES dbo.users(id),
        CONSTRAINT FK_mayor_agenda_items_resolved_by FOREIGN KEY (resolved_by) REFERENCES dbo.users(id),
        CONSTRAINT mayor_agenda_items_type_check CHECK (
            (item_type=N'project' AND project_id IS NOT NULL AND risk_id IS NULL AND topic_title IS NULL)
            OR (item_type=N'risk' AND project_id IS NULL AND risk_id IS NOT NULL AND topic_title IS NULL)
            OR (item_type=N'topic' AND project_id IS NULL AND risk_id IS NULL AND topic_title IS NOT NULL)
        ),
        CONSTRAINT mayor_agenda_items_priority_check CHECK (priority IN (N'normal',N'high',N'critical')),
        CONSTRAINT mayor_agenda_items_status_check CHECK (status IN (N'open',N'discussed',N'removed')),
        CONSTRAINT mayor_agenda_items_resolution_check CHECK (
            (status=N'open' AND resolved_by IS NULL AND resolved_at IS NULL)
            OR (status<>N'open' AND resolved_by IS NOT NULL AND resolved_at IS NOT NULL)
        )
    );

    CREATE UNIQUE INDEX UX_mayor_agenda_items_open_project
        ON dbo.mayor_agenda_items(project_id)
        WHERE item_type=N'project' AND status=N'open';

    CREATE UNIQUE INDEX UX_mayor_agenda_items_open_risk
        ON dbo.mayor_agenda_items(risk_id)
        WHERE item_type=N'risk' AND status=N'open';

    CREATE INDEX IX_mayor_agenda_items_dashboard
        ON dbo.mayor_agenda_items(status,priority,discussion_date,directorate_id);
END;
GO
