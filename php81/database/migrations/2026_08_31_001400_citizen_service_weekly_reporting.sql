IF OBJECT_ID(N'dbo.citizen_services', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.citizen_services (
        id bigint IDENTITY(1,1) NOT NULL,
        directorate_id bigint NOT NULL,
        code nvarchar(100) NOT NULL,
        name nvarchar(250) NOT NULL,
        description nvarchar(1000) NULL,
        sla_target_hours decimal(12,2) NULL,
        active bit NOT NULL CONSTRAINT DF_citizen_services_active DEFAULT (1),
        sort_order int NOT NULL CONSTRAINT DF_citizen_services_sort DEFAULT (100),
        created_by bigint NULL,
        created_at datetime2(0) NOT NULL CONSTRAINT DF_citizen_services_created DEFAULT (SYSUTCDATETIME()),
        updated_at datetime2(0) NOT NULL CONSTRAINT DF_citizen_services_updated DEFAULT (SYSUTCDATETIME()),
        row_version rowversion NOT NULL,
        CONSTRAINT PK_citizen_services PRIMARY KEY CLUSTERED (id),
        CONSTRAINT UQ_citizen_services_code UNIQUE (code),
        CONSTRAINT FK_citizen_services_directorate FOREIGN KEY (directorate_id) REFERENCES dbo.directorates(id),
        CONSTRAINT FK_citizen_services_created_by FOREIGN KEY (created_by) REFERENCES dbo.users(id),
        CONSTRAINT CK_citizen_services_sla CHECK (sla_target_hours IS NULL OR sla_target_hours >= 0)
    );
    CREATE INDEX IX_citizen_services_directorate ON dbo.citizen_services(directorate_id,active,sort_order,name);
END;
GO

IF OBJECT_ID(N'dbo.citizen_service_metrics', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.citizen_service_metrics (
        id bigint IDENTITY(1,1) NOT NULL,
        service_id bigint NOT NULL,
        metric_key nvarchar(60) NOT NULL,
        label nvarchar(200) NOT NULL,
        metric_role nvarchar(20) NOT NULL,
        active bit NOT NULL CONSTRAINT DF_citizen_service_metrics_active DEFAULT (1),
        sort_order int NOT NULL CONSTRAINT DF_citizen_service_metrics_sort DEFAULT (100),
        created_at datetime2(0) NOT NULL CONSTRAINT DF_citizen_service_metrics_created DEFAULT (SYSUTCDATETIME()),
        updated_at datetime2(0) NOT NULL CONSTRAINT DF_citizen_service_metrics_updated DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_citizen_service_metrics PRIMARY KEY CLUSTERED (id),
        CONSTRAINT UQ_citizen_service_metrics_key UNIQUE (service_id,metric_key),
        CONSTRAINT FK_citizen_service_metrics_service FOREIGN KEY (service_id) REFERENCES dbo.citizen_services(id),
        CONSTRAINT CK_citizen_service_metrics_role CHECK (metric_role IN (N'received',N'resolved',N'open',N'informational'))
    );
    CREATE INDEX IX_citizen_service_metrics_service ON dbo.citizen_service_metrics(service_id,active,sort_order,id);
END;
GO

IF OBJECT_ID(N'dbo.citizen_service_weekly_reports', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.citizen_service_weekly_reports (
        id bigint IDENTITY(1,1) NOT NULL,
        service_id bigint NOT NULL,
        directorate_id bigint NOT NULL,
        week_start date NOT NULL,
        average_resolution_hours decimal(12,2) NULL,
        notes nvarchar(4000) NULL,
        submitted_by bigint NOT NULL,
        submitted_at datetime2(0) NOT NULL CONSTRAINT DF_citizen_service_reports_submitted DEFAULT (SYSUTCDATETIME()),
        updated_at datetime2(0) NOT NULL CONSTRAINT DF_citizen_service_reports_updated DEFAULT (SYSUTCDATETIME()),
        row_version rowversion NOT NULL,
        CONSTRAINT PK_citizen_service_weekly_reports PRIMARY KEY CLUSTERED (id),
        CONSTRAINT UQ_citizen_service_weekly_reports UNIQUE (service_id,week_start),
        CONSTRAINT FK_citizen_service_reports_service FOREIGN KEY (service_id) REFERENCES dbo.citizen_services(id),
        CONSTRAINT FK_citizen_service_reports_directorate FOREIGN KEY (directorate_id) REFERENCES dbo.directorates(id),
        CONSTRAINT FK_citizen_service_reports_user FOREIGN KEY (submitted_by) REFERENCES dbo.users(id),
        CONSTRAINT CK_citizen_service_reports_monday CHECK (DATEDIFF(day,CONVERT(date,'19000101',112),week_start) % 7 = 0),
        CONSTRAINT CK_citizen_service_reports_hours CHECK (average_resolution_hours IS NULL OR average_resolution_hours >= 0)
    );
    CREATE INDEX IX_citizen_service_reports_week ON dbo.citizen_service_weekly_reports(week_start,directorate_id,service_id);
END;
GO

IF OBJECT_ID(N'dbo.citizen_service_weekly_values', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.citizen_service_weekly_values (
        report_id bigint NOT NULL,
        metric_id bigint NOT NULL,
        metric_value bigint NOT NULL,
        created_at datetime2(0) NOT NULL CONSTRAINT DF_citizen_service_values_created DEFAULT (SYSUTCDATETIME()),
        updated_at datetime2(0) NOT NULL CONSTRAINT DF_citizen_service_values_updated DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_citizen_service_weekly_values PRIMARY KEY CLUSTERED (report_id,metric_id),
        CONSTRAINT FK_citizen_service_values_report FOREIGN KEY (report_id) REFERENCES dbo.citizen_service_weekly_reports(id) ON DELETE CASCADE,
        CONSTRAINT FK_citizen_service_values_metric FOREIGN KEY (metric_id) REFERENCES dbo.citizen_service_metrics(id),
        CONSTRAINT CK_citizen_service_values_nonnegative CHECK (metric_value >= 0)
    );
    CREATE INDEX IX_citizen_service_values_metric ON dbo.citizen_service_weekly_values(metric_id,report_id);
END;
GO

IF OBJECT_ID(N'dbo.citizen_service_transfers', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.citizen_service_transfers (
        id bigint IDENTITY(1,1) NOT NULL,
        service_id bigint NOT NULL,
        from_directorate_id bigint NOT NULL,
        to_directorate_id bigint NOT NULL,
        reason nvarchar(1000) NOT NULL,
        transferred_by bigint NOT NULL,
        transferred_at datetime2(0) NOT NULL CONSTRAINT DF_citizen_service_transfers_at DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_citizen_service_transfers PRIMARY KEY CLUSTERED (id),
        CONSTRAINT FK_citizen_service_transfers_service FOREIGN KEY (service_id) REFERENCES dbo.citizen_services(id),
        CONSTRAINT FK_citizen_service_transfers_from FOREIGN KEY (from_directorate_id) REFERENCES dbo.directorates(id),
        CONSTRAINT FK_citizen_service_transfers_to FOREIGN KEY (to_directorate_id) REFERENCES dbo.directorates(id),
        CONSTRAINT FK_citizen_service_transfers_user FOREIGN KEY (transferred_by) REFERENCES dbo.users(id),
        CONSTRAINT CK_citizen_service_transfers_different CHECK (from_directorate_id <> to_directorate_id)
    );
    CREATE INDEX IX_citizen_service_transfers_service ON dbo.citizen_service_transfers(service_id,transferred_at DESC);
END;
GO

CREATE OR ALTER PROCEDURE dbo.usp_seed_citizen_service_catalog
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @services TABLE (
        directorate_code nvarchar(30), service_code nvarchar(100), service_name nvarchar(250),
        description nvarchar(1000), sla_hours decimal(12,2), sort_order int,
        received_label nvarchar(200), reviewed_label nvarchar(200), resolved_label nvarchar(200), open_label nvarchar(200)
    );

    INSERT INTO @services VALUES
    (N'ADM',N'ADM_CIVIL_STATUS',N'Матична евиденција',N'Уверенија, изводи и други барања од матичната евиденција.',24,10,N'Примени барања',N'Разгледани барања',N'Завршени барања',N'Во постапка'),
    (N'DARS',N'DARS_PRESCHOOL',N'Барање за градинка',N'Апликации и запишувања во предучилишните установи.',120,10,N'Барање',N'Разгледувања',N'Регистрации',N'Во постапка'),
    (N'DARS',N'DARS_SCHOOL_ENROLMENT',N'Запишување во училиште',N'Барања за запишување во општинските училишта.',120,20,N'Барање',N'Разгледувања',N'Регистрации',N'Во постапка'),
    (N'DARS',N'DARS_TRANSFERS',N'Префрлувања на ученици',N'Барања за префрлување меѓу образовните установи.',120,30,N'Барање',N'Разгледувања',N'Реализирани префрлувања',N'Во постапка'),
    (N'DBUJQ',N'DBUJQ_SUBSIDIES',N'Земјоделски субвенции',N'Барања за субвенции и поддршка во земјоделството.',240,10,N'Барање',N'Разгледувања',N'Одобрени барања',N'Во постапка'),
    (N'FIN',N'FIN_PAYMENTS',N'Барања за плаќања',N'Финансиски барања и статус на плаќањата.',72,10,N'Барања за плаќања',N'Одбиени барања',N'Платени барања',N'Во постапка'),
    (N'DINSP',N'DINSP_CITIZEN_REQUESTS',N'Барања од граѓани',N'Барања и жалби упатени до Инспекцијата.',72,10,N'Барања од граѓани',NULL,N'Разгледани барања',N'Во постапка'),
    (N'DINSP',N'DINSP_INSPECTIONS',N'Инспекции',N'Инспекциски активности и преземени мерки.',72,20,N'Инспекции',N'Запирање на работи',N'Решенија',N'Предмети во постапка'),
    (N'INF',N'INF_INTERVENTIONS',N'Интервенции во инфраструктурата',N'Барања за интервенции на патишта и јавна инфраструктура.',168,10,N'Барање',N'Разгледани барања',N'Завршени интервенции',N'Во постапка'),
    (N'INF',N'INF_PROJECT_REQUESTS',N'Барања за капитални проекти',N'Предлози и барања за нови инфраструктурни проекти.',240,20,N'Барање',N'Разгледани барања',N'Завршени барања',N'Во постапка'),
    (N'DKAD',N'DKAD_LEGALIZATION',N'Легализација',N'Барања за легализација на објекти.',240,10,N'Барање',N'Разгледувања',N'Завршени барања',N'Во постапка'),
    (N'DKAD',N'DKAD_PROPERTY_TRANSFER',N'Пренос на имот',N'Катастарски барања за пренос на сопственост.',120,20,N'Барање',N'Разгледувања',N'Регистрирани преноси',N'Во постапка'),
    (N'DKAD',N'DKAD_PROPERTY_REGISTRATION',N'Регистрација на имот',N'Барања за запишување во катастарската евиденција.',120,30,N'Барање',N'Разгледувања',N'Регистрации',N'Во постапка'),
    (N'KULT',N'KULT_ACTIVITIES',N'Културни активности',N'Барања за организирање или поддршка на културни активности.',168,10,N'Барање',N'Разгледувања',N'Одобрени барања',N'Во постапка'),
    (N'KULT',N'KULT_SUBSIDIES',N'Субвенции за култура',N'Барања за субвенции во културата.',240,20,N'Барање',N'Разгледувања',N'Одобрени барања',N'Во постапка'),
    (N'DMQS',N'DMQS_TREATMENT',N'Барања за социјален третман',N'Барања за проценка, третман и социјални услуги.',72,10,N'Барање',N'Разгледувања',N'Обработени барања',N'Во постапка'),
    (N'DMQS',N'DMQS_SUBSIDIES',N'Социјални субвенции',N'Барања за поддршка и социјални субвенции.',168,20,N'Барање',N'Разгледувања',N'Одобрени барања',N'Во постапка'),
    (N'DMOB',N'DMOB_COMPLAINTS',N'Жалби за мобилност',N'Жалби за сообраќај, превоз и мобилност.',72,10,N'Жалби',N'Разгледувања',N'Решени жалби',N'Во постапка'),
    (N'DMOB',N'DMOB_RECONSIDERATION',N'Барања за преиспитување',N'Барања за преиспитување на одлуките за мобилност.',120,20,N'Барање',N'Разгледувања',N'Завршени барања',N'Во постапка'),
    (N'DMOB',N'DMOB_INTERVENTIONS',N'Барања за интервенции',N'Барања за сигнализација, сообраќај и интервенции во мобилноста.',120,30,N'Барање',N'Разгледувања',N'Завршени интервенции',N'Во постапка'),
    (N'DPARK',N'DPARK_COMPLAINTS',N'Жалби за паркови',N'Жалби за паркови и зелени површини.',72,10,N'Жалби',N'Разгледувања',N'Решени жалби',N'Во постапка'),
    (N'DPARK',N'DPARK_RECONSIDERATION',N'Барања за преиспитување',N'Барања за преиспитување во областа на парковите.',120,20,N'Барање',N'Разгледувања',N'Завршени барања',N'Во постапка'),
    (N'DPARK',N'DPARK_INTERVENTIONS',N'Интервенции во паркови',N'Барања за одржување и интервенции во парковите.',120,30,N'Барање',N'Разгледувања',N'Завршени интервенции',N'Во постапка'),
    (N'DPRON',N'DPRON_COMPENSATION',N'Барања за надомест',N'Имотни барања за надомест.',240,10,N'Барање',N'Разгледувања',N'Завршени барања',N'Во постапка'),
    (N'DPRON',N'DPRON_TREATMENT',N'Решавање имотни прашања',N'Други барања за решавање имотни прашања.',240,20,N'Барање',N'Разгледувања',N'Обработени барања',N'Во постапка'),
    (N'DRIN',N'DRIN_ACTIVITIES',N'Младински активности',N'Барања за младински активности и иницијативи.',168,10,N'Барање',N'Разгледувања',N'Одобрени барања',N'Во постапка'),
    (N'DRIN',N'DRIN_SUBSIDIES',N'Субвенции за млади',N'Барања за субвенции и поддршка на младите.',240,20,N'Барање',N'Разгледувања',N'Одобрени барања',N'Во постапка'),
    (N'DRIN',N'DRIN_YOUTH_SPACES',N'Користење младински простори',N'Резервации и барања за младински простори.',72,30,N'Барање',N'Разгледувања',N'Потврдени резервации',N'Во постапка'),
    (N'DHEALTH',N'DHEALTH_SUPPORT',N'Здравствена поддршка',N'Барања од граѓани за поддршка и општински здравствени услуги.',72,10,N'Барање',N'Разгледувања',N'Обработени барања',N'Во постапка'),
    (N'DHEALTH',N'DHEALTH_COMPLAINTS',N'Здравствени жалби',N'Жалби поврзани со општинските здравствени услуги.',72,20,N'Жалби',N'Разгледувања',N'Решени жалби',N'Во постапка'),
    (N'DHEALTH',N'DHEALTH_FACILITY_INTERVENTIONS',N'Интервенции во здравствени објекти',N'Барања за одржување и интервенции во здравствените објекти.',120,30,N'Барање',N'Разгледувања',N'Завршени интервенции',N'Во постапка'),
    (N'DSHP',N'DSHP_LIGHTING',N'Жалби за јавно осветлување',N'Жалби и дефекти на јавното осветлување.',48,10,N'Жалби',N'Разгледувања',N'Решени жалби',N'Во постапка'),
    (N'DSHP',N'DSHP_STRAY_DOGS',N'Жалби за кучиња скитници',N'Жалби и барања за интервенција за кучиња скитници.',24,20,N'Жалби',N'Разгледувања',N'Завршени интервенции',N'Во постапка'),
    (N'DSHP',N'DSHP_WASTE',N'Жалби за отпад',N'Жалби за собирање и управување со отпадот.',24,30,N'Жалби',N'Разгледувања',N'Решени жалби',N'Во постапка'),
    (N'DSHP',N'DSHP_PUBLIC_SPACES',N'Одржување на јавните површини',N'Жалби за чистење и одржување на јавните површини.',48,40,N'Жалби',N'Разгледувања',N'Завршени интервенции',N'Во постапка'),
    (N'DSPORT',N'DSPORT_ACTIVITIES',N'Спортски активности',N'Барања за организирање или поддршка на спортски активности.',168,10,N'Барање',N'Разгледувања',N'Одобрени барања',N'Во постапка'),
    (N'DSPORT',N'DSPORT_SUBSIDIES',N'Субвенции за спорт',N'Барања за спортски субвенции.',240,20,N'Барање',N'Разгледувања',N'Одобрени барања',N'Во постапка'),
    (N'DTRANSF',N'DTRANSF_DIGITAL_SERVICES',N'Дигитални услуги',N'Барања за нови дигитални услуги или подобрувања.',72,10,N'Барање',N'Разгледувања',N'Завршени барања',N'Во постапка'),
    (N'DTRANSF',N'DTRANSF_ACCESS_SUPPORT',N'Пристап и техничка поддршка',N'Барања за сметки, пристап и техничка помош.',24,20,N'Барање',N'Разгледувања',N'Решени барања',N'Во постапка'),
    (N'DTRANSF',N'DTRANSF_INCIDENTS',N'Дигитални инциденти',N'Пријави за пречки и инциденти во дигиталните системи.',8,30,N'Инциденти',N'Разгледани инциденти',N'Решени инциденти',N'Во постапка'),
    (N'DTUR',N'DTUR_ACTIVITIES',N'Туристички активности',N'Барања за туристички активности, настани и иницијативи.',168,10,N'Барање',N'Разгледувања',N'Одобрени барања',N'Во постапка'),
    (N'DTUR',N'DTUR_INFORMATION',N'Туристичко информирање и промоција',N'Барања за информирање, промоција и туристички материјали.',72,20,N'Барање',N'Разгледувања',N'Завршени барања',N'Во постапка'),
    (N'DTUR',N'DTUR_SUBSIDIES',N'Поддршка за туризмот',N'Барања за субвенции и поддршка на туристичките оператори.',240,30,N'Барање',N'Разгледувања',N'Одобрени барања',N'Во постапка'),
    (N'DURB',N'DURB_BUILDING_PERMITS',N'Одобренија за градење',N'Барања за одобрение за градење и статус на разгледувањето.',240,10,N'Барање',N'Разгледувања',N'Издадени дозволи',N'Во постапка'),
    (N'DIG',N'DIG_ACTIVITIES',N'Економски активности и иновации',N'Барања за економски активности, претприемништво и иновации.',168,10,N'Барање',N'Разгледувања',N'Одобрени барања',N'Во постапка'),
    (N'DIG',N'DIG_SUBSIDIES',N'Економски субвенции',N'Барања за субвенции за бизниси и иновации.',240,20,N'Барање',N'Разгледувања',N'Одобрени барања',N'Во постапка'),
    (N'DIG',N'DIG_BUSINESS_SUPPORT',N'Поддршка за бизниси и стартапи',N'Барања за советување, инкубација и поддршка на претприемништвото.',120,30,N'Барање',N'Разгледувања',N'Обработени барања',N'Во постапка'),
    (N'DEMERG',N'DEMERG_COMPLAINTS',N'Жалби и барања за безбедност',N'Жалби и барања од граѓани во областа на безбедноста и вонредните состојби.',24,10,N'Жалби',N'Разгледувања',N'Решени жалби',N'Во постапка'),
    (N'DEMERG',N'DEMERG_INTERVENTIONS',N'Итни интервенции',N'Пријави што бараат координација или итна интервенција.',4,20,N'Пријави',N'Проверки',N'Завршени интервенции',N'Во постапка'),
    (N'ZPROK',N'ZPROK_PROCUREMENT',N'Активности за јавни набавки',N'Неделен преглед на активностите и договорите за јавни набавки.',240,10,N'Отворени активности',N'Склучени активности',N'Завршени договори',N'Активности на чекање'),
    (N'PRAV',N'PRAV_ENFORCEMENT',N'Барања за извршување',N'Барања и предмети за извршување.',240,10,N'Барање',N'Разгледувања',N'Завршени барања',N'Во постапка'),
    (N'PRAV',N'PRAV_OTHER',N'Други правни барања',N'Други барања за обработка од Правната служба.',240,20,N'Барање',N'Разгледувања',N'Завршени барања',N'Во постапка');

    MERGE dbo.citizen_services AS target
    USING (
        SELECT matched.id directorate_id,s.*
        FROM @services s
        CROSS APPLY (
            SELECT TOP 1 d.id,d.code,d.name
            FROM dbo.directorates d
            WHERE d.code=s.directorate_code
               OR (s.directorate_code=N'DTRANSF' AND d.code=N'DIG' AND (d.name LIKE N'%Transformimit%' OR d.name LIKE N'%Digjital%'))
            ORDER BY CASE WHEN d.code=s.directorate_code THEN 0 ELSE 1 END,d.id
        ) matched
        WHERE NOT (s.directorate_code=N'DIG' AND matched.code=N'DIG' AND (matched.name LIKE N'%Transformimit%' OR matched.name LIKE N'%Digjital%'))
    ) AS source ON target.code=source.service_code
    WHEN MATCHED THEN UPDATE SET target.name=source.service_name,target.description=source.description,target.sla_target_hours=source.sla_hours,target.sort_order=source.sort_order,target.updated_at=SYSUTCDATETIME()
    WHEN NOT MATCHED THEN INSERT(directorate_id,code,name,description,sla_target_hours,active,sort_order)
        VALUES(source.directorate_id,source.service_code,source.service_name,source.description,source.sla_hours,1,source.sort_order);

    DECLARE @metrics TABLE(service_code nvarchar(100),metric_key nvarchar(60),label nvarchar(200),metric_role nvarchar(20),sort_order int);
    INSERT INTO @metrics(service_code,metric_key,label,metric_role,sort_order)
    SELECT service_code,N'received',received_label,N'received',10 FROM @services WHERE received_label IS NOT NULL
    UNION ALL SELECT service_code,N'reviewed',reviewed_label,N'informational',20 FROM @services WHERE reviewed_label IS NOT NULL
    UNION ALL SELECT service_code,N'resolved',resolved_label,N'resolved',30 FROM @services WHERE resolved_label IS NOT NULL
    UNION ALL SELECT service_code,N'open',open_label,N'open',40 FROM @services WHERE open_label IS NOT NULL;

    MERGE dbo.citizen_service_metrics AS target
    USING (SELECT cs.id service_id,m.* FROM @metrics m JOIN dbo.citizen_services cs ON cs.code=m.service_code) AS source
        ON target.service_id=source.service_id AND target.metric_key=source.metric_key
    WHEN MATCHED THEN UPDATE SET target.label=source.label,target.metric_role=source.metric_role,target.sort_order=source.sort_order,target.updated_at=SYSUTCDATETIME()
    WHEN NOT MATCHED THEN INSERT(service_id,metric_key,label,metric_role,active,sort_order)
        VALUES(source.service_id,source.metric_key,source.label,source.metric_role,1,source.sort_order);
END;
GO

EXEC dbo.usp_seed_citizen_service_catalog;
GO

CREATE OR ALTER VIEW dbo.vw_mayor_civil_request_kpis
AS
    WITH totals AS (
        SELECT
            r.id report_id,
            SUM(CASE WHEN m.metric_role=N'received' THEN v.metric_value ELSE 0 END) received_count,
            SUM(CASE WHEN m.metric_role=N'resolved' THEN v.metric_value ELSE 0 END) resolved_count,
            SUM(CASE WHEN m.metric_role=N'open' THEN v.metric_value ELSE 0 END) explicit_open_count,
            MAX(CASE WHEN m.metric_role=N'open' THEN 1 ELSE 0 END) has_open_metric
        FROM dbo.citizen_service_weekly_reports r
        JOIN dbo.citizen_service_weekly_values v ON v.report_id=r.id
        JOIN dbo.citizen_service_metrics m ON m.id=v.metric_id AND m.active=1
        GROUP BY r.id
    )
    SELECT
        CAST(s.code AS nvarchar(100)) service_key,
        CAST(d.code+N' · '+s.name AS nvarchar(200)) service_name,
        r.week_start period_start,
        DATEADD(day,6,r.week_start) period_end,
        CAST(t.received_count AS bigint) received_count,
        CAST(t.resolved_count AS bigint) resolved_count,
        CAST(CASE WHEN t.has_open_metric=1 THEN t.explicit_open_count WHEN t.received_count>=t.resolved_count THEN t.received_count-t.resolved_count ELSE 0 END AS bigint) open_count,
        CAST(r.average_resolution_hours AS decimal(12,2)) average_resolution_hours,
        CAST(s.sla_target_hours AS decimal(12,2)) sla_target_hours,
        CAST(N'e-kabineti-weekly' AS nvarchar(100)) source_system,
        CAST(r.updated_at AS datetime2(0)) source_as_of
    FROM dbo.citizen_service_weekly_reports r
    JOIN totals t ON t.report_id=r.id
    JOIN dbo.citizen_services s ON s.id=r.service_id
    JOIN dbo.directorates d ON d.id=r.directorate_id;
GO
