<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Pricing\Services\EstimateCalibrationService;
use Illuminate\Console\Command;

class RecordObservationCommand extends Command
{
    protected $signature = 'taxiscanner:record-observation
                            {--provider= : Provider slug (uber, bolt, streetcars, veezu)}
                            {--pickup= : Pickup location or query}
                            {--dropoff= : Dropoff location or query}
                            {--distance= : Route distance in miles}
                            {--duration= : Route duration in minutes}
                            {--observed= : Observed real-world fare amount in GBP}
                            {--estimated= : TaxiScanner estimated fare amount in GBP}
                            {--category= : Optional category (e.g. airport, city_centre, standard)}
                            {--notes= : Optional observation notes}';

    protected $description = 'Record a real-world observed fare sample for taxi estimation calibration';

    public function handle(EstimateCalibrationService $calibrationService): int
    {
        $provider = (string) ($this->option('provider') ?? $this->ask('Provider (uber, bolt, streetcars, veezu)'));
        $pickup = (string) ($this->option('pickup') ?? $this->ask('Pickup location'));
        $dropoff = (string) ($this->option('dropoff') ?? $this->ask('Dropoff location'));
        $distance = (float) ($this->option('distance') ?? $this->ask('Route distance (miles)'));
        $duration = (int) ($this->option('duration') ?? $this->ask('Route duration (minutes)'));
        $observed = (float) ($this->option('observed') ?? $this->ask('Observed real-world fare (£)'));
        $estimated = (float) ($this->option('estimated') ?? $this->ask('TaxiScanner estimated fare (£)'));
        $category = $this->option('category') ?: null;
        $notes = $this->option('notes') ?: null;

        try {
            $obs = $calibrationService->recordObservation([
                'provider' => $provider,
                'pickup' => $pickup,
                'dropoff' => $dropoff,
                'route_distance' => $distance,
                'route_duration' => $duration,
                'observed_real_world_fare' => $observed,
                'estimated_fare' => $estimated,
                'trip_category' => $category,
                'notes' => $notes,
            ]);

            $diffFormatted = ($obs->difference_percentage > 0 ? '+' : '').$obs->difference_percentage.'%';

            $this->info(sprintf(
                'Recorded observation #%d for %s: Observed=£%.2f, Estimated=£%.2f, Diff=%s, Ratio=%.4f',
                $obs->id,
                strtoupper($obs->provider_slug),
                $obs->observed_real_world_fare,
                $obs->estimated_fare,
                $diffFormatted,
                $obs->recommended_multiplier
            ));

            if ($obs->is_outlier) {
                $this->warn('Notice: Observation flagged as a potential statistical outlier.');
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed to record observation: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
