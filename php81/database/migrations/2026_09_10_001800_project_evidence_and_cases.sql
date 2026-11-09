IF COL_LENGTH('dbo.projects','location_name') IS NULL ALTER TABLE dbo.projects ADD location_name nvarchar(250) NULL;
IF COL_LENGTH('dbo.projects','address') IS NULL ALTER TABLE dbo.projects ADD address nvarchar(500) NULL;
IF COL_LENGTH('dbo.projects','latitude') IS NULL ALTER TABLE dbo.projects ADD latitude decimal(10,7) NULL;
IF COL_LENGTH('dbo.projects','longitude') IS NULL ALTER TABLE dbo.projects ADD longitude decimal(10,7) NULL;
IF COL_LENGTH('dbo.projects','google_maps_url') IS NULL ALTER TABLE dbo.projects ADD google_maps_url nvarchar(1000) NULL;
IF COL_LENGTH('dbo.projects','physical_progress') IS NULL ALTER TABLE dbo.projects ADD physical_progress tinyint NOT NULL CONSTRAINT DF_projects_physical_progress DEFAULT(0) WITH VALUES;
IF COL_LENGTH('dbo.projects','financial_progress') IS NULL ALTER TABLE dbo.projects ADD financial_progress decimal(5,2) NOT NULL CONSTRAINT DF_projects_financial_progress DEFAULT(0) WITH VALUES;
IF COL_LENGTH('dbo.progress_updates','physical_progress') IS NULL ALTER TABLE dbo.progress_updates ADD physical_progress tinyint NULL;
IF COL_LENGTH('dbo.progress_updates','financial_progress') IS NULL ALTER TABLE dbo.progress_updates ADD financial_progress decimal(5,2) NULL;
IF COL_LENGTH('dbo.attachments','document_type') IS NULL ALTER TABLE dbo.attachments ADD document_type nvarchar(40) NULL;
IF COL_LENGTH('dbo.attachments','title') IS NULL ALTER TABLE dbo.attachments ADD title nvarchar(250) NULL;
IF COL_LENGTH('dbo.attachments','document_date') IS NULL ALTER TABLE dbo.attachments ADD document_date date NULL;

IF OBJECT_ID('dbo.project_photos','U') IS NULL
BEGIN
    CREATE TABLE dbo.project_photos(
        id bigint IDENTITY(1,1) NOT NULL PRIMARY KEY,
        project_id bigint NOT NULL,
        progress_update_id bigint NULL,
        attachment_id bigint NOT NULL,
        caption nvarchar(1000) NULL,
        taken_at datetime NULL,
        latitude decimal(10,7) NULL,
        longitude decimal(10,7) NULL,
        uploaded_by bigint NOT NULL,
        created_at datetime NULL,
        updated_at datetime NULL,
        CONSTRAINT FK_project_photos_project FOREIGN KEY(project_id) REFERENCES dbo.projects(id),
        CONSTRAINT FK_project_photos_progress FOREIGN KEY(progress_update_id) REFERENCES dbo.progress_updates(id),
        CONSTRAINT FK_project_photos_attachment FOREIGN KEY(attachment_id) REFERENCES dbo.attachments(id),
        CONSTRAINT FK_project_photos_user FOREIGN KEY(uploaded_by) REFERENCES dbo.users(id)
    );
    CREATE INDEX IX_project_photos_project ON dbo.project_photos(project_id,created_at);
END;

IF OBJECT_ID('dbo.project_user_access','U') IS NULL
BEGIN
    CREATE TABLE dbo.project_user_access(
        id bigint IDENTITY(1,1) NOT NULL PRIMARY KEY,
        project_id bigint NOT NULL,
        user_id bigint NOT NULL,
        access_level nvarchar(20) NOT NULL CONSTRAINT DF_project_user_access_level DEFAULT('viewer'),
        granted_by bigint NOT NULL,
        created_at datetime NOT NULL,
        CONSTRAINT UQ_project_user_access UNIQUE(project_id,user_id),
        CONSTRAINT CK_project_user_access_level CHECK(access_level IN('viewer','editor','manager')),
        CONSTRAINT FK_project_user_access_project FOREIGN KEY(project_id) REFERENCES dbo.projects(id),
        CONSTRAINT FK_project_user_access_user FOREIGN KEY(user_id) REFERENCES dbo.users(id),
        CONSTRAINT FK_project_user_access_granted_by FOREIGN KEY(granted_by) REFERENCES dbo.users(id)
    );
END;
IF OBJECT_ID('dbo.task_user_access','U') IS NULL
BEGIN
    CREATE TABLE dbo.task_user_access(
        id bigint IDENTITY(1,1) NOT NULL PRIMARY KEY,
        task_id bigint NOT NULL,
        user_id bigint NOT NULL,
        access_level nvarchar(20) NOT NULL CONSTRAINT DF_task_user_access_level DEFAULT('viewer'),
        granted_by bigint NOT NULL,
        created_at datetime NOT NULL,
        CONSTRAINT UQ_task_user_access UNIQUE(task_id,user_id),
        CONSTRAINT CK_task_user_access_level CHECK(access_level IN('viewer','editor')),
        CONSTRAINT FK_task_user_access_task FOREIGN KEY(task_id) REFERENCES dbo.project_tasks(id),
        CONSTRAINT FK_task_user_access_user FOREIGN KEY(user_id) REFERENCES dbo.users(id),
        CONSTRAINT FK_task_user_access_granted_by FOREIGN KEY(granted_by) REFERENCES dbo.users(id)
    );
