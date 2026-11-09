CREATE OR ALTER VIEW dbo.vw_mayor_civil_request_kpis
AS
    SELECT
        CAST(NULL AS nvarchar(100)) AS service_key,
        CAST(NULL AS nvarchar(200)) AS service_name,
        CAST(NULL AS date) AS period_start,
        CAST(NULL AS date) AS period_end,
        CAST(NULL AS bigint) AS received_count,
        CAST(NULL AS bigint) AS resolved_count,
        CAST(NULL AS bigint) AS open_count,
        CAST(NULL AS decimal(12, 2)) AS average_resolution_hours,
        CAST(NULL AS decimal(12, 2)) AS sla_target_hours,
        CAST(NULL AS nvarchar(100)) AS source_system,
        CAST(NULL AS datetime2(0)) AS source_as_of
    WHERE 1 = 0;
GO

