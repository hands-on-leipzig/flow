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

class PlanGeneratorCore
{
    private ActivityWriter $writer;

    private ChallengeGenerator $challenge;
    private ExploreGenerator $explore;

    use UsesPlanParameter;

    public function __construct(int $planId)
    {
        $this->writer = new ActivityWriter($planId);
        $this->params = PlanParameter::load($planId);
    }

    // ***********************************************************************************
    // Parameter naming convention
    // ***********************************************************************************
    // "snake_case" is used
    // g_ global / generic
    // c_ FLL Challenge
    // j_ FLL Challenge judging
    // r_ FLL Challenge robot game
    // e_ FLL Explore
    // e1_ FLL Explore first group (morning)
    // e2_ FLL Explore second group (afternoon)
    // f_ Finale
    // lc_ Live Challenge

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

        


        if ($cMode == 1) {
            // Challenge present - instantiate ChallengeGenerator
        $this->challenge = new ChallengeGenerator(
            $this->writer,
                $this->params
            );
            
            if ($eMode == 0) {
                // Challenge only
                $this->challenge->openingsAndBriefings();        // check
                $this->challenge->main();
                $this->challenge->robotGameFinals();            // check
                $this->challenge->awards();                     // check
                
            } elseif ($eMode == 1) {
                // Challenge + Explore integrated morning
        $this->explore = new ExploreGenerator(
            $this->writer,
            $this->params
        );
                
                $this->challenge->openingsAndBriefings(true);        // check
                $this->explore->openingsAndBriefings(1, true);       // check
                $this->explore->judgingAndDeliberations(1);          // check
                $this->challenge->main(true);
                $this->challenge->robotGameFinals();            // check
                $this->challenge->awards();                     // check
                
            } elseif ($eMode == 2) {
                // Challenge + Explore integrated afternoon
                $this->explore = new ExploreGenerator(
                    $this->writer,
                    $this->params
                );
                
                $this->challenge->openingsAndBriefings();        // check
                $this->challenge->main(true);
                $this->explore->openingsAndBriefings(2, true);       // check
                $this->explore->judgingAndDeliberations(2);          // check   
                $this->challenge->robotGameFinals();            // check
                $this->challenge->awards(true);                     // check
                
            } elseif (in_array($eMode, [3, 4, 5])) {
                // Challenge + Explore decoupled
                
                // Challenge + Explore integrated afternoon
                $this->explore = new ExploreGenerator(
                    $this->writer,
                    $this->params
                );

                $this->challenge->openingsAndBriefings();        // check
                $this->challenge->main();
                $this->challenge->robotGameFinals();            // check
                $this->challenge->awards();                     // check


                if ($eMode == 3) {
                    // Explore decoupled morning
                    $this->explore->openingsAndBriefings(1);        // check
                    $this->explore->judgingAndDeliberations(1);        // check
                    $this->explore->awards(1);                         // check
                } elseif ($eMode == 4) {
                    // Explore decoupled afternoon
                    $this->explore->openingsAndBriefings(2);             // check
                    $this->explore->judgingAndDeliberations(2);        // check
                    $this->explore->awards(2);                         // check
                } elseif ($eMode == 5) {
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
            if ($eMode == 3) {
                // Explore morning only
                $this->explore = new ExploreGenerator(
                    $this->writer,
                    $this->params
                );
                $this->explore->openingsAndBriefings(1);        // check
                $this->explore->judgingAndDeliberations(1);        // check
                $this->explore->awards(1);                         // check
                
            } elseif ($eMode == 4) {
                // Explore afternoon only
                $this->explore = new ExploreGenerator(
                    $this->writer,
                    $this->params
                );
                $this->explore->openingsAndBriefings(2);        // check
                $this->explore->judgingAndDeliberations(2);        // check
                $this->explore->awards(2);                         // check

            } elseif ($eMode == 5) {
                // Explore both morning and afternoon
                $this->explore = new ExploreGenerator(
                    $this->writer,
                    $this->params
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