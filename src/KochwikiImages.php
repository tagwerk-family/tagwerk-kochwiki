<?php

declare(strict_types=1);

namespace Tagwerk\Kochwiki;

use Tagwerk\PluginApi\Security\ContributesToCsp;

/** Photos are shown from kochwiki.org, not copied. */
final class KochwikiImages implements ContributesToCsp
{
    public function sources(): array
    {
        return ['img-src' => [KochwikiClient::SITE]];
    }
}
