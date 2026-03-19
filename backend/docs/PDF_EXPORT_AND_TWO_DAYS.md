# PDF Export and Two-Day (Finale) Support

Overview of how each PDF is generated and where two-day logic already exists or needs improvement. **One-day behaviour must remain unchanged** (`event.days === 1` or `event.level !== 2`).

---

## 1. Entry point: `PlanExportController::download()`

- **Route:** `GET/POST /api/export/pdf_download/{type}/{eventId}`  
- **Types:** `rooms` | `teams` | `roles` | `full`
- **Two-day detection:** `event.days` from DB; `$isMultidayEvent = (int)($eventDays ?? 1) > 1`.
- **Current two-day behaviour:** Reduces `$maxRowsPerPage` from 16 to 14 when multiday (to leave space for date bar). Passed into `roomSchedulePdf`, `teamSchedulePdf`, `roleSchedulePdf`. **`full` (Gesamtplan) does not use `$maxRowsPerPage` and has no day grouping** (see below).

---

## 2. Rooms PDF – `roomSchedulePdf($planId, $maxRowsPerPage)`

- **Controller:** `PlanExportController::roomSchedulePdf()` (~line 1840+).
- **View:** `pdf/content/room_schedule.blade.php` (per-room, per-chunk).
- **Data:** Activities from `ActivityFetcherService` (already filtered by event date range via `event.date` + `event.days`). Grouped by room; rows built with `start_date` (Carbon) for each activity.
- **Existing two-day logic:**
  - `$isMultidayEvent` from `$event->days`.
  - Each **chunk** of rows gets `multi_day_event` and `page_date` (first row’s `start_date`) passed to the view.
  - View: groups rows by day (`$activitiesByDay` by `start_date->format('Y-m-d')`). If `multi_day_event && page_date`: shows a date bar at top. If `$isMultiDay` (multiple days in that chunk): shows a day header per day.
- **Chunking:** `array_chunk($rows, $maxRowsPerPage)` – **does not split by day**; a page can contain two days if the chunk spans the boundary.
- **Room schedule preparation block** (team_plan rooms): uses `pdf/content/room_schedule_preparation.blade.php`. **No `multi_day_event` or `page_date`** passed; no day headers. For two days, preparation rooms might list teams from both days without separation.

**Improvement ideas:**  
- Ensure chunking respects day boundaries (e.g. don’t put two days on one page, or add day header when crossing days).  
- Add day context to preparation section if teams can be in different days.

---

## 3. Teams PDF – `teamSchedulePdf($planId, $programIds, $maxRowsPerPage)`

- **Controller:** `PlanExportController::teamSchedulePdf()` (~line 2137+).
- **View:** `pdf/content/team_schedule.blade.php` (per team page, then chunked).
- **Data:** Explore + Challenge activities; `buildExploreTeamPages` / `buildChallengeTeamPages`; rows include `start_date`.
- **Existing two-day logic:**
  - `$isMultidayEvent` from `$event->days`; if multiday, `$maxRowsPerPage` capped at 14.
  - View receives `multi_day_event` and `page_date` (first row of chunk). View shows date bar when `multi_day_event && page_date`.
  - **Team schedule view does not group rows by day** – it only shows the date bar at top; rows are a flat list per chunk. So for a team active on both days, the table is still one list (possibly spanning pages) with only the first row’s date on the bar.
- **Chunking:** Same as rooms: uniform `array_chunk($rows, $maxRowsPerPage)`; no day-boundary awareness.

**Improvement ideas:**  
- Group rows by day inside each team page and show day headers (like roles/rooms), or at least ensure page breaks and date bars when the chunk crosses days.  
- Consider ordering by day then time so two-day teams see clear day sections.

---

## 4. Roles PDF – `roleSchedulePdf($planId, $roleIds, $maxRowsPerPage)`

