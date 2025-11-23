# Master Migration Cleanup

## Removed Tables

The following tables were removed from the master migration because they were **not** part of the 46 reviewed tables:

1. **`plan_extra_block`** (lines 563-573)
   - Was in master migration but not in reviewed list
   - Removed from `up()` method
   - Removed from `down()` method

2. **`q_plan_match`** (lines 694-710)
   - Was in master migration but not in reviewed list
   - Removed from `up()` method
   - Removed from `down()` method

## Verification

All remaining tables in the master migration (41 tables) match the reviewed list:

### Master Tables (m_*)
- m_season ✅
- m_level ✅
- m_news ✅
- m_room_type_group ✅
- m_room_type ✅
- m_first_program ✅
- m_parameter ✅
- m_parameter_condition ✅
- m_activity_type ✅
- m_activity_type_detail ✅
- m_insert_point ✅
- m_role ✅
- m_visibility ✅
- m_supported_plan ✅

### Data Tables
- regional_partner ✅
- event ✅
- contao_public_rounds ✅
- slideshow ✅
- slide ✅
- publication ✅
- user ✅
- news_user ✅
- user_regional_partner ✅
- room ✅
- room_type_room ✅
- team ✅
- plan ✅
- s_generator ✅
- s_one_link_access ✅
- team_plan ✅
- plan_param_value ✅
- match ✅
- extra_block ✅
- activity_group ✅
- activity ✅
- logo ✅
- event_logo ✅
- table_event ✅
- q_plan ✅
- q_plan_team ✅
- q_run ✅

**Total: 41 tables** (all match the reviewed list, excluding system tables like cache, jobs, migrations, etc.)

## Impact

- No foreign key references to removed tables found
- Master migration now contains only reviewed tables
- Cleanup complete ✅

