# Dev Database Schema Export
Generated: 2025-11-30 11:48:33
Database: fll_planning

## Table: `m_activity_type`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | - |
| `name` | `varchar(100)` | NO | - | NULL | - |
| `sequence` | `smallint(5) unsigned` | NO | - | 0 | - |
| `description` | `text` | YES | - | NULL | - |
| `first_program` | `int(10) unsigned` | YES | - | NULL | - |
| `overview_plan_column` | `varchar(100)` | YES | - | NULL | - |

### Foreign Keys

| Constraint | Column | References | On Update | On Delete |
|------------|--------|------------|-----------|----------|
| `m_activity_type_first_program_foreign` | `first_program` | `m_first_program`.`id` | RESTRICT | RESTRICT |

---

## Table: `m_activity_type_detail`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | - |
| `name` | `varchar(100)` | NO | - | NULL | - |
| `code` | `varchar(50)` | YES | - | NULL | - |
| `name_preview` | `varchar(100)` | YES | - | NULL | - |
| `sequence` | `smallint(5) unsigned` | NO | - | 0 | - |
| `first_program` | `int(10) unsigned` | YES | - | NULL | - |
| `description` | `text` | YES | - | NULL | - |
| `link` | `varchar(255)` | YES | - | NULL | - |
| `link_text` | `varchar(100)` | YES | - | NULL | - |
| `activity_type` | `int(10) unsigned` | NO | - | NULL | - |

### Foreign Keys

| Constraint | Column | References | On Update | On Delete |
|------------|--------|------------|-----------|----------|
| `m_activity_type_detail_activity_type_foreign` | `activity_type` | `m_activity_type`.`id` | RESTRICT | RESTRICT |
| `m_activity_type_detail_first_program_foreign` | `first_program` | `m_first_program`.`id` | RESTRICT | RESTRICT |

---

## Table: `m_first_program`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | - |
| `name` | `varchar(50)` | NO | - | NULL | - |
| `sequence` | `smallint(5) unsigned` | NO | - | 0 | - |
| `color_hex` | `varchar(10)` | YES | - | NULL | - |
| `logo_white` | `varchar(255)` | YES | - | NULL | - |

---

## Table: `m_insert_point`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | - |
| `code` | `varchar(50)` | YES | - | NULL | - |
| `first_program` | `int(10) unsigned` | YES | - | NULL | - |
| `level` | `int(10) unsigned` | YES | - | NULL | - |
| `sequence` | `smallint(5) unsigned` | NO | - | 0 | - |
| `ui_label` | `varchar(255)` | YES | - | NULL | - |
| `ui_description` | `text` | YES | - | NULL | - |

### Foreign Keys

| Constraint | Column | References | On Update | On Delete |
|------------|--------|------------|-----------|----------|
| `m_insert_point_first_program_foreign` | `first_program` | `m_first_program`.`id` | RESTRICT | RESTRICT |
| `m_insert_point_level_foreign` | `level` | `m_level`.`id` | RESTRICT | RESTRICT |

---

## Table: `m_level`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | - |
| `name` | `varchar(50)` | NO | - | NULL | - |

---

## Table: `m_news`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | - |
| `title` | `varchar(255)` | NO | - | NULL | - |
| `text` | `text` | NO | - | NULL | - |
| `link` | `varchar(500)` | YES | - | NULL | - |
| `created_at` | `timestamp` | NO | - | NULL | - |
| `updated_at` | `timestamp` | NO | - | NULL | - |

---

## Table: `m_parameter`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | - |
| `name` | `varchar(255)` | YES | - | NULL | - |
| `context` | `enum('input','expert','protected','finale')` | YES | - | NULL | - |
| `level` | `int(10) unsigned` | NO | - | NULL | - |
| `type` | `enum('integer','decimal','time','date','boolean')` | YES | - | NULL | - |
| `value` | `varchar(255)` | YES | - | NULL | - |
| `min` | `varchar(255)` | YES | - | NULL | - |
| `max` | `varchar(255)` | YES | - | NULL | - |
| `step` | `varchar(255)` | YES | - | NULL | - |
| `first_program` | `int(10) unsigned` | YES | - | NULL | - |
| `sequence` | `smallint(5) unsigned` | NO | - | 0 | - |
| `ui_label` | `varchar(255)` | YES | - | NULL | - |
| `ui_description` | `longtext` | YES | - | NULL | - |

### Foreign Keys

| Constraint | Column | References | On Update | On Delete |
|------------|--------|------------|-----------|----------|
| `m_parameter_first_program_foreign` | `first_program` | `m_first_program`.`id` | RESTRICT | RESTRICT |
| `m_parameter_level_foreign` | `level` | `m_level`.`id` | RESTRICT | RESTRICT |

---

