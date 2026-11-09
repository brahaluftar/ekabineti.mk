-- Release 2026.11.06.01: catalog of the municipal services and weekly reporting per service, by the sector heads /
-- responsible officers (forward-only, idempotent).
-- * citizen_service_sectors: the sectors of each directorate (or the directorate itself when it has none).
-- * citizen_service_catalog: the services of the municipality, entered in the application (no seed data in this installation).
-- * Each service belongs to a reporting window (citizen_services). A window with catalog services is filled from the
--   sector reports: its weekly totals are computed by the application, so the directorate totals, the Mayor's dashboard
--   and the weekly briefing keep working. 15 windows are new, 9 existing windows become computed.
-- * citizen_service_sector_reporters: the users who report a sector (assigned by the director or a system administrator).
-- * citizen_service_item_reports: the weekly numbers of a service (tier A/B) or of a group (tier C).

-- Општина Битола: не се создава посебен сектор (ZKKK); услугите на непостоечките сектори се прескокнуваат.
SELECT 1 AS no_op;
GO

-- New reporting windows (request type, standard fields).
DECLARE @windows TABLE(directorate_code nvarchar(30),code nvarchar(100),name nvarchar(250),description nvarchar(1000),sort_order int);
-- Општина Битола: без почетни податоци (каталогот се внесува во апликацијата: Услуги за граѓани → Каталог).

MERGE dbo.citizen_services AS target
USING (SELECT d.id directorate_id,w.* FROM @windows w JOIN dbo.directorates d ON d.code=w.directorate_code) AS source ON target.code=source.code
WHEN NOT MATCHED THEN INSERT(directorate_id,code,name,description,sla_target_hours,active,sort_order,window_type)
    VALUES(source.directorate_id,source.code,source.name,source.description,NULL,1,source.sort_order,N'request');

DECLARE @fields TABLE(metric_key nvarchar(60),label nvarchar(200),metric_role nvarchar(20),sort_order int);
INSERT INTO @fields VALUES(N'received',N'Примени барања',N'received',10),(N'reviewed',N'Разгледани барања',N'informational',20),(N'resolved',N'Завршени барања',N'resolved',30),(N'open',N'Во постапка',N'open',40);
INSERT INTO dbo.citizen_service_metrics(service_id,metric_key,label,metric_role,active,sort_order)
SELECT cs.id,f.metric_key,f.label,f.metric_role,1,f.sort_order
FROM @windows w JOIN dbo.citizen_services cs ON cs.code=w.code CROSS JOIN @fields f
WHERE NOT EXISTS (SELECT 1 FROM dbo.citizen_service_metrics m WHERE m.service_id=cs.id AND m.metric_key=f.metric_key);
GO

