<?php

declare(strict_types=1);

namespace Tagwerk\Kochwiki;

use App\Enums\ConnectionStatus;
use App\Enums\ProviderArea;
use App\Models\CatalogRecipe;
use App\Models\ProviderAccount;
use App\Support\Recipes\RecipeSearch;
use Illuminate\Database\Eloquent\Builder;
use Tagwerk\PluginApi\Connections\DescribesSetup;
use Tagwerk\PluginApi\Connections\UsesCredentials;
use Tagwerk\PluginApi\Recipes\DescribesRecipes;
use Tagwerk\PluginApi\Recipes\RecipeDetail;
use Tagwerk\PluginApi\Recipes\RecipeProvider;
use Tagwerk\PluginApi\Recipes\RecipeQuery;

/**
 * About 11,000 recipes from kochwiki.org, read from the server's mirror.
 *
 * Connecting asks nothing — the wizard's credential step stays empty — but
 * a household still connects it: whoever does not want a public collection
 * among the family's recipes simply does not.
 */
final class KochwikiRecipeProvider implements DescribesRecipes, DescribesSetup, RecipeProvider, UsesCredentials
{
    public const KEY = 'tagwerk/kochwiki';

    /** Koch-Wiki tags generously; the filter offers the most used only. */
    private const CATEGORIES = 40;

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Koch-Wiki';
    }

    public function isConfigured(): bool
    {
        return ProviderAccount::query()
            ->forArea(ProviderArea::Recipes)
            ->where('provider', self::KEY)
            ->where('status', ConnectionStatus::Active->value)
            ->exists();
    }

    public function icon(): string
    {
        return 'heroicon-o-book-open';
    }

    public function description(): string
    {
        return __('About 11,000 recipes from the free Koch-Wiki, with photos — browse, plan and shop.');
    }

    /** @return array<int, string> */
    public function setupNotes(): array
    {
        return [
            __('Nothing to enter. The recipes are fetched once for the whole server, the night after the first connection, and refreshed monthly.'),
            __('Texts and photos are under a free licence (CC BY-SA); the recipe shows who wrote it.'),
            __('Photos load directly from kochwiki.org.'),
        ];
    }

    public function credentialFields(): array
    {
        return [];
    }

    public function verifyCredentials(array $credentials): ?string
    {
        return null;
    }

    public function accountLabel(array $credentials): string
    {
        return 'Koch-Wiki';
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    public function search(string $term = '', int $limit = 25): array
    {
        return array_map(
            static fn (array $card): array => ['id' => $card['id'], 'name' => $card['name']],
            $this->browse(new RecipeQuery(term: $term, limit: $limit)),
        );
    }

    /**
     * @return array<int, array{name: string, spec: string|null}>
     */
    public function ingredients(string $recipeId): array
    {
        return $this->find($recipeId)?->ingredientList() ?? [];
    }

    public function browse(RecipeQuery $query): array
    {
        return RecipeSearch::apply($this->catalog(), $query)
            ->get()
            ->map(static fn (CatalogRecipe $recipe): array => $recipe->card())
            ->values()
            ->all();
    }

    public function recipe(string $recipeId): ?RecipeDetail
    {
        return $this->find($recipeId)?->detail();
    }

    public function categories(): array
    {
        return $this->catalog()
            ->toBase()
            ->selectRaw('jsonb_array_elements_text(categories) as category, count(*) as uses')
            ->groupBy('category')
            ->orderByDesc('uses')
            ->limit(self::CATEGORIES)
            ->pluck('category')
            ->map(static fn (mixed $category): string => (string) $category)
            ->values()
            ->all();
    }

    /** @return Builder<CatalogRecipe> */
    private function catalog(): Builder
    {
        return CatalogRecipe::query()->where('source', self::KEY);
    }

    private function find(string $recipeId): ?CatalogRecipe
    {
        return ctype_digit($recipeId) ? $this->catalog()->find($recipeId) : null;
    }
}