## Table: `m_parameter_condition`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | - |
| `parameter` | `int(10) unsigned` | YES | - | NULL | - |
| `if_parameter` | `int(10) unsigned` | YES | - | NULL | - |
| `is` | `enum('=','<','>')` | YES | - | NULL | - |
| `value` | `varchar(255)` | YES | - | NULL | - |
| `action` | `enum('show','hide','disable')` | NO | - | show | - |

### Foreign Keys

| Constraint | Column | References | On Update | On Delete |
|------------|--------|------------|-----------|----------|
| `m_parameter_condition_if_parameter_foreign` | `if_parameter` | `m_parameter`.`id` | RESTRICT | CASCADE |
| `m_parameter_condition_parameter_foreign` | `parameter` | `m_parameter`.`id` | RESTRICT | CASCADE |

---

## Table: `m_role`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | - |
| `name` | `varchar(100)` | NO | - | NULL | - |
| `name_short` | `varchar(50)` | YES | - | NULL | - |
| `sequence` | `smallint(5) unsigned` | NO | - | 0 | - |
| `first_program` | `int(10) unsigned` | YES | - | NULL | - |
| `description` | `text` | YES | - | NULL | - |
| `differentiation_type` | `varchar(100)` | YES | - | NULL | - |
| `differentiation_source` | `text` | YES | - | NULL | - |
| `differentiation_parameter` | `varchar(100)` | YES | - | NULL | - |
| `preview_matrix` | `tinyint(1)` | NO | - | 0 | - |
| `pdf_export` | `tinyint(1)` | NO | - | 0 | - |

### Foreign Keys

| Constraint | Column | References | On Update | On Delete |
|------------|--------|------------|-----------|----------|
| `m_role_first_program_foreign` | `first_program` | `m_first_program`.`id` | RESTRICT | RESTRICT |

---

## Table: `m_room_type`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | - |
| `code` | `varchar(100)` | YES | - | NULL | - |
| `name` | `varchar(255)` | YES | - | NULL | - |
| `sequence` | `smallint(5) unsigned` | NO | - | 0 | - |
| `room_type_group` | `int(10) unsigned` | NO | - | NULL | - |
| `level` | `int(10) unsigned` | NO | - | NULL | - |
| `first_program` | `tinyint(3) unsigned` | NO | - | 0 | - |

### Foreign Keys

| Constraint | Column | References | On Update | On Delete |
|------------|--------|------------|-----------|----------|
| `m_room_type_level_foreign` | `level` | `m_level`.`id` | RESTRICT | RESTRICT |
| `m_room_type_room_type_group_foreign` | `room_type_group` | `m_room_type_group`.`id` | RESTRICT | RESTRICT |

---

## Table: `m_room_type_group`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | - |
| `name` | `varchar(255)` | YES | - | NULL | - |
| `sequence` | `int(11)` | YES | - | NULL | - |

---

## Table: `m_season`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | - |
| `name` | `varchar(50)` | NO | - | NULL | - |
| `year` | `smallint(5) unsigned` | NO | - | NULL | - |

---

## Table: `m_supported_plan`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | - |
| `first_program` | `int(10) unsigned` | YES | - | NULL | - |
| `teams` | `smallint(5) unsigned` | YES | - | NULL | - |
| `lanes` | `smallint(5) unsigned` | YES | - | NULL | - |
| `tables` | `smallint(5) unsigned` | YES | - | NULL | - |
| `calibration` | `tinyint(1)` | YES | - | NULL | - |
| `note` | `text` | YES | - | NULL | - |
| `alert_level` | `tinyint(3) unsigned` | YES | - | NULL | - |

### Foreign Keys

| Constraint | Column | References | On Update | On Delete |
|------------|--------|------------|-----------|----------|
| `m_supported_plan_first_program_foreign` | `first_program` | `m_first_program`.`id` | RESTRICT | RESTRICT |

---

## Table: `m_visibility`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | - |
| `activity_type_detail` | `int(10) unsigned` | YES | - | NULL | - |
| `role` | `int(10) unsigned` | YES | - | NULL | - |

### Foreign Keys

| Constraint | Column | References | On Update | On Delete |
|------------|--------|------------|-----------|----------|
| `m_visibility_activity_type_detail_foreign` | `activity_type_detail` | `m_activity_type_detail`.`id` | RESTRICT | CASCADE |
| `m_visibility_role_foreign` | `role` | `m_role`.`id` | RESTRICT | CASCADE |

---

## Table: `activity`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | auto_increment |
| `activity_group` | `int(10) unsigned` | NO | - | NULL | - |
| `start` | `datetime` | NO | - | NULL | - |
| `end` | `datetime` | NO | - | NULL | - |
| `room_type` | `int(10) unsigned` | YES | - | NULL | - |
| `jury_lane` | `tinyint(4) unsigned` | YES | - | NULL | - |
| `jury_team` | `int(10) unsigned` | YES | - | NULL | - |
| `table_1` | `tinyint(4) unsigned` | YES | - | NULL | - |
| `table_1_team` | `int(10) unsigned` | YES | - | NULL | - |
| `table_2` | `tinyint(4) unsigned` | YES | - | NULL | - |
| `table_2_team` | `int(10) unsigned` | YES | - | NULL | - |
| `activity_type_detail` | `int(10) unsigned` | NO | - | NULL | - |
| `extra_block` | `int(10) unsigned` | YES | - | NULL | - |

