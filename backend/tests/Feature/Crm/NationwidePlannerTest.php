<?php

namespace Tests\Feature\Crm;

use App\Modules\Crm\Services\Discovery\Geo\NationwidePlanner;
use App\Modules\Crm\Services\Discovery\Geo\PhilippinesBoundary;
use Tests\TestCase;

/**
 * "Nationwide" must mean the country, and must keep meaning it.
 *
 * The failure this guards against is not a crash — it is a plan that looks national, reports as
 * national, and quietly isn't: a handful of cities, a northern strip, or a grid that wandered into
 * someone else's territory. Every assertion here is about the SHAPE of the plan rather than its
 * size, because the size is a budget decision and the shape is the product claim.
 */
class NationwidePlannerTest extends TestCase
{
    private function planner(): NationwidePlanner
    {
        return new NationwidePlanner(new PhilippinesBoundary());
    }

    public function test_every_generated_cell_touches_the_philippines(): void
    {
        $boundary = new PhilippinesBoundary();
        $cells = $this->planner()->cells(0.5);

        $this->assertNotEmpty($cells);

        foreach ($cells as $cell) {
            $this->assertTrue(
                $boundary->intersects($cell['bbox']),
                'a cell over ocean or another country reached the plan: '.json_encode($cell['bbox'])
            );
        }
    }

    public function test_no_cell_extends_beyond_the_national_extent(): void
    {
        [$south, $west, $north, $east] = (new PhilippinesBoundary())->bbox();

        foreach ($this->planner()->cells(0.5) as $cell) {
            [$s, $w, $n, $e] = $cell['bbox'];
            $this->assertGreaterThanOrEqual($south, $s);
            $this->assertGreaterThanOrEqual($west, $w);
            $this->assertLessThanOrEqual($north, $n);
            $this->assertLessThanOrEqual($east, $e);
        }
    }

    public function test_no_global_or_worldwide_cell_is_ever_produced(): void
    {
        // The specific catastrophe: one unclipped cell spanning the planet, which would hand Google
        // an unbounded search and pull in the entire world's businesses.
        foreach ($this->planner()->cells(0.5) as $cell) {
            [$s, $w, $n, $e] = $cell['bbox'];
            $this->assertLessThan(6.0, $n - $s, 'a cell spans an implausible latitude range');
            $this->assertLessThan(6.0, $e - $w, 'a cell spans an implausible longitude range');
            $this->assertGreaterThan(0.0, $n - $s);
            $this->assertGreaterThan(0.0, $e - $w);
        }
    }

    public function test_coverage_reaches_luzon_visayas_and_mindanao(): void
    {
        $groups = array_unique(array_column($this->planner()->cells(0.5), 'group'));
        sort($groups);

        $this->assertSame(
            [NationwidePlanner::GROUP_LUZON, NationwidePlanner::GROUP_MINDANAO, NationwidePlanner::GROUP_VISAYAS],
            $groups
        );
    }

    public function test_known_cities_land_in_the_right_island_group(): void
    {
        $planner = $this->planner();

        // Luzon
        $this->assertSame(NationwidePlanner::GROUP_LUZON, $planner->group(14.5995, 120.9842)); // Manila
        $this->assertSame(NationwidePlanner::GROUP_LUZON, $planner->group(16.4023, 120.5960)); // Baguio
        // Mindanao
        $this->assertSame(NationwidePlanner::GROUP_MINDANAO, $planner->group(7.1907, 125.4553)); // Davao
        $this->assertSame(NationwidePlanner::GROUP_MINDANAO, $planner->group(8.4542, 124.6319)); // CDO
        // Visayas / outlying
        $this->assertSame(NationwidePlanner::GROUP_VISAYAS, $planner->group(10.3157, 123.8854)); // Cebu
        $this->assertSame(NationwidePlanner::GROUP_VISAYAS, $planner->group(10.6407, 122.9689)); // Bacolod
    }

    public function test_a_small_budget_still_samples_all_three_island_groups(): void
    {
        // The property that makes a small verification run meaningful: a truncated plan must still be
        // national, not "the first few cells of northern Luzon".
        $tasks = $this->planner()->plan(['bakery'], 0.5, 3);

        $this->assertCount(3, $tasks);
        $groups = array_unique(array_column($tasks, 'group'));
        sort($groups);

        $this->assertSame(
            [NationwidePlanner::GROUP_LUZON, NationwidePlanner::GROUP_MINDANAO, NationwidePlanner::GROUP_VISAYAS],
            $groups
        );
    }

    public function test_the_budget_is_a_hard_ceiling_on_scheduled_tasks(): void
    {
        $this->assertCount(7, $this->planner()->plan(['bakery', 'dental clinic'], 0.5, 7));
        $this->assertCount(0, $this->planner()->plan(['bakery'], 0.5, 0));
    }

    public function test_coverage_is_not_the_curated_city_file(): void
    {
        // The whole point: nationwide is the polygon, so the plan is far larger than the nine
        // curated pairs and is not derived from them in any way.
        $cells = $this->planner()->cells(0.5);

        $this->assertGreaterThan(50, count($cells), 'nationwide collapsed to something list-sized');
    }

    public function test_niches_are_free_text_and_never_validated_against_a_list(): void
    {
        $tasks = $this->planner()->plan(['artisanal sourdough bakery', 'tuli clinic'], 0.5, 4);

        $categories = array_unique(array_column($tasks, 'category'));
        sort($categories);

        $this->assertSame(['artisanal sourdough bakery', 'tuli clinic'], $categories);
    }

    public function test_every_cell_is_labelled_with_an_anchor_for_reporting(): void
    {
        foreach ($this->planner()->cells(1.0) as $cell) {
            $this->assertNotSame('', $cell['area']);
        }
    }
}
