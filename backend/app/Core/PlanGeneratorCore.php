<?php

namespace App\Core;

use DateTime;
use Illuminate\Support\Facades\Log;

use App\Core\ActivityWriter;
use App\Core\ChallengeGenerator;
use App\Core\ExploreGenerator;
use App\Core\MatchPlan;
use App\Core\FreeBlockGenerator;
use App\Core\TimeCursor;
use App\Core\FinaleGenerator;

use App\Support\PlanParameter;
use App\Support\UsesPlanParameter;

class PlanGeneratorCore
{
    private int $planId;
    private ActivityWriter $writer;
    private PlanParameter $params;

    private TimeCursor $cTime;
    private TimeCursor $rTime;
    private TimeCursor $jTime;
    private TimeCursor $eTime;
    private TimeCursor $lcTime;

    private ChallengeGenerator $challenge;
    private ExploreGenerator $explore;
    private MatchPlan $matchPlan;

    private int $cDay = 0; // Aktueller Event-Tag (1 = erster, 2 = zweiter, …)

    use UsesPlanParameter;

    public function __construct(int $planId)
    {
        $this->planId = $planId;
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
        Log::info("PlanGeneratorCore: Start generation for plan {$this->planId}");
                
        try {
                $this->generateByMode();
            } catch (\Throwable $e) {
                Log::error("Plan generation failed: {$e->getMessage()}", ['plan_id' => $this->planId]);
                throw $e;
            }
        

        // -----------------------------------------------------------------------------------
        // Add all free blocks.
        // Timing does not matter, because these are parallel to other activities.
        // -----------------------------------------------------------------------------------

        (new FreeBlockGenerator($this->writer, $this->planId))->insertFreeActivities();

        Log::info("PlanGeneratorCore: Finished generation for plan {$this->planId}");
    }

    private function generateByMode(): void
    {
        // Base date
        $gDate = clone $this->pp('g_date');
        // Initialize main cursors
        $this->cTime = new TimeCursor(clone $gDate);
        $this->jTime = new TimeCursor(clone $gDate);
        $this->rTime = new TimeCursor(clone $gDate);
        $this->eTime = new TimeCursor(clone $gDate);

        $cMode = $this->pp('c_mode');
        $eMode = $this->pp('e_mode');
        
        if ($cMode == 1) {
            // Challenge present - instantiate ChallengeGenerator
            $this->challenge = new ChallengeGenerator(
                $this->writer,
                $this->cTime,
                $this->jTime,
                $this->rTime,
                $this->planId
            );
            
            if ($eMode == 0) {
                // Challenge only
                $this->challenge->openingsAndBriefings(false);        // check
                $this->challenge->main(false);
                $this->challenge->robotGameFinals();
                $this->challenge->awards(false);
                
            } elseif ($eMode == 1) {
                // Challenge + Explore integrated morning
                $this->explore = new ExploreGenerator(
                    $this->writer,
                    $this->eTime,
                    $this->rTime,
                    $this->planId
                );
                
                $this->challenge->openingsAndBriefings(true);        // check
                $this->explore->openingsAndBriefings(1, true);       // check
                $this->explore->judgingAndDeliberations(1);          
                $this->challenge->main(true);
                $this->challenge->robotGameFinals();
                $this->challenge->awards(false);
                
            } elseif ($eMode == 2) {
                // Challenge + Explore integrated afternoon
                $this->explore = new ExploreGenerator(
                    $this->writer,
                    $this->eTime,
                    $this->rTime,
                    $this->planId
                );
                
                $this->challenge->openingsAndBriefings(false);
                $this->challenge->main(true);
                $this->explore->openingsAndBriefings(2, true);
                $this->explore->judgingAndDeliberations(2);
                $this->challenge->robotGameFinals();
                $this->challenge->awards(true);
                
            } elseif (in_array($eMode, [3, 4, 5])) {
                // Challenge + Explore decoupled
                
                // Challenge + Explore integrated afternoon
                $this->explore = new ExploreGenerator(
                    $this->writer,
                    $this->eTime,
                    $this->rTime,
                    $this->planId
                );

                $this->challenge->openingsAndBriefings(false);
                $this->challenge->main(false);
                $this->challenge->robotGameFinals();
                $this->challenge->awards(false);


                if ($eMode == 3) {
                    // Explore decoupled morning
                    $this->explore->openingsAndBriefings(1);
                    $this->explore->judgingAndDeliberations(1);
                    $this->explore->awards(1);
                } elseif ($eMode == 4) {
                    // Explore decoupled afternoon
                    $this->explore->openingsAndBriefings(2);
                    $this->explore->judgingAndDeliberations(2);
                    $this->explore->awards(2);
                } elseif ($eMode == 5) {
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
            if ($eMode == 3) {
                // Explore morning only
                $this->explore = new ExploreGenerator(
                    $this->writer,
                    $this->eTime,
                    $this->rTime,
                    $this->planId
                );
                $this->explore->openingsAndBriefings(1);
                $this->explore->judgingAndDeliberations(1);
                $this->explore->awards(1);
                
            } elseif ($eMode == 4) {
                // Explore afternoon only
                $this->explore = new ExploreGenerator(
                    $this->writer,
                    $this->eTime,
                    $this->rTime,
                    $this->planId
                );
                $this->explore->openingsAndBriefings(2);
                $this->explore->judgingAndDeliberations(2);
                $this->explore->awards(2);
                
            } elseif ($eMode == 5) {
                // Explore both morning and afternoon
                $this->explore = new ExploreGenerator(
                    $this->writer,
                    $this->eTime,
                    $this->rTime,
                    $this->planId
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