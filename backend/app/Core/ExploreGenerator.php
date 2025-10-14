<?php

namespace App\Core;

use DateTime;
use Illuminate\Support\Facades\Log;
use App\Support\PlanParameter;
use App\Support\UsesPlanParameter;
use App\Support\IntegratedExploreState;
use App\Enums\ExploreMode;


class ExploreGenerator
{
    private ActivityWriter $writer;
    private TimeCursor $eTime;
    private int $eMode;

    // Shared state for integrated Explore mode
    private IntegratedExploreState $integratedExplore;

    use UsesPlanParameter;

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
        $this->eMode = (int) $params->get('e_mode');
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
        }
    }

    public function openingsAndBriefings(): void
    {
        Log::info('ExploreGenerator: Starting openings and briefings', ['eMode' => $this->eMode]);

        try {
            if ($this->eMode == ExploreMode::INTEGRATED_MORNING->value) {

                $group = 1;
                $startOpening = clone $this->eTime;
                $this->eTime->addMinutes($this->pp('g_duration_opening'));

            } else {

                if ($this->eMode == ExploreMode::DECOUPLED_MORNING->value) {
                    $group = 1;
                } else if($this->eMode == ExploreMode::INTEGRATED_AFTERNOON->value || 
                          $this->eMode == ExploreMode::DECOUPLED_AFTERNOON->value) {
                    $group = 2;
                } else {
                    throw new \RuntimeException("Invalid Explore mode: {$this->eMode}");
                }
                
                $this->eTime->setTime($this->pp("e{$group}_start_opening"));
                $startOpening = clone $this->eTime;

                $this->writer->withGroup('e_opening', function () use ($group) {
                    $this->writer->insertActivity('e_opening', $this->eTime, $this->pp("e{$group}_duration_opening"));
                });

                $this->eTime->addMinutes($this->pp("e{$group}_duration_opening"));

                if($group == 1) {
                    Log::info('Explore stand-alone morning', [
                        'teams' => $this->pp('e1_teams'),
                        'lanes' => $this->pp('e1_lanes'),
                        'rounds' => $this->pp('e1_rounds')
                    ]);
                } else {
                    Log::info('Explore stand-alone afternoon', [
                        'teams' => $this->pp('e2_teams'),
                        'lanes' => $this->pp('e2_lanes'),
                        'rounds' => $this->pp('e2_rounds')
                    ]);
                }

            }

            $this->briefings($startOpening->current(), $group);

        } catch (\Throwable $e) {
            Log::error('ExploreGenerator: Error in openings and briefings', [
                'group' => isset($group) ? $group : null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \RuntimeException("Failed to generate Explore openings and briefings: {$e->getMessage()}", 0, $e);
        }
    }

    public function briefings(\DateTime $t, int $group): void
    {
        
        $this->writer->withGroup('e_briefing_coach', function () use ($t, $group) {
            $cursor = new TimeCursor($t);
            $cursor->subMinutes($this->pp("e{$group}_duration_briefing_t") + $this->pp("e_ready_opening"));
            $this->writer->insertActivity('e_briefing_coach', $cursor, $this->pp("e{$group}_duration_briefing_t"));
        });

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
        });

        $this->eTime->addMinutes($this->pp("e_ready_action"));
    }


    public function judgingAndDeliberations(): void
    {
        // Derive group from eMode
        $group = match($this->eMode) {
            ExploreMode::INTEGRATED_MORNING->value, ExploreMode::DECOUPLED_MORNING->value => 1,
            ExploreMode::INTEGRATED_AFTERNOON->value, ExploreMode::DECOUPLED_AFTERNOON->value => 2,
            ExploreMode::DECOUPLED_BOTH->value => throw new \RuntimeException("judgingAndDeliberations() cannot handle DECOUPLED_BOTH mode - must be called separately for each group"),
            default => throw new \RuntimeException("Invalid Explore mode: {$this->eMode}"),
        };
        
        Log::info('ExploreGenerator: Starting judging and deliberations', ['eMode' => $this->eMode, 'group' => $group]);

        try {
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
        });

        // Buffer before all judges meet for deliberations
        $this->eTime->addMinutes($this->pp('e_ready_deliberations'));

        // Deliberations
        $this->writer->withGroup('e_deliberations', function () use ($group) {
            $this->writer->insertActivity('e_deliberations', $this->eTime, $this->pp("e{$group}_duration_deliberations"));
        });

        $this->eTime->addMinutes($this->pp("e{$group}_duration_deliberations"));

        } catch (\Throwable $e) {
            Log::error('ExploreGenerator: Error in judging and deliberations', [
                'group' => $group,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \RuntimeException("Failed to generate Explore judging and deliberations: {$e->getMessage()}", 0, $e);
        }
    }

    public function awards(bool $challenge = false): void   
    {
        // Derive group from eMode
        $group = match($this->eMode) {
            ExploreMode::INTEGRATED_MORNING->value, ExploreMode::DECOUPLED_MORNING->value => 1,
            ExploreMode::INTEGRATED_AFTERNOON->value, ExploreMode::DECOUPLED_AFTERNOON->value => 2,
            ExploreMode::DECOUPLED_BOTH->value => throw new \RuntimeException("awards() cannot handle DECOUPLED_BOTH mode - must be called separately for each group"),
            default => throw new \RuntimeException("Invalid Explore mode: {$this->eMode}"),
        };
        
        Log::info('ExploreGenerator: Starting awards', ['eMode' => $this->eMode, 'group' => $group, 'challenge' => $challenge]);
        
        try {
            if (!$challenge) {

            $this->eTime->addMinutes($this->pp("e_ready_awards"));
            $this->writer->withGroup('e_awards', function () use ($group) {
                $this->writer->insertActivity('e_awards', $this->eTime, $this->pp("e{$group}_duration_awards"));
            });
            $this->eTime->addMinutes($this->pp("e{$group}_duration_awards"));
        }

        } catch (\Throwable $e) {
            Log::error('ExploreGenerator: Error in awards', [
                'eMode' => $this->eMode,
                'group' => $group,
                'challenge' => $challenge,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \RuntimeException("Failed to generate Explore awards: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Handle integrated Explore activity inserted during Challenge robot game
     * For INTEGRATED_MORNING: inserts awards
     * For INTEGRATED_AFTERNOON: inserts opening
     */
    public function integratedActivity(): void
    {
        // Check if start time was written by ChallengeGenerator
        if ($this->integratedExplore->startTime === null) {
            Log::debug("No integratedExploreStart set, skipping integrated activity");
            return;
        }

        try {
            // Parse start time (HH:MM format)
            [$hours, $minutes] = explode(':', $this->integratedExplore->startTime);
            $this->eTime->current()->setTime((int)$hours, (int)$minutes);

            if ($this->eMode == ExploreMode::INTEGRATED_MORNING->value) {
                // INTEGRATED_MORNING: Insert awards
                $this->eTime->addMinutes($this->pp('e_ready_awards'));
                
                $this->writer->withGroup('e_awards', function () {
                    $this->writer->insertActivity('e_awards', $this->eTime, $this->pp('e1_duration_awards'));
                });
                $this->eTime->addMinutes($this->pp('e1_duration_awards'));
                $this->eTime->addMinutes($this->pp('e_ready_awards'));
                
                Log::info("ExploreGenerator: Integrated awards inserted at {$this->integratedExplore->startTime}");
                
            } elseif ($this->eMode == ExploreMode::INTEGRATED_AFTERNOON->value) {
                // INTEGRATED_AFTERNOON: Insert opening
                $this->eTime->addMinutes($this->pp('e_ready_opening'));

                $startOpening = clone $this->eTime;
                
                $this->writer->withGroup('e_opening', function () {
                    $this->writer->insertActivity('e_opening', $this->eTime, $this->pp('e2_duration_opening'));
                });
                $this->eTime->addMinutes($this->pp('e2_duration_opening'));
                
                Log::info("ExploreGenerator: Integrated opening inserted at {$this->integratedExplore->startTime}");

                // Derive group from eMode
                $group = match($this->eMode) {
                    ExploreMode::INTEGRATED_MORNING->value => 1,
                    ExploreMode::INTEGRATED_AFTERNOON->value => 2,
                    default => throw new \RuntimeException("integratedActivity() only valid for INTEGRATED modes"),
                };
                
                $this->briefings($startOpening->current(), $group);
            
            }

        } catch (\Throwable $e) {
            Log::error('ExploreGenerator: Error in integrated activity', [
                'eMode' => $this->eMode,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \RuntimeException("Failed to generate integrated Explore activity: {$e->getMessage()}", 0, $e);
        }
    }

}
