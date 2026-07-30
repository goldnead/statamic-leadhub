<?php

namespace Goldnead\Leadhub\Console;

use Goldnead\BrandContext\Concerns\RunsForEachBrand;
use Goldnead\Leadhub\Services\SegmentService;
use Illuminate\Console\Command;

/**
 * Re-materializes segment membership for every active segment.
 *
 * The reactive listener keeps membership fresh on contact mutations, but
 * time-based rules (e.g. "no activity in the last 30 days") drift without a
 * mutation to trigger re-evaluation. This scheduled sweep closes that gap.
 * Registered to run daily via the ServiceProvider schedule.
 *
 * Runs once per brand. A console run has no session, so without this the
 * multi-brand global scope fails closed and `sweepAll()` sees no segments at
 * all — the command then reports "Swept 0 segment(s)" and exits successfully,
 * which reads as "nothing to do" and means "I could not see anything". The
 * symptom is a segment list stuck at 0 members for rules that clearly match.
 */
class SweepSegmentsCommand extends Command
{
    use RunsForEachBrand;

    protected $signature = 'leadhub:segments:sweep
        {--brand= : Restrict to a single brand (handle or id)}';

    protected $description = 'Re-materialize segment membership for time-based rules and drift correction.';

    public function handle(): int
    {
        return $this->forEachBrand(fn () => $this->sweepCurrentBrand());
    }

    protected function sweepCurrentBrand(): int
    {
        // Resolved inside the brand context: the service reads the current
        // brand when it queries, so resolving it outside would be a coin flip.
        $result = app(SegmentService::class)->sweepAll();

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
