<?php

namespace Goldnead\Leadhub\Console;

use Goldnead\BrandContext\Concerns\RunsForEachBrand;
use Goldnead\Leadhub\Models\ScoringRule;
use Illuminate\Console\Command;

/**
 * Copies the point table from `config/leadhub.php` into `leadhub_scoring_rules`.
 *
 * This is the whole safety story of moving the rules into the database. Until
 * it has run, ScoringService keeps reading the config file verbatim (see its
 * `rulesFor()`), so an upgrade computes exactly the scores it computed before.
 * After it has run, the same numbers are in the table, per brand, and the CP
 * takes over.
 *
 * Two properties matter more than the code:
 *
 * - **Idempotent.** A second run changes nothing. Existing rules are left
 *   alone even when their points differ from the config file — a rule that
 *   differs is a rule somebody edited in the CP, and re-running an import must
 *   never quietly undo that. `--force` is the explicit way to overwrite.
 * - **Dry run first.** `--dry-run` prints exactly what a real run would write
 *   and touches nothing, so the diff can be read before the scoring behaviour
 *   of a live install changes.
 *
 * `scoring.default` is imported as the catch-all rule (`event_type = '*'`), so
 * a brand can raise or lower its own baseline instead of inheriting one global
 * config value.
 */
class ImportScoringRulesCommand extends Command
{
    use RunsForEachBrand;

    protected $signature = 'leadhub:scoring:import
        {--dry-run : Show what would be written without writing anything}
        {--force : Overwrite rules whose points differ from the config file}
        {--brand= : Restrict to a single brand (handle or id)}';

    protected $description = 'Import the leadhub.scoring config table into the per-brand scoring rules.';

    public function handle(): int
    {
        if (config('leadhub.storage.driver', 'eloquent') !== 'eloquent') {
            $this->error('Scoring rules are stored in the database; the flat driver has no table to import into.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->line('Dry run — nothing will be written.');
        }

        return $this->forEachBrand(fn () => $this->importCurrentBrand());
    }

    protected function importCurrentBrand(): int
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($this->configuredRules() as $type => $points) {
            $existing = ScoringRule::query()->where('event_type', $type)->first();

            if ($existing === null) {
                $this->line(sprintf('  + %-28s %+d', $type, $points));

                if (! $this->option('dry-run')) {
                    ScoringRule::create([
                        'event_type' => $type,
                        'points' => $points,
                        'label' => $this->labelFor($type),
                        'enabled' => true,
                    ]);
                }

                $created++;

                continue;
            }

            if ((int) $existing->points === $points) {
                $skipped++;

                continue;
            }

            if (! $this->option('force')) {
                $this->line(sprintf(
                    '  ~ %-28s kept at %+d (config says %+d) — use --force to overwrite',
                    $type,
                    (int) $existing->points,
                    $points,
                ));
                $skipped++;

                continue;
            }

            $this->line(sprintf('  ! %-28s %+d → %+d', $type, (int) $existing->points, $points));

            if (! $this->option('dry-run')) {
                $existing->update(['points' => $points]);
            }

            $updated++;
        }

        $this->info(sprintf(
            '%s %d rule(s), updated %d, left %d unchanged.',
            $this->option('dry-run') ? 'Would create' : 'Created',
            $created,
            $updated,
            $skipped,
        ));

        return self::SUCCESS;
    }

    /**
     * The config point table plus the catch-all, in a stable order.
     *
     * @return array<string,int>
     */
    protected function configuredRules(): array
    {
        $rules = [];

        foreach ((array) config('leadhub.scoring.events', []) as $type => $points) {
            $type = trim((string) $type);

            if ($type === '') {
                continue;
            }

            $rules[$type] = (int) $points;
        }

        ksort($rules);

        // The catch-all last, so the summary reads config-first and the row a
        // reader is most likely to reconsider is the final line.
        $rules[ScoringRule::CATCH_ALL] = (int) config('leadhub.scoring.default', 1);

        return $rules;
    }

    protected function labelFor(string $type): ?string
    {
        return $type === ScoringRule::CATCH_ALL
            ? __('leadhub::scoring.catch_all_label')
            : null;
    }
}
