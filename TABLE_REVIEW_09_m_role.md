# Table Review #9: m_role

## Status: ✅ Ready to Complete (Migration Updated)

## Comparison: Dev Database vs Master Migration

### Columns

| Column | Dev DB | Master Migration | Status | Notes |
|--------|--------|------------------|--------|-------|
| `id` | `int(10) unsigned` NOT NULL | `unsignedInteger` | ✅ Match | Standard type |
| `name` | `varchar(100)` NOT NULL | `string('name', 100)` | ✅ Match | |
| `name_short` | `varchar(50)` NULLABLE | `string('name_short', 50)->nullable()` | ✅ Match | |
| `sequence` | `smallint(5) unsigned` NOT NULL, default 0 | `unsignedSmallInteger('sequence')->default(0)` | ✅ Match | |
| `first_program` | `int(10) unsigned` NULLABLE | `unsignedInteger('first_program')->nullable()` | ✅ Match | |
| `description` | `text` NULLABLE | `text('description')->nullable()` | ✅ Match | |
| `differentiation_type` | `varchar(100)` NULLABLE | `string('differentiation_type', 100)->nullable()` | ✅ Match | |
| `differentiation_source` | `text` NULLABLE | `text('differentiation_source')->nullable()` | ✅ Match | |
| `differentiation_parameter` | `varchar(100)` NULLABLE | `string('differentiation_parameter', 100)->nullable()` | ✅ Match | |
| `preview_matrix` | `tinyint(1)` NOT NULL, default 0 | `boolean('preview_matrix')->default(false)` | ✅ Match | |
| `pdf_export` | `tinyint(1)` NOT NULL, default 0 | `boolean('pdf_export')->default(false)` | ✅ Match | |

### Foreign Keys

| FK Column in This Table | References Table.Column | Dev DB Delete Rule | Master Migration | Status | Notes |
|-------------------------|------------------------|-------------------|------------------|--------|-------|
| `first_program` | `m_first_program.id` | RESTRICT | `foreign('first_program')->references('id')->on('m_first_program')->onDelete('restrict')` | ✅ Match | Explicit RESTRICT |

**Foreign Key Details:**

#### FK: `first_program` → `m_first_program.id`
- **This Table**: `m_role`
- **Field in This Table**: `first_program` (nullable, `int(10) unsigned`)
- **References**: `m_first_program.id`
- **Relationship**: A role can optionally belong to a first program (Explore, Challenge, Joint)
- **Current Delete Rule**: RESTRICT (implicit in migration, explicit in Dev DB)
- **Meaning**: If you try to delete a `m_first_program` record, it will be prevented if any `m_role` records reference it

### Indexes

| Index | Dev DB | Master Migration | Status |
|-------|--------|------------------|--------|
| `m_role_first_program_foreign` | Exists on `first_program` | Created by foreign key | ✅ Match | |

## Issues Found

### 1. ✅ Foreign Key Delete Rule - RESOLVED
- **Decision**: Make RESTRICT explicit
- **Action**: Added `->onDelete('restrict')` to foreign key definition

## Standards Compliance

- ✅ ID type: `unsignedInteger` (correct)
- ✅ Foreign key types: `unsignedInteger` (correct, matches referenced IDs)
- ⚠️ Foreign key delete rules: RESTRICT (needs decision on whether to make explicit)
- ✅ Nullable fields: All match correctly
- ✅ Boolean fields: `preview_matrix` and `pdf_export` use boolean type (correct)

## Decisions Made

1. ✅ **FK `first_program` delete rule**: Made explicit `->onDelete('restrict')`

## Next Steps

1. ✅ **Decision made and applied**
2. ✅ **Migration updated**
3. ✅ **Review Complete**: Table #9 is ready

