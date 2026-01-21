<?php

namespace App\Core;

use DateTime;
use Illuminate\Support\Facades\Log;
use App\Support\PlanParameter;
use App\Support\UsesPlanParameter;
use App\Support\IntegratedExploreState;
use App\Core\TimeCursor;
use App\Enums\ExploreMode;


class ExploreGenerator
{
    private ActivityWriter $writer;
    private TimeCursor $eTime;
    private int $eMode;

    // Shared state for integrated Explore mode
    private IntegratedExploreState $integratedExplore;

    use UsesPlanParameter;


    public function setMode(int $eMode): void
    {
        $this->eMode = $eMode;
    }

    public function __construct(
        ActivityWriter $writer, 
        PlanParameter $params,
        IntegratedExploreState $integratedExplore
    ) {
        $this->writer = $writer;
        $this->params = $params;
        $this->integratedExplore = $integratedExplore;
        
        // Create time cursors from base date
        $baseDate = $params->get('g_date');
        $this->eTime = new TimeCursor(clone $baseDate);
        
        // Initialize eMode
        $this->eMode = (int) $params->get('e_mode');

        // Derived parameters formerly computed in Core::initialize for Explore
        $e1Teams = (int) ($params->get('e1_teams') ?? 0);
        if ($e1Teams > 0) {
            $e1Lanes = (int) ($params->get('e1_lanes') ?? 1);
            $e1Rounds = (int) ceil($e1Teams / max(1, $e1Lanes));
            $params->add('e1_rounds', $e1Rounds, 'integer');
        }

        $e2Teams = (int) ($params->get('e2_teams') ?? 0);
        if ($e2Teams > 0) {
            $e2Lanes = (int) ($params->get('e2_lanes') ?? 1);
            $e2Rounds = (int) ceil($e2Teams / max(1, $e2Lanes));
            $params->add('e2_rounds', $e2Rounds, 'integer');
        }

        // Calculate integrated Explore duration for Challenge to use
        if ($this->eMode == ExploreMode::INTEGRATED_MORNING->value) {
            // For morning: Explore awards are inserted after RG round 1 (lunch break)
            $this->integratedExplore->duration = 
                $params->get('e_ready_awards') + 
                $params->get('e1_duration_awards') +
                $params->get('e_ready_awards');           // back to challenge
        } elseif ($this->eMode == ExploreMode::INTEGRATED_AFTERNOON->value) {
            // For afternoon: Explore opening is inserted after RG round 1 (lunch break)
            $this->integratedExplore->duration = 
                $params->get('e_ready_opening') + 
                $params->get('e2_duration_opening') + 
                $params->get('e_ready_action');
        };


    }

