-- Release 2026.10.26.02: budget codes (FreeBalance PCLASS / program codes) per directorate (forward-only, idempotent).
-- directorates.budget_codes lists the program codes of the directorate (e.g. "92030, 92310, 93150, 94350").
-- It is kept in sync with freebalance_program_directorates, which links FreeBalance and approved-budget lines to directorates.

IF COL_LENGTH('dbo.directorates','budget_codes') IS NULL
    ALTER TABLE dbo.directorates ADD budget_codes nvarchar(200) NULL;
GO
-- Existing program mappings become the initial budget codes of each directorate.
UPDATE d SET budget_codes = x.codes
FROM dbo.directorates d
CROSS APPLY (
    SELECT STUFF((SELECT N', ' + m.program_code FROM dbo.freebalance_program_directorates m WHERE m.directorate_id = d.id ORDER BY m.program_code FOR XML PATH(''), TYPE).value('.', 'nvarchar(200)'), 1, 2, N'') AS codes
) x
WHERE d.budget_codes IS NULL AND x.codes IS NOT NULL;
GO
