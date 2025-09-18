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
                'name' => 'Test Explore Event',
                'region' => 2001,
                'first_program' => 2, // Explore only
                'date' => strtotime('+30 days'),
                'enddate' => strtotime('+30 days'),
                'teams' => [
                    [
                        'id' => 1,
                        'name' => 'Team Alpha',
                        'first_program' => 2,
                        'members' => [
                            ['name' => 'John Doe', 'email' => 'john@example.com'],
                            ['name' => 'Jane Smith', 'email' => 'jane@example.com']
                        ]
                    ],
                    [
                        'id' => 2,
                        'name' => 'Team Beta',
                        'first_program' => 2,
                        'members' => [
                            ['name' => 'Bob Johnson', 'email' => 'bob@example.com'],
                            ['name' => 'Alice Brown', 'email' => 'alice@example.com']
                        ]
                    ]
                ]
            ],
            [
                'id' => 1002,
                'name' => 'Test Challenge Event',
                'region' => 2001,
                'first_program' => 3, // Challenge only
                'date' => strtotime('+45 days'),
                'enddate' => strtotime('+45 days'),
                'teams' => [
                    [
                        'id' => 3,
                        'name' => 'Team Gamma',
                        'first_program' => 3,
                        'members' => [
                            ['name' => 'Charlie Wilson', 'email' => 'charlie@example.com'],
                            ['name' => 'Diana Lee', 'email' => 'diana@example.com']
                        ]
                    ]
                ]
            ],
            [
                'id' => 1003,
                'name' => 'Test Combined Event',
                'region' => 2002,
                'first_program' => 1, // Both Explore and Challenge
                'date' => strtotime('+60 days'),
                'enddate' => strtotime('+60 days'),
                'teams' => [
                    [
                        'id' => 4,
                        'name' => 'Team Delta',
                        'first_program' => 2,
                        'members' => [
                            ['name' => 'Eve Davis', 'email' => 'eve@example.com'],
                            ['name' => 'Frank Miller', 'email' => 'frank@example.com']
                        ]
                    ],
                    [
                        'id' => 5,
                        'name' => 'Team Epsilon',
                        'first_program' => 3,
                        'members' => [
                            ['name' => 'Grace Taylor', 'email' => 'grace@example.com'],
                            ['name' => 'Henry Anderson', 'email' => 'henry@example.com']
                        ]
                    ]
                ]
            ]
        ]);
    }

    /**
     * Simulate /handson/events/{id}/scheduledata endpoint
     */
    private function simulateGetEventScheduleData($eventId)
    {
        $eventData = [
            'id' => (int)$eventId,
            'name' => "Test Event {$eventId}",
            'address' => "Test Address {$eventId}, 12345 Test City",
            'contact' => serialize([
                'name' => 'Test Contact',
                'email' => 'contact@test.com',
                'phone' => '+49 123 456789'
            ]),
            'information' => "This is test information for event {$eventId}. It contains important details about the event.",
            'teams' => $this->generateMockTeams($eventId),
            'capacity_teams' => rand(10, 50),
            'date' => strtotime('+' . rand(1, 90) . ' days'),
            'enddate' => strtotime('+' . rand(1, 90) . ' days'),
        ];

        return response()->json($eventData);
    }

    /**
     * Generate mock teams for an event
     */
    private function generateMockTeams($eventId)
    {
        $teamCount = rand(2, 8);
        $teams = [];
        
        for ($i = 1; $i <= $teamCount; $i++) {
            $teams[] = [
                'id' => ($eventId * 100) + $i,
                'name' => "Team " . chr(64 + $i) . " (Event {$eventId})",
                'first_program' => rand(1, 3),
                'members' => $this->generateMockMembers($i)
            ];
        }
        
        return $teams;
    }

    /**
     * Generate mock team members
     */
    private function generateMockMembers($teamId)
    {
        $memberCount = rand(2, 4);
        $members = [];
        
        $firstNames = ['John', 'Jane', 'Bob', 'Alice', 'Charlie', 'Diana', 'Eve', 'Frank', 'Grace', 'Henry'];
        $lastNames = ['Smith', 'Johnson', 'Brown', 'Wilson', 'Lee', 'Davis', 'Miller', 'Taylor', 'Anderson', 'Garcia'];
        
        for ($i = 0; $i < $memberCount; $i++) {
            $firstName = $firstNames[array_rand($firstNames)];
            $lastName = $lastNames[array_rand($lastNames)];
            $members[] = [
                'name' => "{$firstName} {$lastName}",
                'email' => strtolower("{$firstName}.{$lastName}@test.com"),
                'role' => $i === 0 ? 'Team Leader' : 'Member'
            ];
        }
        
        return $members;
    }
}
