<?php

declare(strict_types=1);

namespace Modules\Core\Controllers;

use CodeIgniter\HTTP\RedirectResponse;
use Modules\PublicSite\Services\PublicScheduleService;

class Home extends BaseController
{
    public function index(): string|RedirectResponse
    {
        // A judge or expert who is checked in (the tab closed, the phone dropped off the network, someone opened the
        // site again) goes straight back to the voting screen. ?home=1 still shows the home page.
        $sess = session('reviewer');
        if (is_array($sess) && $this->request->getGet('home') === null && service('checkins')->validateSession($sess) !== null) {
            return redirect()->to(site_url('review'));
        }
        $svc    = new PublicScheduleService();
        $slams  = $svc->listPublished(true);
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
            $coming[] = $s;
        }

        return view('Modules\Core\Views\home', ['coming' => $coming, 'past' => array_reverse($past), 'live' => $svc->live(true), 'results' => service('results')->published(true), 'title' => 'Kerala Science Slam', 'indexable' => true]);
    }

    public function about(): string
    {
        return view('Modules\Core\Views\about', ['title' => 'About · Kerala Science Slam', 'indexable' => true]);
    }
}
