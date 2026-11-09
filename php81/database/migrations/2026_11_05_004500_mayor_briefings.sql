-- Release 2026.11.05.04: weekly briefing of the Mayor and meeting preparation for the Mayor's agenda (forward-only,
-- idempotent).
-- * mayor_briefings: one generated briefing (the facts read from the application, the AI summary when the AI is
--   configured, or the AI error). A week may have more than one; the latest one is shown.
-- * mayor_meeting_briefs: the preparation of one agenda item (facts, AI summary, key points and questions), then the
--   meeting notes, the decisions and follow-ups (drafted by the AI from the notes, edited and confirmed by a person).

IF OBJECT_ID(N'dbo.mayor_briefings', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.mayor_briefings (
        id bigint IDENTITY(1,1) NOT NULL,
        week_start date NOT NULL,
        facts_json nvarchar(max) NOT NULL,
        result_json nvarchar(max) NULL,
        ai_model nvarchar(100) NULL,
        ai_error nvarchar(500) NULL,
        generated_by bigint NULL,
        created_at datetime2 NOT NULL CONSTRAINT DF_mayor_briefings_created DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_mayor_briefings PRIMARY KEY (id),
        CONSTRAINT FK_mayor_briefings_user FOREIGN KEY (generated_by) REFERENCES dbo.users(id)
    );
    CREATE INDEX IX_mayor_briefings_week ON dbo.mayor_briefings(week_start,id);
END;
GO
IF OBJECT_ID(N'dbo.mayor_meeting_briefs', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.mayor_meeting_briefs (
        id bigint IDENTITY(1,1) NOT NULL,
        agenda_item_id bigint NOT NULL,
        facts_json nvarchar(max) NOT NULL,
        result_json nvarchar(max) NULL,
        ai_model nvarchar(100) NULL,
        ai_error nvarchar(500) NULL,
        notes nvarchar(max) NULL,
        decisions_json nvarchar(max) NULL,
        decisions_text nvarchar(max) NULL,
        confirmed_by bigint NULL,
        confirmed_at datetime2 NULL,
        generated_by bigint NULL,
        created_at datetime2 NOT NULL CONSTRAINT DF_mayor_meeting_briefs_created DEFAULT (SYSUTCDATETIME()),
        updated_at datetime2 NOT NULL CONSTRAINT DF_mayor_meeting_briefs_updated DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_mayor_meeting_briefs PRIMARY KEY (id),
        CONSTRAINT FK_mayor_meeting_briefs_item FOREIGN KEY (agenda_item_id) REFERENCES dbo.mayor_agenda_items(id),
        CONSTRAINT FK_mayor_meeting_briefs_confirmed FOREIGN KEY (confirmed_by) REFERENCES dbo.users(id),
        CONSTRAINT FK_mayor_meeting_briefs_user FOREIGN KEY (generated_by) REFERENCES dbo.users(id)
    );
    CREATE INDEX IX_mayor_meeting_briefs_item ON dbo.mayor_meeting_briefs(agenda_item_id,id);
END;
GO
