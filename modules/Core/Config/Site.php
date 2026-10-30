<?php

declare(strict_types=1);

namespace Modules\Core\Config;

use CodeIgniter\Config\BaseConfig;

/** Public site header/footer details. Override in .env as site.contactEmail etc. */
class Site extends BaseConfig
{
    public string $name        = 'Science Slam';
    public string $mainSiteUrl = 'https://scienceslam.in/';
    public string $contactEmail = 'lucaemagazine@gmail.com';

    /** Social links of Luca (luca.co.in): [label, url, icon]. */
    public array $social = [
        ['Facebook', 'https://www.facebook.com/LUCAmagazine', 'social.facebook'],
        ['Luca podcast on Spotify', 'https://open.spotify.com/show/49BxkT9wwKb7CjdU9CNt3r', 'social.spotify'],
    ];

    public string $poweredBy    = 'Alphafork';
    public string $poweredByUrl = 'https://alphafork.com/';
}
