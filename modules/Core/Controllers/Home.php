<?php

declare(strict_types=1);

namespace Modules\Core\Controllers;

class Home extends BaseController
{
    public function index(): string
    {
        return view('Modules\Core\Views\home', [
            'slams' => (new \Modules\PublicSite\Services\PublicScheduleService())->listPublished(),
        ]);
    }
}