IF OBJECT_ID(N'dbo.citizen_service_sectors', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.citizen_service_sectors (
        id bigint IDENTITY(1,1) NOT NULL,
        directorate_id bigint NOT NULL,
        code nvarchar(60) NOT NULL,
        name nvarchar(250) NOT NULL,
        active bit NOT NULL CONSTRAINT DF_citizen_service_sectors_active DEFAULT (1),
        sort_order int NOT NULL CONSTRAINT DF_citizen_service_sectors_sort DEFAULT (100),
        created_at datetime2(0) NOT NULL CONSTRAINT DF_citizen_service_sectors_created DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_citizen_service_sectors PRIMARY KEY (id),
        CONSTRAINT UQ_citizen_service_sectors_code UNIQUE (code),
        CONSTRAINT FK_citizen_service_sectors_directorate FOREIGN KEY (directorate_id) REFERENCES dbo.directorates(id)
    );
END;
GO
IF OBJECT_ID(N'dbo.citizen_service_catalog', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.citizen_service_catalog (
        id bigint IDENTITY(1,1) NOT NULL,
        code nvarchar(30) NOT NULL,
        sector_id bigint NOT NULL,
        window_id bigint NOT NULL,
        name nvarchar(400) NOT NULL,
        category_code nvarchar(30) NOT NULL,
        beneficiaries nvarchar(200) NULL,
        fee_required bit NOT NULL,
        online bit NOT NULL,
        legal_deadline_days int NULL,
        tier char(1) NOT NULL,
        active bit NOT NULL CONSTRAINT DF_citizen_service_catalog_active DEFAULT (1),
        sort_order int NOT NULL CONSTRAINT DF_citizen_service_catalog_sort DEFAULT (100),
        created_at datetime2(0) NOT NULL CONSTRAINT DF_citizen_service_catalog_created DEFAULT (SYSUTCDATETIME()),
        updated_at datetime2(0) NOT NULL CONSTRAINT DF_citizen_service_catalog_updated DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_citizen_service_catalog PRIMARY KEY (id),
        CONSTRAINT UQ_citizen_service_catalog_code UNIQUE (code),
        CONSTRAINT FK_citizen_service_catalog_sector FOREIGN KEY (sector_id) REFERENCES dbo.citizen_service_sectors(id),
        CONSTRAINT FK_citizen_service_catalog_window FOREIGN KEY (window_id) REFERENCES dbo.citizen_services(id),
        CONSTRAINT CK_citizen_service_catalog_tier CHECK (tier IN ('A','B','C')),
        CONSTRAINT CK_citizen_service_catalog_category CHECK (category_code IN (N'certificates',N'registrations',N'marriage',N'permits',N'consents',N'public_space',N'decisions',N'subsidies',N'property_tax',N'municipal_property')),
        CONSTRAINT CK_citizen_service_catalog_deadline CHECK (legal_deadline_days IS NULL OR legal_deadline_days > 0)
    );
    CREATE INDEX IX_citizen_service_catalog_sector ON dbo.citizen_service_catalog(sector_id,active,sort_order);
    CREATE INDEX IX_citizen_service_catalog_window ON dbo.citizen_service_catalog(window_id,active);
END;
GO
IF OBJECT_ID(N'dbo.citizen_service_sector_reporters', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.citizen_service_sector_reporters (
        id bigint IDENTITY(1,1) NOT NULL,
        sector_id bigint NOT NULL,
        user_id bigint NOT NULL,
        active bit NOT NULL CONSTRAINT DF_citizen_service_sector_reporters_active DEFAULT (1),
        assigned_by bigint NOT NULL,
        assigned_at datetime2(0) NOT NULL CONSTRAINT DF_citizen_service_sector_reporters_at DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_citizen_service_sector_reporters PRIMARY KEY (id),
        CONSTRAINT UQ_citizen_service_sector_reporters UNIQUE (sector_id,user_id),
        CONSTRAINT FK_citizen_service_sector_reporters_sector FOREIGN KEY (sector_id) REFERENCES dbo.citizen_service_sectors(id),
        CONSTRAINT FK_citizen_service_sector_reporters_user FOREIGN KEY (user_id) REFERENCES dbo.users(id),
        CONSTRAINT FK_citizen_service_sector_reporters_by FOREIGN KEY (assigned_by) REFERENCES dbo.users(id)
    );
    CREATE INDEX IX_citizen_service_sector_reporters_user ON dbo.citizen_service_sector_reporters(user_id,active);
END;
GO
IF OBJECT_ID(N'dbo.citizen_service_item_reports', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.citizen_service_item_reports (
        id bigint IDENTITY(1,1) NOT NULL,
        row_key nvarchar(120) NOT NULL,
        week_start date NOT NULL,
        sector_id bigint NOT NULL,
        window_id bigint NOT NULL,
        catalog_id bigint NULL,
        category_code nvarchar(30) NOT NULL,
        received bigint NULL,
        resolved bigint NULL,
        rejected bigint NULL,
        open_count bigint NULL,
        over_deadline bigint NULL,
        online_count bigint NULL,
        amount decimal(19,2) NULL,
        submitted_by bigint NOT NULL,
        submitted_at datetime2(0) NOT NULL CONSTRAINT DF_citizen_service_item_reports_at DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_citizen_service_item_reports PRIMARY KEY (id),
        CONSTRAINT UQ_citizen_service_item_reports UNIQUE (row_key,week_start),
        CONSTRAINT FK_citizen_service_item_reports_sector FOREIGN KEY (sector_id) REFERENCES dbo.citizen_service_sectors(id),
        CONSTRAINT FK_citizen_service_item_reports_window FOREIGN KEY (window_id) REFERENCES dbo.citizen_services(id),
        CONSTRAINT FK_citizen_service_item_reports_catalog FOREIGN KEY (catalog_id) REFERENCES dbo.citizen_service_catalog(id),
        CONSTRAINT FK_citizen_service_item_reports_user FOREIGN KEY (submitted_by) REFERENCES dbo.users(id),
        CONSTRAINT CK_citizen_service_item_reports_monday CHECK (DATEDIFF(day,CONVERT(date,'19000101',112),week_start) % 7 = 0),
        CONSTRAINT CK_citizen_service_item_reports_values CHECK (COALESCE(received,0)>=0 AND COALESCE(resolved,0)>=0 AND COALESCE(rejected,0)>=0 AND COALESCE(open_count,0)>=0 AND COALESCE(over_deadline,0)>=0 AND COALESCE(online_count,0)>=0 AND COALESCE(amount,0)>=0)
    );
    CREATE INDEX IX_citizen_service_item_reports_week ON dbo.citizen_service_item_reports(week_start,sector_id);
    CREATE INDEX IX_citizen_service_item_reports_window ON dbo.citizen_service_item_reports(window_id,week_start);
END;
GO

-- Sectors (a directorate without sectors reports as one sector).
DECLARE @sectors TABLE(directorate_code nvarchar(30),code nvarchar(60),name nvarchar(250),sort_order int);
-- Општина Битола: без почетни податоци (каталогот се внесува во апликацијата: Услуги за граѓани → Каталог).
MERGE dbo.citizen_service_sectors AS target
USING (SELECT d.id directorate_id,s.* FROM @sectors s JOIN dbo.directorates d ON d.code=s.directorate_code) AS source ON target.code=source.code
WHEN NOT MATCHED THEN INSERT(directorate_id,code,name,sort_order) VALUES(source.directorate_id,source.code,source.name,source.sort_order);

-- Services: code, directorate, sector, window, name, category, beneficiaries, fee, online, legal deadline (days), tier, order.
DECLARE @catalog TABLE(code nvarchar(30),directorate_code nvarchar(30),sector_code nvarchar(60),window_code nvarchar(100),name nvarchar(400),category_code nvarchar(30),beneficiaries nvarchar(200),fee_required bit,online bit,legal_deadline_days int,tier char(1),sort_order int);
-- Општина Битола: без почетни податоци (каталогот се внесува во апликацијата: Услуги за граѓани → Каталог).
MERGE dbo.citizen_service_catalog AS target
USING (SELECT s.id sector_id,w.id window_id,c.* FROM @catalog c JOIN dbo.citizen_service_sectors s ON s.code=c.sector_code JOIN dbo.citizen_services w ON w.code=c.window_code) AS source ON target.code=source.code
WHEN NOT MATCHED THEN INSERT(code,sector_id,window_id,name,category_code,beneficiaries,fee_required,online,legal_deadline_days,tier,sort_order)
    VALUES(source.code,source.sector_id,source.window_id,source.name,source.category_code,source.beneficiaries,source.fee_required,source.online,source.legal_deadline_days,source.tier,source.sort_order);
GO