### Foreign Keys

| Constraint | Column | References | On Update | On Delete |
|------------|--------|------------|-----------|----------|
| `activity_activity_group_foreign` | `activity_group` | `activity_group`.`id` | RESTRICT | CASCADE |
| `activity_activity_type_detail_foreign` | `activity_type_detail` | `m_activity_type_detail`.`id` | RESTRICT | CASCADE |
| `activity_extra_block_foreign` | `extra_block` | `extra_block`.`id` | RESTRICT | SET NULL |
| `activity_room_type_foreign` | `room_type` | `m_room_type`.`id` | RESTRICT | CASCADE |

---

## Table: `activity_group`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | auto_increment |
| `activity_type_detail` | `int(10) unsigned` | NO | - | NULL | - |
| `plan` | `int(10) unsigned` | NO | - | NULL | - |

### Foreign Keys

| Constraint | Column | References | On Update | On Delete |
|------------|--------|------------|-----------|----------|
| `activity_group_activity_type_detail_foreign` | `activity_type_detail` | `m_activity_type_detail`.`id` | RESTRICT | CASCADE |
| `activity_group_plan_foreign` | `plan` | `plan`.`id` | RESTRICT | CASCADE |

---

## Table: `api_keys`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | auto_increment |
| `name` | `varchar(100)` | NO | - | NULL | - |
| `key_hash` | `varchar(64)` | NO | UNI | NULL | - |
| `application_id` | `int(10) unsigned` | NO | MUL | NULL | - |
| `scopes` | `longtext` | YES | - | NULL | - |
| `last_used_at` | `timestamp` | YES | - | NULL | - |
| `expires_at` | `timestamp` | YES | - | NULL | - |
| `is_active` | `tinyint(1)` | NO | - | 1 | - |
| `created_at` | `timestamp` | YES | - | NULL | - |
| `updated_at` | `timestamp` | YES | - | NULL | - |

### Indexes

| Index Name | Columns | Unique | Type |
|------------|---------|--------|------|
| `api_keys_key_hash_unique` | key_hash | YES | BTREE |
| `api_keys_application_id_is_active_index` | application_id, is_active | NO | BTREE |
| `api_keys_key_hash_index` | key_hash | NO | BTREE |

### Foreign Keys

| Constraint | Column | References | On Update | On Delete |
|------------|--------|------------|-----------|----------|
| `api_keys_application_id_foreign` | `application_id` | `applications`.`id` | RESTRICT | CASCADE |

---

## Table: `api_request_logs`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `bigint(20) unsigned` | NO | PRI | NULL | auto_increment |
| `application_id` | `int(10) unsigned` | NO | MUL | NULL | - |
| `api_key_id` | `int(10) unsigned` | YES | MUL | NULL | - |
| `method` | `varchar(10)` | NO | - | NULL | - |
| `path` | `varchar(500)` | NO | - | NULL | - |
| `status_code` | `int(11)` | NO | MUL | NULL | - |
| `response_time_ms` | `int(11)` | NO | - | NULL | - |
| `ip_address` | `varchar(45)` | NO | - | NULL | - |
| `user_agent` | `text` | YES | - | NULL | - |
| `request_headers` | `longtext` | YES | - | NULL | - |
| `response_headers` | `longtext` | YES | - | NULL | - |
| `created_at` | `timestamp` | NO | MUL | NULL | - |

### Indexes

| Index Name | Columns | Unique | Type |
|------------|---------|--------|------|
| `api_request_logs_application_id_created_at_index` | application_id, created_at | NO | BTREE |
| `api_request_logs_api_key_id_created_at_index` | api_key_id, created_at | NO | BTREE |
| `api_request_logs_status_code_index` | status_code | NO | BTREE |
| `api_request_logs_created_at_index` | created_at | NO | BTREE |

### Foreign Keys

| Constraint | Column | References | On Update | On Delete |
|------------|--------|------------|-----------|----------|
| `api_request_logs_api_key_id_foreign` | `api_key_id` | `api_keys`.`id` | RESTRICT | SET NULL |
| `api_request_logs_application_id_foreign` | `application_id` | `applications`.`id` | RESTRICT | CASCADE |

---

## Table: `applications`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | auto_increment |
| `name` | `varchar(100)` | NO | - | NULL | - |
| `description` | `text` | YES | - | NULL | - |
| `contact_email` | `varchar(255)` | NO | - | NULL | - |
| `webhook_url` | `varchar(500)` | YES | - | NULL | - |
| `allowed_ips` | `longtext` | YES | - | NULL | - |
| `rate_limit` | `int(10) unsigned` | NO | - | 1000 | - |
| `is_active` | `tinyint(1)` | NO | MUL | 1 | - |
| `created_at` | `timestamp` | YES | - | NULL | - |
| `updated_at` | `timestamp` | YES | - | NULL | - |

