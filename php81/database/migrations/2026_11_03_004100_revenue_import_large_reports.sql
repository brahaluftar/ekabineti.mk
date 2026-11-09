-- Release 2026.11.03.01: reports of up to 300 000 rows, read and confirmed in chunks. Forward-only and idempotent.
-- * revenue_import_rows.content_key / loose_key: identity of the row as exported and in upper case without extra spaces.
--   Rows of one report with the same loose_key but different content_key are marked possible_duplicate in one UPDATE
--   after the report has been read (the whole file is no longer kept in memory).
-- * import_batches.status gets 'posting' (confirmation running in chunks of 4 000 rows); no constraint lists the statuses.

IF COL_LENGTH('dbo.revenue_import_rows','content_key') IS NULL
    ALTER TABLE dbo.revenue_import_rows ADD content_key char(64) NULL;
GO
IF COL_LENGTH('dbo.revenue_import_rows','loose_key') IS NULL
    ALTER TABLE dbo.revenue_import_rows ADD loose_key char(64) NULL;
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_revenue_import_rows_loose_key' AND object_id=OBJECT_ID(N'dbo.revenue_import_rows'))
    CREATE INDEX IX_revenue_import_rows_loose_key ON dbo.revenue_import_rows(import_batch_id,loose_key) INCLUDE (content_key);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_revenue_import_rows_batch_id' AND object_id=OBJECT_ID(N'dbo.revenue_import_rows'))
    CREATE INDEX IX_revenue_import_rows_batch_id ON dbo.revenue_import_rows(import_batch_id,id) INCLUDE (row_status);
GO