    public function openingsAndBriefings(int $group): void
    {
        Log::info('ExploreGenerator::openingsAndBriefings', [
            'plan_id' => $this->pp('g_plan'),
            'e_mode' => $this->eMode,
            'e1_teams' => $this->pp('e1_teams'),
            'e1_lanes' => $this->pp('e1_lanes'),
            'e2_teams' => $this->pp('e2_teams'),
            'e2_lanes' => $this->pp('e2_lanes'),
        ]);

        try {
            if ($group == 1 && 
            ($this->eMode == ExploreMode::INTEGRATED_MORNING->value || $this->eMode == ExploreMode::HYBRID_BOTH->value)  ) {

                // ChallengeGenerator has created the opening activity. We only need to set the time cursor
                $this->eTime->setTime($this->pp("g_start_opening"));
                $startOpening = clone $this->eTime;
                $this->eTime->addMinutes($this->pp('g_duration_opening'));

            } else {
                
                if($group == 2 && ($this->eMode == ExploreMode::INTEGRATED_AFTERNOON->value || $this->eMode == ExploreMode::HYBRID_BOTH->value)) {

                    // Time cursor already set before calling this method

                } else {
                
                    // Set the time cursor respectively
                    $this->eTime->setTime($this->pp("e{$group}_start_opening"));

                }
                
                $startOpening = clone $this->eTime;

                $this->writer->withGroup('e_opening', function () use ($group) {
                    $this->writer->insertActivity('e_opening', $this->eTime, $this->pp("e{$group}_duration_opening"));
                }, $group);

                $this->eTime->addMinutes($this->pp("e{$group}_duration_opening"));

            }

            $this->briefings($startOpening->current(), $group);

        } catch (\Throwable $e) {
            Log::error('ExploreGenerator: Error in openings and briefings', [
                'group' => $group,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \RuntimeException("Fehler beim Generieren der Explore-Eröffnung und Briefings (Gruppe {$group}): {$e->getMessage()}", 0, $e);
        }
    }

    public function briefings(\DateTime $t, int $group): void
    {
        try {
            $this->writer->withGroup('e_briefing_coach', function () use ($t, $group) {
                $cursor = new TimeCursor($t);
                $cursor->subMinutes($this->pp("e{$group}_duration_briefing_t") + $this->pp("e_ready_opening"));
                $this->writer->insertActivity('e_briefing_coach', $cursor, $this->pp("e{$group}_duration_briefing_t"));
            }, $group);

            $this->writer->withGroup('e_briefing_judge', function () use ($t, $group) {
                if (!$this->pp("e_briefing_after_opening_j")) {
                    $cursor = new TimeCursor($t);
                    $cursor->subMinutes($this->pp("e{$group}_duration_briefing_j") + $this->pp("e_ready_opening"));
                    $this->writer->insertActivity('e_briefing_judge', $cursor, $this->pp("e{$group}_duration_briefing_j"));
                } else {
                    $cursor = $this->eTime->copy();
                    $cursor->addMinutes($this->pp("e_ready_briefing"));
                    $this->writer->insertActivity('e_briefing_judge', $cursor, $this->pp("e{$group}_duration_briefing_j"));
                    $this->eTime->addMinutes($this->pp("e_ready_briefing") + $this->pp("e{$group}_duration_briefing_j"));
                }
            }, $group);

            $this->eTime->addMinutes($this->pp("e_ready_action"));

        } catch (\Throwable $e) {
            Log::error('ExploreGenerator: Error in briefings', [
                'group' => $group,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \RuntimeException("Fehler beim Generieren der Explore-Briefings (Gruppe {$group}): {$e->getMessage()}", 0, $e);
        }
    }


    public function judgingAndDeliberations(int $group): void
    {

        try {
            // Capture start time of judging (beginning of exhibition)
            $exhibitionStart = clone $this->eTime;
            
            $lanes = $this->pp("e{$group}_lanes");
            $rounds = $this->pp("e{$group}_rounds");
            $teams = $this->pp("e{$group}_teams");

            $teamOffset = ($group === 1) ? 0 : $this->pp("e1_teams");
            $laneOffset = ($group === 1) ? 0 : $this->pp("e1_lanes");

            $this->writer->withGroup('e_judging_package', function () use ($rounds, $lanes, $teams, $teamOffset, $laneOffset) {
                for ($round = 1; $round <= $rounds; $round++) {
                    // WITH team
                    for ($lane = 1; $lane <= $lanes; $lane++) {
                        $team = ceil($teams / $lanes) * ($lane - 1) + $round;
                        if ($team <= $teams) {
                            $this->writer->insertActivity('e_with_team', $this->eTime, $this->pp("e_duration_with_team"), $lane + $laneOffset, $team + $teamOffset);
                        }
                    }
                    $this->eTime->addMinutes($this->pp("e_duration_with_team"));

                    // Scoring
                    for ($lane = 1; $lane <= $lanes; $lane++) {
                        $team = ($lane - 1) * $rounds + $round;
                        if ($team <= $teams) {
                            $this->writer->insertActivity('e_scoring', $this->eTime, $this->pp("e_duration_scoring"), $lane + $laneOffset, $team + $teamOffset);
                        }
                    }
                    $this->eTime->addMinutes($this->pp("e_duration_scoring"));

                    if ($round < $rounds) {
                        $this->eTime->addMinutes($this->pp("e_duration_break"));
                    }
                }
            }, $group);

            // Buffer before all judges meet for deliberations
            $this->eTime->addMinutes($this->pp('e_ready_deliberations'));

            // Deliberations
            $this->writer->withGroup('e_deliberations', function () use ($group) {
                $this->writer->insertActivity('e_deliberations', $this->eTime, $this->pp("e{$group}_duration_deliberations"));
            }, $group);

            $this->eTime->addMinutes($this->pp("e{$group}_duration_deliberations"));
            
            // For INTEGRATED_MORNING mode, store deliberation end time (after e_ready_awards buffer)
            // This is when Explore awards can start (after buffer period)
            if ($group == 1 && $this->eMode == ExploreMode::INTEGRATED_MORNING->value) {
                $this->eTime->addMinutes($this->pp('e_ready_awards'));
                $this->integratedExplore->deliberationEndTime = $this->eTime->format('H:i');
                $this->eTime->subMinutes($this->pp('e_ready_awards')); // Restore for exhibition calculation
            }
            
            // For INTEGRATED_AFTERNOON mode, store Explore end time after deliberations + e_ready_awards buffer
            // This is when Explore activities are complete and ready for awards (for joint awards synchronization)
            if ($group == 2 && $this->eMode == ExploreMode::INTEGRATED_AFTERNOON->value) {
                $this->eTime->addMinutes($this->pp('e_ready_awards'));
                $this->integratedExplore->exploreEndTime = $this->eTime->format('H:i');
                $this->eTime->subMinutes($this->pp('e_ready_awards')); // Restore for exhibition calculation
            }
            
            // Capture end time of deliberations (end of exhibition)
            $exhibitionEnd = clone $this->eTime;
            
            // Create exhibition activity group spanning from start of judging to end of deliberations
            $this->writer->withGroup('e_exhibition', function () use ($exhibitionStart, $exhibitionEnd, $group) {
                $duration = $exhibitionEnd->diffInMinutes($exhibitionStart);
                $this->writer->insertActivity('e_exhibition', $exhibitionStart, $duration);
            }, $group);
            
            Log::info('ExploreGenerator: Exhibition activity created', [
                'group' => $group,
                'start_time' => $exhibitionStart->format('H:i'),
                'end_time' => $exhibitionEnd->format('H:i'),
                'duration_minutes' => $exhibitionEnd->diffInMinutes($exhibitionStart)
            ]);

        } catch (\Throwable $e) {
            Log::error('ExploreGenerator: Error in judging and deliberations', [
                'group' => $group,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \RuntimeException("Fehler beim Generieren der Explore-Bewertung und Beratungen (Gruppe {$group}): {$e->getMessage()}", 0, $e);
        }
    }

    public function awards(int $group, bool $challenge = false): void   
    {
        try {

            $this->eTime->addMinutes($this->pp("e_ready_awards"));
            $this->writer->withGroup('e_awards', function () use ($group) {
                $this->writer->insertActivity('e_awards', $this->eTime, $this->pp("e{$group}_duration_awards"));
            }, $group);
            $this->eTime->addMinutes($this->pp("e{$group}_duration_awards"));        

        } catch (\Throwable $e) {
            Log::error('ExploreGenerator: Error in awards', [
                'eMode' => $this->eMode,
                'group' => $group,
                'challenge' => $challenge,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \RuntimeException("Fehler beim Generieren der Explore-Preisverleihung (Gruppe {$group}, eMode: {$this->eMode}, Challenge: " . ($challenge ? 'ja' : 'nein') . "): {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Handle integrated Explore activity inserted during Challenge robot game
     * For INTEGRATED_MORNING: inserts awards
     * For INTEGRATED_AFTERNOON: inserts opening
     * 
     * @param int $group Explore group (1 or 2)
     * @param TimeCursor|null $rTime Robot game time cursor (for INTEGRATED_MORNING to return awards end time)
     * @return string|null Awards end time (H:i format) for INTEGRATED_MORNING group 1, null otherwise
     */
    public function integratedActivity(int $group, ?TimeCursor $rTime = null): ?string
    {
        // Check if start time was written by ChallengeGenerator
        if ($this->integratedExplore->startTime === null) {
            // Log::debug("No integratedExploreStart set, skipping integrated activity");
            return null;
        }

        try {
            // Set cursor to start time provided by ChallengeGenerator
            $this->eTime->setTime($this->integratedExplore->startTime);

            if ($group == 1) {
                // Insert awards
                $this->awards($group);
                // Log::info("ExploreGenerator: Integrated awards inserted at {$this->integratedExplore->startTime}");
                
                // Return awards end time for INTEGRATED_MORNING mode
                if ($this->eMode == ExploreMode::INTEGRATED_MORNING->value) {
                    return $this->eTime->format('H:i');
                }
                
            } elseif ($group == 2) {
                // Insert opening

                if($this->eMode == ExploreMode::INTEGRATED_AFTERNOON->value) {
                
                    // time handed over is end of last robot game match. Need to add buffer to start of opening
                    $this->eTime->addMinutes($this->pp("e_ready_opening"));
                } 
               
                $this->openingsAndBriefings($group);                
                // Log::info("ExploreGenerator: Integrated opening inserted at {$this->integratedExplore->startTime}");
            
            }

            return null;

        } catch (\Throwable $e) {
            Log::error('ExploreGenerator: Error in integrated activity', [
                'eMode' => $this->eMode,
                'startTime' => $this->integratedExplore->startTime ?? 'null',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \RuntimeException("Fehler beim Generieren der integrierten Explore-Aktivität (eMode: {$this->eMode}, Startzeit: " . ($this->integratedExplore->startTime ?? 'nicht gesetzt') . "): {$e->getMessage()}", 0, $e);
        }
    }

}
