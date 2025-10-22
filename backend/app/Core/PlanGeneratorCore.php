<?php

namespace App\Core;

use DateTime;
use Illuminate\Support\Facades\Log;

use App\Core\ActivityWriter;
use App\Core\ChallengeGenerator;
use App\Core\ExploreGenerator;
use App\Core\FreeBlockGenerator;
use App\Core\FinaleGenerator;

use App\Support\PlanParameter;
use App\Support\UsesPlanParameter;
use App\Support\IntegratedExploreState;
use App\Enums\ExploreMode;

class PlanGeneratorCore
{
    private ActivityWriter $writer;

    private ChallengeGenerator $challenge;
    private ExploreGenerator $explore;

    // Shared state for integrated Explore mode
    private IntegratedExploreState $integratedExplore;

    use UsesPlanParameter;

    public function __construct(int $planId, PlanParameter $params)
    {
        $this->writer = new ActivityWriter($planId);
        $this->params = $params;
        $this->integratedExplore = new IntegratedExploreState();
    }

    public static function generate(int $planId): void
    {
        Log::info("PlanGeneratorCore: Start generation for plan {$planId}");
        
        $params = PlanParameter::load($planId);
        $instance = new self($planId, $params);
        
        try {
                $instance->generateByMode();
            } catch (\Throwable $e) {
                Log::error("Plan generation failed: {$e->getMessage()}", ['plan_id' => $planId]);
                throw $e;
            }
        

        // -----------------------------------------------------------------------------------
        // Add all free blocks.
        // Timing does not matter, because these are parallel to other activities.
        // -----------------------------------------------------------------------------------

        (new FreeBlockGenerator($instance->writer, $instance->params))->insertFreeActivities();

        Log::info("PlanGeneratorCore: Finished generation for plan {$planId}");
    }

    private function generateByMode(): void
    {
        // Check for finale event (level 3) - special 2-day generation path
        if ($this->pp('g_finale')) {
            // Finale event - delegate to FinaleGenerator for complete 2-day generation
            $finale = new FinaleGenerator($this->writer, $this->params);
            $finale->generate();
            return;
        }

        // Normal events - use standard one-day generation
        $this->generateOneDayEvent();
    }

    /**
     * Generate a standard one-day event
     * This method can be called by both normal events and Finale Day 2
     */
    public function generateOneDayEvent(): void
    {
        $cMode = $this->pp('c_mode');
        $eMode = $this->pp('e_mode');

        // Log::debug("PlanGeneratorCore: generateOneDayEvent", ['cMode' => $cMode, 'eMode' => $eMode]);

        if ($cMode == 1) {
            // Challenge present - instantiate ChallengeGenerator
        $this->challenge = new ChallengeGenerator(
            $this->writer,
                $this->params,
                $this->integratedExplore
            );
            
            if ($eMode == ExploreMode::NONE->value) {
                // Challenge only
                $this->challenge->openingsAndBriefings();
                $this->challenge->main();
                $this->challenge->robotGameFinals();
                $this->challenge->awards();
                
            } elseif ($eMode == ExploreMode::INTEGRATED_MORNING->value) {
                // Challenge + Explore integrated morning
                $this->explore = new ExploreGenerator(
                    $this->writer,
                    $this->params,
                    $this->integratedExplore
                );
                        
                $this->challenge->openingsAndBriefings(true);
                $this->explore->openingsAndBriefings();
                $this->explore->judgingAndDeliberations();
                $this->challenge->main(true);
                $this->explore->integratedActivity();
                $this->challenge->robotGameFinals();
                $this->challenge->awards();
                
            } elseif ($eMode == ExploreMode::INTEGRATED_AFTERNOON->value) {
                // Challenge + Explore integrated afternoon
                $this->explore = new ExploreGenerator(
                    $this->writer,
                    $this->params,
                    $this->integratedExplore
                );
                
                $this->challenge->openingsAndBriefings();
                $this->challenge->main(true);
                $this->explore->integratedActivity();
                $this->explore->judgingAndDeliberations();                
                $this->challenge->robotGameFinals();
                $this->challenge->awards(true);
                
            } elseif (in_array($eMode, [
                ExploreMode::DECOUPLED_MORNING->value, 
                ExploreMode::DECOUPLED_AFTERNOON->value, 
                ExploreMode::DECOUPLED_BOTH->value
            ])) {
                // Challenge + Explore decoupled
                
                $this->explore = new ExploreGenerator(
                    $this->writer,
                    $this->params,
                    $this->integratedExplore
                );

                $this->challenge->openingsAndBriefings();
                $this->challenge->main();
                $this->challenge->robotGameFinals();
                $this->challenge->awards();

                // Explore runs in parallel/separate time slots
                // DECOUPLED_BOTH initially runs as MORNING (group 1)
                $this->explore->openingsAndBriefings();
                $this->explore->judgingAndDeliberations();
                $this->explore->awards();
                
                // For DECOUPLED_BOTH, run the same sequence again for the second session (afternoon)
                if ($eMode == ExploreMode::DECOUPLED_BOTH->value) {
                    $this->explore->setMode(ExploreMode::DECOUPLED_AFTERNOON->value);
                    
                    $this->explore->openingsAndBriefings();
                    $this->explore->judgingAndDeliberations();
                    $this->explore->awards();
                }

            }

        } else {
            // No Challenge - check if Explore is enabled
            if ($eMode == ExploreMode::NONE->value) {
                // Both programs disabled - nothing to generate
                Log::warning("PlanGeneratorCore: Both programs disabled (e_mode=0, c_mode=0) - generating empty plan");
                return;
            }
            
            // Explore only
            $this->explore = new ExploreGenerator(
                $this->writer,
                $this->params,
                $this->integratedExplore
            );
            
            // include morning part of DECOUPLED_BOTH
            $this->explore->openingsAndBriefings();
            $this->explore->judgingAndDeliberations();
            $this->explore->awards();
            
            // For DECOUPLED_BOTH, run the same sequence again for the second session (afternoon)
            if ($eMode == ExploreMode::DECOUPLED_BOTH->value) {
                $this->explore->setMode(ExploreMode::DECOUPLED_AFTERNOON->value);
              
                $this->explore->openingsAndBriefings();
                $this->explore->judgingAndDeliberations();
                $this->explore->awards();
            }
        }
    }
}
