<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { formatTimeOnly } from '@/utils/dateTimeFormat';
import { programLogoAlt, programLogoSrc } from '@/utils/images';
import { useScaleToFit } from '@/composables/useScaleToFit';

const props = withDefaults(defineProps<{
  result: any;
  showDemoData?: boolean;
}>(), {
  showDemoData: false,
});

const containerRef = ref<HTMLElement | null>(null);
const contentRef = ref<HTMLElement | null>(null);
const { scaleFactor, updateScale } = useScaleToFit(containerRef, contentRef, {
  safetyMargin: 0.02,
  observe: 'both',
});

// This should be used in the future as a scaling preview, currently unused.
function getDemoData() {
  return {
    "plan_id": 19,
    "groups": [
      {
        "activity_group_id": 1203,
        "group_meta": {
          "name": "Er\u00f6ffnung Challenge",
          "first_program_id": 3,
          "first_program_name": "CHALLENGE",
          "description": "Der Challenge Wettbewerb wird er\u00f6ffnet"
        },
        "activities": {
          "4381": {
            "activity_id": 4381,
            "start_time": "2026-01-24 09:00:00",
            "end_time": "2026-01-24 09:30:00",
            "activity_name": "Er\u00f6ffnung C",
            "meta": {
              "name": "Er\u00f6ffnung Challenge",
              "first_program_id": 3,
              "first_program_name": "CHALLENGE",
              "description": "Der Challenge Wettbewerb wird er\u00f6ffnet"
            },
            "program": "CHALLENGE",
            "lane": null,
            "team": null,
            "table_1": null,
            "table_1_name": null,
            "table_1_team": null,
            "table_2": null,
            "table_2_name": null,
            "table_2_team": null,
            "team_name": null,
            "table_1_team_name": null,
            "table_2_team_name": null,
            "room": {
              "room_type_id": 45,
              "room_type_name": "Er\u00f6ffnung Challenge",
              "room_id": null,
              "room_name": "Großer Saal"
            }
          }
        }
      },
      {
        "activity_group_id": 1215,
        "group_meta": {
          "name": "Robot-Game Vorrunde 1",
          "first_program_id": 3,
          "first_program_name": "CHALLENGE",
          "description": "Die erste von drei Robot-Game Runden"
        },
        "activities": {
          "4466": {
            "activity_id": 4466,
            "start_time": "2026-01-24 09:45:00",
            "end_time": "2026-01-24 09:50:00",
            "activity_name": "Match",
            "meta": {
              "name": "Robot-Game Match",
              "first_program_id": 3,
              "first_program_name": "CHALLENGE",
              "description": "In einem Match stehen sich zwei Teams gegen\u00fcber. Ein Match dauert 2:30 Min. Danach vergeben die Schiedsrichter:innen Punkte pro gel\u00f6ster Aufgabe"
            },
            "program": "CHALLENGE",
            "lane": null,
            "team": null,
            "table_1": 3,
            "table_1_name": "Tisch 1",
            "table_1_team": 9,
            "table_2": 4,
            "table_2_name": "Tisch 2",
            "table_2_team": 11,
            "team_name": null,
            "table_1_team_name": "JFG B\u00e4ren",
            "table_2_team_name": "WHG - Robotics",
            "room": {"room_type_id": 1, "room_type_name": "Robot-Game-Bereich", "room_id": null, "room_name": "Großer Saal"}
          },
          "4467": {
            "activity_id": 4467,
            "start_time": "2026-01-24 09:50:00",
            "end_time": "2026-01-24 09:55:00",
            "activity_name": "Match",
            "meta": {
              "name": "Robot-Game Match",
              "first_program_id": 3,
              "first_program_name": "CHALLENGE",
              "description": "In einem Match stehen sich zwei Teams gegen\u00fcber. Ein Match dauert 2:30 Min. Danach vergeben die Schiedsrichter:innen Punkte pro gel\u00f6ster Aufgabe"
            },
            "program": "CHALLENGE",
            "lane": null,
            "team": null,
            "table_1": 1,
            "table_1_name": "Tisch 3",
            "table_1_team": 10,
            "table_2": 2,
            "table_2_name": "Tisch 4",
            "table_2_team": 13,
            "team_name": null,
            "table_1_team_name": "Unbrickables",
            "table_2_team_name": "AFR",
            "room": {"room_type_id": 1, "room_type_name": "Robot-Game-Bereich", "room_id": null, "room_name": "Großer Saal"}
          }
        }
      }, {
        "activity_group_id": 1216,
        "group_meta": {
          "name": "Jurybewertung",
          "first_program_id": 3,
          "first_program_name": "CHALLENGE",
          "description": "Die Teams pr\u00e4sentieren ihre Forschung und ihr Roboterdesign. Anschlie\u00dfend f\u00fcllen die Juror:innen Bewertungsb\u00f6gen aus"
        },
        "activities": {
          "4473": {
            "activity_id": 4473,
            "start_time": "2026-01-24 09:50:00",
            "end_time": "2026-01-24 10:30:00",
            "activity_name": "Mit Team",
            "meta": {
              "name": "Jurygespr\u00e4ch",
              "first_program_id": 3,
              "first_program_name": "CHALLENGE",
              "description": "Die Teams pr\u00e4sentieren ihre Forschung und ihr Roboterdesign. Zu beiden stellen die Juror:innen Fragen"
            },
            "program": "CHALLENGE",
            "lane": 1,
            "team": 21,
            "table_1": null,
            "table_1_name": null,
            "table_1_team": null,
            "table_2": null,
            "table_2_name": null,
            "table_2_team": null,
            "team_name": "RoHoKi",
            "table_1_team_name": null,
            "table_2_team_name": null,
            "room": {"room_type_id": 2, "room_type_name": "Jury 1", "room_id": null, "room_name": "Garching"}
          },
          "4474": {
            "activity_id": 4474,
            "start_time": "2026-01-24 09:50:00",
            "end_time": "2026-01-24 10:30:00",
            "activity_name": "Mit Team",
            "meta": {
              "name": "Jurygespr\u00e4ch",
              "first_program_id": 3,
              "first_program_name": "CHALLENGE",
              "description": "Die Teams pr\u00e4sentieren ihre Forschung und ihr Roboterdesign. Zu beiden stellen die Juror:innen Fragen"
            },
            "program": "CHALLENGE",
            "lane": 2,
            "team": 22,
            "table_1": null,
            "table_1_name": null,
            "table_1_team": null,
            "table_2": null,
            "table_2_name": null,
            "table_2_team": null,
            "team_name": "RoboRo",
            "table_1_team_name": null,
            "table_2_team_name": null,
            "room": {"room_type_id": 3, "room_type_name": "Jury 2", "room_id": null, "room_name": "B.123"}
          },
          "4475": {
            "activity_id": 4475,
            "start_time": "2026-01-24 09:50:00",
            "end_time": "2026-01-24 10:30:00",
            "activity_name": "Mit Team",
            "meta": {
              "name": "Jurygespr\u00e4ch",
              "first_program_id": 3,
              "first_program_name": "CHALLENGE",
              "description": "Die Teams pr\u00e4sentieren ihre Forschung und ihr Roboterdesign. Zu beiden stellen die Juror:innen Fragen"
            },
            "program": "CHALLENGE",
            "lane": 3,
            "team": 23,
            "table_1": null,
            "table_1_name": null,
            "table_1_team": null,
            "table_2": null,
            "table_2_name": null,
            "table_2_team": null,
            "team_name": "PaRaMeRoS",
            "table_1_team_name": null,
            "table_2_team_name": null,
            "room": {"room_type_id": 4, "room_type_name": "Jury 3", "room_id": null, "room_name": "A.101"}
          },
          "4476": {
            "activity_id": 4476,
            "start_time": "2026-01-24 09:50:00",
            "end_time": "2026-01-24 10:30:00",
            "activity_name": "Mit Team",
            "meta": {
              "name": "Jurygespr\u00e4ch",
              "first_program_id": 3,
              "first_program_name": "CHALLENGE",
              "description": "Die Teams pr\u00e4sentieren ihre Forschung und ihr Roboterdesign. Zu beiden stellen die Juror:innen Fragen"
            },
            "program": "CHALLENGE",
            "lane": 4,
            "team": 24,
            "table_1": null,
            "table_1_name": null,
            "table_1_team": null,
            "table_2": null,
            "table_2_name": null,
            "table_2_team": null,
            "team_name": "SOMZ Robotics",
            "table_1_team_name": null,
            "table_2_team_name": null,
            "room": {"room_type_id": 5, "room_type_name": "Jury 4", "room_id": null, "room_name": "Z.102"}
          }
        }
      }
    ]
  };
}

