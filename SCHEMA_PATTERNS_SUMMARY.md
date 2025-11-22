# Current Database Schema Patterns Summary

Based on analysis of the Dev database schema, here are the identified patterns:

## 1. ID Column Types

### Primary Pattern: `int(10) unsigned`
- **43 columns** use this type for IDs
- Used for: Most primary keys (activity, event, plan, m_* tables, etc.)
- **Standard**: This is the primary ID type

### Exceptions:
- `bigint(20) unsigned`: 1 column (s_one_link_access.id) - likely for future scalability
- `int(11)`: 4 columns (event_logo.id, room_type_room.id, user.dolibarr_id, user_regional_partner.id)
- `varchar(10)`: 1 column (regional_partner.dolibarr_id) - external ID

**Recommendation**: Standardize on `unsignedInteger` for all primary keys (matches Laravel convention for IDs)

## 2. String Length Patterns

### Common Lengths:
- **VARCHAR(255)**: 37 columns - Most common, used for:
  - URLs, links, paths
  - Email addresses
  - Cache keys
  - General text fields

- **VARCHAR(100)**: 18 columns - Used for:
  - Names (event.name, m_activity_type.name)
  - Labels and titles
  - Overview columns

- **VARCHAR(50)**: 10 columns - Used for:
  - Short names (m_level.name, m_first_program.name)
  - Codes (m_activity_type_detail.code, m_insert_point.code)

- **VARCHAR(10)**: 3 columns - Used for:
  - Color codes (m_first_program.color_hex)
  - Language codes (user.lang)
  - External IDs (regional_partner.dolibarr_id)

- **VARCHAR(20)**: 3 columns - Used for:
  - Status fields (q_run.status)
  - Source/connection types

- **VARCHAR(64)**: 1 column - Used for:
  - Hashed values (s_one_link_access.ip_hash)

- **VARCHAR(500)**: 1 column - Used for:
  - Long links (m_news.link)

**Pattern**: Clear hierarchy: 10 → 20 → 50 → 100 → 255 → 500

## 3. Date/Time Types

### Pattern:
- **timestamp**: 14 columns - Used for:
  - Created/updated timestamps (plan.created, plan.last_change)
  - Event timestamps (q_run.started_at, q_run.finished_at)
  - Laravel timestamps (m_news.created_at, m_news.updated_at)

- **datetime**: 4 columns - Used for:
  - Activity start/end times (activity.start, activity.end)
  - Extra block times (extra_block.start, extra_block.end)

- **date**: 2 columns - Used for:
  - Event dates (event.date)
  - Access dates (s_one_link_access.access_date)

**Pattern**: 
- `timestamp` for system timestamps and Laravel conventions
- `datetime` for business event times (activities, blocks)
- `date` for date-only fields

## 4. Integer Types

### Patterns:
- **int unsigned**: 105 columns - Most common
  - IDs, foreign keys, counts
  - Used when values are never negative

- **int signed**: 50 columns
  - Used when values can be negative
  - External IDs (contao_id_explore, contao_id_challenge)
  - Cache expiration timestamps

- **smallint unsigned**: 20 columns
  - Sequence numbers (m_activity_type.sequence)
  - Small counts (event.event_explore, event.event_challenge)
  - Sort orders (event_logo.sort_order)

- **tinyint signed**: 20 columns
  - Boolean-like flags in contao_public_rounds table
  - Various flags and small numeric values

- **tinyint unsigned**: 7 columns
  - Small counts (activity.jury_lane, activity.table_1, activity.table_2)
  - Event days (event.days)
  - Alert levels (m_supported_plan.alert_level)

**Pattern**: 
- Use smallest appropriate type (tinyint < smallint < int < bigint)
- Use unsigned when values are never negative
- Use signed when values can be negative

## 5. Foreign Key Patterns

### Delete Rules:
- **RESTRICT**: Most common (used for m_* table references)
  - Prevents deletion if child records exist
  - Used for: m_first_program, m_level, m_role, event, regional_partner, etc.

- **CASCADE**: Used for dependent data
  - Used for: plan → plan_param_value, plan → s_generator
  - Used for: event → activity_group → activity
  - Used for: team → team_plan
  - Used for: room → room_type_room, extra_block

- **SET NULL**: Used for optional references
  - Used for: activity.extra_block
  - Used for: m_parameter_condition.parameter

**Pattern**:
- **RESTRICT** for master data (m_* tables) - prevents accidental deletion
- **CASCADE** for dependent operational data - clean up when parent deleted
- **SET NULL** for optional relationships - allow orphaned records

### Type Matching:
✅ **All foreign keys match their referenced column types!**
- This is good - no type mismatches found

## 6. Nullable Patterns

### Most Common Nullable Types:
- **int(10) unsigned**: 31 nullable columns
  - Foreign keys that are optional
  - Counts that might not be set yet

- **varchar(255)**: 27 nullable columns
  - Optional text fields
  - URLs, links, descriptions

- **int(11)**: 15 nullable columns
  - Optional integer values
  - External IDs that might not exist

- **text**: 13 nullable columns
  - Optional descriptions
  - Long-form content

- **timestamp**: 9 nullable columns
  - Optional timestamps
  - Dates that might not be set yet

**Pattern**: Nullable is used appropriately for optional fields

## 7. Key Observations

### Strengths:
1. ✅ Consistent ID type: `int(10) unsigned` for most IDs
2. ✅ Foreign keys match referenced types perfectly
3. ✅ Clear string length hierarchy
4. ✅ Appropriate use of nullable
5. ✅ Good use of smaller integer types where appropriate

### Areas for Standardization:
1. **ID Types**: 
   - Most use `int(10) unsigned` ✅
   - Some use `int(11)` - should standardize
   - One uses `bigint(20) unsigned` - intentional or should be standardized?

2. **String Lengths**: 
   - Clear patterns exist ✅
   - Could document standard lengths for common use cases

3. **Date/Time**: 
   - Clear pattern: timestamp for system, datetime for business ✅
   - Consistent usage

4. **Foreign Keys**: 
   - All match types ✅
   - Delete rules follow logical patterns ✅

## Recommendations for Standards

Based on these patterns, proposed standards:

1. **IDs**: `unsignedInteger` (matches current pattern of 43/46 tables)
2. **String Lengths**: 
   - Codes: `string(50)`
   - Names: `string(100)`
   - URLs/Paths: `string(255)`
   - Descriptions: `text`
3. **Timestamps**: `timestamp` for system times, `datetime` for business times
4. **Foreign Keys**: Always match referenced column type exactly
5. **Delete Rules**: 
   - RESTRICT for m_* tables
   - CASCADE for dependent operational data
   - SET NULL for optional relationships

