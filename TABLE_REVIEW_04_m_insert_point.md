# Table Review #4: m_insert_point

## Status: ✅ Ready to Complete (Migration Updated)

## Comparison: Dev Database vs Master Migration

### Columns

| Column | Dev DB | Master Migration | Status | Notes |
|--------|--------|------------------|--------|-------|
| `id` | `int(10) unsigned` NOT NULL | `unsignedInteger` | ✅ Match | Standard type |
| `code` | `varchar(50)` NULLABLE, UNIQUE | `string('code', 50)->nullable()->unique()` | ✅ Match | Added to migration |
| `first_program` | `int(10) unsigned` NULLABLE | `unsignedInteger('first_program')->nullable()` | ✅ Match | |
| `level` | `int(10) unsigned` NULLABLE | `unsignedInteger('level')->nullable()` | ✅ Match | |
| `sequence` | `smallint(5) unsigned` NOT NULL, default 0 | `unsignedSmallInteger('sequence')->default(0)` | ✅ Match | |
| `ui_label` | `varchar(255)` NULLABLE | `string('ui_label', 255)->nullable()` | ✅ Match | |
| `ui_description` | `text` NULLABLE | `text('ui_description')->nullable()` | ✅ Match | |
| `room_type` | **MISSING** | **REMOVED** | ✅ Match | Removed from migration (not in Dev DB) |

### Foreign Keys

| FK Column in This Table | References Table.Column | Dev DB Delete Rule | Master Migration | Status | Notes |
|-------------------------|------------------------|-------------------|------------------|--------|-------|
| `first_program` | `m_first_program.id` | RESTRICT | `foreign('first_program')->references('id')->on('m_first_program')->onDelete('restrict')` | ✅ Match | Explicit RESTRICT |
| `level` | `m_level.id` | RESTRICT | `foreign('level')->references('id')->on('m_level')->onDelete('restrict')` | ✅ Match | Explicit RESTRICT |

**Foreign Key Details:**

#### FK 1: `first_program` → `m_first_program.id`
- **This Table**: `m_insert_point`
- **Field in This Table**: `first_program` (nullable, `int(10) unsigned`)
- **References**: `m_first_program.id`
- **Relationship**: An insert point can optionally belong to a first program (Explore, Challenge, Joint)
- **Current Delete Rule**: RESTRICT (implicit in migration, explicit in Dev DB)

#### FK 2: `level` → `m_level.id`
- **This Table**: `m_insert_point`
- **Field in This Table**: `level` (nullable, `int(10) unsigned`)
- **References**: `m_level.id`
- **Relationship**: An insert point can optionally belong to a level
- **Current Delete Rule**: RESTRICT (implicit in migration, explicit in Dev DB)

### Indexes

| Index | Dev DB | Master Migration | Status |
|-------|--------|------------------|--------|
| `m_insert_point_code_unique` | Exists on `code` (UNIQUE) | Created by `->unique()` on `code` | ✅ Match | Unique index on `code` |
| `m_insert_point_first_program_foreign` | Exists on `first_program` | Created by foreign key | ✅ Match |
| `m_insert_point_level_foreign` | Exists on `first_program` | Created by foreign key | ✅ Match |

## Issues Found

### 1. ✅ Column `code` - RESOLVED
- **Decision**: Add `code` column to migration
- **Action**: Added `string('code', 50)->nullable()->unique()` to migration

### 2. ✅ Column `room_type` - RESOLVED
- **Decision**: Remove `room_type` from migration
- **Action**: Removed `room_type` column from migration

### 3. ✅ Foreign Key `first_program` - RESOLVED
- **Decision**: Make RESTRICT explicit
- **Action**: Added `->onDelete('restrict')` to foreign key definition

### 4. ✅ Foreign Key `level` - RESOLVED
- **Decision**: Make RESTRICT explicit
- **Action**: Added `->onDelete('restrict')` to foreign key definition

## Standards Compliance

- ✅ ID type: `unsignedInteger` (correct)
- ✅ Foreign key types: `unsignedInteger` (correct, matches referenced IDs)
- ⚠️ Foreign key delete rules: RESTRICT (needs decision on whether to make explicit)
- ❌ Column structure: Two mismatches found (`code` and `room_type`)

## Decisions Made

1. ✅ **`code` column**: Added to migration with `->nullable()->unique()`
2. ✅ **`room_type` column**: Removed from migration
3. ✅ **FK `first_program` delete rule**: Made explicit `->onDelete('restrict')`
4. ✅ **FK `level` delete rule**: Made explicit `->onDelete('restrict')`

## Next Steps

1. ✅ **All decisions made and applied**
2. ✅ **Migration updated**
3. ✅ **Review Complete**: Table #4 is ready

