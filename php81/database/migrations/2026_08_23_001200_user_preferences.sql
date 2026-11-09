IF COL_LENGTH('dbo.users','notification_email_mode') IS NULL
    ALTER TABLE dbo.users ADD notification_email_mode nvarchar(20) NOT NULL CONSTRAINT DF_users_notification_email_mode DEFAULT ('immediate') WITH VALUES;
GO

IF COL_LENGTH('dbo.users','notification_digest_time') IS NULL
    ALTER TABLE dbo.users ADD notification_digest_time char(5) NOT NULL CONSTRAINT DF_users_notification_digest_time DEFAULT ('09:00') WITH VALUES;
GO

IF COL_LENGTH('dbo.users','notification_digest_weekday') IS NULL
    ALTER TABLE dbo.users ADD notification_digest_weekday tinyint NOT NULL CONSTRAINT DF_users_notification_digest_weekday DEFAULT (1) WITH VALUES;
GO

IF COL_LENGTH('dbo.users','notification_window_start') IS NULL
    ALTER TABLE dbo.users ADD notification_window_start char(5) NULL;
GO

IF COL_LENGTH('dbo.users','notification_window_end') IS NULL
    ALTER TABLE dbo.users ADD notification_window_end char(5) NULL;
GO

IF COL_LENGTH('dbo.users','dashboard_widgets') IS NULL
    ALTER TABLE dbo.users ADD dashboard_widgets nvarchar(max) NULL;
GO
