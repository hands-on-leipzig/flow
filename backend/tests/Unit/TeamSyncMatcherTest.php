<?php

namespace Tests\Unit;

use App\Support\TeamSyncMatcher;
use PHPUnit\Framework\TestCase;

class TeamSyncMatcherTest extends TestCase
{
    public function test_merge_detects_new_missing_and_conflict(): void
    {
        $local = [
            ['id' => 1, 'name' => 'Alpha', 'team_number_hot' => 101],
            ['id' => 2, 'name' => 'Gone', 'team_number_hot' => 102],
        ];
        $draht = [
            ['number' => 101, 'name' => 'Alpha Renamed', 'id' => 'a'],
            ['number' => 103, 'name' => 'New Team', 'id' => 'b'],
        ];

        $merged = TeamSyncMatcher::merge($local, $draht);

        $byStatus = collect($merged)->groupBy('status')->map->count();

        $this->assertSame(1, $byStatus['conflict'] ?? 0);
        $this->assertSame(1, $byStatus['missing'] ?? 0);
        $this->assertSame(1, $byStatus['new'] ?? 0);
    }

    public function test_action_counts(): void
    {
        $merged = [
            ['status' => 'missing'],
            ['status' => 'new'],
            ['status' => 'new'],
            ['status' => 'conflict'],
            ['status' => 'match'],
        ];

        $this->assertSame(
            ['removed' => 1, 'added' => 2, 'updated' => 1],
            TeamSyncMatcher::actionCounts($merged)
        );
    }

    public function test_normalize_draht_teams_skips_missing_ref(): void
    {
        $raw = [
            ['ref' => 5, 'name' => 'OK'],
            ['name' => 'No Ref'],
        ];

        $normalized = TeamSyncMatcher::normalizeDrahtTeams($raw);

        $this->assertCount(1, $normalized);
        $this->assertSame(5, $normalized[0]['number']);
    }
}
