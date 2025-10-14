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
use App\Enums\ExploreMode;

class PlanGeneratorCore
{
    private ActivityWriter $writer;

    private ChallengeGenerator $challenge;
    private ExploreGenerator $explore;

    // Shared state for integrated Explore mode
    private int $integratedExploreDuration = 0;
    private ?string $integratedExploreStart = null;

    use UsesPlanParameter;

    public function __construct(int $planId)
    {
        $this->writer = new ActivityWriter($planId);
        $this->params = PlanParameter::load($planId);
    }

    public function generate(): void
    {
        Log::info("PlanGeneratorCore: Start generation for plan {$this->pp('g_plan')}");
        
        try {
                $this->generateByMode();
            } catch (\Throwable $e) {
                Log::error("Plan generation failed: {$e->getMessage()}", ['plan_id' => $this->pp('g_plan')]);
                throw $e;
            }
        

        // -----------------------------------------------------------------------------------
        // Add all free blocks.
        // Timing does not matter, because these are parallel to other activities.
        // -----------------------------------------------------------------------------------

        (new FreeBlockGenerator($this->writer, $this->params))->insertFreeActivities();

        Log::info("PlanGeneratorCore: Finished generation for plan {$this->pp('g_plan')}");
    }

    private function generateByMode(): void
    {
        $cMode = $this->pp('c_mode');
        $eMode = $this->pp('e_mode');

        Log::debug("PlanGeneratorCore: generateByMode", ['cMode' => $cMode, 'eMode' => $eMode]);


        if ($cMode == 1) {
            // Challenge present - instantiate ChallengeGenerator
        $this->challenge = new ChallengeGenerator(
            $this->writer,
                $this->params,
                $this->integratedExploreDuration,
                $this->integratedExploreStart
            );
            
            if ($eMode == ExploreMode::NONE->value) {
                // Challenge only
                $this->challenge->openingsAndBriefings();        // check
                $this->challenge->main();
                $this->challenge->robotGameFinals();            // check
                $this->challenge->awards();                     // check
                
            } elseif ($eMode == ExploreMode::INTEGRATED_MORNING->value) {
                // Challenge + Explore integrated morning
        $this->explore = new ExploreGenerator(
            $this->writer,
            $this->params,
            $this->integratedExploreDuration,
            $this->integratedExploreStart
        );
                
                $this->challenge->openingsAndBriefings(true);        // check
                $this->explore->openingsAndBriefings(1, true);       // check
                $this->explore->judgingAndDeliberations(1);          // check
                $this->challenge->main(true);
                $this->explore->integratedAwards(1);                 // Picks up timing from params
                $this->challenge->robotGameFinals();            // check
                $this->challenge->awards();                     // check
                
            } elseif ($eMode == ExploreMode::INTEGRATED_AFTERNOON->value) {
                // Challenge + Explore integrated afternoon
                $this->explore = new ExploreGenerator(
                    $this->writer,
                    $this->params,
                    $this->integratedExploreDuration,
                    $this->integratedExploreStart
                );
                
                $this->challenge->openingsAndBriefings();        // check
                $this->challenge->main(true);
                $this->explore->openingsAndBriefings(2, true);       // check
                $this->explore->judgingAndDeliberations(2);          // check
                $this->explore->integratedAwards(2);                 // Picks up timing from shared state
                $this->challenge->robotGameFinals();            // check
                $this->challenge->awards(true);                     // check
                
            } elseif (in_array($eMode, [
                ExploreMode::DECOUPLED_MORNING->value, 
                ExploreMode::DECOUPLED_AFTERNOON->value, 
                ExploreMode::DECOUPLED_BOTH->value
            ])) {
                // Challenge + Explore decoupled
                
                $this->explore = new ExploreGenerator(
                    $this->writer,
                    $this->params,
                    $this->integratedExploreDuration,
                    $this->integratedExploreStart
                );

                $this->challenge->openingsAndBriefings();        // check
                $this->challenge->main();
                $this->challenge->robotGameFinals();            // check
                $this->challenge->awards();                     // check


                if ($eMode == ExploreMode::DECOUPLED_MORNING->value) {
                    // Explore decoupled morning
                    $this->explore->openingsAndBriefings(1);        // check
                    $this->explore->judgingAndDeliberations(1);        // check
                    $this->explore->awards(1);                         // check
                } elseif ($eMode == ExploreMode::DECOUPLED_AFTERNOON->value) {
                    // Explore decoupled afternoon
                    $this->explore->openingsAndBriefings(2);             // check
                    $this->explore->judgingAndDeliberations(2);        // check
                    $this->explore->awards(2);                         // check
                } elseif ($eMode == ExploreMode::DECOUPLED_BOTH->value) {
                    // Explore decoupled both  
                    $this->explore->openingsAndBriefings(1);        // check
                    $this->explore->judgingAndDeliberations(1);        // check
                    $this->explore->awards(1);                         // check
                
                    $this->explore->openingsAndBriefings(2);        // check
                    $this->explore->judgingAndDeliberations(2);        // check
                    $this->explore->awards(2);                         // check
                }

            }

        } else {
            // No Challenge - Explore only
            if ($eMode == ExploreMode::DECOUPLED_MORNING->value) {
                // Explore morning only
                $this->explore = new ExploreGenerator(
                    $this->writer,
                    $this->params,
                    $this->integratedExploreDuration,
                    $this->integratedExploreStart
                );
                $this->explore->openingsAndBriefings(1);        // check
                $this->explore->judgingAndDeliberations(1);        // check
                $this->explore->awards(1);                         // check
                
            } elseif ($eMode == ExploreMode::DECOUPLED_AFTERNOON->value) {
                // Explore afternoon only
                $this->explore = new ExploreGenerator(
                    $this->writer,
                    $this->params,
                    $this->integratedExploreDuration,
                    $this->integratedExploreStart
                );
                $this->explore->openingsAndBriefings(2);        // check
                $this->explore->judgingAndDeliberations(2);        // check
                $this->explore->awards(2);                         // check

            } elseif ($eMode == ExploreMode::DECOUPLED_BOTH->value) {
                // Explore both morning and afternoon
                $this->explore = new ExploreGenerator(
                    $this->writer,
                    $this->params,
                    $this->integratedExploreDuration,
                    $this->integratedExploreStart
                );
                $this->explore->openingsAndBriefings(1);       // check     
                $this->explore->judgingAndDeliberations(1);        // check
                $this->explore->awards(1);                         // check
                
                $this->explore->openingsAndBriefings(2);        // check
                $this->explore->judgingAndDeliberations(2);        // check
                $this->explore->awards(2);                         // check
            }
        }
    }

    





}