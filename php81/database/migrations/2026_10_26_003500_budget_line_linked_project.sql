-- Release 2026.10.26.06: project chosen for a budget line on the Budget execution page (forward-only, idempotent).
-- budget_lines.linked_project_id links a line whose figures come from the FreeBalance import to an e-Kabineti project
-- without creating an allocation, so the figures keep coming from FreeBalance. Set only by Finance or the administrator.

IF COL_LENGTH('dbo.budget_lines','linked_project_id') IS NULL
    ALTER TABLE dbo.budget_lines ADD linked_project_id bigint NULL;
GO
IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_budget_lines_linked_project')
    ALTER TABLE dbo.budget_lines WITH CHECK ADD CONSTRAINT FK_budget_lines_linked_project FOREIGN KEY (linked_project_id) REFERENCES dbo.projects(id);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.budget_lines') AND name = 'IX_budget_lines_linked_project')
    CREATE INDEX IX_budget_lines_linked_project ON dbo.budget_lines(linked_project_id) WHERE linked_project_id IS NOT NULL;
GO