- **Controller:** `PlanExportController::roleSchedulePdf()` (~line 2738+).
- **View:** `pdf/content/role_schedule.blade.php` (per role section).
- **Data:** Activities by role (Explore/Challenge jury, refs, check, live challenge); rows include `start_date`.
- **Existing two-day logic:**
  - `$isMultidayEvent` from `$event->days`; `$maxRowsPerPage` limited to 14 when multiday.
  - View: groups rows by day (`$activitiesByDay` by `start_date`). Shows `page_date` bar when `multi_day_event && page_date`. When `$isMultiDay`, shows a day header per day. **This is the most complete two-day handling** among the schedule PDFs.
- **Chunking:** Sections are by role/section; within a section, chunking is uniform. So a single “section page” could still span two days without an extra day header if the chunk boundary is in the middle.

**Improvement ideas:**  
- Ensure each chunk either contains one day only or has a day header when the chunk spans days (similar to rooms).

---

## 5. Full schedule PDF (Gesamtplan) – `fullSchedulePdf($planId)`

- **Controller:** `PlanExportController::fullSchedulePdf()` (~line 1160+).
- **View:** `pdf/plan_export.blade.php` + partials (`plan_export/team.blade.php`, `lane.blade.php`, `table.blade.php`, `general.blade.php`).
- **Data:** All roles with `pdf_export`; activities grouped by program (Freie Blöcke, Explore, Challenge) and by differentiation (team, lane, table, simple). **No date or day passed to the view** – only `programGroups`, `eventName`, `eventDate` (single date), `lastUpdated`.
- **Existing two-day logic:** **None.** All activities are rendered in one flow; no grouping by day, no date bars, no `event.days` check. For a two-day event, Day 1 and Day 2 activities would appear mixed in the same blocks.

**Improvement ideas:**  
- Introduce day grouping at the data level (e.g. group `programGroups` by day, or add a day dimension to each block).  
- Pass `event.days` and per-day labels into the view.  
- Render day headers and optionally split/order blocks by day so “Gesamtplan” is usable for finales.

---

## 6. Event overview PDF – `eventOverviewPdf($planId)`

- **Controller:** `PlanExportController::eventOverviewPdf()` (~line 3394+). Uses `getEventOverviewData($planId)` with `$isPdf = true`.
- **View:** `pdf/event-overview.blade.php` (and shared logic in `getEventOverviewData`).
- **Data:** Activity groups with earliest_start/latest_end; **grouped by day** in `getEventOverviewData`: `$eventsByDay[$dayKey] = ['date' => ..., 'events' => [...]]`. Time grid (10‑min slots) is built globally for PDF (same slot count for all days) and attached per day.
- **Existing two-day logic:**  
  - **Fully day-aware.** Events grouped by `earliest_start->format('Y-m-d')`. For PDF, global time range is computed across days; each day gets the same `timeSlots`; day headers are shown when `$isMultiDay` (count of days > 1).  
  - Preview and PDF share `getEventOverviewData()`; preview uses per-day time ranges (compact); PDF uses global range for consistent grid.

**Improvement ideas:**  
- Mostly done. Verify with real two-day data (e.g. finale) that time grid and day headers align and nothing is cut off.

---

## 7. Match plan PDF (Robot Game) – `matchPlanPdf($planId)`

- **Controller:** `PlanExportController::matchPlanPdf()` (~line 149+).
- **View:** `pdf/match-plan.blade.php`.
- **Data:** Rounds 1–3 (Vorrunde 1/2/3); one block per round; no date or day in view.
- **Existing two-day logic:** **None.** Single plan; for finale, Day 2 has R1–R3 (and possibly finals). If the plan only holds one day’s matches, behaviour is correct. If both days’ matches exist in one plan, they would all be listed without day separation.

**Improvement ideas:**  
- For finales, if match data can span two days, group rounds by day (e.g. by activity `start` date) and add day headers (e.g. “Tag 1 – Testrunde” vs “Tag 2 – Runde 1–3 / Finals”).

---

## 8. Moderator match plan PDF – `moderatorMatchPlanPdf($planId)`

