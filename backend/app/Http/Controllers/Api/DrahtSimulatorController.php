<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DrahtSimulatorController extends Controller
{
    /**
     * Simulate Draht API endpoints for testing
     */
    public function handle(Request $request, $path = '')
    {
        Log::info("Draht Simulator called with path: /{$path}");
        
        // Route to appropriate simulator method based on path
        switch ($path) {
            case 'handson/rp':
                return $this->simulateGetAllRegions();
                
            case 'handson/flow/events':
                return $this->simulateGetAllEventsAndTeams();
                
            case preg_match('/^handson\/events\/(\d+)\/scheduledata$/', $path, $matches) ? true : false:
                $eventId = $matches[1];
                return $this->simulateGetEventScheduleData($eventId);
                
            default:
                return response()->json(['error' => 'Simulated endpoint not found'], 404);
        }
    }

    /**
     * Simulate /handson/rp endpoint
     */
    private function simulateGetAllRegions()
    {
        return response()->json([
            [
                'id' => 2001,
                'name' => 'Test Regional Partner A',
                'region' => 'Test Region A'
            ],
            [
                'id' => 2002,
                'name' => 'Test Regional Partner B',
                'region' => 'Test Region B'
            ],
            [
                'id' => 2003,
                'name' => 'Test Regional Partner C',
                'region' => 'Test Region C'
            ]
        ]);
    }

    /**
     * Simulate /handson/flow/events endpoint
     */
    private function simulateGetAllEventsAndTeams()
    {
        return response()->json([
            [
                'id' => 1001,
                'name' => 'FLL Explore Event Augsburg',
                'region' => 2001,
                'first_program' => 2, // Explore only
                'date' => strtotime('+30 days'),
                'enddate' => strtotime('+30 days'),
                'teams' => $this->generateFLLTeams(1001, 2, 6) // 6 teams for explore
            ],
            [
                'id' => 1002,
                'name' => 'FLL Challenge Event Innsbruck',
                'region' => 2001,
                'first_program' => 3, // Challenge only
                'date' => strtotime('+45 days'),
                'enddate' => strtotime('+45 days'),
                'teams' => $this->generateFLLTeams(1002, 3, 8) // 8 teams for challenge
            ],
            [
                'id' => 1003,
                'name' => 'FLL Combined Event Luzern',
                'region' => 2002,
                'first_program' => 1, // Both Explore and Challenge
                'date' => strtotime('+60 days'),
                'enddate' => strtotime('+60 days'),
                'teams' => $this->generateFLLTeams(1003, 1, 10) // 10 teams for combined
            ],
            [
                'id' => 1004,
                'name' => 'FLL Explore Event Bielefeld',
                'region' => 2002,
                'first_program' => 2, // Explore only
                'date' => strtotime('+75 days'),
                'enddate' => strtotime('+75 days'),
                'teams' => $this->generateFLLTeams(1004, 2, 5) // 5 teams for explore
            ],
            [
                'id' => 1005,
                'name' => 'FLL Challenge Event Graz',
                'region' => 2003,
                'first_program' => 3, // Challenge only
                'date' => strtotime('+90 days'),
                'enddate' => strtotime('+90 days'),
                'teams' => $this->generateFLLTeams(1005, 3, 7) // 7 teams for challenge
            ]
        ]);
    }

    /**
     * Simulate /handson/events/{id}/scheduledata endpoint
     */
    private function simulateGetEventScheduleData($eventId)
    {
        $eventInfo = $this->getEventInfo($eventId);
        
        $eventData = [
            'id' => (int)$eventId,
            'name' => $eventInfo['name'],
            'address' => $eventInfo['address'],
            'contact' => serialize($this->generateContacts($eventInfo)),
            'information' => $eventInfo['information'],
            'teams' => $this->generateFLLTeams($eventId, $eventInfo['program_type'], $eventInfo['team_count']),
            'capacity_teams' => $eventInfo['capacity'],
            'date' => strtotime('+' . rand(1, 90) . ' days'),
            'enddate' => strtotime('+' . rand(1, 90) . ' days'),
        ];

        return response()->json($eventData);
    }

    /**
     * Get event information for specific event ID
     */
    private function getEventInfo($eventId)
    {
        $events = [
            1001 => [
                'name' => 'FLL Explore Event Augsburg',
                'address' => 'Universität Augsburg, Universitätsstraße 2, 86159 Augsburg, Deutschland',
                'contact_name' => 'Dr. Maria Weber',
                'contact_email' => 'maria.weber@uni-augsburg.de',
                'contact_phone' => '+49 821 598-4000',
                'information' => 'FLL Explore Event in der historischen Stadt Augsburg. Das Event findet in den modernen Räumlichkeiten der Universität statt.',
                'program_type' => 2, // Explore
                'team_count' => 6,
                'capacity' => 12
            ],
            1002 => [
                'name' => 'FLL Challenge Event Innsbruck',
                'address' => 'Universität Innsbruck, Innrain 52, 6020 Innsbruck, Österreich',
                'contact_name' => 'Prof. Andreas Müller',
                'contact_email' => 'andreas.mueller@uibk.ac.at',
                'contact_phone' => '+43 512 507-0',
                'information' => 'FLL Challenge Event in der wunderschönen Alpenstadt Innsbruck. Moderne Labore und Hörsäle stehen zur Verfügung.',
                'program_type' => 3, // Challenge
                'team_count' => 8,
                'capacity' => 16
            ],
            1003 => [
                'name' => 'FLL Combined Event Luzern',
                'address' => 'Hochschule Luzern, Technik & Architektur, Technikumstrasse 21, 6048 Horw, Schweiz',
                'contact_name' => 'Stefan Zimmermann',
                'contact_email' => 'stefan.zimmermann@hslu.ch',
                'contact_phone' => '+41 41 349 36 00',
                'information' => 'FLL Combined Event in Luzern mit Blick auf die Schweizer Alpen. Sowohl Explore als auch Challenge Teams sind willkommen.',
                'program_type' => 1, // Both
                'team_count' => 10,
                'capacity' => 20
            ],
            1004 => [
                'name' => 'FLL Explore Event Bielefeld',
                'address' => 'Universität Bielefeld, Universitätsstraße 25, 33615 Bielefeld, Deutschland',
                'contact_name' => 'Dr. Sabine Hoffmann',
                'contact_email' => 'sabine.hoffmann@uni-bielefeld.de',
                'contact_phone' => '+49 521 106-00',
                'information' => 'FLL Explore Event in Bielefeld. Die Universität bietet ideale Bedingungen für junge Forscherinnen und Forscher.',
                'program_type' => 2, // Explore
                'team_count' => 5,
                'capacity' => 10
            ],
            1005 => [
                'name' => 'FLL Challenge Event Graz',
                'address' => 'Technische Universität Graz, Rechbauerstraße 12, 8010 Graz, Österreich',
                'contact_name' => 'Mag. Thomas Steiner',
                'contact_email' => 'thomas.steiner@tugraz.at',
                'contact_phone' => '+43 316 873-0',
                'information' => 'FLL Challenge Event in der steirischen Landeshauptstadt Graz. Moderne Technik-Labore und kompetente Betreuung.',
                'program_type' => 3, // Challenge
                'team_count' => 7,
                'capacity' => 14
            ]
        ];

        return $events[$eventId] ?? [
            'name' => "FLL Event {$eventId}",
            'address' => "Musterstraße 1, 12345 Musterstadt, Deutschland",
            'contact_name' => 'Max Mustermann',
            'contact_email' => 'max.mustermann@example.com',
            'contact_phone' => '+49 123 456789',
            'information' => "FLL Event {$eventId} - Details folgen.",
            'program_type' => 1,
            'team_count' => 5,
            'capacity' => 10
        ];
    }

    /**
     * Generate FLL teams with realistic names
     */
    private function generateFLLTeams($eventId, $programType, $teamCount)
    {
        $teamNames = $this->getFLLTeamNames($programType);
        $teams = [];
        
        for ($i = 0; $i < $teamCount; $i++) {
            $teamName = $teamNames[$i] ?? "Team " . chr(65 + $i);
            $teams[] = [
                'id' => ($eventId * 100) + $i + 1,
                'name' => $teamName,
                'first_program' => $programType === 1 ? (rand(1, 2) == 1 ? 2 : 3) : $programType,
                'members' => $this->generateGermanMembers($i + 1)
            ];
        }
        
        return $teams;
    }

    /**
     * Get realistic FLL team names
     */
    private function getFLLTeamNames($programType)
    {
        $exploreNames = [
            'RoboExplorers', 'TechKids', 'FutureScientists', 'DiscoverySquad', 'InnovationKids',
            'RoboRangers', 'TechTigers', 'ScienceStars', 'ExplorerElite', 'RoboRockets',
            'TechTrekkers', 'DiscoveryDynamos', 'FutureFlashers', 'RoboRunners', 'TechTitans'
        ];
        
        $challengeNames = [
            'RoboChampions', 'TechMasters', 'EliteEngineers', 'RoboRulers', 'TechTitans',
            'ChallengeChamps', 'RoboRebels', 'TechThunder', 'EliteEagles', 'RoboRockets',
            'TechTornadoes', 'ChallengeCrushers', 'RoboRangers', 'TechTigers', 'EliteElites'
        ];
        
        $combinedNames = [
            'RoboAllStars', 'TechElite', 'FutureChampions', 'RoboMasters', 'TechHeroes',
            'EliteExplorers', 'RoboChampions', 'TechStars', 'FutureLeaders', 'RoboElite',
            'TechChampions', 'EliteTech', 'RoboHeroes', 'TechLeaders', 'FutureElite'
        ];
        
        switch ($programType) {
            case 2: return $exploreNames;
            case 3: return $challengeNames;
            case 1: return $combinedNames;
            default: return array_merge($exploreNames, $challengeNames);
        }
    }

    /**
     * Generate German/Austrian/Swiss team members
     */
    private function generateGermanMembers($teamId)
    {
        $memberCount = rand(2, 4);
        $members = [];
        
        $firstNames = [
            'male' => ['Maximilian', 'Alexander', 'Paul', 'Elias', 'Ben', 'Felix', 'Lukas', 'Noah', 'Leon', 'Jonas', 'Finn', 'Liam', 'Anton', 'Theo', 'Emil'],
            'female' => ['Mia', 'Emma', 'Hannah', 'Sophia', 'Emilia', 'Lina', 'Marie', 'Anna', 'Lea', 'Lena', 'Clara', 'Lilly', 'Amelie', 'Mila', 'Ella']
        ];
        
        $lastNames = [
            'Müller', 'Schmidt', 'Schneider', 'Fischer', 'Weber', 'Meyer', 'Wagner', 'Becker', 'Schulz', 'Hoffmann',
            'Schäfer', 'Bauer', 'Koch', 'Richter', 'Klein', 'Wolf', 'Schröder', 'Neumann', 'Schwarz', 'Zimmermann',
            'Braun', 'Hofmann', 'Lange', 'Schmitt', 'Werner', 'Schmitz', 'Krause', 'Meier', 'Lehmann', 'Schmid'
        ];
        
        for ($i = 0; $i < $memberCount; $i++) {
            $gender = rand(0, 1) ? 'male' : 'female';
            $firstName = $firstNames[$gender][array_rand($firstNames[$gender])];
            $lastName = $lastNames[array_rand($lastNames)];
            $members[] = [
                'name' => "{$firstName} {$lastName}",
                'email' => strtolower("{$firstName}.{$lastName}@fll-team{$teamId}.de"),
                'role' => $i === 0 ? 'Team Leader' : 'Member'
            ];
        }
        
        return $members;
    }

    /**
     * Generate multiple contacts for an event
     */
    private function generateContacts($eventInfo)
    {
        $contacts = [];
        
        // Primary contact (from event info)
        $contacts[] = [
            'contact' => $eventInfo['contact_name'],
            'contact_email' => $eventInfo['contact_email'],
            'contact_infos' => $eventInfo['contact_phone'] . ' - Event Coordinator'
        ];

        // Generate 1-2 additional contacts
        $additionalContacts = rand(1, 2);
        $roles = ['Technical Support', 'Administration', 'Volunteer Coordinator', 'Safety Officer'];
        
        for ($i = 0; $i < $additionalContacts; $i++) {
            $name = $this->generateGermanName();
            $email = $this->generateEmail($eventInfo['contact_email']);
            $phone = $this->generatePhone();
            $role = $roles[array_rand($roles)];
            
            $contacts[] = [
                'contact' => $name,
                'contact_email' => $email,
                'contact_infos' => $phone . ' - ' . $role
            ];
        }

        return $contacts;
    }

    /**
     * Generate a German name
     */
    private function generateGermanName()
    {
        $firstNames = ['Anna', 'Max', 'Lisa', 'Tom', 'Sarah', 'Ben', 'Emma', 'Lukas', 'Hannah', 'Felix'];
        $lastNames = ['Schmidt', 'Müller', 'Weber', 'Wagner', 'Becker', 'Schulz', 'Hoffmann', 'Koch', 'Richter', 'Klein'];
        
        return $firstNames[array_rand($firstNames)] . ' ' . $lastNames[array_rand($lastNames)];
    }

    /**
     * Generate an email based on the primary contact's domain
     */
    private function generateEmail($primaryEmail)
    {
        $domain = substr(strrchr($primaryEmail, "@"), 1);
        $name = strtolower(str_replace(' ', '.', $this->generateGermanName()));
        return $name . '@' . $domain;
    }

    /**
     * Generate a phone number
     */
    private function generatePhone()
    {
        $areaCodes = ['+49 821', '+49 521', '+43 512', '+49 30', '+49 89'];
        $areaCode = $areaCodes[array_rand($areaCodes)];
        $number = rand(100000, 999999);
        return $areaCode . ' ' . $number;
    }
}