// Normalize to iterable array
function toArray(list: any): any[] {
  if (!list) return [];
  if (Array.isArray(list)) return list;
  return Object.values(list);
}

/** Groups from API with activities normalized to array. */
const groups = computed(() => {
  const result = props.showDemoData ? getDemoData() : props.result;
  const list = result.groups || [];
  return list.map((g: any) => ({
    ...g,
    activities: toArray(g.activities),
  }));
});

function roomLabel(a: any): string {
  const r = a?.room;
  if (r?.room_name) return r.room_name;
  if (a?.room_name) return a.room_name;
  if (r?.room_type_name) return r.room_type_name;
  return '';
}

function teamLabel(name?: string | null): string {
  const nm = (name ?? '').trim();
  if (nm) return nm;
  return '-';
}

function tableName(a: any, side: 1 | 2): string {
  const name = side === 1 ? a?.table_1_name : a?.table_2_name;
  const num = side === 1 ? a?.table_1 : a?.table_2;
  return name ?? (num != null ? `Tisch ${num}` : '');
}

function hasTables(a: any): boolean {
  return a?.table_1 != null || a?.table_2 != null;
}

function hasSingleTeam(a: any): boolean {
  return a?.team != null || (a?.team_name?.trim());
}

function description(a: any, group: any): string {
  return a?.meta?.description ?? group?.group_meta?.description ?? '';
}