### Indexes

| Index Name | Columns | Unique | Type |
|------------|---------|--------|------|
| `applications_is_active_index` | is_active | NO | BTREE |

---

## Table: `cache`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `key` | `varchar(255)` | NO | PRI | NULL | - |
| `value` | `mediumtext` | NO | - | NULL | - |
| `expiration` | `int(11)` | NO | - | NULL | - |

---

## Table: `cache_locks`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `key` | `varchar(255)` | NO | PRI | NULL | - |
| `owner` | `varchar(255)` | NO | - | NULL | - |
| `expiration` | `int(11)` | NO | - | NULL | - |

---

## Table: `contao_public_rounds`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `event_id` | `int(10) unsigned` | NO | PRI | NULL | - |
| `vr1` | `tinyint(1)` | NO | - | 1 | - |
| `vr2` | `tinyint(1)` | NO | - | 0 | - |
| `vr3` | `tinyint(1)` | NO | - | 0 | - |
| `vf` | `tinyint(1)` | NO | - | 0 | - |
| `hf` | `tinyint(1)` | NO | - | 0 | - |

### Foreign Keys

| Constraint | Column | References | On Update | On Delete |
|------------|--------|------------|-----------|----------|
| `contao_public_rounds_event_id_foreign` | `event_id` | `event`.`id` | RESTRICT | CASCADE |

---

## Table: `event`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | - |
| `name` | `varchar(100)` | YES | - | NULL | - |
| `slug` | `varchar(255)` | YES | - | NULL | - |
| `event_explore` | `smallint(6) unsigned` | YES | - | NULL | - |
| `event_challenge` | `smallint(6) unsigned` | YES | - | NULL | - |
| `contao_id_explore` | `int(10) unsigned` | YES | - | NULL | - |
| `contao_id_challenge` | `int(10) unsigned` | YES | - | NULL | - |
| `regional_partner` | `int(10) unsigned` | NO | - | NULL | - |
| `level` | `int(10) unsigned` | NO | - | NULL | - |
| `season` | `int(10) unsigned` | NO | - | NULL | - |
| `date` | `date` | NO | - | NULL | - |
| `days` | `tinyint(3) unsigned` | NO | - | NULL | - |
| `link` | `varchar(255)` | YES | - | NULL | - |
| `qrcode` | `longtext` | YES | - | NULL | - |
| `wifi_ssid` | `varchar(255)` | YES | - | NULL | - |
| `wifi_password` | `longtext` | YES | - | NULL | - |
| `wifi_instruction` | `text` | YES | - | NULL | - |
| `wifi_qrcode` | `longtext` | YES | - | NULL | - |

### Foreign Keys

| Constraint | Column | References | On Update | On Delete |
|------------|--------|------------|-----------|----------|
| `event_level_foreign` | `level` | `m_level`.`id` | RESTRICT | NO ACTION |
| `event_regional_partner_foreign` | `regional_partner` | `regional_partner`.`id` | RESTRICT | NO ACTION |
| `event_season_foreign` | `season` | `m_season`.`id` | RESTRICT | NO ACTION |

---

## Table: `event_logo`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | auto_increment |
| `event` | `int(10) unsigned` | NO | - | NULL | - |
| `logo` | `int(10) unsigned` | NO | - | NULL | - |
| `sort_order` | `smallint(5) unsigned` | NO | - | 0 | - |

### Foreign Keys

| Constraint | Column | References | On Update | On Delete |
|------------|--------|------------|-----------|----------|
| `event_logo_event_foreign` | `event` | `event`.`id` | RESTRICT | CASCADE |
| `event_logo_logo_foreign` | `logo` | `logo`.`id` | RESTRICT | CASCADE |

---

## Table: `extra_block`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | auto_increment |
| `plan` | `int(10) unsigned` | NO | - | NULL | - |
| `first_program` | `int(10) unsigned` | YES | - | NULL | - |
| `name` | `varchar(50)` | YES | - | NULL | - |
| `description` | `text` | YES | - | NULL | - |
| `link` | `varchar(255)` | YES | - | NULL | - |
| `insert_point` | `int(10) unsigned` | YES | - | NULL | - |
| `buffer_before` | `int(10) unsigned` | YES | - | NULL | - |
| `duration` | `int(10) unsigned` | YES | - | NULL | - |
| `buffer_after` | `int(10) unsigned` | YES | - | NULL | - |
| `start` | `datetime` | YES | - | NULL | - |
| `end` | `datetime` | YES | - | NULL | - |
| `room` | `int(10) unsigned` | YES | - | NULL | - |
| `active` | `tinyint(1)` | NO | - | 0 | - |

### Foreign Keys

