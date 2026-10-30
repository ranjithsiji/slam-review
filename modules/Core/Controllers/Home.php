<?php

declare(strict_types=1);

namespace Modules\Core\Controllers;

use Modules\PublicSite\Services\PublicScheduleService;

class Home extends BaseController
{
    public function index(): string
    {
        $svc    = new PublicScheduleService();
        $slams  = $svc->listPublished();
        $coming = [];
        $past   = [];
        foreach ($slams as $s) {
            if ($s['badge'] === 'finished') {
                $past[] = $s;
                continue;
            }
            if ($s['badge'] === 'live') {
                service('slamPhase')->tick((int) $s['id']);
            }
            $s['doc'] = $svc->document(service('slams')->find((int) $s['id']));
            $coming[] = $s;
        }

        return view('Modules\Core\Views\home', ['coming' => $coming, 'past' => array_reverse($past), 'live' => $svc->live(), 'results' => service('results')->published(), 'title' => 'Kerala Science Slam', 'indexable' => true]);
    }

    public function about(): string
    {
        return view('Modules\Core\Views\about', ['title' => 'About · Kerala Science Slam', 'indexable' => true]);
    }
}
