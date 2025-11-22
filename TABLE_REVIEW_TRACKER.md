# Table Review Tracker

## Progress: 23/46 tables reviewed

### Master Tables (m_*)
- [x] 1. m_activity_type ✅
- [x] 2. m_activity_type_detail ✅
- [x] 3. m_first_program ✅
- [x] 4. m_insert_point ✅
- [x] 5. m_level ✅
- [x] 6. m_news ✅
- [x] 7. m_parameter ✅
- [x] 8. m_parameter_condition ✅
- [x] 9. m_role ✅
- [x] 10. m_room_type ✅
- [x] 11. m_room_type_group ✅
- [x] 12. m_season ✅
- [x] 13. m_supported_plan ✅
- [x] 14. m_visibility ✅

### Data Tables
- [x] 15. activity ✅
- [x] 16. activity_group ✅
- [x] 17. cache ✅ (System table - skip)
- [x] 18. cache_locks ✅ (System table - skip)
- [x] 19. contao_public_rounds ✅
- [x] 20. event ✅
- [x] 21. event_logo ✅
- [x] 22. extra_block ✅
- [x] 23. failed_jobs ✅ (System table - skip)
- [x] 24. jobs ✅ (System table - skip)
- [x] 25. logo ✅
- [x] 26. match ✅
- [x] 27. migrations ✅ (System table - skip)
- [x] 28. news_user ✅
- [x] 29. plan ✅
- [x] 30. plan_param_value ✅
- [x] 31. publication ✅
- [x] 32. q_plan ✅
- [x] 33. q_plan_team ✅
- [x] 34. q_run ✅
- [x] 35. regional_partner ✅
- [x] 36. room ✅
- [x] 37. room_type_room ✅
- [x] 38. s_generator ✅
- [x] 39. s_one_link_access ✅
- [x] 40. slide ✅
- [x] 41. slideshow ✅
- [x] 42. table_event ✅
- [x] 43. team ✅
- [x] 44. team_plan ✅
- [x] 45. user ✅
- [x] 46. user_regional_partner ✅

## Review Notes

Each table review will document:
- ID type (should be `unsignedInteger`)
- Foreign key types (should be `unsignedInteger` to match referenced IDs)
- Foreign key delete rules (review each)
- Nullable fields (review each)
- Missing foreign keys
- Other discrepancies with master migration

