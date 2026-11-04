<?php

declare(strict_types=1);

namespace Tagwerk\Kochwiki;

use App\Enums\ConnectionStatus;
use App\Enums\ProviderArea;
use App\Models\CatalogRecipe;
use App\Models\ProviderAccount;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Daily in the schedule, but only does something when it has to: when a
 * household has connected Koch-Wiki and the mirror is empty or older than
 * four weeks. So the first recipes arrive the night after connecting, and a
 * server nobody uses Koch-Wiki on never asks it anything.
 */
final class KochwikiSyncCommand extends Command
{
    protected $signature = 'tagwerk:kochwiki-sync {--force : Sync now, used or not}';

    protected $description = 'Mirror the Koch-Wiki recipes for every household that connected them';

    private const DAYS = 28;

    public function handle(KochwikiSync $sync): int
    {
        if (! $this->option('force') && ! $this->due()) {
            $this->line('Koch-Wiki is up to date or not connected anywhere.');

            return self::SUCCESS;
        }

        $counts = $sync->run(fn (int $done, int $total) => $this->line("{$done} / {$total}"));

        $this->info(sprintf(
            '%d updated, %d unchanged, %d removed, %d pages without a recipe.',
            $counts['updated'],
            $counts['unchanged'],
            $counts['removed'],
            $counts['skipped'],
        ));

        return self::SUCCESS;
    }

    private function due(): bool
    {
        $used = ProviderAccount::withoutHouseholdScope()
            ->where('area', ProviderArea::Recipes->value)
            ->where('provider', KochwikiRecipeProvider::KEY)
            ->where('status', ConnectionStatus::Active->value)
            ->exists();

        if (! $used) {
            return false;
        }

        $synced = Cache::get(KochwikiSync::SYNCED_AT);

        return ! is_string($synced)
            || ! CatalogRecipe::query()->where('source', KochwikiRecipeProvider::KEY)->exists()
            || CarbonImmutable::parse($synced)->addDays(self::DAYS)->isPast();
    }
}
