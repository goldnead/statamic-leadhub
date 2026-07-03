<?php

namespace Goldnead\Leadhub\Console;

use Goldnead\Leadhub\Services\SegmentService;
use Illuminate\Console\Command;

/**
 * Re-materializes segment membership for every active segment.
 *
 * The reactive listener keeps membership fresh on contact mutations, but
 * time-based rules (e.g. "no activity in the last 30 days") drift without a
 * mutation to trigger re-evaluation. This scheduled sweep closes that gap.
 * Registered to run daily via the ServiceProvider schedule.
 */
class SweepSegmentsCommand extends Command
{
    protected $signature = 'leadhub:segments:sweep';

    protected $description = 'Re-materialize segment membership for time-based rules and drift correction.';

    public function handle(SegmentService $segments): int
    {
        $result = $segments->sweepAll();

        $totalEntered = array_sum(array_column($result, 'entered'));
        $totalLeft = array_sum(array_column($result, 'left'));

        foreach ($result as $handle => $counts) {
            $this->line(sprintf('  %s: +%d / -%d', $handle, $counts['entered'], $counts['left']));
        }

        $this->info(sprintf(
            'Swept %d segment(s): %d entered, %d left.',
            count($result),
            $totalEntered,
            $totalLeft,
        ));

        return self::SUCCESS;
    }
}