| Constraint | Column | References | On Update | On Delete |
|------------|--------|------------|-----------|----------|
| `extra_block_insert_point_foreign` | `insert_point` | `m_insert_point`.`id` | RESTRICT | CASCADE |
| `extra_block_plan_foreign` | `plan` | `plan`.`id` | RESTRICT | CASCADE |
| `extra_block_room_foreign` | `room` | `room`.`id` | RESTRICT | NO ACTION |

---

## Table: `failed_jobs`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | auto_increment |
| `uuid` | `varchar(255)` | NO | UNI | NULL | - |
| `connection` | `text` | NO | - | NULL | - |
| `queue` | `text` | NO | - | NULL | - |
| `payload` | `longtext` | NO | - | NULL | - |
| `exception` | `longtext` | NO | - | NULL | - |
| `failed_at` | `timestamp` | NO | - | current_timestamp() | - |

### Indexes

| Index Name | Columns | Unique | Type |
|------------|---------|--------|------|
| `failed_jobs_uuid_unique` | uuid | YES | BTREE |

---

## Table: `jobs`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | auto_increment |
| `queue` | `varchar(255)` | NO | MUL | NULL | - |
| `payload` | `longtext` | NO | - | NULL | - |
| `attempts` | `tinyint(3) unsigned` | NO | - | NULL | - |
| `reserved_at` | `int(10) unsigned` | YES | - | NULL | - |
| `available_at` | `int(10) unsigned` | NO | - | NULL | - |
| `created_at` | `int(10) unsigned` | NO | - | NULL | - |

### Indexes

| Index Name | Columns | Unique | Type |
|------------|---------|--------|------|
| `jobs_queue_index` | queue | NO | BTREE |

---

## Table: `logo`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | auto_increment |
| `regional_partner` | `int(10) unsigned` | NO | - | NULL | - |
| `path` | `varchar(255)` | NO | - | NULL | - |
| `title` | `varchar(255)` | YES | - | NULL | - |
| `link` | `varchar(255)` | YES | - | NULL | - |

### Foreign Keys

| Constraint | Column | References | On Update | On Delete |
|------------|--------|------------|-----------|----------|
| `logo_regional_partner_foreign` | `regional_partner` | `regional_partner`.`id` | RESTRICT | CASCADE |

---

## Table: `match`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | auto_increment |
| `plan` | `int(10) unsigned` | NO | - | NULL | - |
| `round` | `int(10) unsigned` | NO | - | NULL | - |
| `match_no` | `int(10) unsigned` | NO | - | NULL | - |
| `table_1` | `int(10) unsigned` | NO | - | NULL | - |
| `table_2` | `int(10) unsigned` | NO | - | NULL | - |
| `table_1_team` | `int(10) unsigned` | NO | - | NULL | - |
| `table_2_team` | `int(10) unsigned` | NO | - | NULL | - |

### Foreign Keys

| Constraint | Column | References | On Update | On Delete |
|------------|--------|------------|-----------|----------|
| `match_plan_foreign` | `plan` | `plan`.`id` | RESTRICT | CASCADE |

---

## Table: `migrations`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | auto_increment |
| `migration` | `varchar(255)` | NO | - | NULL | - |
| `batch` | `int(11)` | NO | - | NULL | - |

---

## Table: `news_user`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | auto_increment |
| `user_id` | `int(10) unsigned` | NO | - | NULL | - |
| `news_id` | `int(10) unsigned` | NO | - | NULL | - |
| `read_at` | `timestamp` | NO | - | NULL | - |

### Foreign Keys

| Constraint | Column | References | On Update | On Delete |
|------------|--------|------------|-----------|----------|
| `news_user_news_id_fk` | `news_id` | `m_news`.`id` | RESTRICT | CASCADE |
| `news_user_user_id_fk` | `user_id` | `user`.`id` | RESTRICT | CASCADE |

---

## Table: `plan`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | - |
| `name` | `varchar(100)` | NO | - | NULL | - |
| `event` | `int(10) unsigned` | NO | - | NULL | - |
| `created` | `timestamp` | YES | - | NULL | - |
| `last_change` | `timestamp` | YES | - | NULL | - |
| `generator_status` | `varchar(50)` | YES | - | NULL | - |

### Foreign Keys

| Constraint | Column | References | On Update | On Delete |
|------------|--------|------------|-----------|----------|
| `plan_event_foreign` | `event` | `event`.`id` | RESTRICT | CASCADE |

---

## Table: `plan_param_value`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | auto_increment |
| `parameter` | `int(10) unsigned` | NO | MUL | NULL | - |
| `plan` | `int(10) unsigned` | NO | - | NULL | - |
| `set_value` | `varchar(255)` | YES | - | NULL | - |

### Indexes

| Index Name | Columns | Unique | Type |
|------------|---------|--------|------|
| `plan_param_value_parameter_foreign` | parameter | NO | BTREE |

### Foreign Keys

