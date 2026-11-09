IF COL_LENGTH('dbo.budget_lines','loan_financing') IS NULL
    ALTER TABLE dbo.budget_lines ADD loan_financing decimal(19,4) NOT NULL CONSTRAINT DF_budget_lines_loan_financing DEFAULT ((0)) WITH VALUES;
GO
IF COL_LENGTH('dbo.budget_lines','source_section_code') IS NULL
    ALTER TABLE dbo.budget_lines ADD source_section_code nvarchar(60) NULL;
GO
IF EXISTS (SELECT 1 FROM sys.computed_columns WHERE object_id=OBJECT_ID('dbo.budget_lines') AND name='total_amount' AND definition NOT LIKE '%loan_financing%')
    ALTER TABLE dbo.budget_lines DROP COLUMN total_amount;
GO
IF COL_LENGTH('dbo.budget_lines','total_amount') IS NULL
    ALTER TABLE dbo.budget_lines ADD total_amount AS (CONVERT(decimal(19,4),own_revenue+government_grant+health_grant+education_grant+loan_financing)) PERSISTED;
GO
IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name='budget_lines_loan_financing_check')
    ALTER TABLE dbo.budget_lines WITH CHECK ADD CONSTRAINT budget_lines_loan_financing_check CHECK (loan_financing>=0);
GO
IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE name='budget_lines_category_check')
    ALTER TABLE dbo.budget_lines DROP CONSTRAINT budget_lines_category_check;
GO
ALTER TABLE dbo.budget_lines WITH CHECK ADD CONSTRAINT budget_lines_category_check CHECK (budget_category IS NULL OR budget_category IN ('goods_services','capital_investments','wages','subsidies','utilities'));
GO
