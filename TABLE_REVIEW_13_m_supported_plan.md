# Table Review #13: m_supported_plan

## Status: ✅ Ready to Complete (Migration Updated)

## Comparison: Dev Database vs Master Migration

### Columns

| Column | Dev DB | Master Migration | Status | Notes |
|--------|--------|------------------|--------|-------|
| `id` | `int(10) unsigned` NOT NULL | `unsignedInteger` | ✅ Match | |
| `first_program` | `int(10) unsigned` NULLABLE | `unsignedInteger('first_program')->nullable()` | ✅ Match | |
| `teams` | `smallint(5) unsigned` NULLABLE | `unsignedSmallInteger('teams')->nullable()` | ✅ Match | |
| `lanes` | `smallint(5) unsigned` NULLABLE | `unsignedSmallInteger('lanes')->nullable()` | ✅ Match | |
| `tables` | `smallint(5) unsigned` NULLABLE | `unsignedSmallInteger('tables')->nullable()` | ✅ Match | |
| `jury_rounds` | `smallint(5) unsigned` NOT NULL, default 0 | **REMOVED** | ✅ Match | Remove from Dev DB (not in migration) |
| `calibration` | `tinyint(1)` NULLABLE | `boolean('calibration')->nullable()` | ✅ Match | |
| `note` | `text` NULLABLE | `text('note')->nullable()` | ✅ Match | |
| `alert_level` | `tinyint(3) unsigned` NULLABLE | `unsignedTinyInteger('alert_level')->nullable()` | ✅ Match | |

### Foreign Keys

| FK Column | References | Dev DB Delete Rule | Migration | Status |
|-----------|------------|-------------------|-----------|--------|
| `first_program` | `m_first_program.id` | RESTRICT | `->onDelete('restrict')` | ✅ Match | Explicit RESTRICT |

## Issues Found

1. ✅ **`jury_rounds` column**: Remove from Dev DB (decision made)
2. ✅ **FK delete rule**: Made explicit `->onDelete('restrict')`

## Decisions Made

1. ✅ **`jury_rounds` column**: Remove from Dev DB (not needed in migration)
2. ✅ **FK `first_program` delete rule**: Made explicit RESTRICT

**Note**: `jury_rounds` column should be removed from Dev DB on next migration/deployment.

