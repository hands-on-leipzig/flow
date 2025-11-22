# Table Review #8: m_parameter_condition

## Status: ✅ Ready to Complete (Migration Updated)

## Comparison: Dev Database vs Master Migration

### Columns

| Column | Dev DB | Master Migration | Status | Notes |
|--------|--------|------------------|--------|-------|
| `id` | `int(10) unsigned` NOT NULL | `unsignedInteger` | ✅ Match | Standard type |
| `parameter` | `int(10) unsigned` NULLABLE | `unsignedInteger("parameter")->nullable()` | ✅ Match | |
| `if_parameter` | `int(10) unsigned` NULLABLE | `unsignedInteger("if_parameter")->nullable()` | ✅ Match | |
| `is` | `enum('=','<','>')` NULLABLE | `enum("is", ["=", "<", ">"])->nullable()` | ✅ Match | |
| `value` | `varchar(255)` NULLABLE | `string("value")->nullable()` | ✅ Match | |
| `action` | `enum('show','hide','disable')` NOT NULL, default 'show' | `enum("action", ["show", "hide", "disable"])->default('show')` | ✅ Match | Added 'disable' value, made NOT NULL with default 'show' |

### Foreign Keys

| FK Column in This Table | References Table.Column | Dev DB Delete Rule | Master Migration | Status | Notes |
|-------------------------|------------------------|-------------------|------------------|--------|-------|
| `parameter` | `m_parameter.id` | SET NULL → CASCADE | `foreign("parameter")->references("id")->on("m_parameter")->onDelete('cascade')` | ✅ Match | Changed to CASCADE |
| `if_parameter` | `m_parameter.id` | SET NULL → CASCADE | `foreign("if_parameter")->references("id")->on("m_parameter")->onDelete('cascade')` | ✅ Match | Changed to CASCADE |

**Foreign Key Details:**

#### FK 1: `parameter` → `m_parameter.id`
- **This Table**: `m_parameter_condition`
- **Field in This Table**: `parameter` (nullable, `int(10) unsigned`)
- **References**: `m_parameter.id`
- **Relationship**: A condition can optionally reference a parameter
- **Delete Rule**: CASCADE (changed from SET NULL)
- **Meaning**: If you delete a `m_parameter` record, all `m_parameter_condition` records referencing it will be deleted

#### FK 2: `if_parameter` → `m_parameter.id`
- **This Table**: `m_parameter_condition`
- **Field in This Table**: `if_parameter` (nullable, `int(10) unsigned`)
- **References**: `m_parameter.id`
- **Relationship**: A condition can optionally reference another parameter for conditional logic
- **Delete Rule**: CASCADE (changed from SET NULL)
- **Meaning**: If you delete a `m_parameter` record, all `m_parameter_condition` records referencing it will be deleted

### Indexes

| Index | Dev DB | Master Migration | Status |
|-------|--------|------------------|--------|
| `m_parameter_condition_parameter_foreign` | Exists on `parameter` | Created by foreign key | ✅ Match | |
| `m_parameter_condition_if_parameter_foreign` | Exists on `if_parameter` | Created by foreign key | ✅ Match | |

## Issues Found

### 1. ✅ Enum Value `action` - RESOLVED
- **Decision**: Add 'disable' to enum
- **Action**: Updated enum to `["show", "hide", "disable"]`

### 2. ✅ Nullability and Default `action` - RESOLVED
- **Decision**: Make NOT NULL with default 'show'
- **Action**: Changed to `->default('show')` (removed nullable)

### 3. ✅ Foreign Key Delete Rules - RESOLVED
- **Decision**: Change both FKs from SET NULL to CASCADE
- **Action**: Changed `parameter` and `if_parameter` FKs to `->onDelete('cascade')`

## Standards Compliance

- ✅ ID type: `unsignedInteger` (correct)
- ✅ Foreign key types: `unsignedInteger` (correct, matches referenced IDs)
- ✅ Foreign key delete rules: CASCADE (changed from SET NULL as requested)
- ✅ Enum values: All three values included ('show', 'hide', 'disable')
- ✅ Nullable/default: `action` column is NOT NULL with default 'show'

## Decisions Made

1. ✅ **`action` enum values**: Added 'disable' to migration enum
2. ✅ **`action` nullability and default**: Made NOT NULL with default 'show'
3. ✅ **Foreign key delete rules**: Changed both FKs from SET NULL to CASCADE

## Next Steps

1. ✅ **All decisions made and applied**
2. ✅ **Migration updated**
3. ⚠️ **Note**: Dev DB currently has SET NULL for these FKs. Migration will change them to CASCADE on next run.
4. ✅ **Review Complete**: Table #8 is ready

