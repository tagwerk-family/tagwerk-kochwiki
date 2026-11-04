<?php

declare(strict_types=1);

namespace Tagwerk\Kochwiki;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * The MediaWiki API of kochwiki.org, used politely.
 *
 * Koch-Wiki is run by volunteers. One request at a time, a second apart, a
 * User-Agent that says who asks and how to reach them, and `maxlag` so that
 * a busy database is left alone. A whole mirror is a few hundred requests.
 */
final class KochwikiClient
{
    public const API = 'https://www.kochwiki.org/w/api.php';

    public const SITE = 'https://www.kochwiki.org';

    private const RETRIES = 5;

    private bool $first = true;

    /**
     * Every recipe page with its latest revision: page id → [title, revision].
     *
     * @return array<int, array{title: string, revision: int}>
     */
    public function pages(): array
    {
        $pages = [];
        $continue = [];

        do {
            $data = $this->get([
                'generator' => 'allpages',
                'gapnamespace' => 0,
                'gapfilterredir' => 'nonredirects',
                'gaplimit' => 500,
                'prop' => 'info',
                ...$continue,
            ]);

            foreach ($data['query']['pages'] ?? [] as $page) {
                $pages[(int) $page['pageid']] = ['title' => (string) $page['title'], 'revision' => (int) ($page['lastrevid'] ?? 0)];
            }

            $continue = is_array($data['continue'] ?? null) ? $data['continue'] : [];
        } while ($continue !== []);

        return $pages;
    }

    /**
     * Wikitext of up to fifty pages: page id → [title, revision, text].
     *
     * @param  list<int>  $ids
     * @return array<int, array{title: string, revision: int, text: string}>
     */
    public function contents(array $ids): array
    {
        $data = $this->get([
            'pageids' => implode('|', array_slice($ids, 0, 50)),
            'prop' => 'revisions',
            'rvprop' => 'ids|content',
            'rvslots' => 'main',
        ]);

        $pages = [];

        foreach ($data['query']['pages'] ?? [] as $page) {
            $revision = $page['revisions'][0] ?? null;

            if (is_array($revision)) {
                $pages[(int) $page['pageid']] = [
                    'title' => (string) $page['title'],
                    'revision' => (int) $revision['revid'],
                    'text' => (string) ($revision['slots']['main']['content'] ?? ''),
                ];
            }
        }

        return $pages;
    }

    /**
     * Thumbnails and credit of up to fifty files, by file name.
     *
     * @param  list<string>  $files
     * @return array<string, array{small: string, large: string, page: string, author: string|null, license: string|null}>
     */
    public function images(array $files): array
    {
        $data = $this->get([
            'titles' => implode('|', array_map(static fn (string $file): string => 'Datei:'.$file, array_slice($files, 0, 50))),
            'prop' => 'imageinfo|revisions',
            'iiprop' => 'url|user',
            'iiurlwidth' => 400,
            'rvprop' => 'content',
            'rvslots' => 'main',
        ]);

        // The API answers with the normalised title; map back to what was asked.
        $asked = [];

        foreach ($data['query']['normalized'] ?? [] as $pair) {
            $asked[(string) $pair['to']] = (string) $pair['from'];
        }

        $images = [];

        foreach ($data['query']['pages'] ?? [] as $page) {
            $info = $page['imageinfo'][0] ?? null;
            $small = $info['thumburl'] ?? $info['url'] ?? null;

            if (! is_string($small) || ! str_starts_with($small, self::SITE.'/')) {
                continue;
            }

            $title = $asked[(string) $page['title']] ?? (string) $page['title'];
            $credit = Wikitext::fileCredit((string) ($page['revisions'][0]['slots']['main']['content'] ?? ''));

            $images[str_replace('_', ' ', (string) preg_replace('/^Datei:/u', '', $title))] = [
                'small' => $small,
                // Thumbnails are made on request at any width; 800 for the open recipe.
                'large' => str_replace('/400px-', '/800px-', $small),
                'page' => (string) ($info['descriptionurl'] ?? ''),
                'author' => $credit['author'] ?? (is_string($info['user'] ?? null) ? $info['user'] : null),
                'license' => $credit['license'],
            ];
        }

        return $images;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(array $query): array
    {
        for ($attempt = 1; ; $attempt++) {
            if (! $this->first) {
                Sleep::for(1)->second();
            }

            $this->first = false;

            $response = Http::withUserAgent($this->userAgent())
                ->acceptJson()
                ->timeout(30)
                ->get(self::API, [
                    'action' => 'query',
                    'format' => 'json',
                    'formatversion' => 2,
                    'maxlag' => 5,
                    ...$query,
                ]);

            $lagged = $response->json('error.code') === 'maxlag' || $response->status() === 503;

            if ($lagged && $attempt < self::RETRIES) {
                Sleep::for(max(5, (int) $response->header('Retry-After')))->seconds();

                continue;
            }

            if (! $response->successful() || $response->json('error') !== null) {
                throw new RuntimeException('Koch-Wiki answered '.$response->status().': '.(string) $response->json('error.info', ''));
            }

            /** @var array<string, mixed> */
            return $response->json();
        }
    }

    private function userAgent(): string
    {
        $contact = (string) config('mail.from.address');

        return 'Tagwerk/1.0 (+'.config('app.url').($contact !== '' ? '; '.$contact : '').')';
    }
}
