# Table Review #7: m_parameter

## Status: ✅ Ready to Complete (Migration Updated)

## Comparison: Dev Database vs Master Migration

### Columns

| Column | Dev DB | Master Migration | Status | Notes |
|--------|--------|------------------|--------|-------|
| `id` | `int(10) unsigned` NOT NULL | `unsignedInteger` | ✅ Match | Standard type |
| `name` | `varchar(255)` NULLABLE, UNIQUE | `string('name', 255)->nullable()->unique()` | ✅ Match | Added unique constraint to migration |
| `context` | `enum('input','expert','protected','finale')` NULLABLE | `enum('context', ['input', 'expert', 'protected', 'finale'])->nullable()` | ✅ Match | |
| `level` | `int(10) unsigned` NOT NULL | `unsignedInteger('level')` | ✅ Match | |
| `type` | `enum('integer','decimal','time','date','boolean')` NULLABLE | `enum('type', ['integer', 'decimal', 'time', 'date', 'boolean'])->nullable()` | ✅ Match | |
| `value` | `varchar(255)` NULLABLE | `string('value', 255)->nullable()` | ✅ Match | |
| `min` | `varchar(255)` NULLABLE | `string('min', 255)->nullable()` | ✅ Match | |
| `max` | `varchar(255)` NULLABLE | `string('max', 255)->nullable()` | ✅ Match | |
| `step` | `varchar(255)` NULLABLE | `string('step', 255)->nullable()` | ✅ Match | |
| `first_program` | `int(10) unsigned` NULLABLE | `unsignedInteger('first_program')->nullable()` | ✅ Match | |
| `sequence` | `smallint(5) unsigned` NOT NULL, default 0 | `unsignedSmallInteger('sequence')->default(0)` | ✅ Match | |
| `ui_label` | `varchar(255)` NULLABLE | `string('ui_label', 255)->nullable()` | ✅ Match | |
| `ui_description` | `longtext` NULLABLE | `longText('ui_description')->nullable()` | ✅ Match | |

### Foreign Keys

| FK Column in This Table | References Table.Column | Dev DB Delete Rule | Master Migration | Status | Notes |
|-------------------------|------------------------|-------------------|------------------|--------|-------|
| `level` | `m_level.id` | RESTRICT | `foreign('level')->references('id')->on('m_level')->onDelete('restrict')` | ✅ Match | Explicit RESTRICT |
| `first_program` | `m_first_program.id` | RESTRICT | `foreign('first_program')->references('id')->on('m_first_program')->onDelete('restrict')` | ✅ Match | Explicit RESTRICT |

**Foreign Key Details:**

#### FK 1: `level` → `m_level.id`
- **This Table**: `m_parameter`
- **Field in This Table**: `level` (NOT NULL, `int(10) unsigned`)
- **References**: `m_level.id`
- **Relationship**: A parameter belongs to a level (required relationship)
- **Current Delete Rule**: RESTRICT (implicit in migration, explicit in Dev DB)
- **Meaning**: If you try to delete a `m_level` record, it will be prevented if any `m_parameter` records reference it

#### FK 2: `first_program` → `m_first_program.id`
- **This Table**: `m_parameter`
- **Field in This Table**: `first_program` (nullable, `int(10) unsigned`)
- **References**: `m_first_program.id`
- **Relationship**: A parameter can optionally belong to a first program (Explore, Challenge, Joint)
- **Current Delete Rule**: RESTRICT (implicit in migration, explicit in Dev DB)
- **Meaning**: If you try to delete a `m_first_program` record, it will be prevented if any `m_parameter` records reference it

### Indexes

| Index | Dev DB | Master Migration | Status |
|-------|--------|------------------|--------|
| `m_parameter_name_unique` | Exists on `name` (UNIQUE) | Created by `->unique()` on `name` | ✅ Match | Unique index on `name` |
| `m_parameter_level_foreign` | Exists on `level` | Created by foreign key | ✅ Match | |
| `m_parameter_first_program_foreign` | Exists on `first_program` | Created by foreign key | ✅ Match | |

## Issues Found

### 1. ✅ Unique Index on `name` - RESOLVED
- **Decision**: Add unique constraint to migration
- **Action**: Added `->unique()` to `name` column in migration

### 2. ✅ Foreign Key `level` - RESOLVED
- **Decision**: Make RESTRICT explicit
- **Action**: Added `->onDelete('restrict')` to foreign key definition

### 3. ✅ Foreign Key `first_program` - RESOLVED
- **Decision**: Make RESTRICT explicit
- **Action**: Added `->onDelete('restrict')` to foreign key definition

## Standards Compliance

- ✅ ID type: `unsignedInteger` (correct)
- ✅ Foreign key types: `unsignedInteger` (correct, matches referenced IDs)
- ⚠️ Foreign key delete rules: RESTRICT (needs decision on whether to make explicit)
- ⚠️ Unique constraint: `name` column has unique index in Dev DB but not in migration

## Decisions Made

1. ✅ **`name` column unique constraint**: Added `->unique()` to migration
2. ✅ **FK `level` delete rule**: Made explicit `->onDelete('restrict')`
3. ✅ **FK `first_program` delete rule**: Made explicit `->onDelete('restrict')`

## Next Steps

1. ✅ **All decisions made and applied**
2. ✅ **Migration updated**
3. ✅ **Review Complete**: Table #7 is ready

