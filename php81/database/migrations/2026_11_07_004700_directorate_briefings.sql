-- Release 2026.11.07.01: weekly briefing per directorate (forward-only, idempotent). mayor_briefings.directorate_id is
-- NULL for the Mayor's briefing and holds the directorate for a directorate briefing.

IF COL_LENGTH('dbo.mayor_briefings','directorate_id') IS NULL
    ALTER TABLE dbo.mayor_briefings ADD directorate_id bigint NULL CONSTRAINT FK_mayor_briefings_directorate FOREIGN KEY REFERENCES dbo.directorates(id);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='IX_mayor_briefings_directorate_week' AND object_id=OBJECT_ID('dbo.mayor_briefings'))
    CREATE INDEX IX_mayor_briefings_directorate_week ON dbo.mayor_briefings(directorate_id,week_start,id);
GO
