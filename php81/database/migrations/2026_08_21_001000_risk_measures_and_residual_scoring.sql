IF COL_LENGTH('dbo.risks','residual_probability') IS NULL
    ALTER TABLE dbo.risks ADD residual_probability tinyint NULL;
GO

IF COL_LENGTH('dbo.risks','residual_impact') IS NULL
    ALTER TABLE dbo.risks ADD residual_impact tinyint NULL;
GO

UPDATE dbo.risks
SET residual_probability=probability,
    residual_impact=impact,
    residual_score=inherent_score
WHERE residual_score IS NULL;
GO
UPDATE dbo.risks
SET residual_probability=NULL,residual_impact=NULL
WHERE residual_score IS NOT NULL AND (residual_probability IS NULL OR residual_impact IS NULL);
GO

IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name=N'risks_residual_components_check')
    ALTER TABLE dbo.risks WITH CHECK ADD CONSTRAINT risks_residual_components_check CHECK (
        (residual_probability IS NULL AND residual_impact IS NULL AND (residual_score IS NULL OR residual_score BETWEEN 1 AND inherent_score))
        OR (residual_probability BETWEEN 1 AND 5 AND residual_impact BETWEEN 1 AND 5
            AND residual_probability<=probability AND residual_impact<=impact
            AND residual_score=residual_probability*residual_impact)
    );
GO

IF COL_LENGTH('dbo.risk_actions','measure_type') IS NULL
    ALTER TABLE dbo.risk_actions ADD measure_type nvarchar(20) NULL;
GO
UPDATE dbo.risk_actions SET measure_type=N'reduce' WHERE measure_type IS NULL;
GO
IF EXISTS (SELECT 1 FROM sys.columns WHERE object_id=OBJECT_ID(N'dbo.risk_actions') AND name=N'measure_type' AND is_nullable=1)
    ALTER TABLE dbo.risk_actions ALTER COLUMN measure_type nvarchar(20) NOT NULL;
GO

IF COL_LENGTH('dbo.risk_actions','probability_reduction') IS NULL
    ALTER TABLE dbo.risk_actions ADD probability_reduction tinyint NOT NULL CONSTRAINT DF_risk_actions_probability_reduction DEFAULT ((0)) WITH VALUES;
GO
IF COL_LENGTH('dbo.risk_actions','impact_reduction') IS NULL
    ALTER TABLE dbo.risk_actions ADD impact_reduction tinyint NOT NULL CONSTRAINT DF_risk_actions_impact_reduction DEFAULT ((0)) WITH VALUES;
GO
IF COL_LENGTH('dbo.risk_actions','evidence') IS NULL
    ALTER TABLE dbo.risk_actions ADD evidence nvarchar(max) NULL;
GO
IF COL_LENGTH('dbo.risk_actions','completed_at') IS NULL
    ALTER TABLE dbo.risk_actions ADD completed_at datetime2(0) NULL;
GO
IF COL_LENGTH('dbo.risk_actions','created_by') IS NULL
    ALTER TABLE dbo.risk_actions ADD created_by bigint NULL;
GO
IF COL_LENGTH('dbo.risk_actions','row_version') IS NULL
    ALTER TABLE dbo.risk_actions ADD row_version rowversion;
GO

IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name=N'FK_risk_actions_created_by')
    ALTER TABLE dbo.risk_actions ADD CONSTRAINT FK_risk_actions_created_by FOREIGN KEY(created_by) REFERENCES dbo.users(id);
GO
IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name=N'risk_actions_measure_type_check')
    ALTER TABLE dbo.risk_actions WITH CHECK ADD CONSTRAINT risk_actions_measure_type_check CHECK (measure_type IN (N'prevent',N'reduce',N'transfer',N'avoid',N'contingency'));
GO
IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name=N'risk_actions_reduction_check')
    ALTER TABLE dbo.risk_actions WITH CHECK ADD CONSTRAINT risk_actions_reduction_check CHECK (probability_reduction BETWEEN 0 AND 4 AND impact_reduction BETWEEN 0 AND 4);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.risk_actions') AND name=N'IX_risk_actions_risk_status')
    CREATE INDEX IX_risk_actions_risk_status ON dbo.risk_actions(risk_id,status,due_at);
GO
