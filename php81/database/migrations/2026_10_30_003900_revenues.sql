-- Release 2026.10.30.01: Të hyrat (daily revenue report of the treasury account), forward-only and idempotent.
-- * revenue_categories / revenue_category_rules: revenue types and the UNIREF rules that assign them (editable in
--   /revenues/categories without a new release). Rule = UNIREF type prefix (e.g. B2, F) + optional keyword; the
--   lowest priority wins.
-- * revenue_import_rows: preview of one uploaded report (import_batches.type = revenue_daily). Nothing reaches
--   revenue_transactions before confirmation.
-- * revenue_transactions: one row per payment. raw_* keep every source value exactly as in the file; the other
--   columns are what was read from them. source_key (content of the payment + n-th repetition in the same file) is
--   unique, so the same report or overlapping reports are never stored twice.

IF OBJECT_ID(N'dbo.revenue_categories', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.revenue_categories (
        id int IDENTITY(1,1) NOT NULL,
        code nvarchar(40) NOT NULL,
        name nvarchar(200) NOT NULL,
        revenue_group char(2) NULL,
        sort_order int NOT NULL CONSTRAINT DF_revenue_categories_sort DEFAULT (100),
        active bit NOT NULL CONSTRAINT DF_revenue_categories_active DEFAULT (1),
        created_at datetime2 NOT NULL CONSTRAINT DF_revenue_categories_created DEFAULT (SYSUTCDATETIME()),
        updated_at datetime2 NOT NULL CONSTRAINT DF_revenue_categories_updated DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_revenue_categories PRIMARY KEY (id),
        CONSTRAINT UQ_revenue_categories_code UNIQUE (code),
        CONSTRAINT CK_revenue_categories_group CHECK (revenue_group IS NULL OR revenue_group IN ('10','20','30','40'))
    );
END;
GO
IF OBJECT_ID(N'dbo.revenue_category_rules', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.revenue_category_rules (
        id int IDENTITY(1,1) NOT NULL,
        uniref_prefix nvarchar(2) NOT NULL,
        keyword nvarchar(100) NULL,
        category_id int NOT NULL,
        priority int NOT NULL CONSTRAINT DF_revenue_category_rules_priority DEFAULT (100),
        active bit NOT NULL CONSTRAINT DF_revenue_category_rules_active DEFAULT (1),
        created_by bigint NULL,
        created_at datetime2 NOT NULL CONSTRAINT DF_revenue_category_rules_created DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_revenue_category_rules PRIMARY KEY (id),
        CONSTRAINT FK_revenue_category_rules_category FOREIGN KEY (category_id) REFERENCES dbo.revenue_categories(id),
        CONSTRAINT FK_revenue_category_rules_user FOREIGN KEY (created_by) REFERENCES dbo.users(id),
        CONSTRAINT CK_revenue_category_rules_prefix CHECK (LEN(uniref_prefix) BETWEEN 1 AND 2)
    );
END;
GO
IF OBJECT_ID(N'dbo.revenue_import_rows', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.revenue_import_rows (
        id bigint IDENTITY(1,1) NOT NULL,
        import_batch_id uniqueidentifier NOT NULL,
        source_row_number int NOT NULL,
        row_status nvarchar(20) NOT NULL,
        errors nvarchar(300) NULL,
        source_key nvarchar(80) NULL,
        occurrence int NOT NULL DEFAULT (0),
        raw_row_number nvarchar(300) NULL,
        raw_amount nvarchar(300) NULL,
        raw_beneficiary nvarchar(300) NULL,
        raw_payer nvarchar(300) NULL,
        raw_revenue_group nvarchar(300) NULL,
        raw_description nvarchar(2000) NULL,
        raw_payment_date nvarchar(300) NULL,
        raw_uniref nvarchar(300) NULL,
        payment_date date NULL,
        amount decimal(19,2) NULL,
        revenue_group char(2) NULL,
        uniref nvarchar(20) NULL,
        uniref_valid bit NOT NULL DEFAULT (0),
        uniref_municipality char(2) NULL,
        uniref_type nvarchar(2) NULL,
        uniref_subtype nchar(1) NULL,
        uniref_reference nvarchar(10) NULL,
        uniref_generic bit NOT NULL DEFAULT (0),
        payer_name nvarchar(200) NULL,
        payer_type nvarchar(20) NOT NULL DEFAULT (N'unknown'),
        identification_number nvarchar(20) NULL,
        identification_type nvarchar(20) NULL,
        invoice_number nvarchar(40) NULL,
        taxpayer_number nvarchar(20) NULL,
        property_number nvarchar(40) NULL,
        plate_number nvarchar(15) NULL,
        case_reference nvarchar(40) NULL,
        channel nvarchar(30) NULL,
        category_id int NOT NULL,
        classification_method nvarchar(20) NOT NULL,
        extraction nvarchar(1000) NULL,
        warnings nvarchar(300) NULL,
        CONSTRAINT PK_revenue_import_rows PRIMARY KEY (id),
        CONSTRAINT FK_revenue_import_rows_batch FOREIGN KEY (import_batch_id) REFERENCES dbo.import_batches(id),
        CONSTRAINT CK_revenue_import_rows_status CHECK (row_status IN (N'new',N'existing',N'duplicate_in_file',N'invalid'))
    );
    CREATE INDEX IX_revenue_import_rows_batch ON dbo.revenue_import_rows(import_batch_id,row_status,source_row_number);
END;
GO
IF OBJECT_ID(N'dbo.revenue_transactions', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.revenue_transactions (
        id bigint IDENTITY(1,1) NOT NULL,
        source_key nvarchar(80) NOT NULL,
        import_batch_id uniqueidentifier NOT NULL,
        source_row_number int NOT NULL,
        raw_row_number nvarchar(300) NULL,
        raw_amount nvarchar(300) NULL,
        raw_beneficiary nvarchar(300) NULL,
        raw_payer nvarchar(300) NULL,
        raw_revenue_group nvarchar(300) NULL,
        raw_description nvarchar(2000) NULL,
        raw_payment_date nvarchar(300) NULL,
        raw_uniref nvarchar(300) NULL,
        payment_date date NULL,
        amount decimal(19,2) NULL,
        revenue_group char(2) NULL,
        uniref nvarchar(20) NULL,
        uniref_valid bit NOT NULL DEFAULT (0),
        uniref_municipality char(2) NULL,
        uniref_type nvarchar(2) NULL,
        uniref_subtype nchar(1) NULL,
        uniref_reference nvarchar(10) NULL,
        uniref_generic bit NOT NULL DEFAULT (0),
        payer_name nvarchar(200) NULL,
        payer_type nvarchar(20) NOT NULL DEFAULT (N'unknown'),
        identification_number nvarchar(20) NULL,
        identification_type nvarchar(20) NULL,
        invoice_number nvarchar(40) NULL,
        taxpayer_number nvarchar(20) NULL,
        property_number nvarchar(40) NULL,
        plate_number nvarchar(15) NULL,
        case_reference nvarchar(40) NULL,
        channel nvarchar(30) NULL,
        category_id int NOT NULL,
        classification_method nvarchar(20) NOT NULL,
        extraction nvarchar(1000) NULL,
        warnings nvarchar(300) NULL,
        classified_by bigint NULL,
        classified_at datetime2 NULL,
        created_at datetime2 NOT NULL CONSTRAINT DF_revenue_transactions_created DEFAULT (SYSUTCDATETIME()),
        updated_at datetime2 NOT NULL CONSTRAINT DF_revenue_transactions_updated DEFAULT (SYSUTCDATETIME()),
        row_version rowversion NOT NULL,
        CONSTRAINT PK_revenue_transactions PRIMARY KEY (id),
        CONSTRAINT UQ_revenue_transactions_source_key UNIQUE (source_key),
        CONSTRAINT FK_revenue_transactions_batch FOREIGN KEY (import_batch_id) REFERENCES dbo.import_batches(id),
        CONSTRAINT FK_revenue_transactions_category FOREIGN KEY (category_id) REFERENCES dbo.revenue_categories(id),
        CONSTRAINT FK_revenue_transactions_user FOREIGN KEY (classified_by) REFERENCES dbo.users(id),
        CONSTRAINT CK_revenue_transactions_paid CHECK (amount > 0 AND payment_date IS NOT NULL),
        CONSTRAINT CK_revenue_transactions_method CHECK (classification_method IN (N'uniref',N'keyword',N'group',N'manual',N'none'))
    );
    CREATE INDEX IX_revenue_transactions_date ON dbo.revenue_transactions(payment_date) INCLUDE (amount,category_id,revenue_group);
    CREATE INDEX IX_revenue_transactions_category ON dbo.revenue_transactions(category_id,payment_date) INCLUDE (amount);
    CREATE INDEX IX_revenue_transactions_uniref ON dbo.revenue_transactions(uniref);
    CREATE INDEX IX_revenue_transactions_identification ON dbo.revenue_transactions(identification_number) WHERE identification_number IS NOT NULL;
    CREATE INDEX IX_revenue_transactions_plate ON dbo.revenue_transactions(plate_number) WHERE plate_number IS NOT NULL;
    CREATE INDEX IX_revenue_transactions_invoice ON dbo.revenue_transactions(invoice_number) WHERE invoice_number IS NOT NULL;
    CREATE INDEX IX_revenue_transactions_taxpayer ON dbo.revenue_transactions(taxpayer_number) WHERE taxpayer_number IS NOT NULL;
END;
GO

DECLARE @categories TABLE (code nvarchar(40), name nvarchar(200), revenue_group char(2), sort_order int);
INSERT INTO @categories VALUES
    (N'property_tax',N'Данок на имот',N'10',10),
    (N'health',N'Здравство – партиципации и уверенија',N'20',20),
    (N'edu_kindergarten',N'Образование – предучилишни установи (градинки)',N'30',30),
    (N'edu_halls',N'Образование – користење спортски сали',N'30',40),
    (N'edu_duplicates',N'Образование – дупликати од дипломи и свидетелства',N'30',50),
    (N'edu_other',N'Образование – други',N'30',60),
    (N'vehicle_registration',N'Општинска такса за регистрација на возила',N'40',70),
    (N'cadastre_data',N'Катастар – податоци и уверенија',N'40',80),
    (N'cadastre_services',N'Катастар – трансакции и услуги',N'40',90),
    (N'traffic_fines',N'Казни – сообраќај и паркирање',N'40',100),
    (N'vehicle_impound',N'Одземање возила',N'40',110),
    (N'public_space',N'Користење на јавна површина и јавен имот',N'40',120),
    (N'waste',N'Надомест за собирање отпад',N'40',130),
    (N'building_permits',N'Урбанизам – одобренија за градење',N'40',140),
    (N'legalisation',N'Урбанизам – легализација',N'40',150),
    (N'urban_services',N'Урбанизам – информации и услуги',N'40',160),
    (N'infrastructure_fee',N'Надомест за уредување на градежно земјиште',N'40',170),
    (N'rent',N'Закупнина за општински имот',N'40',180),
    (N'civil_registry',N'Матична евиденција и управни услуги',N'40',190),
    (N'library',N'Култура – библиотека',N'40',200),
    (N'archive',N'Архив – техничка документација',N'40',210),
    (N'business_permits',N'Бизнис – дозволи и такси',N'40',220),
    (N'environment_permits',N'Еколошки дозволи',N'40',230),
    (N'unclassified',N'Некласифицирани',NULL,240);
INSERT INTO dbo.revenue_categories(code,name,revenue_group,sort_order)
SELECT c.code,c.name,c.revenue_group,c.sort_order FROM @categories c WHERE NOT EXISTS (SELECT 1 FROM dbo.revenue_categories x WHERE x.code=c.code);

-- Rules are seeded once; later changes are made in production and are not overwritten.
IF NOT EXISTS (SELECT 1 FROM dbo.revenue_category_rules)
BEGIN
    DECLARE @rules TABLE (uniref_prefix nvarchar(2), keyword nvarchar(100), category_code nvarchar(40), priority int);
    INSERT INTO @rules VALUES
    (N'B2',NULL,N'property_tax',100),
    (N'B4',NULL,N'vehicle_registration',100),
    (N'BM',NULL,N'waste',100),
    (N'MM',NULL,N'waste',100),
    (N'GF',NULL,N'cadastre_data',100),
    (N'G4',NULL,N'cadastre_services',100),
    (N'N9',NULL,N'traffic_fines',100),
    (N'M5',NULL,N'vehicle_impound',100),
    (N'M8',NULL,N'public_space',100),
    (N'H8',NULL,N'health',100),
    (N'Q4',NULL,N'civil_registry',100),
    (N'Q4',N'PLAN',N'urban_services',50),
    (N'R8',NULL,N'library',100),
    (N'R4',NULL,N'archive',100),
    (N'U4',NULL,N'building_permits',100),
    (N'UA',NULL,N'legalisation',100),
    (N'UB',NULL,N'business_permits',100),
    (N'E6',NULL,N'business_permits',100),
    (N'UL',NULL,N'environment_permits',100),
    (N'D8',NULL,N'infrastructure_fee',100),
    (N'A8',NULL,N'rent',100),
    (N'F1',NULL,N'edu_kindergarten',100),
    (N'F2',NULL,N'edu_kindergarten',100),
    (N'F3',NULL,N'edu_kindergarten',100),
    (N'F4',NULL,N'edu_kindergarten',100),
    (N'F5',NULL,N'edu_kindergarten',100),
    (N'F6',NULL,N'edu_kindergarten',100),
    (N'F7',NULL,N'edu_kindergarten',100),
    (N'F8',NULL,N'edu_kindergarten',100),
    (N'F9',NULL,N'edu_duplicates',100),
    (N'FA',NULL,N'edu_duplicates',100),
    (N'FB',NULL,N'edu_duplicates',100),
    (N'FP',NULL,N'edu_halls',100),
    (N'FS',NULL,N'edu_halls',100),
    (N'F',N'SALL',N'edu_halls',60),
    (N'F',NULL,N'edu_other',200);
    INSERT INTO dbo.revenue_category_rules(uniref_prefix,keyword,category_id,priority)
    SELECT r.uniref_prefix,r.keyword,c.id,r.priority FROM @rules r JOIN dbo.revenue_categories c ON c.code=r.category_code;
END;
GO
