# Table Review #30: plan_param_value

## Current Schema (Dev DB)

### Columns
- `id` (int(10) unsigned, NOT NULL, PRIMARY KEY, auto_increment) ✅
- `parameter` (int(10) unsigned, nullable, FK) ⚠️ **Dev DB: nullable, Master: NOT NULL**
- `plan` (int(10) unsigned, nullable, FK) ⚠️ **Dev DB: nullable, Master: NOT NULL**
- `set_value` (varchar(255), nullable) ✅

### Indexes
- `parameter` (index on `parameter`) ✅ (created by FK)
- `plan` (index on `plan`) ✅ (created by FK)

### Foreign Keys
- `plan` → `plan.id`: RESTRICT on update, **CASCADE on delete** ✅
- **Note:** Dev DB export doesn't show FK for `parameter`, but master migration has one

## Master Migration Current State

- `id`: `unsignedInteger` ✅
- `plan`: `unsignedInteger` (NOT NULL) ⚠️ **Dev DB: nullable**
- `parameter`: `unsignedInteger` (NOT NULL) ⚠️ **Dev DB: nullable**
- `value`: `string('value', 255)->nullable()` ⚠️ **NOT in Dev DB!**
- `set_value`: `string('set_value', 255)->nullable()` ✅
- FK: `plan` → `plan.id` with `onDelete('cascade')` ✅
- FK: `parameter` → `m_parameter.id` (no explicit rule) ⚠️ **Should have delete rule?**

## Usage
- Stores plan-specific parameter value overrides
- Used in `PlanParamValue` model with relationship to `MParameter`
- Used in `PlanParameterController` and `PlanParameter` support class
- Model `fillable` includes: `parameter`, `plan`, `set_value` (no `value` column)

## Questions for Review

1. **Extra Column:**
   - Master migration has `value` column, but Dev DB does NOT have it
   - Model doesn't use `value` column (only `set_value`)
   - **Should `value` column be removed from master migration?**

2. **Nullable Fields:**
   - `plan` and `parameter`: Dev DB shows nullable, master has NOT NULL
   - **Which is correct?** (Junction table typically has NOT NULL FKs, but Dev DB shows nullable)

3. **FK Delete Rule for `parameter`:**
   - Master has FK to `m_parameter.id` but no explicit delete rule
   - **What should the delete rule be?** (CASCADE, RESTRICT, SET NULL?)

## Decisions ✅

- [x] **Remove `value` column from master migration** ✅
- [x] **Both `plan` and `parameter` NOT NULL with unique constraint together** ✅
- [x] **Both FK delete rules: CASCADE** ✅

## Implementation

Updated master migration:
- Removed `value` column (doesn't exist in Dev DB, not used by model)
- Kept `plan` and `parameter` as NOT NULL (already correct)
- Added unique constraint on (`plan`, `parameter`) to prevent duplicate parameter assignments per plan
- Changed FK delete rule for `parameter` to `onDelete('cascade')`
- FK for `plan` already had CASCADE ✅

