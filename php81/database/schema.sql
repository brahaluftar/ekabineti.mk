-- Generated from the verified current e-Kabineti SQL Server schema.
-- The installer executes this only against a newly created empty database.
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
SET XACT_ABORT ON;
GO
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[attachments](
	[id] [bigint] IDENTITY(1,1) NOT NULL,
	[entity_type] [nvarchar](100) NOT NULL,
	[entity_id] [bigint] NOT NULL,
	[original_name] [nvarchar](255) NOT NULL,
	[storage_key] [nvarchar](500) NOT NULL,
	[checksum] [nchar](64) NOT NULL,
	[size_bytes] [bigint] NOT NULL,
	[mime_type] [nvarchar](150) NOT NULL,
	[version] [int] NOT NULL,
	[classification] [nvarchar](30) NOT NULL,
	[scan_status] [nvarchar](20) NOT NULL,
	[uploaded_by] [bigint] NOT NULL,
	[created_at] [datetime] NULL,
	[updated_at] [datetime] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY]
GO
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[audit_events](
	[id] [bigint] IDENTITY(1,1) NOT NULL,
	[actor_id] [bigint] NULL,
	[action] [nvarchar](100) NOT NULL,
	[entity_type] [nvarchar](120) NOT NULL,
	[entity_id] [nvarchar](80) NULL,
	[before_state] [nvarchar](max) NULL,
	[after_state] [nvarchar](max) NULL,
	[ip_address] [nvarchar](45) NULL,
	[correlation_id] [uniqueidentifier] NOT NULL,
	[reason] [nvarchar](max) NULL,
	[occurred_at] [datetime] NOT NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[budget_allocations](
	[id] [bigint] IDENTITY(1,1) NOT NULL,
	[budget_line_id] [bigint] NOT NULL,
	[project_id] [bigint] NULL,
	[task_id] [bigint] NULL,
	[fiscal_year] [smallint] NOT NULL,
	[period] [tinyint] NOT NULL,
	[planned_amount] [decimal](19, 4) NOT NULL,
	[current_amount] [decimal](19, 4) NOT NULL,
	[forecast_amount] [decimal](19, 4) NOT NULL,
	[created_at] [datetime] NULL,
	[updated_at] [datetime] NULL,
	[row_version] [timestamp] NOT NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY]
GO
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[budget_change_requests](
	[id] [bigint] IDENTITY(1,1) NOT NULL,
	[type] [nvarchar](30) NOT NULL,
	[source_allocation_id] [bigint] NULL,
	[target_allocation_id] [bigint] NULL,
	[amount] [decimal](19, 4) NOT NULL,
	[fiscal_year] [smallint] NOT NULL,
	[justification] [nvarchar](max) NOT NULL,
	[impact] [nvarchar](max) NULL,
	[urgency] [nvarchar](20) NOT NULL,
	[workflow_status] [nvarchar](30) NOT NULL,
	[requested_by] [bigint] NOT NULL,
	[approved_by] [bigint] NULL,
	[approved_at] [datetime] NULL,
	[created_at] [datetime] NULL,
	[updated_at] [datetime] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[budget_cycles](
	[id] [bigint] IDENTITY(1,1) NOT NULL,
	[name] [nvarchar](150) NOT NULL,
	[base_year] [smallint] NOT NULL,
	[horizon] [tinyint] NOT NULL,
	[status] [nvarchar](30) NOT NULL,
	[starts_at] [date] NULL,
	[ends_at] [date] NULL,
	[created_at] [datetime] NULL,
	[updated_at] [datetime] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY]
GO
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[budget_lines](
	[id] [bigint] IDENTITY(1,1) NOT NULL,
	[budget_version_id] [bigint] NOT NULL,
	[directorate_id] [bigint] NOT NULL,
	[program_id] [bigint] NULL,
	[code] [nvarchar](60) NOT NULL,
	[name] [nvarchar](250) NOT NULL,
	[economic_code] [nvarchar](40) NOT NULL,
	[funding_source] [nvarchar](100) NOT NULL,
	[budget_category] [nvarchar](40) NULL,
	[own_revenue] [decimal](19, 4) NOT NULL,
	[government_grant] [decimal](19, 4) NOT NULL,
	[health_grant] [decimal](19, 4) NOT NULL,
	[education_grant] [decimal](19, 4) NOT NULL,
	[total_amount]  AS (CONVERT([decimal](19,4),(([own_revenue]+[government_grant])+[health_grant])+[education_grant])) PERSISTED,
	[imported_available_amount] [decimal](19, 4) NULL,
	[imported_available_as_of] [date] NULL,
	[currency] [nchar](3) NOT NULL,
	[created_at] [datetime] NULL,
	[updated_at] [datetime] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY]
GO
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[budget_transactions](
	[id] [bigint] IDENTITY(1,1) NOT NULL,
	[budget_allocation_id] [bigint] NOT NULL,
	[type] [nvarchar](30) NOT NULL,
	[amount] [decimal](19, 4) NOT NULL,
	[direction] [nchar](1) NOT NULL,
	[reference_type] [nvarchar](100) NOT NULL,
	[reference_id] [bigint] NOT NULL,
	[idempotency_key] [uniqueidentifier] NOT NULL,
	[posted_by] [bigint] NOT NULL,
	[posted_at] [datetime] NOT NULL,
	[created_at] [datetime] NULL,
	[updated_at] [datetime] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY]
GO
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[budget_versions](
	[id] [bigint] IDENTITY(1,1) NOT NULL,
	[budget_cycle_id] [bigint] NOT NULL,
	[scenario] [nvarchar](30) NOT NULL,
	[version_no] [int] NOT NULL,
	[status] [nvarchar](30) NOT NULL,
	[is_official] [bit] NOT NULL,
	[approved_at] [datetime] NULL,
	[approved_by] [bigint] NULL,
	[created_at] [datetime] NULL,
	[updated_at] [datetime] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY]
GO
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[cache](
	[key] [nvarchar](255) NOT NULL,
	[value] [nvarchar](max) NOT NULL,
	[expiration] [bigint] NOT NULL,
 CONSTRAINT [cache_key_primary] PRIMARY KEY CLUSTERED 
(
	[key] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[cache_locks](
	[key] [nvarchar](255) NOT NULL,
	[owner] [nvarchar](255) NOT NULL,
	[expiration] [bigint] NOT NULL,
 CONSTRAINT [cache_locks_key_primary] PRIMARY KEY CLUSTERED 
(
	[key] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY]
GO
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[commitments](
	[id] [bigint] IDENTITY(1,1) NOT NULL,
	[budget_allocation_id] [bigint] NOT NULL,
	[reservation_id] [bigint] NULL,
	[reference] [nvarchar](100) NOT NULL,
	[supplier] [nvarchar](250) NOT NULL,
	[amount] [decimal](19, 4) NOT NULL,
	[committed_at] [date] NOT NULL,
	[status] [nvarchar](20) NOT NULL,
	[created_by] [bigint] NOT NULL,
	[created_at] [datetime] NULL,
	[updated_at] [datetime] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY]
GO
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[directorates](
	[id] [bigint] IDENTITY(1,1) NOT NULL,
	[code] [nvarchar](30) NOT NULL,
	[name] [nvarchar](200) NOT NULL,
	[active] [bit] NOT NULL,
	[created_at] [datetime] NULL,
	[updated_at] [datetime] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY]
GO
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[expenditures](
	[id] [bigint] IDENTITY(1,1) NOT NULL,
	[commitment_id] [bigint] NULL,
	[budget_allocation_id] [bigint] NOT NULL,
	[source_reference] [nvarchar](120) NOT NULL,
	[amount] [decimal](19, 4) NOT NULL,
	[accounting_date] [date] NOT NULL,
	[exception_reason] [nvarchar](max) NULL,
	[created_by] [bigint] NOT NULL,
	[created_at] [datetime] NULL,
	[updated_at] [datetime] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[failed_jobs](
	[id] [bigint] IDENTITY(1,1) NOT NULL,
	[uuid] [nvarchar](255) NOT NULL,
	[connection] [nvarchar](255) NOT NULL,
	[queue] [nvarchar](255) NOT NULL,
	[payload] [nvarchar](max) NOT NULL,
	[exception] [nvarchar](max) NOT NULL,
	[failed_at] [datetime] NOT NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[import_batches](
	[id] [uniqueidentifier] NOT NULL,
	[type] [nvarchar](50) NOT NULL,
	[storage_key] [nvarchar](500) NOT NULL,
	[status] [nvarchar](20) NOT NULL,
	[summary] [nvarchar](max) NULL,
	[errors] [nvarchar](max) NULL,
	[created_by] [bigint] NOT NULL,
	[confirmed_at] [datetime] NULL,
	[created_at] [datetime] NULL,
	[updated_at] [datetime] NULL,
 CONSTRAINT [import_batches_id_primary] PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[import_rows](
	[id] [bigint] IDENTITY(1,1) NOT NULL,
	[import_batch_id] [uniqueidentifier] NOT NULL,
	[external_row_key] [nvarchar](150) NOT NULL,
	[payload] [nvarchar](max) NOT NULL,
	[status] [nvarchar](20) NOT NULL,
	[errors] [nvarchar](max) NULL,
	[created_at] [datetime] NULL,
	[updated_at] [datetime] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[job_batches](
	[id] [nvarchar](255) NOT NULL,
	[name] [nvarchar](255) NOT NULL,
	[total_jobs] [int] NOT NULL,
	[pending_jobs] [int] NOT NULL,
	[failed_jobs] [int] NOT NULL,
	[failed_job_ids] [nvarchar](max) NOT NULL,
	[options] [nvarchar](max) NULL,
	[cancelled_at] [int] NULL,
	[created_at] [int] NOT NULL,
	[finished_at] [int] NULL,
 CONSTRAINT [job_batches_id_primary] PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[jobs](
	[id] [bigint] IDENTITY(1,1) NOT NULL,
	[queue] [nvarchar](255) NOT NULL,
	[payload] [nvarchar](max) NOT NULL,
	[attempts] [smallint] NOT NULL,
	[reserved_at] [int] NULL,
	[available_at] [int] NOT NULL,
	[created_at] [int] NOT NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[migrations](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[migration] [nvarchar](255) NOT NULL,
	[batch] [int] NOT NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY]
GO
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[milestones](
	[id] [bigint] IDENTITY(1,1) NOT NULL,
	[project_id] [bigint] NOT NULL,
	[name] [nvarchar](200) NOT NULL,
	[due_at] [date] NOT NULL,
	[expected_result] [nvarchar](max) NULL,
	[status] [nvarchar](30) NOT NULL,
	[created_at] [datetime] NULL,
	[updated_at] [datetime] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[notifications](
	[id] [uniqueidentifier] NOT NULL,
	[recipient_id] [bigint] NOT NULL,
	[channel] [nvarchar](20) NOT NULL,
	[template] [nvarchar](100) NOT NULL,
	[subject] [nvarchar](250) NOT NULL,
	[payload] [nvarchar](max) NOT NULL,
	[status] [nvarchar](20) NOT NULL,
	[retry_count] [int] NOT NULL,
	[sent_at] [datetime] NULL,
	[read_at] [datetime] NULL,
	[created_at] [datetime] NULL,
	[updated_at] [datetime] NULL,
	[provider_request_id] [nvarchar](100) NULL,
	[last_error] [nvarchar](max) NULL,
 CONSTRAINT [notifications_id_primary] PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[password_reset_tokens](
	[email] [nvarchar](255) NOT NULL,
	[token] [nvarchar](255) NOT NULL,
	[created_at] [datetime] NULL,
 CONSTRAINT [password_reset_tokens_email_primary] PRIMARY KEY CLUSTERED 
(
	[email] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY]
GO
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[permission_role](
	[permission_id] [bigint] NOT NULL,
	[role_id] [bigint] NOT NULL,
 CONSTRAINT [permission_role_permission_id_role_id_primary] PRIMARY KEY CLUSTERED 
(
	[permission_id] ASC,
	[role_id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY]
GO
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[permissions](
	[id] [bigint] IDENTITY(1,1) NOT NULL,
	[action] [nvarchar](80) NOT NULL,
	[resource_type] [nvarchar](80) NOT NULL,
	[description] [nvarchar](250) NULL,
	[created_at] [datetime] NULL,
	[updated_at] [datetime] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY]
GO
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[programs](
	[id] [bigint] IDENTITY(1,1) NOT NULL,
	[directorate_id] [bigint] NOT NULL,
	[parent_id] [bigint] NULL,
	[type] [nvarchar](20) NOT NULL,
	[code] [nvarchar](40) NOT NULL,
	[name] [nvarchar](200) NOT NULL,
	[valid_from] [date] NULL,
	[valid_to] [date] NULL,
	[active] [bit] NOT NULL,
	[created_at] [datetime] NULL,
	[updated_at] [datetime] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY]
GO
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[progress_updates](
	[id] [bigint] IDENTITY(1,1) NOT NULL,
	[project_id] [bigint] NULL,
	[task_id] [bigint] NULL,
	[period] [date] NOT NULL,
	[progress] [tinyint] NOT NULL,
	[narrative] [nvarchar](max) NOT NULL,
	[blockers] [nvarchar](max) NULL,
	[next_step] [nvarchar](max) NULL,
	[created_by] [bigint] NOT NULL,
	[created_at] [datetime] NULL,
	[updated_at] [datetime] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[project_kpis](
	[id] [bigint] IDENTITY(1,1) NOT NULL,
	[project_id] [bigint] NOT NULL,
	[name] [nvarchar](200) NOT NULL,
	[unit] [nvarchar](40) NOT NULL,
	[baseline] [decimal](19, 4) NULL,
	[target] [decimal](19, 4) NOT NULL,
	[actual] [decimal](19, 4) NULL,
	[frequency] [nvarchar](30) NOT NULL,
	[source] [nvarchar](500) NULL,
	[created_at] [datetime] NULL,
	[updated_at] [datetime] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY]
GO
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[project_tasks](
	[id] [bigint] IDENTITY(1,1) NOT NULL,
	[project_id] [bigint] NOT NULL,
	[title] [nvarchar](250) NOT NULL,
	[owner_id] [bigint] NULL,
	[starts_at] [date] NULL,
	[due_at] [date] NULL,
	[status] [nvarchar](30) NOT NULL,
	[progress] [tinyint] NOT NULL,
	[weight_percent] [decimal](5, 2) NOT NULL,
	[next_step] [nvarchar](max) NULL,
	[has_budget_impact] [bit] NOT NULL,
	[dependency_notes] [nvarchar](max) NULL,
	[created_at] [datetime] NULL,
	[updated_at] [datetime] NULL,
	[row_version] [timestamp] NOT NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[projects](
	[id] [bigint] IDENTITY(1,1) NOT NULL,
	[directorate_id] [bigint] NOT NULL,
	[program_id] [bigint] NULL,
	[code] [nvarchar](50) NOT NULL,
	[name] [nvarchar](250) NOT NULL,
	[sponsor_id] [bigint] NULL,
	[manager_id] [bigint] NULL,
	[objective] [nvarchar](max) NULL,
	[starts_at] [date] NULL,
	[ends_at] [date] NULL,
	[status] [nvarchar](30) NOT NULL,
	[has_budget_impact] [bit] NOT NULL,
	[progress] [tinyint] NOT NULL,
	[created_at] [datetime] NULL,
	[updated_at] [datetime] NULL,
	[row_version] [timestamp] NOT NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[reporting_campaigns](
	[id] [bigint] IDENTITY(1,1) NOT NULL,
	[title] [nvarchar](200) NOT NULL,
	[audience] [nvarchar](max) NOT NULL,
	[period] [nvarchar](30) NOT NULL,
	[opens_at] [datetime] NOT NULL,
	[due_at] [datetime] NOT NULL,
	[reminder_days] [nvarchar](max) NULL,
	[status] [nvarchar](20) NOT NULL,
	[created_by] [bigint] NOT NULL,
	[created_at] [datetime] NULL,
	[updated_at] [datetime] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[reservations](
	[id] [bigint] IDENTITY(1,1) NOT NULL,
	[budget_allocation_id] [bigint] NOT NULL,
	[reference] [nvarchar](100) NOT NULL,
	[amount] [decimal](19, 4) NOT NULL,
	[expires_at] [datetime] NULL,
	[status] [nvarchar](20) NOT NULL,
	[created_by] [bigint] NOT NULL,
	[created_at] [datetime] NULL,
	[updated_at] [datetime] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY]
GO
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[risk_actions](
	[id] [bigint] IDENTITY(1,1) NOT NULL,
	[risk_id] [bigint] NOT NULL,
	[action] [nvarchar](max) NOT NULL,
	[measure_type] [nvarchar](20) NOT NULL,
	[owner_id] [bigint] NULL,
	[due_at] [date] NULL,
	[cost] [decimal](19, 4) NULL,
	[status] [nvarchar](20) NOT NULL,
	[probability_reduction] [tinyint] NOT NULL,
	[impact_reduction] [tinyint] NOT NULL,
	[evidence] [nvarchar](max) NULL,
	[completed_at] [datetime2](0) NULL,
	[created_by] [bigint] NULL,
	[created_at] [datetime] NULL,
	[updated_at] [datetime] NULL,
	[row_version] [timestamp] NOT NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[risks](
	[id] [bigint] IDENTITY(1,1) NOT NULL,
	[directorate_id] [bigint] NOT NULL,
	[project_id] [bigint] NULL,
	[task_id] [bigint] NULL,
	[category] [nvarchar](60) NOT NULL,
	[description] [nvarchar](max) NOT NULL,
	[cause] [nvarchar](max) NULL,
	[consequence] [nvarchar](max) NULL,
	[owner_id] [bigint] NULL,
	[probability] [tinyint] NOT NULL,
	[impact] [tinyint] NOT NULL,
	[inherent_score] [tinyint] NOT NULL,
	[response] [nvarchar](20) NOT NULL,
	[residual_score] [tinyint] NULL,
	[residual_probability] [tinyint] NULL,
	[residual_impact] [tinyint] NULL,
	[review_at] [date] NULL,
	[status] [nvarchar](20) NOT NULL,
	[created_at] [datetime] NULL,
	[updated_at] [datetime] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[roles](
	[id] [bigint] IDENTITY(1,1) NOT NULL,
	[code] [nvarchar](50) NOT NULL,
	[name] [nvarchar](120) NOT NULL,
	[financial_role] [bit] NOT NULL,
	[created_at] [datetime] NULL,
	[updated_at] [datetime] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY]
GO
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[sessions](
	[id] [nvarchar](255) NOT NULL,
	[user_id] [bigint] NULL,
	[ip_address] [nvarchar](45) NULL,
	[user_agent] [nvarchar](max) NULL,
	[payload] [nvarchar](max) NOT NULL,
	[last_activity] [int] NOT NULL,
 CONSTRAINT [sessions_id_primary] PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[user_role_scopes](
	[id] [bigint] IDENTITY(1,1) NOT NULL,
	[user_id] [bigint] NOT NULL,
	[role_id] [bigint] NOT NULL,
	[directorate_id] [bigint] NULL,
	[program_id] [bigint] NULL,
	[project_id] [bigint] NULL,
	[valid_from] [datetime] NULL,
	[valid_to] [datetime] NULL,
	[granted_by] [bigint] NULL,
	[reason] [nvarchar](500) NULL,
	[created_at] [datetime] NULL,
	[updated_at] [datetime] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY]
GO
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[users](
	[id] [bigint] IDENTITY(1,1) NOT NULL,
	[name] [nvarchar](255) NOT NULL,
	[email] [nvarchar](255) NOT NULL,
	[email_verified_at] [datetime] NULL,
	[password] [nvarchar](255) NOT NULL,
	[locale] [nvarchar](5) NOT NULL,
	[status] [nvarchar](20) NOT NULL,
	[last_login_at] [datetime] NULL,
	[remember_token] [nvarchar](100) NULL,
	[created_at] [datetime] NULL,
	[updated_at] [datetime] NULL,
	[row_version] [timestamp] NOT NULL,
	[username] [nvarchar](100) NOT NULL,
	[notification_email_mode] [nvarchar](20) NOT NULL,
	[notification_digest_time] [char](5) NOT NULL,
	[notification_digest_weekday] [tinyint] NOT NULL,
	[notification_window_start] [char](5) NULL,
	[notification_window_end] [char](5) NULL,
	[dashboard_widgets] [nvarchar](max) NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY]
GO
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[workflow_instances](
	[id] [bigint] IDENTITY(1,1) NOT NULL,
	[entity_type] [nvarchar](100) NOT NULL,
	[entity_id] [bigint] NOT NULL,
	[workflow_type] [nvarchar](60) NOT NULL,
	[state] [nvarchar](30) NOT NULL,
	[initiated_by] [bigint] NOT NULL,
	[completed_at] [datetime] NULL,
	[created_at] [datetime] NULL,
	[updated_at] [datetime] NULL,
	[directorate_id] [bigint] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY]
GO
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[workflow_steps](
	[id] [bigint] IDENTITY(1,1) NOT NULL,
	[workflow_instance_id] [bigint] NOT NULL,
	[sequence] [int] NOT NULL,
	[required_role] [nvarchar](50) NOT NULL,
	[assignee_id] [bigint] NULL,
	[state] [nvarchar](30) NOT NULL,
	[decision] [nvarchar](30) NULL,
	[comment] [nvarchar](max) NULL,
	[due_at] [datetime] NULL,
	[decided_at] [datetime] NULL,
	[decided_by] [bigint] NULL,
	[created_at] [datetime] NULL,
	[updated_at] [datetime] NULL,
PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO
SET ANSI_PADDING ON
GO
CREATE NONCLUSTERED INDEX [attachments_entity_type_entity_id_index] ON [dbo].[attachments]
(
	[entity_type] ASC,
	[entity_id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
SET ANSI_PADDING ON
GO
CREATE UNIQUE NONCLUSTERED INDEX [attachments_storage_key_unique] ON [dbo].[attachments]
(
	[storage_key] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, IGNORE_DUP_KEY = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
CREATE NONCLUSTERED INDEX [audit_events_actor_id_occurred_at_index] ON [dbo].[audit_events]
(
	[actor_id] ASC,
	[occurred_at] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
CREATE NONCLUSTERED INDEX [audit_events_correlation_id_index] ON [dbo].[audit_events]
(
	[correlation_id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
SET ANSI_PADDING ON
GO
CREATE NONCLUSTERED INDEX [audit_events_entity_type_entity_id_occurred_at_index] ON [dbo].[audit_events]
(
	[entity_type] ASC,
	[entity_id] ASC,
	[occurred_at] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
CREATE NONCLUSTERED INDEX [budget_allocations_fiscal_year_budget_line_id_index] ON [dbo].[budget_allocations]
(
	[fiscal_year] ASC,
	[budget_line_id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
CREATE UNIQUE NONCLUSTERED INDEX [budget_allocations_unique] ON [dbo].[budget_allocations]
(
	[budget_line_id] ASC,
	[project_id] ASC,
	[task_id] ASC,
	[fiscal_year] ASC,
	[period] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, IGNORE_DUP_KEY = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
SET ANSI_PADDING ON
GO
CREATE NONCLUSTERED INDEX [budget_change_requests_workflow_status_fiscal_year_index] ON [dbo].[budget_change_requests]
(
	[workflow_status] ASC,
	[fiscal_year] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
SET ANSI_PADDING ON
GO
CREATE UNIQUE NONCLUSTERED INDEX [budget_cycles_base_year_name_unique] ON [dbo].[budget_cycles]
(
	[base_year] ASC,
	[name] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, IGNORE_DUP_KEY = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
SET ANSI_PADDING ON
GO
CREATE UNIQUE NONCLUSTERED INDEX [budget_lines_budget_version_id_directorate_id_code_funding_source_unique] ON [dbo].[budget_lines]
(
	[budget_version_id] ASC,
	[directorate_id] ASC,
	[code] ASC,
	[funding_source] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, IGNORE_DUP_KEY = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
SET ANSI_PADDING ON
GO
CREATE NONCLUSTERED INDEX [budget_lines_directorate_id_economic_code_index] ON [dbo].[budget_lines]
(
	[directorate_id] ASC,
	[economic_code] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
CREATE NONCLUSTERED INDEX [budget_transactions_budget_allocation_id_posted_at_index] ON [dbo].[budget_transactions]
(
	[budget_allocation_id] ASC,
	[posted_at] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
CREATE UNIQUE NONCLUSTERED INDEX [budget_transactions_idempotency_key_unique] ON [dbo].[budget_transactions]
(
	[idempotency_key] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, IGNORE_DUP_KEY = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
SET ANSI_PADDING ON
GO
CREATE NONCLUSTERED INDEX [budget_versions_budget_cycle_id_scenario_is_official_index] ON [dbo].[budget_versions]
(
	[budget_cycle_id] ASC,
	[scenario] ASC,
	[is_official] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
SET ANSI_PADDING ON
GO
CREATE UNIQUE NONCLUSTERED INDEX [budget_versions_budget_cycle_id_scenario_version_no_unique] ON [dbo].[budget_versions]
(
	[budget_cycle_id] ASC,
	[scenario] ASC,
	[version_no] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, IGNORE_DUP_KEY = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
SET ANSI_PADDING ON
GO
CREATE UNIQUE NONCLUSTERED INDEX [budget_versions_one_official] ON [dbo].[budget_versions]
(
	[budget_cycle_id] ASC,
	[scenario] ASC
)
WHERE ([is_official]=(1))
WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, IGNORE_DUP_KEY = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
CREATE NONCLUSTERED INDEX [cache_expiration_index] ON [dbo].[cache]
(
	[expiration] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
CREATE NONCLUSTERED INDEX [cache_locks_expiration_index] ON [dbo].[cache_locks]
(
	[expiration] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
SET ANSI_PADDING ON
GO
CREATE NONCLUSTERED INDEX [commitments_budget_allocation_id_status_index] ON [dbo].[commitments]
(
	[budget_allocation_id] ASC,
	[status] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
SET ANSI_PADDING ON
GO
CREATE UNIQUE NONCLUSTERED INDEX [commitments_reference_unique] ON [dbo].[commitments]
(
	[reference] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, IGNORE_DUP_KEY = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
SET ANSI_PADDING ON
GO
CREATE UNIQUE NONCLUSTERED INDEX [directorates_code_unique] ON [dbo].[directorates]
(
	[code] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, IGNORE_DUP_KEY = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
CREATE NONCLUSTERED INDEX [expenditures_budget_allocation_id_accounting_date_index] ON [dbo].[expenditures]
(
	[budget_allocation_id] ASC,
	[accounting_date] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
SET ANSI_PADDING ON
GO
CREATE UNIQUE NONCLUSTERED INDEX [expenditures_source_reference_unique] ON [dbo].[expenditures]
(
	[source_reference] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, IGNORE_DUP_KEY = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
SET ANSI_PADDING ON
GO
CREATE NONCLUSTERED INDEX [failed_jobs_connection_queue_failed_at_index] ON [dbo].[failed_jobs]
(
	[connection] ASC,
	[queue] ASC,
	[failed_at] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
SET ANSI_PADDING ON
GO
CREATE UNIQUE NONCLUSTERED INDEX [failed_jobs_uuid_unique] ON [dbo].[failed_jobs]
(
	[uuid] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, IGNORE_DUP_KEY = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
SET ANSI_PADDING ON
GO
CREATE UNIQUE NONCLUSTERED INDEX [import_rows_import_batch_id_external_row_key_unique] ON [dbo].[import_rows]
(
	[import_batch_id] ASC,
	[external_row_key] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, IGNORE_DUP_KEY = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
SET ANSI_PADDING ON
GO
CREATE NONCLUSTERED INDEX [jobs_queue_index] ON [dbo].[jobs]
(
	[queue] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
SET ANSI_PADDING ON
GO
CREATE NONCLUSTERED INDEX [notifications_channel_status_created_at_index] ON [dbo].[notifications]
(
	[channel] ASC,
	[status] ASC,
	[created_at] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
SET ANSI_PADDING ON
GO
CREATE NONCLUSTERED INDEX [notifications_recipient_id_status_created_at_index] ON [dbo].[notifications]
(
	[recipient_id] ASC,
	[status] ASC,
	[created_at] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
SET ANSI_PADDING ON
GO
CREATE UNIQUE NONCLUSTERED INDEX [permissions_action_resource_type_unique] ON [dbo].[permissions]
(
	[action] ASC,
	[resource_type] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, IGNORE_DUP_KEY = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
SET ANSI_PADDING ON
GO
CREATE UNIQUE NONCLUSTERED INDEX [programs_directorate_id_code_unique] ON [dbo].[programs]
(
	[directorate_id] ASC,
	[code] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, IGNORE_DUP_KEY = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
SET ANSI_PADDING ON
GO
CREATE NONCLUSTERED INDEX [programs_directorate_id_type_active_index] ON [dbo].[programs]
(
	[directorate_id] ASC,
	[type] ASC,
	[active] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
CREATE NONCLUSTERED INDEX [progress_updates_project_id_period_index] ON [dbo].[progress_updates]
(
	[project_id] ASC,
	[period] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
SET ANSI_PADDING ON
GO
CREATE NONCLUSTERED INDEX [project_tasks_project_id_status_due_at_index] ON [dbo].[project_tasks]
(
	[project_id] ASC,
	[status] ASC,
	[due_at] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
SET ANSI_PADDING ON
GO
CREATE UNIQUE NONCLUSTERED INDEX [projects_directorate_id_code_unique] ON [dbo].[projects]
(
	[directorate_id] ASC,
	[code] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, IGNORE_DUP_KEY = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
SET ANSI_PADDING ON
GO
CREATE NONCLUSTERED INDEX [projects_directorate_id_status_index] ON [dbo].[projects]
(
	[directorate_id] ASC,
	[status] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
SET ANSI_PADDING ON
GO
CREATE NONCLUSTERED INDEX [reservations_budget_allocation_id_status_expires_at_index] ON [dbo].[reservations]
(
	[budget_allocation_id] ASC,
	[status] ASC,
	[expires_at] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
SET ANSI_PADDING ON
GO
CREATE UNIQUE NONCLUSTERED INDEX [reservations_reference_unique] ON [dbo].[reservations]
(
	[reference] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, IGNORE_DUP_KEY = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
SET ANSI_PADDING ON
GO
CREATE NONCLUSTERED INDEX [risks_directorate_id_status_inherent_score_index] ON [dbo].[risks]
(
	[directorate_id] ASC,
	[status] ASC,
	[inherent_score] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
SET ANSI_PADDING ON
GO
CREATE UNIQUE NONCLUSTERED INDEX [roles_code_unique] ON [dbo].[roles]
(
	[code] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, IGNORE_DUP_KEY = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
CREATE NONCLUSTERED INDEX [sessions_last_activity_index] ON [dbo].[sessions]
(
	[last_activity] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
CREATE NONCLUSTERED INDEX [sessions_user_id_index] ON [dbo].[sessions]
(
	[user_id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
CREATE NONCLUSTERED INDEX [user_role_scopes_directorate_id_role_id_index] ON [dbo].[user_role_scopes]
(
	[directorate_id] ASC,
	[role_id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
CREATE NONCLUSTERED INDEX [user_role_scopes_user_id_valid_from_valid_to_index] ON [dbo].[user_role_scopes]
(
	[user_id] ASC,
	[valid_from] ASC,
	[valid_to] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
SET ANSI_PADDING ON
GO
CREATE UNIQUE NONCLUSTERED INDEX [users_email_unique] ON [dbo].[users]
(
	[email] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, IGNORE_DUP_KEY = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
SET ANSI_PADDING ON
GO
CREATE UNIQUE NONCLUSTERED INDEX [users_username_unique] ON [dbo].[users]
(
	[username] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, IGNORE_DUP_KEY = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
SET ANSI_PADDING ON
GO
CREATE NONCLUSTERED INDEX [workflow_instances_directorate_id_state_index] ON [dbo].[workflow_instances]
(
	[directorate_id] ASC,
	[state] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
SET ANSI_PADDING ON
GO
CREATE NONCLUSTERED INDEX [workflow_instances_entity_type_entity_id_index] ON [dbo].[workflow_instances]
(
	[entity_type] ASC,
	[entity_id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
SET ANSI_PADDING ON
GO
CREATE NONCLUSTERED INDEX [workflow_instances_state_workflow_type_index] ON [dbo].[workflow_instances]
(
	[state] ASC,
	[workflow_type] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
CREATE UNIQUE NONCLUSTERED INDEX [workflow_steps_workflow_instance_id_sequence_unique] ON [dbo].[workflow_steps]
(
	[workflow_instance_id] ASC,
	[sequence] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, IGNORE_DUP_KEY = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
ALTER TABLE [dbo].[attachments] ADD  DEFAULT ('1') FOR [version]
GO
ALTER TABLE [dbo].[attachments] ADD  DEFAULT ('internal') FOR [classification]
GO
ALTER TABLE [dbo].[attachments] ADD  DEFAULT ('pending') FOR [scan_status]
GO
ALTER TABLE [dbo].[budget_allocations] ADD  DEFAULT ('0') FOR [period]
GO
ALTER TABLE [dbo].[budget_allocations] ADD  DEFAULT ('0') FOR [planned_amount]
GO
ALTER TABLE [dbo].[budget_allocations] ADD  DEFAULT ('0') FOR [current_amount]
GO
ALTER TABLE [dbo].[budget_allocations] ADD  DEFAULT ('0') FOR [forecast_amount]
GO
ALTER TABLE [dbo].[budget_change_requests] ADD  DEFAULT ('normal') FOR [urgency]
GO
ALTER TABLE [dbo].[budget_change_requests] ADD  DEFAULT ('draft') FOR [workflow_status]
GO
ALTER TABLE [dbo].[budget_cycles] ADD  DEFAULT ('3') FOR [horizon]
GO
ALTER TABLE [dbo].[budget_cycles] ADD  DEFAULT ('working_draft') FOR [status]
GO
ALTER TABLE [dbo].[budget_lines] ADD  DEFAULT ('MKD') FOR [currency]
GO
ALTER TABLE [dbo].[budget_lines] ADD CONSTRAINT [DF_budget_lines_own_revenue] DEFAULT ((0)) FOR [own_revenue]
GO
ALTER TABLE [dbo].[budget_lines] ADD CONSTRAINT [DF_budget_lines_government_grant] DEFAULT ((0)) FOR [government_grant]
GO
ALTER TABLE [dbo].[budget_lines] ADD CONSTRAINT [DF_budget_lines_health_grant] DEFAULT ((0)) FOR [health_grant]
GO
ALTER TABLE [dbo].[budget_lines] ADD CONSTRAINT [DF_budget_lines_education_grant] DEFAULT ((0)) FOR [education_grant]
GO
ALTER TABLE [dbo].[budget_versions] ADD  DEFAULT ('base') FOR [scenario]
GO
ALTER TABLE [dbo].[budget_versions] ADD  DEFAULT ('1') FOR [version_no]
GO
ALTER TABLE [dbo].[budget_versions] ADD  DEFAULT ('working_draft') FOR [status]
GO
ALTER TABLE [dbo].[budget_versions] ADD  DEFAULT ('0') FOR [is_official]
GO
ALTER TABLE [dbo].[commitments] ADD  DEFAULT ('active') FOR [status]
GO
ALTER TABLE [dbo].[directorates] ADD  DEFAULT ('1') FOR [active]
GO
ALTER TABLE [dbo].[failed_jobs] ADD  DEFAULT (getdate()) FOR [failed_at]
GO
ALTER TABLE [dbo].[import_batches] ADD  DEFAULT ('preview') FOR [status]
GO
ALTER TABLE [dbo].[import_rows] ADD  DEFAULT ('valid') FOR [status]
GO
ALTER TABLE [dbo].[milestones] ADD  DEFAULT ('open') FOR [status]
GO
ALTER TABLE [dbo].[notifications] ADD  DEFAULT ('in_app') FOR [channel]
GO
ALTER TABLE [dbo].[notifications] ADD  DEFAULT ('pending') FOR [status]
GO
ALTER TABLE [dbo].[notifications] ADD  DEFAULT ('0') FOR [retry_count]
GO
ALTER TABLE [dbo].[users] ADD  DEFAULT ('immediate') FOR [notification_email_mode]
GO
ALTER TABLE [dbo].[users] ADD  DEFAULT ('09:00') FOR [notification_digest_time]
GO
ALTER TABLE [dbo].[users] ADD  DEFAULT ((1)) FOR [notification_digest_weekday]
GO
ALTER TABLE [dbo].[programs] ADD  DEFAULT ('program') FOR [type]
GO
ALTER TABLE [dbo].[programs] ADD  DEFAULT ('1') FOR [active]
GO
ALTER TABLE [dbo].[project_kpis] ADD  DEFAULT ('monthly') FOR [frequency]
GO
ALTER TABLE [dbo].[project_tasks] ADD  DEFAULT ('not_started') FOR [status]
GO
ALTER TABLE [dbo].[project_tasks] ADD  DEFAULT ('0') FOR [progress]
GO
ALTER TABLE [dbo].[project_tasks] ADD CONSTRAINT [DF_project_tasks_weight_percent] DEFAULT ((0)) FOR [weight_percent]
GO
ALTER TABLE [dbo].[project_tasks] ADD  DEFAULT ('0') FOR [has_budget_impact]
GO
ALTER TABLE [dbo].[projects] ADD  DEFAULT ('draft') FOR [status]
GO
ALTER TABLE [dbo].[projects] ADD  DEFAULT ('0') FOR [has_budget_impact]
GO
ALTER TABLE [dbo].[projects] ADD  DEFAULT ('0') FOR [progress]
GO
ALTER TABLE [dbo].[reporting_campaigns] ADD  DEFAULT ('draft') FOR [status]
GO
ALTER TABLE [dbo].[reservations] ADD  DEFAULT ('active') FOR [status]
GO
ALTER TABLE [dbo].[risk_actions] ADD  DEFAULT ('open') FOR [status]
GO
ALTER TABLE [dbo].[risk_actions] ADD CONSTRAINT [DF_risk_actions_measure_type] DEFAULT (N'reduce') FOR [measure_type]
GO
ALTER TABLE [dbo].[risk_actions] ADD CONSTRAINT [DF_risk_actions_probability_reduction] DEFAULT ((0)) FOR [probability_reduction]
GO
ALTER TABLE [dbo].[risk_actions] ADD CONSTRAINT [DF_risk_actions_impact_reduction] DEFAULT ((0)) FOR [impact_reduction]
GO
ALTER TABLE [dbo].[risks] ADD  DEFAULT ('reduce') FOR [response]
GO
ALTER TABLE [dbo].[risks] ADD  DEFAULT ('open') FOR [status]
GO
ALTER TABLE [dbo].[roles] ADD  DEFAULT ('0') FOR [financial_role]
GO
ALTER TABLE [dbo].[users] ADD  DEFAULT ('mk') FOR [locale]
GO
ALTER TABLE [dbo].[users] ADD  DEFAULT ('active') FOR [status]
GO
ALTER TABLE [dbo].[workflow_instances] ADD  DEFAULT ('draft') FOR [state]
GO
ALTER TABLE [dbo].[workflow_steps] ADD  DEFAULT ('pending') FOR [state]
GO
ALTER TABLE [dbo].[attachments]  WITH CHECK ADD  CONSTRAINT [attachments_uploaded_by_foreign] FOREIGN KEY([uploaded_by])
REFERENCES [dbo].[users] ([id])
GO
ALTER TABLE [dbo].[attachments] CHECK CONSTRAINT [attachments_uploaded_by_foreign]
GO
ALTER TABLE [dbo].[audit_events]  WITH CHECK ADD  CONSTRAINT [audit_events_actor_id_foreign] FOREIGN KEY([actor_id])
REFERENCES [dbo].[users] ([id])
GO
ALTER TABLE [dbo].[audit_events] CHECK CONSTRAINT [audit_events_actor_id_foreign]
GO
ALTER TABLE [dbo].[budget_allocations]  WITH CHECK ADD  CONSTRAINT [budget_allocations_budget_line_id_foreign] FOREIGN KEY([budget_line_id])
REFERENCES [dbo].[budget_lines] ([id])
GO
ALTER TABLE [dbo].[budget_allocations] CHECK CONSTRAINT [budget_allocations_budget_line_id_foreign]
GO
ALTER TABLE [dbo].[budget_allocations]  WITH CHECK ADD  CONSTRAINT [budget_allocations_project_id_foreign] FOREIGN KEY([project_id])
REFERENCES [dbo].[projects] ([id])
GO
ALTER TABLE [dbo].[budget_allocations] CHECK CONSTRAINT [budget_allocations_project_id_foreign]
GO
ALTER TABLE [dbo].[budget_allocations]  WITH CHECK ADD  CONSTRAINT [budget_allocations_task_id_foreign] FOREIGN KEY([task_id])
REFERENCES [dbo].[project_tasks] ([id])
GO
ALTER TABLE [dbo].[budget_allocations] CHECK CONSTRAINT [budget_allocations_task_id_foreign]
GO
ALTER TABLE [dbo].[budget_change_requests]  WITH CHECK ADD  CONSTRAINT [budget_change_requests_approved_by_foreign] FOREIGN KEY([approved_by])
REFERENCES [dbo].[users] ([id])
GO
ALTER TABLE [dbo].[budget_change_requests] CHECK CONSTRAINT [budget_change_requests_approved_by_foreign]
GO
ALTER TABLE [dbo].[budget_change_requests]  WITH CHECK ADD  CONSTRAINT [budget_change_requests_requested_by_foreign] FOREIGN KEY([requested_by])
REFERENCES [dbo].[users] ([id])
GO
ALTER TABLE [dbo].[budget_change_requests] CHECK CONSTRAINT [budget_change_requests_requested_by_foreign]
GO
ALTER TABLE [dbo].[budget_change_requests]  WITH CHECK ADD  CONSTRAINT [budget_change_requests_source_allocation_id_foreign] FOREIGN KEY([source_allocation_id])
REFERENCES [dbo].[budget_allocations] ([id])
GO
ALTER TABLE [dbo].[budget_change_requests] CHECK CONSTRAINT [budget_change_requests_source_allocation_id_foreign]
GO
ALTER TABLE [dbo].[budget_change_requests]  WITH CHECK ADD  CONSTRAINT [budget_change_requests_target_allocation_id_foreign] FOREIGN KEY([target_allocation_id])
REFERENCES [dbo].[budget_allocations] ([id])
GO
ALTER TABLE [dbo].[budget_change_requests] CHECK CONSTRAINT [budget_change_requests_target_allocation_id_foreign]
GO
ALTER TABLE [dbo].[budget_lines]  WITH CHECK ADD  CONSTRAINT [budget_lines_budget_version_id_foreign] FOREIGN KEY([budget_version_id])
REFERENCES [dbo].[budget_versions] ([id])
GO
ALTER TABLE [dbo].[budget_lines] CHECK CONSTRAINT [budget_lines_budget_version_id_foreign]
GO
ALTER TABLE [dbo].[budget_lines]  WITH CHECK ADD  CONSTRAINT [budget_lines_directorate_id_foreign] FOREIGN KEY([directorate_id])
REFERENCES [dbo].[directorates] ([id])
GO
ALTER TABLE [dbo].[budget_lines] CHECK CONSTRAINT [budget_lines_directorate_id_foreign]
GO
ALTER TABLE [dbo].[budget_lines]  WITH CHECK ADD  CONSTRAINT [budget_lines_program_id_foreign] FOREIGN KEY([program_id])
REFERENCES [dbo].[programs] ([id])
GO
ALTER TABLE [dbo].[budget_lines] CHECK CONSTRAINT [budget_lines_program_id_foreign]
GO
ALTER TABLE [dbo].[budget_transactions]  WITH CHECK ADD  CONSTRAINT [budget_transactions_budget_allocation_id_foreign] FOREIGN KEY([budget_allocation_id])
REFERENCES [dbo].[budget_allocations] ([id])
GO
ALTER TABLE [dbo].[budget_transactions] CHECK CONSTRAINT [budget_transactions_budget_allocation_id_foreign]
GO
ALTER TABLE [dbo].[budget_transactions]  WITH CHECK ADD  CONSTRAINT [budget_transactions_posted_by_foreign] FOREIGN KEY([posted_by])
REFERENCES [dbo].[users] ([id])
GO
ALTER TABLE [dbo].[budget_transactions] CHECK CONSTRAINT [budget_transactions_posted_by_foreign]
GO
ALTER TABLE [dbo].[budget_versions]  WITH CHECK ADD  CONSTRAINT [budget_versions_approved_by_foreign] FOREIGN KEY([approved_by])
REFERENCES [dbo].[users] ([id])
GO
ALTER TABLE [dbo].[budget_versions] CHECK CONSTRAINT [budget_versions_approved_by_foreign]
GO
ALTER TABLE [dbo].[budget_versions]  WITH CHECK ADD  CONSTRAINT [budget_versions_budget_cycle_id_foreign] FOREIGN KEY([budget_cycle_id])
REFERENCES [dbo].[budget_cycles] ([id])
GO
ALTER TABLE [dbo].[budget_versions] CHECK CONSTRAINT [budget_versions_budget_cycle_id_foreign]
GO
ALTER TABLE [dbo].[commitments]  WITH CHECK ADD  CONSTRAINT [commitments_budget_allocation_id_foreign] FOREIGN KEY([budget_allocation_id])
REFERENCES [dbo].[budget_allocations] ([id])
GO
ALTER TABLE [dbo].[commitments] CHECK CONSTRAINT [commitments_budget_allocation_id_foreign]
GO
ALTER TABLE [dbo].[commitments]  WITH CHECK ADD  CONSTRAINT [commitments_created_by_foreign] FOREIGN KEY([created_by])
REFERENCES [dbo].[users] ([id])
GO
ALTER TABLE [dbo].[commitments] CHECK CONSTRAINT [commitments_created_by_foreign]
GO
ALTER TABLE [dbo].[commitments]  WITH CHECK ADD  CONSTRAINT [commitments_reservation_id_foreign] FOREIGN KEY([reservation_id])
REFERENCES [dbo].[reservations] ([id])
GO
ALTER TABLE [dbo].[commitments] CHECK CONSTRAINT [commitments_reservation_id_foreign]
GO
ALTER TABLE [dbo].[expenditures]  WITH CHECK ADD  CONSTRAINT [expenditures_budget_allocation_id_foreign] FOREIGN KEY([budget_allocation_id])
REFERENCES [dbo].[budget_allocations] ([id])
GO
ALTER TABLE [dbo].[expenditures] CHECK CONSTRAINT [expenditures_budget_allocation_id_foreign]
GO
ALTER TABLE [dbo].[expenditures]  WITH CHECK ADD  CONSTRAINT [expenditures_commitment_id_foreign] FOREIGN KEY([commitment_id])
REFERENCES [dbo].[commitments] ([id])
GO
ALTER TABLE [dbo].[expenditures] CHECK CONSTRAINT [expenditures_commitment_id_foreign]
GO
ALTER TABLE [dbo].[expenditures]  WITH CHECK ADD  CONSTRAINT [expenditures_created_by_foreign] FOREIGN KEY([created_by])
REFERENCES [dbo].[users] ([id])
GO
ALTER TABLE [dbo].[expenditures] CHECK CONSTRAINT [expenditures_created_by_foreign]
GO
ALTER TABLE [dbo].[import_batches]  WITH CHECK ADD  CONSTRAINT [import_batches_created_by_foreign] FOREIGN KEY([created_by])
REFERENCES [dbo].[users] ([id])
GO
ALTER TABLE [dbo].[import_batches] CHECK CONSTRAINT [import_batches_created_by_foreign]
GO
ALTER TABLE [dbo].[import_rows]  WITH CHECK ADD  CONSTRAINT [import_rows_import_batch_id_foreign] FOREIGN KEY([import_batch_id])
REFERENCES [dbo].[import_batches] ([id])
ON DELETE CASCADE
GO
ALTER TABLE [dbo].[import_rows] CHECK CONSTRAINT [import_rows_import_batch_id_foreign]
GO
ALTER TABLE [dbo].[milestones]  WITH CHECK ADD  CONSTRAINT [milestones_project_id_foreign] FOREIGN KEY([project_id])
REFERENCES [dbo].[projects] ([id])
ON DELETE CASCADE
GO
ALTER TABLE [dbo].[milestones] CHECK CONSTRAINT [milestones_project_id_foreign]
GO
ALTER TABLE [dbo].[notifications]  WITH CHECK ADD  CONSTRAINT [notifications_recipient_id_foreign] FOREIGN KEY([recipient_id])
REFERENCES [dbo].[users] ([id])
ON DELETE CASCADE
GO
ALTER TABLE [dbo].[notifications] CHECK CONSTRAINT [notifications_recipient_id_foreign]
GO
ALTER TABLE [dbo].[permission_role]  WITH CHECK ADD  CONSTRAINT [permission_role_permission_id_foreign] FOREIGN KEY([permission_id])
REFERENCES [dbo].[permissions] ([id])
ON DELETE CASCADE
GO
ALTER TABLE [dbo].[permission_role] CHECK CONSTRAINT [permission_role_permission_id_foreign]
GO
ALTER TABLE [dbo].[permission_role]  WITH CHECK ADD  CONSTRAINT [permission_role_role_id_foreign] FOREIGN KEY([role_id])
REFERENCES [dbo].[roles] ([id])
ON DELETE CASCADE
GO
ALTER TABLE [dbo].[permission_role] CHECK CONSTRAINT [permission_role_role_id_foreign]
GO
ALTER TABLE [dbo].[programs]  WITH CHECK ADD  CONSTRAINT [programs_directorate_id_foreign] FOREIGN KEY([directorate_id])
REFERENCES [dbo].[directorates] ([id])
GO
ALTER TABLE [dbo].[programs] CHECK CONSTRAINT [programs_directorate_id_foreign]
GO
ALTER TABLE [dbo].[programs]  WITH CHECK ADD  CONSTRAINT [programs_parent_id_foreign] FOREIGN KEY([parent_id])
REFERENCES [dbo].[programs] ([id])
GO
ALTER TABLE [dbo].[programs] CHECK CONSTRAINT [programs_parent_id_foreign]
GO
ALTER TABLE [dbo].[progress_updates]  WITH CHECK ADD  CONSTRAINT [progress_updates_created_by_foreign] FOREIGN KEY([created_by])
REFERENCES [dbo].[users] ([id])
GO
ALTER TABLE [dbo].[progress_updates] CHECK CONSTRAINT [progress_updates_created_by_foreign]
GO
ALTER TABLE [dbo].[progress_updates]  WITH CHECK ADD  CONSTRAINT [progress_updates_project_id_foreign] FOREIGN KEY([project_id])
REFERENCES [dbo].[projects] ([id])
GO
ALTER TABLE [dbo].[progress_updates] CHECK CONSTRAINT [progress_updates_project_id_foreign]
GO
ALTER TABLE [dbo].[progress_updates]  WITH CHECK ADD  CONSTRAINT [progress_updates_task_id_foreign] FOREIGN KEY([task_id])
REFERENCES [dbo].[project_tasks] ([id])
GO
ALTER TABLE [dbo].[progress_updates] CHECK CONSTRAINT [progress_updates_task_id_foreign]
GO
ALTER TABLE [dbo].[project_kpis]  WITH CHECK ADD  CONSTRAINT [project_kpis_project_id_foreign] FOREIGN KEY([project_id])
REFERENCES [dbo].[projects] ([id])
ON DELETE CASCADE
GO
ALTER TABLE [dbo].[project_kpis] CHECK CONSTRAINT [project_kpis_project_id_foreign]
GO
ALTER TABLE [dbo].[project_tasks]  WITH CHECK ADD  CONSTRAINT [project_tasks_owner_id_foreign] FOREIGN KEY([owner_id])
REFERENCES [dbo].[users] ([id])
GO
ALTER TABLE [dbo].[project_tasks] CHECK CONSTRAINT [project_tasks_owner_id_foreign]
GO
ALTER TABLE [dbo].[project_tasks]  WITH CHECK ADD  CONSTRAINT [project_tasks_project_id_foreign] FOREIGN KEY([project_id])
REFERENCES [dbo].[projects] ([id])
GO
ALTER TABLE [dbo].[project_tasks] CHECK CONSTRAINT [project_tasks_project_id_foreign]
GO
ALTER TABLE [dbo].[projects]  WITH CHECK ADD  CONSTRAINT [projects_directorate_id_foreign] FOREIGN KEY([directorate_id])
REFERENCES [dbo].[directorates] ([id])
GO
ALTER TABLE [dbo].[projects] CHECK CONSTRAINT [projects_directorate_id_foreign]
GO
ALTER TABLE [dbo].[projects]  WITH CHECK ADD  CONSTRAINT [projects_manager_id_foreign] FOREIGN KEY([manager_id])
REFERENCES [dbo].[users] ([id])
GO
ALTER TABLE [dbo].[projects] CHECK CONSTRAINT [projects_manager_id_foreign]
GO
ALTER TABLE [dbo].[projects]  WITH CHECK ADD  CONSTRAINT [projects_program_id_foreign] FOREIGN KEY([program_id])
REFERENCES [dbo].[programs] ([id])
GO
ALTER TABLE [dbo].[projects] CHECK CONSTRAINT [projects_program_id_foreign]
GO
ALTER TABLE [dbo].[projects]  WITH CHECK ADD  CONSTRAINT [projects_sponsor_id_foreign] FOREIGN KEY([sponsor_id])
REFERENCES [dbo].[users] ([id])
GO
ALTER TABLE [dbo].[projects] CHECK CONSTRAINT [projects_sponsor_id_foreign]
GO
ALTER TABLE [dbo].[reporting_campaigns]  WITH CHECK ADD  CONSTRAINT [reporting_campaigns_created_by_foreign] FOREIGN KEY([created_by])
REFERENCES [dbo].[users] ([id])
GO
ALTER TABLE [dbo].[reporting_campaigns] CHECK CONSTRAINT [reporting_campaigns_created_by_foreign]
GO
ALTER TABLE [dbo].[reservations]  WITH CHECK ADD  CONSTRAINT [reservations_budget_allocation_id_foreign] FOREIGN KEY([budget_allocation_id])
REFERENCES [dbo].[budget_allocations] ([id])
GO
ALTER TABLE [dbo].[reservations] CHECK CONSTRAINT [reservations_budget_allocation_id_foreign]
GO
ALTER TABLE [dbo].[reservations]  WITH CHECK ADD  CONSTRAINT [reservations_created_by_foreign] FOREIGN KEY([created_by])
REFERENCES [dbo].[users] ([id])
GO
ALTER TABLE [dbo].[reservations] CHECK CONSTRAINT [reservations_created_by_foreign]
GO
ALTER TABLE [dbo].[risk_actions]  WITH CHECK ADD  CONSTRAINT [risk_actions_owner_id_foreign] FOREIGN KEY([owner_id])
REFERENCES [dbo].[users] ([id])
GO
ALTER TABLE [dbo].[risk_actions] CHECK CONSTRAINT [risk_actions_owner_id_foreign]
GO
ALTER TABLE [dbo].[risk_actions]  WITH CHECK ADD  CONSTRAINT [risk_actions_risk_id_foreign] FOREIGN KEY([risk_id])
REFERENCES [dbo].[risks] ([id])
ON DELETE CASCADE
GO
ALTER TABLE [dbo].[risk_actions] CHECK CONSTRAINT [risk_actions_risk_id_foreign]
GO
ALTER TABLE [dbo].[risk_actions] WITH CHECK ADD CONSTRAINT [FK_risk_actions_created_by] FOREIGN KEY([created_by]) REFERENCES [dbo].[users] ([id])
GO
ALTER TABLE [dbo].[risk_actions] CHECK CONSTRAINT [FK_risk_actions_created_by]
GO
ALTER TABLE [dbo].[risks]  WITH CHECK ADD  CONSTRAINT [risks_directorate_id_foreign] FOREIGN KEY([directorate_id])
REFERENCES [dbo].[directorates] ([id])
GO
ALTER TABLE [dbo].[risks] CHECK CONSTRAINT [risks_directorate_id_foreign]
GO
ALTER TABLE [dbo].[risks]  WITH CHECK ADD  CONSTRAINT [risks_owner_id_foreign] FOREIGN KEY([owner_id])
REFERENCES [dbo].[users] ([id])
GO
ALTER TABLE [dbo].[risks] CHECK CONSTRAINT [risks_owner_id_foreign]
GO
ALTER TABLE [dbo].[risks]  WITH CHECK ADD  CONSTRAINT [risks_project_id_foreign] FOREIGN KEY([project_id])
REFERENCES [dbo].[projects] ([id])
GO
ALTER TABLE [dbo].[risks] CHECK CONSTRAINT [risks_project_id_foreign]
GO
ALTER TABLE [dbo].[risks]  WITH CHECK ADD  CONSTRAINT [risks_task_id_foreign] FOREIGN KEY([task_id])
REFERENCES [dbo].[project_tasks] ([id])
GO
ALTER TABLE [dbo].[risks] CHECK CONSTRAINT [risks_task_id_foreign]
GO
ALTER TABLE [dbo].[user_role_scopes]  WITH CHECK ADD  CONSTRAINT [user_role_scopes_directorate_id_foreign] FOREIGN KEY([directorate_id])
REFERENCES [dbo].[directorates] ([id])
GO
ALTER TABLE [dbo].[user_role_scopes] CHECK CONSTRAINT [user_role_scopes_directorate_id_foreign]
GO
ALTER TABLE [dbo].[user_role_scopes]  WITH CHECK ADD  CONSTRAINT [user_role_scopes_granted_by_foreign] FOREIGN KEY([granted_by])
REFERENCES [dbo].[users] ([id])
GO
ALTER TABLE [dbo].[user_role_scopes] CHECK CONSTRAINT [user_role_scopes_granted_by_foreign]
GO
ALTER TABLE [dbo].[user_role_scopes]  WITH CHECK ADD  CONSTRAINT [user_role_scopes_program_id_foreign] FOREIGN KEY([program_id])
REFERENCES [dbo].[programs] ([id])
GO
ALTER TABLE [dbo].[user_role_scopes] CHECK CONSTRAINT [user_role_scopes_program_id_foreign]
GO
ALTER TABLE [dbo].[user_role_scopes]  WITH CHECK ADD  CONSTRAINT [user_role_scopes_project_id_foreign] FOREIGN KEY([project_id])
REFERENCES [dbo].[projects] ([id])
GO
ALTER TABLE [dbo].[user_role_scopes] CHECK CONSTRAINT [user_role_scopes_project_id_foreign]
GO
ALTER TABLE [dbo].[user_role_scopes]  WITH CHECK ADD  CONSTRAINT [user_role_scopes_role_id_foreign] FOREIGN KEY([role_id])
REFERENCES [dbo].[roles] ([id])
GO
ALTER TABLE [dbo].[user_role_scopes] CHECK CONSTRAINT [user_role_scopes_role_id_foreign]
GO
ALTER TABLE [dbo].[user_role_scopes]  WITH CHECK ADD  CONSTRAINT [user_role_scopes_user_id_foreign] FOREIGN KEY([user_id])
REFERENCES [dbo].[users] ([id])
ON DELETE CASCADE
GO
ALTER TABLE [dbo].[user_role_scopes] CHECK CONSTRAINT [user_role_scopes_user_id_foreign]
GO
ALTER TABLE [dbo].[workflow_instances]  WITH CHECK ADD  CONSTRAINT [workflow_instances_directorate_id_foreign] FOREIGN KEY([directorate_id])
REFERENCES [dbo].[directorates] ([id])
GO
ALTER TABLE [dbo].[workflow_instances] CHECK CONSTRAINT [workflow_instances_directorate_id_foreign]
GO
ALTER TABLE [dbo].[workflow_instances]  WITH CHECK ADD  CONSTRAINT [workflow_instances_initiated_by_foreign] FOREIGN KEY([initiated_by])
REFERENCES [dbo].[users] ([id])
GO
ALTER TABLE [dbo].[workflow_instances] CHECK CONSTRAINT [workflow_instances_initiated_by_foreign]
GO
ALTER TABLE [dbo].[workflow_steps]  WITH CHECK ADD  CONSTRAINT [workflow_steps_assignee_id_foreign] FOREIGN KEY([assignee_id])
REFERENCES [dbo].[users] ([id])
GO
ALTER TABLE [dbo].[workflow_steps] CHECK CONSTRAINT [workflow_steps_assignee_id_foreign]
GO
ALTER TABLE [dbo].[workflow_steps]  WITH CHECK ADD  CONSTRAINT [workflow_steps_decided_by_foreign] FOREIGN KEY([decided_by])
REFERENCES [dbo].[users] ([id])
GO
ALTER TABLE [dbo].[workflow_steps] CHECK CONSTRAINT [workflow_steps_decided_by_foreign]
GO
ALTER TABLE [dbo].[workflow_steps]  WITH CHECK ADD  CONSTRAINT [workflow_steps_workflow_instance_id_foreign] FOREIGN KEY([workflow_instance_id])
REFERENCES [dbo].[workflow_instances] ([id])
ON DELETE CASCADE
GO
ALTER TABLE [dbo].[workflow_steps] CHECK CONSTRAINT [workflow_steps_workflow_instance_id_foreign]
GO
ALTER TABLE [dbo].[budget_allocations]  WITH CHECK ADD  CONSTRAINT [allocations_amount_check] CHECK  (([planned_amount]>=(0) AND [current_amount]>=(0) AND [forecast_amount]>=(0)))
GO
ALTER TABLE [dbo].[budget_allocations] CHECK CONSTRAINT [allocations_amount_check]
GO
ALTER TABLE [dbo].[budget_change_requests]  WITH CHECK ADD  CONSTRAINT [budget_change_requests_amount_check] CHECK  (([amount]>(0)))
GO
ALTER TABLE [dbo].[budget_change_requests] CHECK CONSTRAINT [budget_change_requests_amount_check]
GO
ALTER TABLE [dbo].[budget_transactions]  WITH CHECK ADD  CONSTRAINT [transactions_direction_check] CHECK  (([direction]='C' OR [direction]='D'))
GO
ALTER TABLE [dbo].[budget_transactions] CHECK CONSTRAINT [transactions_direction_check]
GO
ALTER TABLE [dbo].[commitments]  WITH CHECK ADD  CONSTRAINT [commitments_amount_check] CHECK  (([amount]>(0)))
GO
ALTER TABLE [dbo].[commitments] CHECK CONSTRAINT [commitments_amount_check]
GO
ALTER TABLE [dbo].[expenditures]  WITH CHECK ADD  CONSTRAINT [expenditures_amount_check] CHECK  (([amount]>(0)))
GO
ALTER TABLE [dbo].[expenditures] CHECK CONSTRAINT [expenditures_amount_check]
GO
ALTER TABLE [dbo].[programs]  WITH CHECK ADD  CONSTRAINT [programs_type_check] CHECK  (([type]='subcategory' OR [type]='category' OR [type]='program'))
GO
ALTER TABLE [dbo].[programs] CHECK CONSTRAINT [programs_type_check]
GO
ALTER TABLE [dbo].[project_tasks]  WITH CHECK ADD  CONSTRAINT [tasks_progress_check] CHECK  (([progress]>=(0) AND [progress]<=(100)))
GO
ALTER TABLE [dbo].[project_tasks] CHECK CONSTRAINT [tasks_progress_check]
GO
ALTER TABLE [dbo].[project_tasks] WITH CHECK ADD CONSTRAINT [tasks_weight_percent_check] CHECK (([weight_percent]>=(0) AND [weight_percent]<=(100)))
GO
ALTER TABLE [dbo].[project_tasks] CHECK CONSTRAINT [tasks_weight_percent_check]
GO
ALTER TABLE [dbo].[project_tasks] WITH CHECK ADD CONSTRAINT [tasks_date_range_check] CHECK (([starts_at] IS NULL OR [due_at] IS NULL OR [due_at]>=[starts_at]))
GO
ALTER TABLE [dbo].[project_tasks] CHECK CONSTRAINT [tasks_date_range_check]
GO
ALTER TABLE [dbo].[budget_lines] WITH CHECK ADD CONSTRAINT [budget_lines_source_amounts_check] CHECK (([own_revenue]>=(0) AND [government_grant]>=(0) AND [health_grant]>=(0) AND [education_grant]>=(0)))
GO
ALTER TABLE [dbo].[budget_lines] CHECK CONSTRAINT [budget_lines_source_amounts_check]
GO
ALTER TABLE [dbo].[budget_lines] WITH CHECK ADD CONSTRAINT [budget_lines_imported_available_check] CHECK (([imported_available_amount] IS NULL OR [imported_available_amount]>=(0)))
GO
ALTER TABLE [dbo].[budget_lines] CHECK CONSTRAINT [budget_lines_imported_available_check]
GO
ALTER TABLE [dbo].[budget_lines] WITH CHECK ADD CONSTRAINT [budget_lines_category_check] CHECK (([budget_category] IS NULL OR [budget_category] IN ('goods_services','capital_investments','wages','subsidies')))
GO
ALTER TABLE [dbo].[budget_lines] CHECK CONSTRAINT [budget_lines_category_check]
GO
ALTER TABLE [dbo].[projects]  WITH CHECK ADD  CONSTRAINT [projects_progress_check] CHECK  (([progress]>=(0) AND [progress]<=(100)))
GO
ALTER TABLE [dbo].[projects] CHECK CONSTRAINT [projects_progress_check]
GO
ALTER TABLE [dbo].[reservations]  WITH CHECK ADD  CONSTRAINT [reservations_amount_check] CHECK  (([amount]>(0)))
GO
ALTER TABLE [dbo].[reservations] CHECK CONSTRAINT [reservations_amount_check]
GO
ALTER TABLE [dbo].[risks]  WITH CHECK ADD  CONSTRAINT [risks_score_check] CHECK  (([probability]>=(1) AND [probability]<=(5) AND ([impact]>=(1) AND [impact]<=(5)) AND [inherent_score]=[probability]*[impact] AND ([residual_score] IS NULL OR [residual_score]>=(1) AND [residual_score]<=(25))))
GO
ALTER TABLE [dbo].[risks] CHECK CONSTRAINT [risks_score_check]
GO
ALTER TABLE [dbo].[risks] WITH CHECK ADD CONSTRAINT [risks_residual_components_check] CHECK ((([residual_probability] IS NULL AND [residual_impact] IS NULL AND ([residual_score] IS NULL OR [residual_score] BETWEEN 1 AND [inherent_score])) OR ([residual_probability] BETWEEN 1 AND 5 AND [residual_impact] BETWEEN 1 AND 5 AND [residual_probability]<=[probability] AND [residual_impact]<=[impact] AND [residual_score]=[residual_probability]*[residual_impact])))
GO
ALTER TABLE [dbo].[risks] CHECK CONSTRAINT [risks_residual_components_check]
GO
ALTER TABLE [dbo].[risk_actions] WITH CHECK ADD CONSTRAINT [risk_actions_measure_type_check] CHECK ([measure_type] IN (N'prevent',N'reduce',N'transfer',N'avoid',N'contingency'))
GO
ALTER TABLE [dbo].[risk_actions] CHECK CONSTRAINT [risk_actions_measure_type_check]
GO
ALTER TABLE [dbo].[risk_actions] WITH CHECK ADD CONSTRAINT [risk_actions_reduction_check] CHECK ([probability_reduction] BETWEEN 0 AND 4 AND [impact_reduction] BETWEEN 0 AND 4)
GO
ALTER TABLE [dbo].[risk_actions] CHECK CONSTRAINT [risk_actions_reduction_check]
GO
CREATE NONCLUSTERED INDEX [IX_risk_actions_risk_status] ON [dbo].[risk_actions] ([risk_id],[status],[due_at])
GO
ALTER TABLE [dbo].[user_role_scopes]  WITH CHECK ADD  CONSTRAINT [user_role_scope_target_check] CHECK  (([directorate_id] IS NOT NULL OR [program_id] IS NOT NULL OR [project_id] IS NOT NULL OR [role_id] IS NOT NULL))
GO
ALTER TABLE [dbo].[user_role_scopes] CHECK CONSTRAINT [user_role_scope_target_check]
GO
ALTER TABLE [dbo].[users]  WITH CHECK ADD  CONSTRAINT [users_status_check] CHECK  (([status]='locked' OR [status]='disabled' OR [status]='active'))
GO
ALTER TABLE [dbo].[users] CHECK CONSTRAINT [users_status_check]
GO

CREATE TABLE [dbo].[mayor_agenda_items](
	[id] [bigint] IDENTITY(1,1) NOT NULL,
	[directorate_id] [bigint] NOT NULL,
	[item_type] [nvarchar](20) NOT NULL,
	[project_id] [bigint] NULL,
	[risk_id] [bigint] NULL,
	[topic_title] [nvarchar](250) NULL,
	[topic_details] [nvarchar](max) NULL,
	[priority] [nvarchar](20) NOT NULL,
	[discussion_date] [date] NULL,
	[status] [nvarchar](20) NOT NULL,
	[selected_by] [bigint] NOT NULL,
	[selected_at] [datetime2](0) NOT NULL,
	[resolved_by] [bigint] NULL,
	[resolved_at] [datetime2](0) NULL,
	[created_at] [datetime2](0) NOT NULL,
	[updated_at] [datetime2](0) NOT NULL,
	[row_version] [timestamp] NOT NULL,
 CONSTRAINT [PK_mayor_agenda_items] PRIMARY KEY CLUSTERED ([id] ASC),
 CONSTRAINT [FK_mayor_agenda_items_directorate] FOREIGN KEY([directorate_id]) REFERENCES [dbo].[directorates] ([id]),
 CONSTRAINT [FK_mayor_agenda_items_project] FOREIGN KEY([project_id]) REFERENCES [dbo].[projects] ([id]),
 CONSTRAINT [FK_mayor_agenda_items_risk] FOREIGN KEY([risk_id]) REFERENCES [dbo].[risks] ([id]),
 CONSTRAINT [FK_mayor_agenda_items_selected_by] FOREIGN KEY([selected_by]) REFERENCES [dbo].[users] ([id]),
 CONSTRAINT [FK_mayor_agenda_items_resolved_by] FOREIGN KEY([resolved_by]) REFERENCES [dbo].[users] ([id]),
 CONSTRAINT [mayor_agenda_items_type_check] CHECK (([item_type]=N'project' AND [project_id] IS NOT NULL AND [risk_id] IS NULL AND [topic_title] IS NULL) OR ([item_type]=N'risk' AND [project_id] IS NULL AND [risk_id] IS NOT NULL AND [topic_title] IS NULL) OR ([item_type]=N'topic' AND [project_id] IS NULL AND [risk_id] IS NULL AND [topic_title] IS NOT NULL)),
 CONSTRAINT [mayor_agenda_items_priority_check] CHECK ([priority] IN (N'normal',N'high',N'critical')),
 CONSTRAINT [mayor_agenda_items_status_check] CHECK ([status] IN (N'open',N'discussed',N'removed')),
 CONSTRAINT [mayor_agenda_items_resolution_check] CHECK (([status]=N'open' AND [resolved_by] IS NULL AND [resolved_at] IS NULL) OR ([status]<>N'open' AND [resolved_by] IS NOT NULL AND [resolved_at] IS NOT NULL))
)
GO
ALTER TABLE [dbo].[mayor_agenda_items] ADD CONSTRAINT [DF_mayor_agenda_items_priority] DEFAULT (N'normal') FOR [priority]
GO
ALTER TABLE [dbo].[mayor_agenda_items] ADD CONSTRAINT [DF_mayor_agenda_items_status] DEFAULT (N'open') FOR [status]
GO
ALTER TABLE [dbo].[mayor_agenda_items] ADD CONSTRAINT [DF_mayor_agenda_items_selected_at] DEFAULT (SYSUTCDATETIME()) FOR [selected_at]
GO
ALTER TABLE [dbo].[mayor_agenda_items] ADD CONSTRAINT [DF_mayor_agenda_items_created_at] DEFAULT (SYSUTCDATETIME()) FOR [created_at]
GO
ALTER TABLE [dbo].[mayor_agenda_items] ADD CONSTRAINT [DF_mayor_agenda_items_updated_at] DEFAULT (SYSUTCDATETIME()) FOR [updated_at]
GO
CREATE UNIQUE NONCLUSTERED INDEX [UX_mayor_agenda_items_open_project] ON [dbo].[mayor_agenda_items] ([project_id]) WHERE ([item_type]=N'project' AND [status]=N'open')
GO
CREATE UNIQUE NONCLUSTERED INDEX [UX_mayor_agenda_items_open_risk] ON [dbo].[mayor_agenda_items] ([risk_id]) WHERE ([item_type]=N'risk' AND [status]=N'open')
GO
CREATE NONCLUSTERED INDEX [IX_mayor_agenda_items_dashboard] ON [dbo].[mayor_agenda_items] ([status],[priority],[discussion_date],[directorate_id])
GO