- **Controller:** `PlanExportController::moderatorMatchPlanPdf()` (~line 264+).
- **View:** `pdf/moderator-match-plan.blade.php`.
- **Data:** Rounds 0 (Testrunde), 1–3, plus final rounds (Achtel-, Viertel-, Halb-, Finale). Rounds are ordered by start time for insertion; no explicit day grouping.
- **Existing two-day logic:** **None.** All rounds are in one structure. For finale: Testrunde (and possibly Day 1–specific content) and R1–R3 + finals (typically Day 2) would appear in one sequence without “Tag 1” / “Tag 2” labels.

**Improvement ideas:**  
- Determine day from activity `start` (or event date + days). Group rounds into “Tag 1” and “Tag 2” and render day headers so moderators can tell which day each block belongs to.

---

## 9. Team list PDF – `teamListPdf($planId)`

- **Controller:** `PlanExportController::teamListPdf()` (~line 959+).
- **View:** `pdf/team-list.blade.php`.
- **Data:** Explore and Challenge teams (name, hot number, noshow, room, group); no activity times. Single list per program.
- **Existing two-day logic:** **None.** No dates or days; just team lists. For two-day events, if the same teams attend both days, one list is correct. If you need separate “Teams Tag 1” vs “Teams Tag 2” (e.g. different attendance), that would require new logic and view changes.

**Improvement ideas:**  
- Only if product needs it: optional “by day” team lists (e.g. by team_plan or attendance per day).

---

## 10. Other PDFs (PublishController, LabelController)

- **PublishController::download() / preview():** Event-sheet types (e.g. schedule info, QR). Not plan-structure dependent; two-day awareness only if content mentions dates (e.g. “Event: 12.–13.03.2025”).
- **LabelController (name tags, volunteer labels):** Event-scoped; no per-day logic. Improve only if you need day-specific labels.

---

## 11. Data and services

- **ActivityFetcherService::fetchActivities()**  
  - Already restricts activities to event date range:  
    `DATE(a.start) >= e.date` and  
    `DATE(a.start) <= DATE(e.date) + INTERVAL (COALESCE(e.days, 1) - 1) DAY`.  
  - So all PDFs that use this fetcher only see activities within the event’s days. No change needed for one-day safety.

- **Event model:** `event.date` (first day), `event.days` (number of days). Finale: `event.level = 2`, typically `event.days = 2`.

- **Plan:** One plan per event (single plan ID for the whole event). Activities have `start`/`end` timestamps; date part distinguishes Day 1 vs Day 2.

---

## 12. Summary table

| PDF              | Method                 | Two-day detection     | Day grouping in view        | Page/chunk behaviour        | Priority for 2-day work   |
|------------------|------------------------|------------------------|-----------------------------|-----------------------------|----------------------------|
| Rooms            | roomSchedulePdf       | Yes (rows per page)   | Yes (by day in view)       | Chunk by row count only     | Medium                     |
| Teams            | teamSchedulePdf       | Yes (rows per page)   | Date bar only              | Chunk by row count only     | Medium                     |
| Roles            | roleSchedulePdf       | Yes (rows per page)   | Yes (by day in view)       | Chunk by row count only     | Low (already good)         |
| Full (Gesamtplan)| fullSchedulePdf       | No                     | No                         | N/A                         | High                       |
| Event overview   | eventOverviewPdf      | Yes                    | Yes (eventsByDay)          | Day-aware                   | Low (already good)         |
| Match plan       | matchPlanPdf          | No                     | No                         | N/A                         | Medium (if rounds span days) |
| Moderator plan   | moderatorMatchPlanPdf | No                     | No                         | N/A                         | High                       |
| Team list        | teamListPdf           | No                     | No                         | N/A                         | Low                        |
| Room preparation | (in roomSchedulePdf)  | No                     | No                         | N/A                         | Low                        |

---

## 13. Safe guards for one-day behaviour

- Use `event.days` (or `event.level === 2`) only where you need two-day behaviour; do not change one-day rendering.
- Prefer: `$isMultidayEvent = (int)($event->days ?? 1) > 1`; then branch in controller/view only when `$isMultidayEvent` is true.
- Keep existing one-day defaults: e.g. `$maxRowsPerPage = 16` when not multiday; no extra day headers when `!$isMultidayEvent`.

This document can be updated as you implement improvements.
