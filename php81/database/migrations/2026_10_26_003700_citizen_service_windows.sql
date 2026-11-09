-- Release 2026.10.26.12: weekly citizen-services report in standard windows (forward-only, idempotent).
-- * citizen_services.window_type: request (kërkesa), subsidy (subvencione), complaint (ankesa) or volume (shërbime të
--   ofruara, e.g. visits). Volume windows are counted apart and never as requests.
-- * New metric roles: volume (services delivered) and amount (euro amounts such as approved subsidies, stored in
--   citizen_service_weekly_values.amount_value with two decimals).
-- * Catalog: Health gets subsidies, complaints split into services / subsidies, and visits for QKMF, the Emergency
--   Medicine Centre and the Dental Polyclinic; every directorate with subsidies gets a subsidy-complaints window;
--   subsidy and complaint windows use the standard fields.
-- * vw_citizen_service_directorate_weeks: weekly totals per directorate, with the cases forwarded with a cover letter
--   (case_forwardings) subtracted from the open ones.

IF COL_LENGTH('dbo.citizen_services','window_type') IS NULL
    ALTER TABLE dbo.citizen_services ADD window_type nvarchar(20) NOT NULL CONSTRAINT DF_citizen_services_window_type DEFAULT(N'request');
GO
IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name='CK_citizen_services_window_type')
    ALTER TABLE dbo.citizen_services WITH CHECK ADD CONSTRAINT CK_citizen_services_window_type CHECK (window_type IN (N'request',N'subsidy',N'complaint',N'volume'));
GO
IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE name='CK_citizen_service_metrics_role' AND definition NOT LIKE N'%volume%')
    ALTER TABLE dbo.citizen_service_metrics DROP CONSTRAINT CK_citizen_service_metrics_role;
GO
IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name='CK_citizen_service_metrics_role')
    ALTER TABLE dbo.citizen_service_metrics WITH CHECK ADD CONSTRAINT CK_citizen_service_metrics_role CHECK (metric_role IN (N'received',N'resolved',N'open',N'informational',N'volume',N'amount'));
GO
IF COL_LENGTH('dbo.citizen_service_weekly_values','amount_value') IS NULL
    ALTER TABLE dbo.citizen_service_weekly_values ADD amount_value decimal(19,2) NULL;
GO
IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name='CK_citizen_service_values_amount')
    ALTER TABLE dbo.citizen_service_weekly_values WITH CHECK ADD CONSTRAINT CK_citizen_service_values_amount CHECK (amount_value IS NULL OR amount_value >= 0);
GO

