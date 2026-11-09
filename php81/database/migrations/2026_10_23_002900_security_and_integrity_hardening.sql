-- Release 2026.10.23.01: security and integrity hardening (forward-only, idempotent).
-- 1. users.session_epoch invalidates every existing session after a password change/reset or access change.
-- 2. users.last_digest_sent_at limits e-mail digests to one per period.
-- 3. audit_events becomes append-only (INSTEAD OF UPDATE/DELETE trigger).
-- 4. The Mayor role no longer carries import/audit permissions that the application always denied.
-- 5. Index supporting the login throttle stored in dbo.cache.

IF COL_LENGTH('dbo.users','session_epoch') IS NULL
    ALTER TABLE dbo.users ADD session_epoch int NOT NULL CONSTRAINT DF_users_session_epoch DEFAULT (0) WITH VALUES;
GO
IF COL_LENGTH('dbo.users','last_digest_sent_at') IS NULL
    ALTER TABLE dbo.users ADD last_digest_sent_at datetime2(0) NULL;
GO
CREATE OR ALTER TRIGGER dbo.tr_audit_events_append_only ON dbo.audit_events INSTEAD OF UPDATE, DELETE AS
BEGIN
    SET NOCOUNT ON;
    THROW 51000, 'audit_events is append-only.', 1;
END;
GO
DELETE pr FROM dbo.permission_role pr
JOIN dbo.roles r ON r.id = pr.role_id
JOIN dbo.permissions p ON p.id = pr.permission_id
WHERE r.code = N'mayor' AND p.resource_type IN (N'import', N'audit');
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.cache') AND name = 'IX_cache_expiration')
    CREATE INDEX IX_cache_expiration ON dbo.cache(expiration);
GO
