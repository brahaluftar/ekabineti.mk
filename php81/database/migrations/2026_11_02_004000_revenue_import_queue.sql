-- Release 2026.11.02.01: revenue reports are read in the background (import_batches.status queued → processing →
-- ready / unchanged / invalid / failed). Forward-only and idempotent.
-- * IX_revenue_transactions_batch: the confirmation total and the "imported" list of one batch read by batch, not by
--   scanning every payment.
-- * IX_import_batches_type_status: the queue lookup of the scheduler.

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_revenue_transactions_batch' AND object_id=OBJECT_ID(N'dbo.revenue_transactions'))
    CREATE INDEX IX_revenue_transactions_batch ON dbo.revenue_transactions(import_batch_id,source_row_number) INCLUDE (amount);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_import_batches_type_status' AND object_id=OBJECT_ID(N'dbo.import_batches'))
    CREATE INDEX IX_import_batches_type_status ON dbo.import_batches(type,status,created_at);
GO
