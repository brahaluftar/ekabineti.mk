-- е-Кабинет · Општина Битола: база на податоци со македонска кириличка колација.
-- Извршување: sqlcmd -S <сервер> -U <sysadmin> -i create-production-database.sql -v AppPassword="..." DeployPassword="..."
:setvar DatabaseName "ekabineti_bitola"
:setvar Collation "Macedonian_FYROM_100_CI_AS"
:setvar AppLogin "ekabineti_bitola_app"
:setvar DeployLogin "ekabineti_bitola_deploy"

IF DB_ID(N'$(DatabaseName)') IS NULL
BEGIN
    EXEC(N'CREATE DATABASE [' + REPLACE(N'$(DatabaseName)', N']', N']]') + N'] COLLATE $(Collation)');
END;
GO

IF SUSER_ID(N'$(AppLogin)') IS NULL
BEGIN
    DECLARE @appSql nvarchar(max) = N'CREATE LOGIN [' + REPLACE(N'$(AppLogin)', N']', N']]') + N'] WITH PASSWORD=N''' + REPLACE(N'$(AppPassword)', N'''', N'''''') + N''', CHECK_POLICY=ON, CHECK_EXPIRATION=ON';
    EXEC(@appSql);
END;
IF SUSER_ID(N'$(DeployLogin)') IS NULL
BEGIN
    DECLARE @deploySql nvarchar(max) = N'CREATE LOGIN [' + REPLACE(N'$(DeployLogin)', N']', N']]') + N'] WITH PASSWORD=N''' + REPLACE(N'$(DeployPassword)', N'''', N'''''') + N''', CHECK_POLICY=ON, CHECK_EXPIRATION=ON';
    EXEC(@deploySql);
END;
GO

USE [$(DatabaseName)];
GO
IF USER_ID(N'$(AppLogin)') IS NULL CREATE USER [$(AppLogin)] FOR LOGIN [$(AppLogin)];
IF USER_ID(N'$(DeployLogin)') IS NULL CREATE USER [$(DeployLogin)] FOR LOGIN [$(DeployLogin)];
ALTER ROLE db_datareader ADD MEMBER [$(AppLogin)];
ALTER ROLE db_datawriter ADD MEMBER [$(AppLogin)];
GRANT EXECUTE TO [$(AppLogin)];
ALTER ROLE db_owner ADD MEMBER [$(DeployLogin)];
GO
