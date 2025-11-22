# Data Type Standards - FINAL DECISIONS

## Purpose
Established standards for data types to ensure consistency across the database.

## Final Standards (Decided)

### 1. Integer Types - DECIDED ✅

**Standard:**
- **All IDs**: `unsignedInteger` (length 10) - **STANDARDIZE ALL TO THIS**
- **All Foreign Keys**: `unsignedInteger` (length 10) - **MUST MATCH REFERENCED ID TYPE**
- **Other integer types**: Keep current variations as-is
  - `smallInteger` / `unsignedSmallInteger` for sequences, small counts
  - `tinyInteger` / `unsignedTinyInteger` for flags, very small values
  - `integer` (signed) when values can be negative
  - `unsignedInteger` for counts/quantities that are never negative

**Action Items:**
- Standardize all `id` columns to `unsignedInteger`
- Ensure all foreign keys are `unsignedInteger` to match referenced IDs
- Review during table-by-table review

### 2. String Types - DECIDED ✅

**Standard:**
- **Keep current length variations as-is**
- Common lengths in use: 10, 20, 50, 100, 255, 500
- Use `text` for longer content
- Use `longText` for very long content
- Use `char($length)` for fixed-length codes when appropriate

**No changes needed** - current pattern is good

### 3. Date/Time Types - DECIDED ✅

**Standard:**
- **Keep current types as-is**
- `timestamp` for system timestamps (created_at, updated_at, last_change)
- `datetime` for business event times (activity.start, activity.end)
- `date` for date-only fields (event.date)
- `time` for time-only fields (if needed)

**No changes needed** - current pattern is good

### 4. Boolean Types - DECIDED ✅

**Standard:**
**Options:**
- `boolean` - TINYINT(1) (0 or 1)
- `tinyInteger` - TINYINT (more explicit)

**Questions:**
- Should we use `boolean` or `tinyInteger`?
- Should nullable booleans be allowed?

**Proposed Standard:**
- Use `boolean` for true/false flags
- Use `nullable()->boolean()` if null is meaningful (unknown/not set)

### 5. Decimal/Numeric Types - DECIDED ✅

**Standard:**
**Options:**
- `decimal($precision, $scale)` - DECIMAL (exact precision)
- `float` - FLOAT (approximate)
- `double` - DOUBLE (approximate, more precision)

**Questions:**
- When to use `decimal` vs `float`?
- What precision/scale for common cases?

**Proposed Standard:**
- Money/currency: `decimal(10, 2)` or `decimal(15, 2)`
- Percentages: `decimal(5, 2)` (0.00 to 999.99)
- General decimals: `decimal($precision, $scale)` based on need
- Avoid `float` unless performance is critical and precision loss is acceptable

### 6. JSON Types - DECIDED ✅

**Standard:**
**Options:**
- `json` - JSON column type
- `text` with JSON encoding

**Questions:**
- Should we use native JSON type or TEXT?

**Proposed Standard:**
- Use `json` for structured data that needs querying
- Use `text` if JSON is just storage and won't be queried

### 7. Enum Types - DECIDED ✅

**Standard:**
**Options:**
- `enum([...])` - ENUM type
- `string($length)` with application-level validation
- `tinyInteger` with application-level mapping

**Questions:**
- Should we use database ENUMs or application-level validation?
- What are the trade-offs?

**Proposed Standard:**
- Use `enum([...])` for fixed, small sets of values that rarely change
- Use `string($length)` with validation for values that might expand
- Document enum values in code comments

## Foreign Key Delete Rules - TO REVIEW ⚠️

**Standard:**
- **Review each foreign key during table-by-table review**
- Current patterns:
  - **RESTRICT**: Most common (m_* tables, event, regional_partner)
  - **CASCADE**: Dependent data (plan → plan_param_value, team → team_plan)
  - **SET NULL**: Optional relationships (activity.extra_block)
- **Action**: Review each FK's delete rule and document rationale
- **Action**: Add missing foreign keys where needed

## Nullable Fields - TO REVIEW ⚠️

**Standard:**
- **Review each nullable field during table-by-table review**
- Document rationale for each nullable field
- Ensure nullable is used appropriately (optional vs required)
- **Action**: Review each nullable field and document decision

## Summary of Decisions

✅ **Decided:**
1. All IDs: `unsignedInteger` (length 10) - **STANDARDIZE**
2. All Foreign Keys: `unsignedInteger` (length 10) - **MUST MATCH REFERENCED ID**
3. String lengths: Keep current variations as-is
4. Date/time types: Keep current types as-is
5. Integer types: Keep current variations as-is (except IDs/FKs)

⚠️ **To Review During Table-by-Table Review:**
1. Foreign key delete rules (RESTRICT/CASCADE/SET NULL)
2. Nullable fields (document rationale for each)
3. Missing foreign keys (add where needed)

## Next Steps

1. ✅ Standards decided
2. ✅ Ready for table-by-table review
3. Start with first m_* table and work through systematically
4. For each table:
   - Compare exported schema with master migration
   - Standardize IDs and FKs to `unsignedInteger`
   - Review foreign key delete rules
   - Review nullable fields
   - Document discrepancies and fixes needed