| Constraint | Column | References | On Update | On Delete |
|------------|--------|------------|-----------|----------|
| `plan_param_value_parameter_foreign` | `parameter` | `m_parameter`.`id` | RESTRICT | CASCADE |
| `plan_param_value_plan_foreign` | `plan` | `plan`.`id` | RESTRICT | CASCADE |

---

## Table: `publication`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | - |
| `event` | `int(10) unsigned` | NO | - | NULL | - |
| `level` | `int(10) unsigned` | NO | - | NULL | - |
| `last_change` | `timestamp` | NO | - | NULL | - |

### Foreign Keys

| Constraint | Column | References | On Update | On Delete |
|------------|--------|------------|-----------|----------|
| `publication_event_foreign` | `event` | `event`.`id` | RESTRICT | CASCADE |

---

## Table: `q_plan`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | auto_increment |
| `plan` | `int(10) unsigned` | NO | - | NULL | - |
| `q_run` | `int(10) unsigned` | YES | - | NULL | - |
| `name` | `varchar(100)` | NO | - | NULL | - |
| `last_change` | `timestamp` | YES | - | NULL | - |
| `c_teams` | `int(10) unsigned` | NO | - | NULL | - |
| `r_tables` | `int(10) unsigned` | NO | - | NULL | - |
| `j_lanes` | `int(10) unsigned` | NO | - | NULL | - |
| `j_rounds` | `int(10) unsigned` | NO | - | NULL | - |
| `r_asym` | `tinyint(1)` | NO | - | 0 | - |
| `r_robot_check` | `tinyint(1)` | NO | - | 0 | - |
| `r_duration_robot_check` | `int(10) unsigned` | NO | - | 0 | - |
| `c_duration_transfer` | `int(10) unsigned` | NO | - | NULL | - |
| `q1_ok_count` | `int(10) unsigned` | YES | - | NULL | - |
| `q2_ok_count` | `int(10) unsigned` | YES | - | NULL | - |
| `q2_1_count` | `int(10) unsigned` | YES | - | NULL | - |
| `q2_2_count` | `int(10) unsigned` | YES | - | NULL | - |
| `q2_3_count` | `int(10) unsigned` | YES | - | NULL | - |
| `q2_score_avg` | `decimal(5,2)` | YES | - | NULL | - |
| `q3_ok_count` | `int(10) unsigned` | YES | - | NULL | - |
| `q3_1_count` | `int(10) unsigned` | YES | - | NULL | - |
| `q3_2_count` | `int(10) unsigned` | YES | - | NULL | - |
| `q3_3_count` | `int(10) unsigned` | YES | - | NULL | - |
| `q3_score_avg` | `decimal(5,2)` | YES | - | NULL | - |
| `q4_ok_count` | `int(10) unsigned` | YES | - | NULL | - |
| `q5_idle_avg` | `decimal(8,2)` | YES | - | NULL | - |
| `q5_idle_stddev` | `decimal(8,2)` | YES | - | NULL | - |
| `q6_duration` | `int(10) unsigned` | YES | - | NULL | - |
| `calculated` | `tinyint(1)` | NO | - | 0 | - |

### Foreign Keys

| Constraint | Column | References | On Update | On Delete |
|------------|--------|------------|-----------|----------|
| `q_plan_plan_foreign` | `plan` | `plan`.`id` | RESTRICT | CASCADE |
| `q_plan_q_run_foreign` | `q_run` | `q_run`.`id` | RESTRICT | CASCADE |

---

## Table: `q_plan_team`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | auto_increment |
| `q_plan` | `int(10) unsigned` | NO | - | NULL | - |
| `team` | `int(10) unsigned` | NO | - | NULL | - |
| `q1_ok` | `tinyint(1)` | NO | - | 0 | - |
| `q1_transition_1_2` | `decimal(8,2)` | NO | - | 0.00 | - |
| `q1_transition_2_3` | `decimal(8,2)` | NO | - | 0.00 | - |
| `q1_transition_3_4` | `decimal(8,2)` | NO | - | 0.00 | - |
| `q1_transition_4_5` | `decimal(8,2)` | NO | - | 0.00 | - |
| `q2_ok` | `tinyint(1)` | NO | - | 0 | - |
| `q2_tables` | `int(10) unsigned` | NO | - | 0 | - |
| `q3_ok` | `tinyint(1)` | NO | - | 0 | - |
| `q3_teams` | `int(10) unsigned` | NO | - | 0 | - |
| `q4_ok` | `tinyint(1)` | NO | - | 0 | - |
| `q5_idle_0_1` | `int(10) unsigned` | NO | - | 0 | - |
| `q5_idle_1_2` | `int(10) unsigned` | NO | - | 0 | - |
| `q5_idle_2_3` | `int(10) unsigned` | NO | - | 0 | - |
| `q5_idle_avg` | `decimal(8,2)` | NO | - | 0.00 | - |

### Foreign Keys

