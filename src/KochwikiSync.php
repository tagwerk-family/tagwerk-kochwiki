<?php

declare(strict_types=1);

namespace Tagwerk\Kochwiki;

use App\Models\CatalogRecipe;
use Illuminate\Support\Facades\Cache;

/**
 * Mirrors Koch-Wiki's recipes into `catalog_recipes`, once for the server.
 *
 * Only what changed is fetched: the page list carries every page's latest
 * revision, and a page whose revision is known is left alone — recipe or
 * not (pages without `{{Rezept}}` are remembered in the cache).
 */
final class KochwikiSync
{
    public const SYNCED_AT = 'kochwiki:synced_at';

    private const NOT_RECIPES = 'kochwiki:not_recipes';

    /** Text licence of every Koch-Wiki page. */
    private const LICENSE = ['text' => 'CC BY-SA 3.0', 'url' => 'https://creativecommons.org/licenses/by-sa/3.0/de/'];

    public function __construct(private readonly KochwikiClient $client) {}

    /**
     * @param  (callable(int $done, int $total): void)|null  $progress
     * @return array{updated: int, unchanged: int, removed: int, skipped: int}
     */
    public function run(?callable $progress = null): array
    {
        $pages = $this->client->pages();

        /** @var array<string, string|null> $known */
        $known = CatalogRecipe::query()
            ->where('source', KochwikiRecipeProvider::KEY)
            ->pluck('revision', 'source_id')
            ->all();

        /** @var array<int, int> $notRecipes */
        $notRecipes = Cache::get(self::NOT_RECIPES, []);

        $changed = [];

        foreach ($pages as $id => $page) {
            if (($known[(string) $id] ?? null) !== (string) $page['revision'] && ($notRecipes[$id] ?? null) !== $page['revision']) {
                $changed[] = $id;
            }
        }

        $counts = ['updated' => 0, 'unchanged' => count($pages) - count($changed), 'removed' => 0, 'skipped' => 0];

        foreach (array_chunk($changed, 50) as $index => $chunk) {
            $recipes = [];

            foreach ($this->client->contents($chunk) as $id => $page) {
                $recipe = Wikitext::recipe($page['title'], $page['text']);

                if ($recipe === null) {
                    $notRecipes[$id] = $page['revision'];
                    $counts['skipped']++;

                    continue;
                }

                $recipes[$id] = [...$recipe, 'revision' => (string) $page['revision']];
            }

            $files = array_values(array_unique(array_filter(array_column($recipes, 'image'))));
            $images = $files === [] ? [] : $this->client->images($files);

            foreach ($recipes as $id => $recipe) {
                $this->store($id, $recipe, $images[$recipe['image'] ?? ''] ?? null);
                $counts['updated']++;
            }

            Cache::forever(self::NOT_RECIPES, $notRecipes);

            if ($progress !== null) {
                $progress(min(count($changed), ($index + 1) * 50), count($changed));
            }
        }

        // Only after a complete list: a page that is gone was deleted or became a redirect.
        $counts['removed'] = CatalogRecipe::query()
            ->where('source', KochwikiRecipeProvider::KEY)
            ->whereNotIn('source_id', array_map(strval(...), array_keys($pages)))
            ->delete();

        Cache::forever(self::SYNCED_AT, now()->toIso8601String());

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $recipe
     * @param  array{small: string, large: string, page: string, author: string|null, license: string|null}|null  $image
     */
    private function store(int $id, array $recipe, ?array $image): void
    {
        unset($recipe['image']);

        CatalogRecipe::query()->updateOrCreate(
            ['source' => KochwikiRecipeProvider::KEY, 'source_id' => (string) $id],
            [
                ...$recipe,
                'photo' => $image === null ? null : ['small' => $image['small'], 'large' => $image['large']],
                'source_url' => KochwikiClient::SITE.'/wiki/'.implode('/', array_map(
                    rawurlencode(...),
                    explode('/', str_replace(' ', '_', (string) $recipe['title'])),
                )),
                'license' => [
                    ...self::LICENSE,
                    'author' => 'Koch-Wiki',
                    'photo_author' => $image['author'] ?? null,
                    'photo_license' => $image['license'] ?? null,
                    'photo_url' => $image['page'] ?? null,
                ],
            ],
        );
    }
}