watch(
  () => props.result,
  () => nextTick(updateScale),
  { deep: true }
);
</script>

<template>
  <div ref="containerRef" class="plan-audience-container">
    <div
        v-if="groups.length"
        ref="contentRef"
        class="plan-audience-content"
        :style="{ transform: `scale(${scaleFactor})` }"
    >
      <div
          v-for="g in groups"
          :key="g.activity_group_id"
          class="audience-group"
      >
        <div class="audience-group-header">
          <img
              v-if="g.group_meta?.first_program_id === 2 || g.group_meta?.first_program_id === 0"
              :src="programLogoSrc('E')"
              :alt="programLogoAlt('E')"
              class="audience-program-icon"
          />
          <img
              v-if="g.group_meta?.first_program_id === 3 || g.group_meta?.first_program_id === 0"
              :src="programLogoSrc('C')"
              :alt="programLogoAlt('C')"
              class="audience-program-icon"
          />
          <span class="audience-group-title">
            {{ g.group_meta?.name ?? '' }}
          </span>
        </div>

        <div class="audience-activity-list">
          <div
              v-for="a in g.activities"
              :key="a.activity_id"
              class="audience-activity-item"
          >
            <div class="audience-row-main">
              <span class="audience-time">
                {{ formatTimeOnly(a.start_time, true) }}<template
                  v-if="g.activities.length === 1 && !hasTables(a)">–{{ formatTimeOnly(a.end_time, true) }}</template>
              </span>
              <span class="audience-room">{{ roomLabel(a) }}</span>
            </div>
            <!-- RG: Tische mit Teams -->
            <div v-if="hasTables(a)" class="audience-tables-block">
              <table class="audience-tables-align">
                <tbody>
                <tr>
                  <td>{{ tableName(a, 1) }}</td>
                  <td class="text-right">{{ tableName(a, 2) }}</td>
                </tr>
                <tr>
                  <td>{{ teamLabel(a.table_1_team_name) }}</td>
                  <td class="text-right">{{ teamLabel(a.table_2_team_name) }}</td>
                </tr>
                </tbody>
              </table>
            </div>
            <!-- Einzelne Teams z.B bei Jury -->
            <div v-else-if="hasSingleTeam(a)" class="audience-row-extra">
              <span class="audience-team">{{ teamLabel(a?.team_name) }}</span>
            </div>
            <!-- Beschreibung nur bei Einzelaktivitäten, z.B. Eröffnung oder freie Blöcke -->
            <p v-if="g.activities.length === 1 && description(a, g) && !hasTables(a)" class="audience-description">
              {{ description(a, g) }}
            </p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.plan-audience-container {
  width: 100%;
  height: 100%;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0;
  box-sizing: border-box;
}

.plan-audience-content {
  transform-origin: center center;
  display: inline-flex;
  flex-wrap: wrap;
  gap: 1.5rem 2rem;
  align-content: center;
  justify-content: center;
  padding: 1rem;
  box-sizing: border-box;
}

.audience-group {
  background: rgba(255, 255, 255, 0.9);
  border-radius: 0.5rem;
  box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
  overflow: hidden;
  min-width: 260px;
  max-width: 420px;
}

.audience-group-header {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.75rem 1rem;
  background: #f8fafc;
  border-bottom: 2px solid #e2e8f0;
}

.audience-program-icon {
  width: 2rem;
  height: 2rem;
  flex-shrink: 0;
  object-fit: contain;
}

.audience-group-title {
  font-size: clamp(1.25rem, 3.2vw, 1.75rem);
  font-weight: 700;
  color: #1e293b;
  line-height: 1.2;
}

.audience-activity-list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.audience-activity-item {
  padding: 0.5rem 1rem 0.75rem;
  font-size: clamp(0.95rem, 2.2vw, 1.35rem);
  border-bottom: 1px solid #e2e8f0;
}

.audience-activity-item:last-child {
  border-bottom: none;
}

.audience-row-main {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.75rem;
}

.audience-row-extra {
  margin-top: 0.35rem;
}

.audience-tables-block {
  margin-top: 0.35rem;
}

.audience-tables-align {
  border: none;
  border-collapse: collapse;
  width: 100%;
  max-width: 100%;
}

.audience-tables-align td {
  border: none;
  padding: 0.1rem 0.5rem 0 0;
  vertical-align: top;
  color: #334155;
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
}

.audience-tables-align tr:first-child td {
  color: #475569;
  font-weight: 500;
}

.audience-time {
  font-weight: 700;
  color: #0f172a;
  white-space: nowrap;
}

.audience-room {
  color: #475569;
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
}

.audience-team {
  color: #334155;
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
}

.audience-description {
  margin: 0.5rem 0 0;
  font-size: clamp(0.85rem, 2vw, 1rem);
  color: #64748b;
  line-height: 1.4;
  overflow: hidden;
  display: -webkit-box;
  -webkit-box-orient: vertical;
}
</style>