| Constraint | Column | References | On Update | On Delete |
|------------|--------|------------|-----------|----------|
| `q_plan_team_q_plan_foreign` | `q_plan` | `q_plan`.`id` | RESTRICT | CASCADE |

---

## Table: `q_run`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | auto_increment |
| `name` | `varchar(100)` | NO | - | NULL | - |
| `comment` | `text` | YES | - | NULL | - |
| `selection` | `text` | YES | - | NULL | - |
| `started_at` | `timestamp` | YES | - | NULL | - |
| `finished_at` | `timestamp` | YES | - | NULL | - |
| `status` | `varchar(20)` | NO | - | pending | - |
| `host` | `varchar(100)` | YES | - | NULL | - |
| `qplans_total` | `int(10) unsigned` | NO | - | 0 | - |
| `qplans_calculated` | `int(10) unsigned` | NO | - | 0 | - |

---

## Table: `regional_partner`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | - |
| `name` | `varchar(100)` | NO | - | NULL | - |
| `region` | `varchar(100)` | NO | - | NULL | - |
| `dolibarr_id` | `int(11)` | YES | - | NULL | - |

---

## Table: `room`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | auto_increment |
| `event` | `int(10) unsigned` | NO | - | NULL | - |
| `name` | `varchar(100)` | NO | - | NULL | - |
| `navigation_instruction` | `text` | YES | - | NULL | - |
| `sequence` | `int(10) unsigned` | NO | - | 0 | - |
| `is_accessible` | `tinyint(1)` | NO | - | 1 | - |

### Foreign Keys

| Constraint | Column | References | On Update | On Delete |
|------------|--------|------------|-----------|----------|
| `room_event_foreign` | `event` | `event`.`id` | RESTRICT | CASCADE |

---

## Table: `room_type_room`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | auto_increment |
| `room_type` | `int(10) unsigned` | NO | MUL | NULL | - |
| `room` | `int(10) unsigned` | NO | MUL | NULL | - |
| `event` | `int(10) unsigned` | NO | MUL | NULL | - |

### Indexes

| Index Name | Columns | Unique | Type |
|------------|---------|--------|------|
| `event` | event | NO | BTREE |
| `room` | room | NO | BTREE |
| `room_type` | room_type | NO | BTREE |

### Foreign Keys

| Constraint | Column | References | On Update | On Delete |
|------------|--------|------------|-----------|----------|
| `room_type_room_event_foreign` | `event` | `event`.`id` | RESTRICT | CASCADE |
| `room_type_room_room_foreign` | `room` | `room`.`id` | RESTRICT | CASCADE |
| `room_type_room_room_type_foreign` | `room_type` | `m_room_type`.`id` | RESTRICT | CASCADE |

---

## Table: `s_generator`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | auto_increment |
| `plan` | `int(10) unsigned` | NO | MUL | NULL | - |
| `start` | `timestamp` | YES | - | NULL | - |
| `end` | `timestamp` | YES | - | NULL | - |
| `mode` | `varchar(255)` | YES | - | NULL | - |

### Indexes

| Index Name | Columns | Unique | Type |
|------------|---------|--------|------|
| `s_generator_plan_foreign` | plan | NO | BTREE |

### Foreign Keys

| Constraint | Column | References | On Update | On Delete |
|------------|--------|------------|-----------|----------|
| `s_generator_plan_foreign` | `plan` | `plan`.`id` | RESTRICT | CASCADE |

---

## Table: `s_one_link_access`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | auto_increment |
| `event` | `int(10) unsigned` | NO | - | NULL | - |
| `access_date` | `date` | NO | MUL | NULL | - |
| `access_time` | `timestamp` | YES | - | NULL | - |
| `user_agent` | `text` | YES | - | NULL | - |
| `referrer` | `text` | YES | - | NULL | - |
| `ip_hash` | `varchar(64)` | YES | - | NULL | - |
| `accept_language` | `varchar(50)` | YES | - | NULL | - |
| `screen_width` | `smallint(5) unsigned` | YES | - | NULL | - |
| `screen_height` | `smallint(5) unsigned` | YES | - | NULL | - |
| `viewport_width` | `smallint(5) unsigned` | YES | - | NULL | - |
| `viewport_height` | `smallint(5) unsigned` | YES | - | NULL | - |
| `device_pixel_ratio` | `decimal(3,2)` | YES | - | NULL | - |
| `touch_support` | `tinyint(1)` | YES | - | NULL | - |
| `connection_type` | `varchar(20)` | YES | - | NULL | - |
| `source` | `varchar(20)` | YES | - | NULL | - |

### Indexes

| Index Name | Columns | Unique | Type |
|------------|---------|--------|------|
| `idx_access_date` | access_date | NO | BTREE |

### Foreign Keys

| Constraint | Column | References | On Update | On Delete |
|------------|--------|------------|-----------|----------|
| `s_one_link_access_event_foreign` | `event` | `event`.`id` | RESTRICT | CASCADE |

---

