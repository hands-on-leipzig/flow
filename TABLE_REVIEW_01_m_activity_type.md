# Table Review #1: m_activity_type

## Status: ✅ Ready to Complete (Migration Updated)

## Comparison: Dev Database vs Master Migration

### Columns

| Column | Dev DB | Master Migration | Status | Notes |
|--------|--------|------------------|--------|-------|
| `id` | `int(10) unsigned` | `unsignedInteger` | ✅ Match | Standard type |
| `name` | `varchar(100)` NOT NULL | `string('name', 100)` | ✅ Match | |
| `sequence` | `smallint(5) unsigned` NOT NULL, default 0 | `unsignedSmallInteger('sequence')->default(0)` | ✅ Match | |
| `description` | `text` NULLABLE | `text('description')->nullable()` | ✅ Match | |
| `first_program` | `int(10) unsigned` NULLABLE | `unsignedInteger('first_program')->nullable()` | ✅ Match | |
| `overview_plan_column` | `varchar(100)` NOT NULL | `string('overview_plan_column', 100)->nullable()` | ❌ **MISMATCH** | Migration says nullable, Dev says NOT NULL |

### Foreign Keys

| FK Column in This Table | References Table.Column | Dev DB Delete Rule | Master Migration | Status | Notes |
|-------------------------|------------------------|-------------------|------------------|--------|-------|
| `first_program` | `m_first_program.id` | RESTRICT | `foreign('first_program')->references('id')->on('m_first_program')`<br>No onDelete specified (defaults to RESTRICT) | ⚠️ **IMPLICIT** | Migration doesn't specify onDelete explicitly |

**Foreign Key Details:**
- **This Table**: `m_activity_type`
- **Field in This Table**: `first_program` (nullable, `int(10) unsigned`)
- **References**: `m_first_program.id`
- **Relationship**: An activity type can optionally belong to a first program (Explore, Challenge, Joint)
- **Current Delete Rule**: RESTRICT (implicit in migration, explicit in Dev DB)
- **Meaning**: If you try to delete a `m_first_program` record, it will be prevented if any `m_activity_type` records reference it

**Delete Rule Options:**
- **RESTRICT** (current): Prevents deletion of `m_first_program` if referenced by `m_activity_type` records
- **SET NULL**: Would set `first_program` to NULL in `m_activity_type` when `m_first_program` is deleted
- **CASCADE**: Would delete all `m_activity_type` records when `m_first_program` is deleted (not recommended for master data)

**Recommendation**: Since both are master tables (m_*), RESTRICT is appropriate - you shouldn't delete a first_program if activity types depend on it.

### Indexes

| Index | Dev DB | Master Migration | Status |
|-------|--------|------------------|--------|
| `m_activity_type_first_program_foreign` | Exists on `first_program` | Created by foreign key | ✅ Match |

## Issues Found

### 1. ✅ Nullability: `overview_plan_column`
- **Dev DB**: NOT NULL
- **Master Migration**: nullable
- **Decision**: Make it nullable (migration is correct)
- **Action**: Update Dev DB to allow NULL values (migration already correct)

### 2. ✅ Foreign Key Delete Rule: `first_program` → `m_first_program.id`
- **Current**: Migration doesn't specify `onDelete()`, defaults to RESTRICT
- **Dev DB**: RESTRICT (matches default)
- **Field in This Table**: `first_program`
- **References**: `m_first_program.id`
- **Decision**: RESTRICT (explicit)
- **Action**: Update migration to add `->onDelete('restrict')` explicitly

## Recommendations

1. ✅ **`overview_plan_column` nullability**: 
   - Migration is correct (nullable)
   - Dev DB needs to be updated to allow NULL (or migration will handle it on next deploy)

2. ✅ **FK delete rule for `first_program` → `m_first_program.id`**:
   - **Decision**: RESTRICT (explicit)
   - **Action**: Add explicit `->onDelete('restrict')` to migration

## Standards Compliance

- ✅ ID type: `unsignedInteger` (correct)
- ✅ Foreign key type: `unsignedInteger` (correct, matches referenced ID)
- ⚠️ Foreign key delete rule: RESTRICT (needs to be explicit in migration)
- ⚠️ Nullable fields: One discrepancy found

## Next Steps

1. ✅ **Decision Made**: `overview_plan_column` should be nullable (migration correct)
2. ✅ **Decision Made**: Foreign key delete rule is RESTRICT (explicit)
3. ✅ **Migration Updated**: Added `->onDelete('restrict')` to the foreign key definition
4. ✅ **Review Complete**: Table #1 is ready

