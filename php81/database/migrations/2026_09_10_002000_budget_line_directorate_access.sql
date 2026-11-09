IF OBJECT_ID('dbo.budget_line_directorate_access','U') IS NULL
BEGIN
 CREATE TABLE dbo.budget_line_directorate_access(
  budget_line_id bigint NOT NULL,
  directorate_id bigint NOT NULL,
  can_select bit NOT NULL CONSTRAINT DF_budget_line_access_select DEFAULT 0,
  can_report bit NOT NULL CONSTRAINT DF_budget_line_access_report DEFAULT 0,
  granted_by bigint NOT NULL,
  created_at datetime2 NOT NULL CONSTRAINT DF_budget_line_access_created DEFAULT SYSUTCDATETIME(),
  updated_at datetime2 NOT NULL CONSTRAINT DF_budget_line_access_updated DEFAULT SYSUTCDATETIME(),
  CONSTRAINT PK_budget_line_directorate_access PRIMARY KEY(budget_line_id,directorate_id),
  CONSTRAINT FK_budget_line_access_line FOREIGN KEY(budget_line_id) REFERENCES dbo.budget_lines(id) ON DELETE CASCADE,
  CONSTRAINT FK_budget_line_access_directorate FOREIGN KEY(directorate_id) REFERENCES dbo.directorates(id),
  CONSTRAINT FK_budget_line_access_granted_by FOREIGN KEY(granted_by) REFERENCES dbo.users(id),
  CONSTRAINT CK_budget_line_access_capability CHECK(can_select=1 OR can_report=1)
 );
END;
GO
IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.budget_line_directorate_access') AND name='IX_budget_line_access_directorate')
 CREATE INDEX IX_budget_line_access_directorate ON dbo.budget_line_directorate_access(directorate_id,can_report,can_select) INCLUDE(budget_line_id);
GO
