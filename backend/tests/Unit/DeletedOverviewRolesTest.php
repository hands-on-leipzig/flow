<?php

namespace Tests\Unit;

use Tests\TestCase;

class DeletedOverviewRolesTest extends TestCase
{
    /** @var list<int> */
    private const DELETE_IDS = [1, 7, 15, 17, 18, 19, 20, 26];

    public function test_catalog_json_omits_deleted_roles_and_visibility_pairs(): void
    {
        $data = json_decode(
            file_get_contents(database_path('exports/main-tables-latest.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $roleIds = collect($data['m_role'])->pluck('id')->map(fn ($id) => (int) $id)->all();
        $visRoles = collect($data['m_visibility'])->pluck('role')->map(fn ($id) => (int) $id)->all();

        foreach (self::DELETE_IDS as $id) {
            $this->assertNotContains($id, $roleIds);
            $this->assertNotContains($id, $visRoles);
        }

        foreach ([3, 6, 8, 10, 14, 21, 24] as $id) {
            $this->assertContains($id, $roleIds);
        }

        foreach ($data['m_role'] as $row) {
            $this->assertArrayNotHasKey('differentiation_type', $row);
            $this->assertArrayNotHasKey('differentiation_source', $row);
            $this->assertArrayHasKey('differentiation_parameter', $row);
        }
    }
}
