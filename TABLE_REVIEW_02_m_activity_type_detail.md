# Table Review #2: m_activity_type_detail

## Status: ✅ Ready to Complete (Migration Updated)

## Comparison: Dev Database vs Master Migration

### Columns

| Column | Dev DB | Master Migration | Status | Notes |
|--------|--------|------------------|--------|-------|
| `id` | `int(10) unsigned` NOT NULL | `unsignedInteger` | ✅ Match | Standard type |
| `name` | `varchar(100)` NOT NULL | `string('name', 100)` | ✅ Match | |
| `code` | `varchar(50)` NULLABLE | `string('code', 50)->nullable()` | ✅ Match | |
| `name_preview` | `varchar(100)` NULLABLE | `string('name_preview', 100)->nullable()` | ✅ Match | |
| `sequence` | `smallint(5) unsigned` NOT NULL, default 0 | `unsignedSmallInteger('sequence')->default(0)` | ✅ Match | |
| `first_program` | `int(10) unsigned` NULLABLE | `unsignedInteger('first_program')->nullable()` | ✅ Match | |
| `description` | `text` NULLABLE | `text('description')->nullable()` | ✅ Match | |
| `link` | `varchar(255)` NULLABLE | `string('link', 255)->nullable()` | ✅ Match | |
| `link_text` | `varchar(100)` NULLABLE | `string('link_text', 100)->nullable()` | ✅ Match | |
| `activity_type` | `int(10) unsigned` NOT NULL | `unsignedInteger('activity_type')` | ✅ Match | |

### Foreign Keys

| FK Column in This Table | References Table.Column | Dev DB Delete Rule | Master Migration | Status | Notes |
|-------------------------|------------------------|-------------------|------------------|--------|-------|
| `activity_type` | `m_activity_type.id` | RESTRICT | `foreign('activity_type')->references('id')->on('m_activity_type')`<br>No onDelete specified | ⚠️ **IMPLICIT** | Migration doesn't specify onDelete explicitly |
| `first_program` | `m_first_program.id` | RESTRICT | `foreign('first_program')->references('id')->on('m_first_program')`<br>No onDelete specified | ⚠️ **IMPLICIT** | Migration doesn't specify onDelete explicitly |

**Foreign Key Details:**

#### FK 1: `activity_type` → `m_activity_type.id`
- **This Table**: `m_activity_type_detail`
- **Field in This Table**: `activity_type` (NOT NULL, `int(10) unsigned`)
- **References**: `m_activity_type.id`
- **Relationship**: An activity type detail belongs to an activity type (required relationship)
- **Current Delete Rule**: RESTRICT (implicit in migration, explicit in Dev DB)
- **Meaning**: If you try to delete a `m_activity_type` record, it will be prevented if any `m_activity_type_detail` records reference it

#### FK 2: `first_program` → `m_first_program.id`
- **This Table**: `m_activity_type_detail`
- **Field in This Table**: `first_program` (nullable, `int(10) unsigned`)
- **References**: `m_first_program.id`
- **Relationship**: An activity type detail can optionally belong to a first program (Explore, Challenge, Joint)
- **Current Delete Rule**: RESTRICT (implicit in migration, explicit in Dev DB)
- **Meaning**: If you try to delete a `m_first_program` record, it will be prevented if any `m_activity_type_detail` records reference it

### Indexes

| Index | Dev DB | Master Migration | Status |
|-------|--------|------------------|--------|
| `m_activity_type_detail_activity_type_foreign` | Exists on `activity_type` | Created by foreign key | ✅ Match |
| `m_activity_type_detail_first_program_foreign` | Exists on `first_program` | Created by foreign key | ✅ Match |

## Issues Found

### 1. ⚠️ Foreign Key Delete Rule Not Explicit: `activity_type` → `m_activity_type.id`
- **Current**: Migration doesn't specify `onDelete()`, defaults to RESTRICT
- **Dev DB**: RESTRICT (matches default)
- **Field in This Table**: `activity_type` (required, NOT NULL)
- **References**: `m_activity_type.id`
- **Decision**: RESTRICT (explicit) - Both are master tables, required relationship
- **Action**: Add explicit `->onDelete('restrict')` to migration

### 2. ⚠️ Foreign Key Delete Rule Not Explicit: `first_program` → `m_first_program.id`
- **Current**: Migration doesn't specify `onDelete()`, defaults to RESTRICT
- **Dev DB**: RESTRICT (matches default)
- **Field in This Table**: `first_program` (optional, nullable)
- **References**: `m_first_program.id`
- **Decision**: RESTRICT (explicit) - Both are master tables, should prevent deletion if referenced
- **Action**: Add explicit `->onDelete('restrict')` to migration

## Recommendations

1. ✅ **FK delete rule for `activity_type` → `m_activity_type.id`**:
   - **Decision**: RESTRICT (explicit)
   - **Action**: Add explicit `->onDelete('restrict')` to migration
   - **Rationale**: Required relationship between master tables, should prevent deletion if referenced

2. ✅ **FK delete rule for `first_program` → `m_first_program.id`**:
   - **Decision**: RESTRICT (explicit)
   - **Action**: Add explicit `->onDelete('restrict')` to migration
   - **Rationale**: Both are master tables, should prevent deletion if referenced

## Standards Compliance

- ✅ ID type: `unsignedInteger` (correct)
- ✅ Foreign key types: `unsignedInteger` (correct, matches referenced IDs)
- ⚠️ Foreign key delete rules: RESTRICT (needs to be explicit in migration)
- ✅ Nullable fields: All match correctly

## Next Steps

1. ✅ **Decision Made**: Both foreign keys should use RESTRICT (explicit)
2. ✅ **Migration Updated**: Added `->onDelete('restrict')` to both foreign key definitions
3. ✅ **Review Complete**: Table #2 is ready

