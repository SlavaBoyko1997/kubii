<?php

namespace Tests\Unit;

use App\Support\CatalogCategoryMergeMap;
use Tests\TestCase;

class CatalogCategoryMergeMapTest extends TestCase
{
    public function test_resolve_target_id_prefers_existing_canonical_target(): void
    {
        $exists = static fn (int $id): bool => in_array($id, [392, 243], true);

        $this->assertSame(392, CatalogCategoryMergeMap::resolveTargetId(392, [362, 392, 243], $exists));
    }

    public function test_resolve_target_id_falls_back_to_existing_source_when_canonical_missing(): void
    {
        $exists = static fn (int $id): bool => $id === 243;

        $this->assertSame(243, CatalogCategoryMergeMap::resolveTargetId(392, [362, 392, 243], $exists));
    }

    public function test_build_source_target_pairs_skips_groups_with_no_existing_categories(): void
    {
        $exists = static fn (int $id): bool => $id === 243;

        $pairs = CatalogCategoryMergeMap::buildSourceTargetPairs($exists);

        $this->assertSame(243, $pairs[362] ?? null);
        $this->assertArrayNotHasKey(243, $pairs);
        $this->assertSame(243, $pairs[392] ?? null);
    }
}