END;

IF OBJECT_ID('dbo.administrative_cases','U') IS NULL
BEGIN
    CREATE TABLE dbo.administrative_cases(
        id bigint IDENTITY(1,1) NOT NULL PRIMARY KEY,
        case_number nvarchar(80) NOT NULL,
        case_type nvarchar(80) NOT NULL,
        subject nvarchar(250) NOT NULL,
        description nvarchar(max) NULL,
        originating_directorate_id bigint NOT NULL,
        responsible_user_id bigint NULL,
        status nvarchar(30) NOT NULL CONSTRAINT DF_administrative_cases_status DEFAULT('open'),
        priority nvarchar(20) NOT NULL CONSTRAINT DF_administrative_cases_priority DEFAULT('normal'),
        opened_at date NOT NULL,
        closed_at date NULL,
        created_by bigint NOT NULL,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        row_version timestamp NOT NULL,
        CONSTRAINT UQ_administrative_cases_number UNIQUE(case_number),
        CONSTRAINT FK_administrative_cases_origin FOREIGN KEY(originating_directorate_id) REFERENCES dbo.directorates(id),
        CONSTRAINT FK_administrative_cases_user FOREIGN KEY(responsible_user_id) REFERENCES dbo.users(id),
        CONSTRAINT FK_administrative_cases_created_by FOREIGN KEY(created_by) REFERENCES dbo.users(id)
    );
END;
IF OBJECT_ID('dbo.case_directorates','U') IS NULL
BEGIN
    CREATE TABLE dbo.case_directorates(
        id bigint IDENTITY(1,1) NOT NULL PRIMARY KEY,
        case_id bigint NOT NULL,
        directorate_id bigint NOT NULL,
        relationship_type nvarchar(30) NOT NULL,
        responsible_user_id bigint NULL,
        status nvarchar(20) NOT NULL CONSTRAINT DF_case_directorates_status DEFAULT('open'),
        assigned_by bigint NOT NULL,
        assigned_at datetime NOT NULL,
        due_at date NULL,
        CONSTRAINT UQ_case_directorates UNIQUE(case_id,directorate_id,relationship_type),
        CONSTRAINT FK_case_directorates_case FOREIGN KEY(case_id) REFERENCES dbo.administrative_cases(id),
        CONSTRAINT FK_case_directorates_directorate FOREIGN KEY(directorate_id) REFERENCES dbo.directorates(id),
        CONSTRAINT FK_case_directorates_user FOREIGN KEY(responsible_user_id) REFERENCES dbo.users(id),
        CONSTRAINT FK_case_directorates_assigned_by FOREIGN KEY(assigned_by) REFERENCES dbo.users(id)
    );
END;
IF OBJECT_ID('dbo.case_tasks','U') IS NULL
BEGIN
    CREATE TABLE dbo.case_tasks(
        id bigint IDENTITY(1,1) NOT NULL PRIMARY KEY,
        case_id bigint NOT NULL,
        case_directorate_id bigint NULL,
        title nvarchar(250) NOT NULL,
        description nvarchar(max) NULL,
        assigned_to bigint NULL,
        due_at date NULL,
        status nvarchar(30) NOT NULL CONSTRAINT DF_case_tasks_status DEFAULT('open'),
        completed_at datetime NULL,
        created_by bigint NOT NULL,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        CONSTRAINT FK_case_tasks_case FOREIGN KEY(case_id) REFERENCES dbo.administrative_cases(id),
        CONSTRAINT FK_case_tasks_directorate FOREIGN KEY(case_directorate_id) REFERENCES dbo.case_directorates(id),
        CONSTRAINT FK_case_tasks_user FOREIGN KEY(assigned_to) REFERENCES dbo.users(id),
        CONSTRAINT FK_case_tasks_created_by FOREIGN KEY(created_by) REFERENCES dbo.users(id)
    );
END;
IF OBJECT_ID('dbo.administrative_acts','U') IS NULL
BEGIN
    CREATE TABLE dbo.administrative_acts(
        id bigint IDENTITY(1,1) NOT NULL PRIMARY KEY,
        case_id bigint NOT NULL,
        act_number nvarchar(100) NOT NULL,
        act_type nvarchar(60) NOT NULL,
        act_date date NOT NULL,
        effective_date date NULL,
        status nvarchar(30) NOT NULL,
        description nvarchar(max) NULL,
        attachment_id bigint NULL,
        created_by bigint NOT NULL,
        created_at datetime NOT NULL,
        CONSTRAINT UQ_administrative_acts_number UNIQUE(act_number),
        CONSTRAINT FK_administrative_acts_case FOREIGN KEY(case_id) REFERENCES dbo.administrative_cases(id),
        CONSTRAINT FK_administrative_acts_attachment FOREIGN KEY(attachment_id) REFERENCES dbo.attachments(id),
        CONSTRAINT FK_administrative_acts_created_by FOREIGN KEY(created_by) REFERENCES dbo.users(id)
    );
