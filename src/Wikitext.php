<?php

declare(strict_types=1);

namespace Tagwerk\Kochwiki;

use Tagwerk\PluginApi\Recipes\Ingredient;

/**
 * A Koch-Wiki page into a recipe: the `{{Rezept}}` box, the sections
 * "Zutaten" and "Zubereitung", the categories — and wikitext into Markdown.
 *
 * Deliberately small. Whatever is not understood is dropped rather than
 * shown as markup: a template nobody renders is noise in a cooking view.
 */
final class Wikitext
{
    private const INGREDIENTS = 'zutaten';

    private const PREPARATION = 'zubereitung';

    /**
     * Columns of `catalog_recipes`, without `photo` and `license` — those
     * come from the file page. Null when the page is no recipe.
     *
     * @return array<string, mixed>|null
     */
    public static function recipe(string $title, string $text): ?array
    {
        if (preg_match('/\{\{\s*Rezept\s*\|(.*?)\}\}/su', $text, $box) !== 1) {
            return null;
        }

        $fields = self::fields($box[1]);
        $text = str_replace($box[0], '', $text);
        $categories = self::categories($text);
        [$intro, $sections] = self::sections(self::strip($text));

        return [
            'title' => $title,
            'intro' => self::markdown($intro, steps: false),
            'steps' => self::markdown($sections[self::PREPARATION] ?? '', steps: true),
            'ingredients' => array_map(
                static fn (Ingredient $ingredient): array => $ingredient->toArray(),
                self::ingredients($sections[self::INGREDIENTS] ?? ''),
            ),
            'servings' => self::servings($fields['menge'] ?? ''),
            'minutes' => self::minutes($fields['zeit'] ?? ''),
            'difficulty' => self::difficulty($fields['schwierigkeit'] ?? ''),
            'categories' => array_slice($categories, 0, 12),
            'vegetarian' => self::vegetarian($categories),
            'image' => self::image($fields['bild'] ?? ''),
        ];
    }

    /** @return array<string, string> lower-case keys */
    private static function fields(string $box): array
    {
        $fields = [];

        foreach (explode('|', $box) as $part) {
            if (str_contains($part, '=')) {
                [$key, $value] = array_map(trim(...), explode('=', $part, 2));
                $fields[mb_strtolower($key)] = $value;
            }
        }

        return $fields;
    }

    /** @return list<string> */
    private static function categories(string $text): array
    {
        preg_match_all('/\[\[\s*Kategorie\s*:\s*([^\]|]+)/iu', $text, $matches);

        return array_values(array_unique(array_map(trim(...), $matches[1])));
    }

    /**
     * Text before the first heading, and the level-two sections by name.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private static function sections(string $text): array
    {
        $parts = preg_split('/^==\s*([^=].*?)\s*==\s*$/mu', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $intro = (string) array_shift($parts);
        $sections = [];

        for ($index = 0; $index + 1 < count($parts); $index += 2) {
            $name = mb_strtolower(trim($parts[$index], " :\t"));
            $sections[$name] = ($sections[$name] ?? '').$parts[$index + 1];
        }

        return [$intro, $sections];
    }

    /** @return list<Ingredient> */
    private static function ingredients(string $section): array
    {
        $list = [];
        $group = null;

        foreach (preg_split('/\R/u', $section) ?: [] as $line) {
            if (preg_match('/^=+\s*(.*?)\s*=+\s*$/u', $line, $heading) === 1) {
                $group = self::plain($heading[1]) ?: null;

                continue;
            }

            if (preg_match('/^[*#]+\s*(.*)$/u', $line, $item) !== 1) {
                continue;
            }

            $ingredient = Ingredient::parse(self::plain($item[1]), $group);

            if ($ingredient !== null && mb_strlen($ingredient->name) <= 255) {
                $list[] = $ingredient;
            }
        }

        return $list;
    }

