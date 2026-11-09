IF COL_LENGTH('dbo.project_photos','task_id') IS NULL
 ALTER TABLE dbo.project_photos ADD task_id bigint NULL;
GO
IF NOT EXISTS(SELECT 1 FROM sys.foreign_keys WHERE parent_object_id=OBJECT_ID('dbo.project_photos') AND name='FK_project_photos_task')
 ALTER TABLE dbo.project_photos ADD CONSTRAINT FK_project_photos_task FOREIGN KEY(task_id) REFERENCES dbo.project_tasks(id);
GO
IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.project_photos') AND name='IX_project_photos_task')
 CREATE INDEX IX_project_photos_task ON dbo.project_photos(task_id) WHERE task_id IS NOT NULL;
GO