END;
IF OBJECT_ID('dbo.administrative_act_relations','U') IS NULL
BEGIN
    CREATE TABLE dbo.administrative_act_relations(
        id bigint IDENTITY(1,1) NOT NULL PRIMARY KEY,
        parent_act_id bigint NOT NULL,
        child_act_id bigint NOT NULL,
        relation_type nvarchar(30) NOT NULL,
        created_by bigint NOT NULL,
        created_at datetime NOT NULL,
        CONSTRAINT UQ_administrative_act_relations UNIQUE(parent_act_id,child_act_id,relation_type),
        CONSTRAINT FK_act_relations_parent FOREIGN KEY(parent_act_id) REFERENCES dbo.administrative_acts(id),
        CONSTRAINT FK_act_relations_child FOREIGN KEY(child_act_id) REFERENCES dbo.administrative_acts(id),
        CONSTRAINT FK_act_relations_user FOREIGN KEY(created_by) REFERENCES dbo.users(id)
    );
END;
IF OBJECT_ID('dbo.case_actions','U') IS NULL
BEGIN
    CREATE TABLE dbo.case_actions(
        id bigint IDENTITY(1,1) NOT NULL PRIMARY KEY,
        case_id bigint NOT NULL,
        action_type nvarchar(50) NOT NULL,
        description nvarchar(max) NOT NULL,
        previous_status nvarchar(30) NULL,
        new_status nvarchar(30) NULL,
        action_date datetime NOT NULL,
        performed_by bigint NOT NULL,
        related_act_id bigint NULL,
        related_attachment_id bigint NULL,
        CONSTRAINT FK_case_actions_case FOREIGN KEY(case_id) REFERENCES dbo.administrative_cases(id),
        CONSTRAINT FK_case_actions_user FOREIGN KEY(performed_by) REFERENCES dbo.users(id),
        CONSTRAINT FK_case_actions_act FOREIGN KEY(related_act_id) REFERENCES dbo.administrative_acts(id),
        CONSTRAINT FK_case_actions_attachment FOREIGN KEY(related_attachment_id) REFERENCES dbo.attachments(id)
    );
    CREATE INDEX IX_case_actions_case ON dbo.case_actions(case_id,action_date);
END;
IF OBJECT_ID('dbo.document_access_links','U') IS NULL
BEGIN
    CREATE TABLE dbo.document_access_links(
        id bigint IDENTITY(1,1) NOT NULL PRIMARY KEY,
        token_hash char(64) NOT NULL,
        entity_type nvarchar(100) NOT NULL,
        entity_id bigint NOT NULL,
        created_by bigint NOT NULL,
        expires_at datetime NOT NULL,
        revoked_at datetime NULL,
        created_at datetime NOT NULL,
        CONSTRAINT UQ_document_access_links_hash UNIQUE(token_hash),
        CONSTRAINT FK_document_access_links_user FOREIGN KEY(created_by) REFERENCES dbo.users(id)
    );
END;

IF NOT EXISTS (SELECT 1 FROM dbo.roles WHERE code='public_relations')
    INSERT INTO dbo.roles(code,name,financial_role,created_at,updated_at) VALUES('public_relations','Односи со јавноста',0,SYSUTCDATETIME(),SYSUTCDATETIME());
DECLARE @pr_role bigint=(SELECT id FROM dbo.roles WHERE code='public_relations');
IF NOT EXISTS (SELECT 1 FROM dbo.permissions WHERE action='view' AND resource_type='case') INSERT INTO dbo.permissions(action,resource_type,description,created_at,updated_at) VALUES('view','case','View administrative cases',SYSUTCDATETIME(),SYSUTCDATETIME());
IF NOT EXISTS (SELECT 1 FROM dbo.permissions WHERE action='create' AND resource_type='case') INSERT INTO dbo.permissions(action,resource_type,description,created_at,updated_at) VALUES('create','case','Create administrative cases',SYSUTCDATETIME(),SYSUTCDATETIME());
IF NOT EXISTS (SELECT 1 FROM dbo.permissions WHERE action='manage' AND resource_type='project_share') INSERT INTO dbo.permissions(action,resource_type,description,created_at,updated_at) VALUES('manage','project_share','Share projects and tasks',SYSUTCDATETIME(),SYSUTCDATETIME());
IF NOT EXISTS (SELECT 1 FROM dbo.permissions WHERE action='view' AND resource_type='document_link') INSERT INTO dbo.permissions(action,resource_type,description,created_at,updated_at) VALUES('view','document_link','Create document access links',SYSUTCDATETIME(),SYSUTCDATETIME());
INSERT INTO dbo.permission_role(permission_id,role_id)
SELECT p.id,@pr_role FROM dbo.permissions p WHERE p.action='view' AND p.resource_type IN('project','case','attachment') AND NOT EXISTS(SELECT 1 FROM dbo.permission_role pr WHERE pr.permission_id=p.id AND pr.role_id=@pr_role);
GO
