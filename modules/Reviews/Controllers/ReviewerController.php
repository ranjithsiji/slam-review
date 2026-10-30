<?php

declare(strict_types=1);

namespace Modules\Reviews\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use Modules\Core\Controllers\BaseController;

/** The reviewer's phone screen (Vue) and its JSON endpoints. */
class ReviewerController extends BaseController
{
    private function attendee(): array
    {
        $s = session('reviewer');

        return ['id' => (int) $s['attendee_id'], 'slam_id' => (int) $s['slam_id'], 'badge_id' => $s['badge_id'], 'name' => $s['name'], 'user_type' => $s['user_type']];
    }

    public function page(): string
    {
        $a = $this->attendee();

        return view('Modules\Reviews\Views\reviewer', [
            'boot' => ['me' => ['name' => $a['name'], 'badge_id' => $a['badge_id'], 'user_type' => $a['user_type']], 'welcome' => session('welcome')],
        ]);
    }

    public function thanks(): string
    {
        return view('Modules\Reviews\Views\thanks', ['message' => session('thanks') ?? 'Thank you for reviewing!']);
    }

    /** Polled every few seconds: ETag/304, session released early, tick on the cheap path. */
    public function state(): ResponseInterface
    {
        $a = $this->attendee();
        session()->close();
        service('slamPhase')->tick($a['slam_id']);
        $state = service('liveState')->forReviewer($a);
        $this->response->setHeader('ETag', $state['etag'])->setHeader('Cache-Control', 'no-cache, private');
        if (trim($this->request->getHeaderLine('If-None-Match')) === $state['etag']) {
            return $this->response->setStatusCode(304)->setHeader('X-Server-Now', $state['body']['server_now']);
        }

        return $this->json($state['body']);
    }

    public function submit(): ResponseInterface
    {
        $in = $this->input();
        $a  = $this->attendee();

        return $this->api(fn () => service('reviews')->submit($a, (int) ($in['schedule_item_id'] ?? 0), $in['answers'] ?? null, $in['comment'] ?? null, $this->actor()) + ['ok' => true]);
    }

    public function mine(): ResponseInterface
    {
        $a = $this->attendee();

        return $this->api(function () use ($a) {
            session()->close();
            $slam = service('slams')->find($a['slam_id']);

            return ['editable' => $slam['status'] === 'revision', 'data' => service('reviews')->myReviews($a)];
        });
    }

    public function revise(int $id): ResponseInterface
    {
        $in = $this->input();

        return $this->api(fn () => service('reviews')->revise($this->attendee(), $id, $in['answers'] ?? null, $in['comment'] ?? null, $this->actor()) + ['ok' => true]);
    }
}