    /**
     * Wikitext lines into the Markdown subset. In the preparation, bullets
     * become numbered steps — Koch-Wiki writes steps as bullets, and only a
     * numbered list can be ticked off while cooking.
     */
    private static function markdown(string $section, bool $steps): ?string
    {
        $lines = [];
        $number = 0;

        foreach (preg_split('/\R/u', $section) ?: [] as $line) {
            $line = rtrim($line);

            if (preg_match('/^=+\s*(.*?)\s*=+$/u', $line, $heading) === 1) {
                $lines[] = '';
                $lines[] = '## '.self::inline($heading[1]);
                $lines[] = '';
                $number = 0;

                continue;
            }

            if (preg_match('/^([*#]+)\s*(.*)$/u', $line, $item) === 1) {
                $depth = mb_strlen($item[1]);
                $content = self::inline($item[2]);

                if ($content === '') {
                    continue;
                }

                if ($depth === 1 && ($steps || $item[1] === '#')) {
                    $lines[] = (++$number).'. '.$content;
                } else {
                    $lines[] = str_repeat('   ', $depth - 1).'- '.$content;
                }

                continue;
            }

            $content = self::inline($line);

            if ($content === '') {
                $lines[] = '';

                continue;
            }

            // A paragraph between steps ends the list; the next one starts at 1 again.
            if ($number > 0 && end($lines) !== '') {
                $lines[] = '';
            }

            $number = 0;
            $lines[] = $content;
        }

        $markdown = trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));

        return $markdown === '' ? null : $markdown;
    }

    /** Removes what is never content: comments, galleries, tables, references, files. */
    private static function strip(string $text): string
    {
        $text = (string) preg_replace('/<!--.*?-->/su', '', $text);
        $text = (string) preg_replace('/<(gallery|ref|references|inputbox|nowiki)\b[^>]*>.*?<\/\1>/siu', '', $text);
        $text = (string) preg_replace('/<ref\b[^>]*\/>/iu', '', $text);
        $text = (string) preg_replace('/^\{\|.*?^\|\}/smu', '', $text);

        return self::files($text);
    }

    /** `[[Datei:…|thumb|caption with [[links]]]]` — nested brackets included. */
    private static function files(string $text): string
    {
        return (string) preg_replace('/\[\[\s*(?:Datei|Bild|File|Image)\s*:(?:[^\[\]]|\[\[[^\]]*\]\])*\]\]/iu', '', $text);
    }

    /** Inline wikitext as Markdown. */
    private static function inline(string $text): string
    {
        $text = self::common($text);

        // External links: [https://x label] — only https survives as a link.
        $text = (string) preg_replace_callback('/\[(https?:\/\/[^\s\]]+)(?:\s+([^\]]*))?\]/u', static function (array $match): string {
            $label = trim($match[2] ?? '') !== '' ? trim($match[2]) : $match[1];

            return str_starts_with($match[1], 'https://') ? '['.$label.']('.$match[1].')' : $label;
        }, $text);

        $text = (string) preg_replace("/'''(.+?)'''/u", '**$1**', $text);
        $text = (string) preg_replace("/''(.+?)''/u", '*$1*', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** Inline wikitext as plain text — for ingredient lines. */
    private static function plain(string $text): string
    {
        $text = self::common($text);
        $text = (string) preg_replace('/\[https?:\/\/[^\s\]]+\s+([^\]]*)\]/u', '$1', $text);
        $text = (string) preg_replace('/\[https?:\/\/[^\]]*\]/u', '', $text);
        $text = str_replace(["'''", "''"], '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** What inline and plain share: templates, internal links, tags, entities. */
    private static function common(string $text): string
    {
        $text = self::files(self::strip($text));
        $text = (string) preg_replace('/\{\{\s*Grad\s*\|\s*([^}|]+?)\s*\}\}/iu', '$1 °C', $text);

        // Templates nest: remove the innermost until none is left.
        do {
            $before = $text;
            $text = (string) preg_replace('/\{\{[^{}]*\}\}/u', '', $text);
        } while ($text !== $before);

        $text = (string) preg_replace('/\[\[\s*:?\s*Kategorie\s*:[^\]|]*\]\]/iu', '', $text);
        $text = (string) preg_replace('/\[\[[^\]|]*\|([^\]]*)\]\]/u', '$1', $text);
        $text = (string) preg_replace_callback('/\[\[\s*:?([^\]]*)\]\]/u', static fn (array $match): string => (string) preg_replace('/^[^:]+:/u', '', $match[1]), $text);
        $text = (string) preg_replace('/<br\s*\/?>/iu', ' ', $text);
        $text = strip_tags($text);

        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function servings(string $value): int
    {
        return preg_match('/\d+/', $value, $match) === 1 ? max(1, min(50, (int) $match[0])) : 4;
    }

    /** "35 Minuten", "1 Stunde 30 Minuten", "1½ Std.", "2 h". */
    private static function minutes(string $value): ?int
    {
        $value = str_replace(['½', ','], ['.5', '.'], mb_strtolower($value));
        $minutes = 0.0;

        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:tag|tage)\b/u', $value, $days) === 1) {
            $minutes += (float) $days[1] * 1440;
        }

        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:stunden?|std\.?|h\b)/u', $value, $hours) === 1) {
            $minutes += (float) $hours[1] * 60;
        }

        if (preg_match('/(\d+)\s*(?:minuten|min\.?)/u', $value, $match) === 1) {
            $minutes += (float) $match[1];
        }

        return $minutes > 0 ? (int) min(2880, round($minutes)) : null;
    }

    private static function difficulty(string $value): ?string
    {
        $value = mb_strtolower($value);

        return match (true) {
            str_contains($value, 'schwer') || str_contains($value, 'anspruchsvoll') => 'hard',
            str_contains($value, 'mittel') => 'medium',
            str_contains($value, 'leicht') || str_contains($value, 'einfach') => 'easy',
            default => null,
        };
    }

    /**
     * True when a category says so. Unknown is null, not false: a recipe
     * without the category is not proven to contain meat.
     *
     * @param  list<string>  $categories
     */
    private static function vegetarian(array $categories): ?bool
    {
        foreach ($categories as $category) {
            if (preg_match('/vegetarisch|vegan/iu', $category) === 1) {
                return true;
            }
        }

        return null;
    }

    private static function image(string $value): ?string
    {
        $value = trim((string) preg_replace('/^(?:Datei|Bild|File)\s*:/iu', '', trim($value)));

        return $value === '' ? null : str_replace('_', ' ', $value);
    }

    /**
     * Author and licence of a file page's `{{Information}}` and licence
     * section.
     *
     * @return array{author: string|null, license: string|null}
     */
    public static function fileCredit(string $text): array
    {
        $author = preg_match('/\|\s*Author\s*=\s*(.*)$/mu', $text, $match) === 1 ? self::plain($match[1]) : '';

        $license = match (true) {
            preg_match('/cc-by-sa/iu', $text) === 1 => 'CC BY-SA',
            preg_match('/cc-by/iu', $text) === 1 => 'CC BY',
            preg_match('/\bgfdl\b/iu', $text) === 1 => 'GFDL',
            preg_match('/\{\{\s*(?:pd|gemeinfrei)/iu', $text) === 1 => 'Public domain',
            default => null,
        };

        return ['author' => $author === '' ? null : $author, 'license' => $license];
    }
}