## Table: `slide`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | auto_increment |
| `name` | `varchar(255)` | NO | - | NULL | - |
| `type` | `varchar(255)` | NO | - | NULL | - |
| `content` | `longtext` | NO | - | NULL | - |
| `order` | `int(11)` | NO | - | 0 | - |
| `slideshow_id` | `int(10) unsigned` | NO | - | NULL | - |
| `active` | `tinyint(1)` | NO | - | 1 | - |

### Foreign Keys

| Constraint | Column | References | On Update | On Delete |
|------------|--------|------------|-----------|----------|
| `slide_slideshow_id_foreign` | `slideshow_id` | `slideshow`.`id` | RESTRICT | CASCADE |

---

## Table: `slideshow`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | auto_increment |
| `name` | `varchar(255)` | NO | - | NULL | - |
| `event` | `int(10) unsigned` | NO | - | NULL | - |
| `transition_time` | `int(11)` | NO | - | 15 | - |

### Foreign Keys

| Constraint | Column | References | On Update | On Delete |
|------------|--------|------------|-----------|----------|
| `slideshow_event_foreign` | `event` | `event`.`id` | RESTRICT | CASCADE |

---

## Table: `table_event`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | auto_increment |
| `event` | `int(10) unsigned` | NO | - | NULL | - |
| `table_number` | `int(11)` | NO | - | NULL | - |
| `table_name` | `varchar(100)` | NO | - | NULL | - |

### Foreign Keys

| Constraint | Column | References | On Update | On Delete |
|------------|--------|------------|-----------|----------|
| `table_event_event_foreign` | `event` | `event`.`id` | RESTRICT | CASCADE |

---

## Table: `team`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | auto_increment |
| `first_program` | `int(10) unsigned` | NO | - | NULL | - |
| `name` | `varchar(100)` | NO | - | NULL | - |
| `event` | `int(10) unsigned` | NO | - | NULL | - |
| `team_number_hot` | `int(11)` | NO | - | NULL | - |
| `location` | `varchar(255)` | YES | - |  | - |
| `organization` | `varchar(255)` | YES | - |  | - |

### Foreign Keys

| Constraint | Column | References | On Update | On Delete |
|------------|--------|------------|-----------|----------|
| `team_event_foreign` | `event` | `event`.`id` | RESTRICT | CASCADE |
| `team_first_program_foreign` | `first_program` | `m_first_program`.`id` | RESTRICT | NO ACTION |

---

## Table: `team_plan`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | auto_increment |
| `team` | `int(10) unsigned` | NO | - | NULL | - |
| `plan` | `int(10) unsigned` | NO | - | NULL | - |
| `team_number_plan` | `int(11)` | NO | - | NULL | - |
| `room` | `int(10) unsigned` | YES | - | NULL | - |
| `noshow` | `tinyint(1)` | NO | - | 0 | - |

### Foreign Keys

| Constraint | Column | References | On Update | On Delete |
|------------|--------|------------|-----------|----------|
| `team_plan_plan_foreign` | `plan` | `plan`.`id` | RESTRICT | CASCADE |
| `team_plan_room_foreign` | `room` | `room`.`id` | RESTRICT | SET NULL |
| `team_plan_team_foreign` | `team` | `team`.`id` | RESTRICT | CASCADE |

---

## Table: `user`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | - |
| `nick` | `varchar(255)` | YES | - | NULL | - |
| `subject` | `varchar(255)` | YES | - | NULL | - |
| `name` | `varchar(255)` | YES | - | NULL | - |
| `email` | `varchar(255)` | YES | - | NULL | - |
| `dolibarr_id` | `int(11)` | YES | - | NULL | - |
| `lang` | `varchar(10)` | YES | - | NULL | - |
| `last_login` | `timestamp` | YES | - | NULL | - |
| `selection_regional_partner` | `int(10) unsigned` | YES | - | NULL | - |
| `selection_event` | `int(10) unsigned` | YES | - | NULL | - |

### Foreign Keys

| Constraint | Column | References | On Update | On Delete |
|------------|--------|------------|-----------|----------|
| `user_selection_event_foreign` | `selection_event` | `event`.`id` | RESTRICT | SET NULL |
| `user_selection_regional_partner_foreign` | `selection_regional_partner` | `regional_partner`.`id` | RESTRICT | SET NULL |

---

## Table: `user_regional_partner`

### Columns

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| `id` | `int(10) unsigned` | NO | PRI | NULL | - |
| `user` | `int(10) unsigned` | NO | - | NULL | - |
| `regional_partner` | `int(10) unsigned` | NO | - | NULL | - |

### Foreign Keys

| Constraint | Column | References | On Update | On Delete |
|------------|--------|------------|-----------|----------|
| `user_regional_partner_regional_partner_foreign` | `regional_partner` | `regional_partner`.`id` | RESTRICT | CASCADE |
| `user_regional_partner_user_foreign` | `user` | `user`.`id` | RESTRICT | CASCADE |

---
