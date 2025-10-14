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

    public function __construct(int $planId)
    {
        $this->writer = new ActivityWriter($planId);
        $this->params = PlanParameter::load($planId);
        $this->integratedExplore = new IntegratedExploreState();
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
                $this->explore->openingsAndBriefings(1, true);
                $this->explore->judgingAndDeliberations(1);
                $this->challenge->main(true);
                $this->explore->integratedActivity($eMode);
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
                $this->explore->openingsAndBriefings(2, true);
                $this->explore->judgingAndDeliberations(2);
                $this->explore->integratedActivity($eMode);
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


                if ($eMode == ExploreMode::DECOUPLED_MORNING->value) {
                    // Explore decoupled morning
                    $this->explore->openingsAndBriefings(1);
                    $this->explore->judgingAndDeliberations(1);
                    $this->explore->awards(1);
                } elseif ($eMode == ExploreMode::DECOUPLED_AFTERNOON->value) {
                    // Explore decoupled afternoon
                    $this->explore->openingsAndBriefings(2);
                    $this->explore->judgingAndDeliberations(2);
                    $this->explore->awards(2);
                } elseif ($eMode == ExploreMode::DECOUPLED_BOTH->value) {
                    // Explore decoupled both  
                    $this->explore->openingsAndBriefings(1);
                    $this->explore->judgingAndDeliberations(1);
                    $this->explore->awards(1);
                
                    $this->explore->openingsAndBriefings(2);
                    $this->explore->judgingAndDeliberations(2);
                    $this->explore->awards(2);
                }

            }

        } else {
            // No Challenge - Explore only
            if ($eMode == ExploreMode::DECOUPLED_MORNING->value) {
                // Explore morning only
                $this->explore = new ExploreGenerator(
                    $this->writer,
                    $this->params,
                    $this->integratedExplore
                );
                $this->explore->openingsAndBriefings(1);
                $this->explore->judgingAndDeliberations(1);
                $this->explore->awards(1);
                
            } elseif ($eMode == ExploreMode::DECOUPLED_AFTERNOON->value) {
                // Explore afternoon only
                $this->explore = new ExploreGenerator(
                    $this->writer,
                    $this->params,
                    $this->integratedExplore
                );
                $this->explore->openingsAndBriefings(2);
                $this->explore->judgingAndDeliberations(2);
                $this->explore->awards(2);

            } elseif ($eMode == ExploreMode::DECOUPLED_BOTH->value) {
                // Explore both morning and afternoon
                $this->explore = new ExploreGenerator(
                    $this->writer,
                    $this->params,
                    $this->integratedExplore
                );
                $this->explore->openingsAndBriefings(1);
                $this->explore->judgingAndDeliberations(1);
                $this->explore->awards(1);
                
                $this->explore->openingsAndBriefings(2);
                $this->explore->judgingAndDeliberations(2);
                $this->explore->awards(2);
            }
        }
    }

    





}