CREATE OR ALTER PROCEDURE dbo.usp_seed_citizen_service_catalog
AS
BEGIN
    SET NOCOUNT ON;

    -- window_type: request (kërkesa), subsidy (subvencione), complaint (ankesa), volume (shërbime të ofruara).
    DECLARE @services TABLE (
        directorate_code nvarchar(30), service_code nvarchar(100), service_name nvarchar(250),
        description nvarchar(1000), sla_hours decimal(12,2), sort_order int,
        received_label nvarchar(200), reviewed_label nvarchar(200), resolved_label nvarchar(200), open_label nvarchar(200),
        window_type nvarchar(20)
    );

    INSERT INTO @services VALUES
    (N'ADM',N'ADM_CIVIL_STATUS',N'Матична евиденција',N'Уверенија, изводи и други барања од матичната евиденција.',24,10,N'Примени барања',N'Разгледани барања',N'Завршени барања',N'Во постапка',N'request'),
    (N'DARS',N'DARS_PRESCHOOL',N'Барање за градинка',N'Апликации и запишувања во предучилишните установи.',120,10,N'Барање',N'Разгледувања',N'Регистрации',N'Во постапка',N'request'),
    (N'DARS',N'DARS_SCHOOL_ENROLMENT',N'Запишување во училиште',N'Барања за запишување во општинските училишта.',120,20,N'Барање',N'Разгледувања',N'Регистрации',N'Во постапка',N'request'),
    (N'DARS',N'DARS_TRANSFERS',N'Префрлувања на ученици',N'Барања за префрлување меѓу образовните установи.',120,30,N'Барање',N'Разгледувања',N'Реализирани префрлувања',N'Во постапка',N'request'),
    (N'DBUJQ',N'DBUJQ_SUBSIDIES',N'Земјоделски субвенции',N'Барања за субвенции и поддршка во земјоделството.',240,10,N'Барање',N'Разгледувања',N'Одобрени барања',N'Во постапка',N'subsidy'),
    (N'FIN',N'FIN_PAYMENTS',N'Барања за плаќања',N'Финансиски барања и статус на плаќањата.',72,10,N'Барања за плаќања',N'Одбиени барања',N'Платени барања',N'Во постапка',N'request'),
    (N'DINSP',N'DINSP_CITIZEN_REQUESTS',N'Барања од граѓани',N'Барања и жалби упатени до Инспекцијата.',72,10,N'Барања од граѓани',NULL,N'Разгледани барања',N'Во постапка',N'request'),
    (N'DINSP',N'DINSP_INSPECTIONS',N'Инспекции',N'Инспекциски активности и преземени мерки.',72,20,N'Инспекции',N'Запирање на работи',N'Решенија',N'Предмети во постапка',N'request'),
    (N'INF',N'INF_INTERVENTIONS',N'Интервенции во инфраструктурата',N'Барања за интервенции на патишта и јавна инфраструктура.',168,10,N'Барање',N'Разгледани барања',N'Завршени интервенции',N'Во постапка',N'request'),
    (N'INF',N'INF_PROJECT_REQUESTS',N'Барања за капитални проекти',N'Предлози и барања за нови инфраструктурни проекти.',240,20,N'Барање',N'Разгледани барања',N'Завршени барања',N'Во постапка',N'request'),
    (N'DKAD',N'DKAD_LEGALIZATION',N'Легализација',N'Барања за легализација на објекти.',240,10,N'Барање',N'Разгледувања',N'Завршени барања',N'Во постапка',N'request'),
    (N'DKAD',N'DKAD_PROPERTY_TRANSFER',N'Пренос на имот',N'Катастарски барања за пренос на сопственост.',120,20,N'Барање',N'Разгледувања',N'Регистрирани преноси',N'Во постапка',N'request'),
    (N'DKAD',N'DKAD_PROPERTY_REGISTRATION',N'Регистрација на имот',N'Барања за запишување во катастарската евиденција.',120,30,N'Барање',N'Разгледувања',N'Регистрации',N'Во постапка',N'request'),
    (N'KULT',N'KULT_ACTIVITIES',N'Културни активности',N'Барања за организирање или поддршка на културни активности.',168,10,N'Барање',N'Разгледувања',N'Одобрени барања',N'Во постапка',N'request'),
    (N'KULT',N'KULT_SUBSIDIES',N'Субвенции за култура',N'Барања за субвенции во културата.',240,20,N'Барање',N'Разгледувања',N'Одобрени барања',N'Во постапка',N'subsidy'),
    (N'DMQS',N'DMQS_TREATMENT',N'Барања за социјален третман',N'Барања за проценка, третман и социјални услуги.',72,10,N'Барање',N'Разгледувања',N'Обработени барања',N'Во постапка',N'request'),
    (N'DMQS',N'DMQS_SUBSIDIES',N'Социјални субвенции',N'Барања за поддршка и социјални субвенции.',168,20,N'Барање',N'Разгледувања',N'Одобрени барања',N'Во постапка',N'subsidy'),
    (N'DMOB',N'DMOB_COMPLAINTS',N'Жалби за мобилност',N'Жалби за сообраќај, превоз и мобилност.',72,10,N'Жалби',N'Разгледувања',N'Решени жалби',N'Во постапка',N'complaint'),
    (N'DMOB',N'DMOB_RECONSIDERATION',N'Барања за преиспитување',N'Барања за преиспитување на одлуките за мобилност.',120,20,N'Барање',N'Разгледувања',N'Завршени барања',N'Во постапка',N'request'),
    (N'DMOB',N'DMOB_INTERVENTIONS',N'Барања за интервенции',N'Барања за сигнализација, сообраќај и интервенции во мобилноста.',120,30,N'Барање',N'Разгледувања',N'Завршени интервенции',N'Во постапка',N'request'),
    (N'DPARK',N'DPARK_COMPLAINTS',N'Жалби за паркови',N'Жалби за паркови и зелени површини.',72,10,N'Жалби',N'Разгледувања',N'Решени жалби',N'Во постапка',N'complaint'),
    (N'DPARK',N'DPARK_RECONSIDERATION',N'Барања за преиспитување',N'Барања за преиспитување во областа на парковите.',120,20,N'Барање',N'Разгледувања',N'Завршени барања',N'Во постапка',N'request'),
    (N'DPARK',N'DPARK_INTERVENTIONS',N'Интервенции во паркови',N'Барања за одржување и интервенции во парковите.',120,30,N'Барање',N'Разгледувања',N'Завршени интервенции',N'Во постапка',N'request'),
    (N'DPRON',N'DPRON_COMPENSATION',N'Барања за надомест',N'Имотни барања за надомест.',240,10,N'Барање',N'Разгледувања',N'Завршени барања',N'Во постапка',N'request'),
    (N'DPRON',N'DPRON_TREATMENT',N'Решавање имотни прашања',N'Други барања за решавање имотни прашања.',240,20,N'Барање',N'Разгледувања',N'Обработени барања',N'Во постапка',N'request'),
    (N'DRIN',N'DRIN_ACTIVITIES',N'Младински активности',N'Барања за младински активности и иницијативи.',168,10,N'Барање',N'Разгледувања',N'Одобрени барања',N'Во постапка',N'request'),
    (N'DRIN',N'DRIN_SUBSIDIES',N'Субвенции за млади',N'Барања за субвенции и поддршка на младите.',240,20,N'Барање',N'Разгледувања',N'Одобрени барања',N'Во постапка',N'subsidy'),
    (N'DRIN',N'DRIN_YOUTH_SPACES',N'Користење младински простори',N'Резервации и барања за младински простори.',72,30,N'Барање',N'Разгледувања',N'Потврдени резервации',N'Во постапка',N'request'),
    (N'DHEALTH',N'DHEALTH_SUPPORT',N'Здравствена поддршка',N'Барања од граѓани за поддршка и општински здравствени услуги.',72,10,N'Барање',N'Разгледувања',N'Обработени барања',N'Во постапка',N'request'),
    (N'DHEALTH',N'DHEALTH_COMPLAINTS',N'Жалби за здравствени услуги',N'Жалби поврзани со општинските здравствени услуги (не за субвенции).',72,20,N'Жалби',N'Разгледувања',N'Решени жалби',N'Во постапка',N'complaint'),
    (N'DHEALTH',N'DHEALTH_FACILITY_INTERVENTIONS',N'Интервенции во здравствени објекти',N'Барања за одржување и интервенции во здравствените објекти.',120,30,N'Барање',N'Разгледувања',N'Завршени интервенции',N'Во постапка',N'request'),
    (N'DSHP',N'DSHP_LIGHTING',N'Жалби за јавно осветлување',N'Жалби и дефекти на јавното осветлување.',48,10,N'Жалби',N'Разгледувања',N'Решени жалби',N'Во постапка',N'complaint'),
    (N'DSHP',N'DSHP_STRAY_DOGS',N'Жалби за кучиња скитници',N'Жалби и барања за интервенција за кучиња скитници.',24,20,N'Жалби',N'Разгледувања',N'Завршени интервенции',N'Во постапка',N'complaint'),
    (N'DSHP',N'DSHP_WASTE',N'Жалби за отпад',N'Жалби за собирање и управување со отпадот.',24,30,N'Жалби',N'Разгледувања',N'Решени жалби',N'Во постапка',N'complaint'),
    (N'DSHP',N'DSHP_PUBLIC_SPACES',N'Одржување на јавните површини',N'Жалби за чистење и одржување на јавните површини.',48,40,N'Жалби',N'Разгледувања',N'Завршени интервенции',N'Во постапка',N'complaint'),
    (N'DSPORT',N'DSPORT_ACTIVITIES',N'Спортски активности',N'Барања за организирање или поддршка на спортски активности.',168,10,N'Барање',N'Разгледувања',N'Одобрени барања',N'Во постапка',N'request'),
    (N'DSPORT',N'DSPORT_SUBSIDIES',N'Субвенции за спорт',N'Барања за спортски субвенции.',240,20,N'Барање',N'Разгледувања',N'Одобрени барања',N'Во постапка',N'subsidy'),
    (N'DTRANSF',N'DTRANSF_DIGITAL_SERVICES',N'Дигитални услуги',N'Барања за нови дигитални услуги или подобрувања.',72,10,N'Барање',N'Разгледувања',N'Завршени барања',N'Во постапка',N'request'),
    (N'DTRANSF',N'DTRANSF_ACCESS_SUPPORT',N'Пристап и техничка поддршка',N'Барања за сметки, пристап и техничка помош.',24,20,N'Барање',N'Разгледувања',N'Решени барања',N'Во постапка',N'request'),
    (N'DTRANSF',N'DTRANSF_INCIDENTS',N'Дигитални инциденти',N'Пријави за пречки и инциденти во дигиталните системи.',8,30,N'Инциденти',N'Разгледани инциденти',N'Решени инциденти',N'Во постапка',N'request'),
    (N'DTUR',N'DTUR_ACTIVITIES',N'Туристички активности',N'Барања за туристички активности, настани и иницијативи.',168,10,N'Барање',N'Разгледувања',N'Одобрени барања',N'Во постапка',N'request'),
    (N'DTUR',N'DTUR_INFORMATION',N'Туристичко информирање и промоција',N'Барања за информирање, промоција и туристички материјали.',72,20,N'Барање',N'Разгледувања',N'Завршени барања',N'Во постапка',N'request'),
    (N'DTUR',N'DTUR_SUBSIDIES',N'Поддршка за туризмот',N'Барања за субвенции и поддршка на туристичките оператори.',240,30,N'Барање',N'Разгледувања',N'Одобрени барања',N'Во постапка',N'subsidy'),
    (N'DURB',N'DURB_BUILDING_PERMITS',N'Одобренија за градење',N'Барања за одобрение за градење и статус на разгледувањето.',240,10,N'Барање',N'Разгледувања',N'Издадени дозволи',N'Во постапка',N'request'),
    (N'DIG',N'DIG_ACTIVITIES',N'Економски активности и иновации',N'Барања за економски активности, претприемништво и иновации.',168,10,N'Барање',N'Разгледувања',N'Одобрени барања',N'Во постапка',N'request'),
    (N'DIG',N'DIG_SUBSIDIES',N'Економски субвенции',N'Барања за субвенции за бизниси и иновации.',240,20,N'Барање',N'Разгледувања',N'Одобрени барања',N'Во постапка',N'subsidy'),
    (N'DIG',N'DIG_BUSINESS_SUPPORT',N'Поддршка за бизниси и стартапи',N'Барања за советување, инкубација и поддршка на претприемништвото.',120,30,N'Барање',N'Разгледувања',N'Обработени барања',N'Во постапка',N'request'),
    (N'DEMERG',N'DEMERG_COMPLAINTS',N'Жалби и барања за безбедност',N'Жалби и барања од граѓани во областа на безбедноста и вонредните состојби.',24,10,N'Жалби',N'Разгледувања',N'Решени жалби',N'Во постапка',N'complaint'),
    (N'DEMERG',N'DEMERG_INTERVENTIONS',N'Итни интервенции',N'Пријави што бараат координација или итна интервенција.',4,20,N'Пријави',N'Проверки',N'Завршени интервенции',N'Во постапка',N'request'),
    (N'ZPROK',N'ZPROK_PROCUREMENT',N'Активности за јавни набавки',N'Неделен преглед на активностите и договорите за јавни набавки.',240,10,N'Отворени активности',N'Склучени активности',N'Завршени договори',N'Активности на чекање',N'request'),
    (N'PRAV',N'PRAV_ENFORCEMENT',N'Барања за извршување',N'Барања и предмети за извршување.',240,10,N'Барање',N'Разгледувања',N'Завршени барања',N'Во постапка',N'request'),
    (N'PRAV',N'PRAV_OTHER',N'Други правни барања',N'Други барања за обработка од Правната служба.',240,20,N'Барање',N'Разгледувања',N'Завршени барања',N'Во постапка',N'request'),
    (N'DHEALTH',N'DHEALTH_SUBSIDIES',N'Здравствени субвенции',N'Апликации за субвенции и финансиска поддршка во здравството.',240,15,NULL,NULL,NULL,NULL,N'subsidy'),
    (N'DHEALTH',N'DHEALTH_SUBSIDY_COMPLAINTS',N'Жалби за здравствени субвенции',N'Жалби против одлуките и постапката за здравствени субвенции.',72,25,NULL,NULL,NULL,NULL,N'complaint'),
    (N'DBUJQ',N'DBUJQ_SUBSIDY_COMPLAINTS',N'Жалби за земјоделски субвенции',N'Жалби против одлуките и постапката за земјоделски субвенции.',72,15,NULL,NULL,NULL,NULL,N'complaint'),
    (N'KULT',N'KULT_SUBSIDY_COMPLAINTS',N'Жалби за субвенции за култура',N'Жалби против одлуките и постапката за субвенции за култура.',72,25,NULL,NULL,NULL,NULL,N'complaint'),
    (N'DMQS',N'DMQS_SUBSIDY_COMPLAINTS',N'Жалби за социјални субвенции',N'Жалби против одлуките и постапката за социјални субвенции.',72,25,NULL,NULL,NULL,NULL,N'complaint'),
    (N'DRIN',N'DRIN_SUBSIDY_COMPLAINTS',N'Жалби за субвенции за млади',N'Жалби против одлуките и постапката за субвенции за млади.',72,25,NULL,NULL,NULL,NULL,N'complaint'),
    (N'DSPORT',N'DSPORT_SUBSIDY_COMPLAINTS',N'Жалби за субвенции за спорт',N'Жалби против одлуките и постапката за субвенции за спорт.',72,25,NULL,NULL,NULL,NULL,N'complaint'),
    (N'DTUR',N'DTUR_SUBSIDY_COMPLAINTS',N'Жалби за субвенции за туризам',N'Жалби против одлуките и постапката за субвенции за туризам.',72,35,NULL,NULL,NULL,NULL,N'complaint'),
    (N'DIG',N'DIG_SUBSIDY_COMPLAINTS',N'Жалби за економски субвенции',N'Жалби против одлуките и постапката за економски субвенции.',72,25,NULL,NULL,NULL,NULL,N'complaint'),
    (N'DHEALTH',N'DHEALTH_VISITS_QKMF',N'Посети и услуги – Општа медицина (ЗД)',N'Посети и здравствени услуги дадени во Здравствениот дом.',NULL,40,NULL,NULL,NULL,NULL,N'volume'),
    (N'DHEALTH',N'DHEALTH_VISITS_QMU',N'Посети и услуги – Служба за итна медицинска помош',N'Посети и здравствени услуги дадени во Службата за итна медицинска помош.',NULL,50,NULL,NULL,NULL,NULL,N'volume'),
    (N'DHEALTH',N'DHEALTH_VISITS_DENTAL',N'Посети и услуги – Стоматолошка поликлиника',N'Посети и здравствени услуги дадени во Стоматолошката поликлиника.',NULL,60,NULL,NULL,NULL,NULL,N'volume');

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
    WHEN MATCHED THEN UPDATE SET target.name=source.service_name,target.description=source.description,target.sla_target_hours=source.sla_hours,target.sort_order=source.sort_order,target.window_type=source.window_type,target.updated_at=SYSUTCDATETIME()
    WHEN NOT MATCHED THEN INSERT(directorate_id,code,name,description,sla_target_hours,active,sort_order,window_type)
        VALUES(source.directorate_id,source.service_code,source.service_name,source.description,source.sla_hours,1,source.sort_order,source.window_type);

    -- Standard fields of subsidy and complaint windows; volume windows list their own fields.
    DECLARE @standard TABLE(window_type nvarchar(20),metric_key nvarchar(60),label nvarchar(200),metric_role nvarchar(20),sort_order int);
    INSERT INTO @standard VALUES
    (N'subsidy',N'received',N'Примени апликации',N'received',10),
    (N'subsidy',N'open',N'Во разгледување',N'open',20),
    (N'subsidy',N'resolved',N'Одобрени',N'resolved',30),
    (N'subsidy',N'rejected',N'Одбиени',N'resolved',40),
    (N'subsidy',N'approved_amount',N'Одобрен износ (ден.)',N'amount',50),
    (N'complaint',N'received',N'Примени жалби',N'received',10),
    (N'complaint',N'resolved',N'Решени',N'resolved',20),
    (N'complaint',N'unfounded',N'Неосновани',N'resolved',30),
    (N'complaint',N'open',N'Во постапка',N'open',40);
    DECLARE @custom TABLE(service_code nvarchar(100),metric_key nvarchar(60),label nvarchar(200),metric_role nvarchar(20),sort_order int);
    INSERT INTO @custom VALUES
    (N'DHEALTH_VISITS_QKMF',N'visits',N'Посети',N'volume',10),
    (N'DHEALTH_VISITS_QKMF',N'health_services',N'Здравствени услуги',N'volume',20),
    (N'DHEALTH_VISITS_QKMF',N'referrals',N'Упатувања',N'volume',30),
    (N'DHEALTH_VISITS_QMU',N'visits',N'Посети',N'volume',10),
    (N'DHEALTH_VISITS_QMU',N'health_services',N'Здравствени услуги',N'volume',20),
    (N'DHEALTH_VISITS_QMU',N'team_dispatches',N'Излегувања на екипа / амбулантно возило',N'volume',30),
    (N'DHEALTH_VISITS_DENTAL',N'visits',N'Посети',N'volume',10),
    (N'DHEALTH_VISITS_DENTAL',N'health_services',N'Здравствени услуги',N'volume',20);

    DECLARE @metrics TABLE(service_code nvarchar(100),metric_key nvarchar(60),label nvarchar(200),metric_role nvarchar(20),sort_order int);
    INSERT INTO @metrics(service_code,metric_key,label,metric_role,sort_order)
    SELECT service_code,N'received',received_label,N'received',10 FROM @services WHERE window_type=N'request' AND received_label IS NOT NULL
    UNION ALL SELECT service_code,N'reviewed',reviewed_label,N'informational',20 FROM @services WHERE window_type=N'request' AND reviewed_label IS NOT NULL
    UNION ALL SELECT service_code,N'resolved',resolved_label,N'resolved',30 FROM @services WHERE window_type=N'request' AND resolved_label IS NOT NULL
    UNION ALL SELECT service_code,N'open',open_label,N'open',40 FROM @services WHERE window_type=N'request' AND open_label IS NOT NULL
    UNION ALL SELECT s.service_code,st.metric_key,st.label,st.metric_role,st.sort_order FROM @services s JOIN @standard st ON st.window_type=s.window_type
    UNION ALL SELECT service_code,metric_key,label,metric_role,sort_order FROM @custom;

    MERGE dbo.citizen_service_metrics AS target
    USING (SELECT cs.id service_id,m.* FROM @metrics m JOIN dbo.citizen_services cs ON cs.code=m.service_code) AS source
        ON target.service_id=source.service_id AND target.metric_key=source.metric_key
    WHEN MATCHED THEN UPDATE SET target.label=source.label,target.metric_role=source.metric_role,target.sort_order=source.sort_order,target.active=1,target.updated_at=SYSUTCDATETIME()
    WHEN NOT MATCHED THEN INSERT(service_id,metric_key,label,metric_role,active,sort_order)
        VALUES(source.service_id,source.metric_key,source.label,source.metric_role,1,source.sort_order);

    -- Standardized windows keep only their standard fields; older fields stay in the history but are no longer asked.
    UPDATE m SET m.active=0,m.updated_at=SYSUTCDATETIME()
    FROM dbo.citizen_service_metrics m
    JOIN dbo.citizen_services cs ON cs.id=m.service_id
    JOIN @services s ON s.service_code=cs.code
    WHERE s.window_type<>N'request' AND m.active=1
      AND NOT EXISTS (SELECT 1 FROM @metrics x WHERE x.service_code=cs.code AND x.metric_key=m.metric_key);
