-- Release 2026.11.09.02: policy areas of the executive, for the report on the areas worked on at the same time
-- (forward-only, idempotent).
-- * policy_areas: the areas (infrastructure, education, health …), managed in the application.
-- * directorates.policy_area_id: the default area of a directorate's work.
-- * projects.policy_area_id: optional area of a project when it differs from its directorate's (e.g. a school built by
--   Capital Investments belongs to Education).

IF OBJECT_ID(N'dbo.policy_areas', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.policy_areas (
        id bigint IDENTITY(1,1) NOT NULL,
        code nvarchar(40) NOT NULL,
        name nvarchar(150) NOT NULL,
        active bit NOT NULL CONSTRAINT DF_policy_areas_active DEFAULT (1),
        sort_order int NOT NULL CONSTRAINT DF_policy_areas_sort DEFAULT (100),
        created_at datetime2(0) NOT NULL CONSTRAINT DF_policy_areas_created DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_policy_areas PRIMARY KEY (id),
        CONSTRAINT UQ_policy_areas_code UNIQUE (code)
    );
END;
GO
IF COL_LENGTH('dbo.directorates','policy_area_id') IS NULL
    ALTER TABLE dbo.directorates ADD policy_area_id bigint NULL CONSTRAINT FK_directorates_policy_area FOREIGN KEY REFERENCES dbo.policy_areas(id);
GO
IF COL_LENGTH('dbo.projects','policy_area_id') IS NULL
    ALTER TABLE dbo.projects ADD policy_area_id bigint NULL CONSTRAINT FK_projects_policy_area FOREIGN KEY REFERENCES dbo.policy_areas(id);
GO

DECLARE @areas TABLE(code nvarchar(40),name nvarchar(150),sort_order int);
INSERT INTO @areas VALUES
    (N'infrastructure',N'Инфраструктура и капитални инвестиции',10),(N'mobility',N'Мобилност и сообраќај',20),
    (N'urban',N'Урбанизам, катастар и имот',30),(N'environment',N'Животна средина, комунални услуги и зелени површини',40),
    (N'education',N'Образование',50),(N'health',N'Здравство',60),(N'social',N'Социјална заштита и заедници',70),
    (N'culture',N'Култура, спорт и млади',80),(N'economy',N'Економија, земјоделство и туризам',90),(N'finance',N'Финансии, буџет и јавни набавки',100),
    (N'administration',N'Администрација, дигитализација и услуги за граѓаните',110),(N'safety',N'Безбедност, вонредни состојби и инспекција',120),(N'legal',N'Правни прашања',130);
INSERT INTO dbo.policy_areas(code,name,sort_order) SELECT a.code,a.name,a.sort_order FROM @areas a WHERE NOT EXISTS (SELECT 1 FROM dbo.policy_areas p WHERE p.code=a.code);

-- Default area of each known directorate (only where none is set yet).
DECLARE @map TABLE(directorate_code nvarchar(30),area_code nvarchar(40));
INSERT INTO @map VALUES
    (N'INF',N'infrastructure'),(N'DMOB',N'mobility'),(N'DURB',N'urban'),(N'DKAD',N'urban'),(N'DPRON',N'urban'),
    (N'DSHP',N'environment'),(N'DPARK',N'environment'),(N'DARS',N'education'),(N'DHEALTH',N'health'),
    (N'DMQS',N'social'),(N'ZKKK',N'social'),(N'KULT',N'culture'),(N'DSPORT',N'culture'),(N'DRIN',N'culture'),
    (N'DBUJQ',N'economy'),(N'DTUR',N'economy'),(N'FIN',N'finance'),(N'ZPROK',N'finance'),
    (N'ADM',N'administration'),(N'DTRANSF',N'administration'),(N'DEMERG',N'safety'),(N'DINSP',N'safety'),(N'PRAV',N'legal'),
    -- Општина Битола (почетни сектори од seed-production.sql)
    (N'KAB',N'administration'),(N'URB',N'urban'),(N'KOM',N'environment'),(N'KI',N'infrastructure'),(N'LER',N'economy'),
    (N'JD',N'education'),(N'IMOT',N'urban'),(N'INS',N'safety'),(N'JN',N'finance'),(N'IT',N'administration'),(N'CUG',N'administration'),(N'REV',N'finance');
UPDATE d SET d.policy_area_id=a.id FROM dbo.directorates d JOIN @map m ON m.directorate_code=d.code JOIN dbo.policy_areas a ON a.code=m.area_code WHERE d.policy_area_id IS NULL;
-- DIG is the Digital Transformation directorate in some installations and Economic Development in others.
UPDATE d SET d.policy_area_id=a.id FROM dbo.directorates d JOIN dbo.policy_areas a ON a.code=CASE WHEN d.name LIKE N'%Digjital%' OR d.name LIKE N'%Transformim%' THEN N'administration' ELSE N'economy' END WHERE d.code=N'DIG' AND d.policy_area_id IS NULL;
GO