END;
GO

EXEC dbo.usp_seed_citizen_service_catalog;
GO

-- Request KPIs per service (Mayor's dashboard and Google Sheets "Sherbimet"): same columns as before; volume windows
-- (visits and other services delivered) are left out, and only request roles are summed.
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
    JOIN dbo.directorates d ON d.id=r.directorate_id
    WHERE s.window_type<>N'volume';
GO

-- Weekly totals per directorate. open_after_forwarding = open requests and complaints minus the cases the directorate
-- forwarded that week with a cover letter (never below zero); volume and approved subsidy amounts are shown apart.
CREATE OR ALTER VIEW dbo.vw_citizen_service_directorate_weeks
AS
    WITH report_totals AS (
        SELECT
            r.id report_id,r.directorate_id,r.week_start,s.window_type,
            SUM(CASE WHEN m.metric_role=N'received' THEN v.metric_value ELSE 0 END) received_count,
            SUM(CASE WHEN m.metric_role=N'resolved' THEN v.metric_value ELSE 0 END) resolved_count,
            SUM(CASE WHEN m.metric_role=N'open' THEN v.metric_value ELSE 0 END) explicit_open_count,
            MAX(CASE WHEN m.metric_role=N'open' THEN 1 ELSE 0 END) has_open_metric,
            SUM(CASE WHEN m.metric_role=N'volume' THEN v.metric_value ELSE 0 END) volume_count,
            SUM(CASE WHEN m.metric_role=N'amount' AND s.window_type=N'subsidy' THEN COALESCE(v.amount_value,0) ELSE 0 END) approved_amount
        FROM dbo.citizen_service_weekly_reports r
        JOIN dbo.citizen_services s ON s.id=r.service_id
        JOIN dbo.citizen_service_weekly_values v ON v.report_id=r.id
        JOIN dbo.citizen_service_metrics m ON m.id=v.metric_id AND m.active=1
        GROUP BY r.id,r.directorate_id,r.week_start,s.window_type
    ),
    directorate_weeks AS (
        SELECT
            directorate_id,week_start,
            COUNT(*) reported_windows,
            SUM(CASE WHEN window_type<>N'volume' THEN received_count ELSE 0 END) received_count,
            SUM(CASE WHEN window_type<>N'volume' THEN resolved_count ELSE 0 END) resolved_count,
            SUM(CASE WHEN window_type=N'volume' THEN 0 WHEN has_open_metric=1 THEN explicit_open_count WHEN received_count>=resolved_count THEN received_count-resolved_count ELSE 0 END) open_count,
            SUM(volume_count) volume_count,
            SUM(approved_amount) approved_amount
        FROM report_totals
        GROUP BY directorate_id,week_start
    ),
    forwarded AS (
        SELECT from_directorate_id directorate_id,DATEADD(day,-(DATEDIFF(day,CONVERT(date,'19000101',112),forwarded_on)%7),forwarded_on) week_start,COUNT(*) forwarded_count
        FROM dbo.case_forwardings
        GROUP BY from_directorate_id,DATEADD(day,-(DATEDIFF(day,CONVERT(date,'19000101',112),forwarded_on)%7),forwarded_on)
    ),
    keys AS (
        SELECT directorate_id,week_start FROM directorate_weeks
        UNION
        SELECT directorate_id,week_start FROM forwarded
    )
    SELECT
        k.directorate_id,
        CAST(d.code AS nvarchar(30)) directorate_code,
        CAST(d.name AS nvarchar(200)) directorate_name,
        k.week_start,
        DATEADD(day,6,k.week_start) week_end,
        CAST(COALESCE(w.reported_windows,0) AS int) reported_windows,
        CAST(COALESCE(w.received_count,0) AS bigint) received_count,
        CAST(COALESCE(w.resolved_count,0) AS bigint) resolved_count,
        CAST(COALESCE(w.open_count,0) AS bigint) reported_open_count,
        CAST(COALESCE(f.forwarded_count,0) AS bigint) forwarded_count,
        CAST(CASE WHEN COALESCE(w.open_count,0)>COALESCE(f.forwarded_count,0) THEN COALESCE(w.open_count,0)-COALESCE(f.forwarded_count,0) ELSE 0 END AS bigint) open_after_forwarding,
        CAST(COALESCE(w.volume_count,0) AS bigint) volume_count,
        CAST(COALESCE(w.approved_amount,0) AS decimal(19,2)) approved_subsidy_amount
    FROM keys k
    JOIN dbo.directorates d ON d.id=k.directorate_id
    LEFT JOIN directorate_weeks w ON w.directorate_id=k.directorate_id AND w.week_start=k.week_start
    LEFT JOIN forwarded f ON f.directorate_id=k.directorate_id AND f.week_start=k.week_start;
GO
