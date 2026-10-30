<?php

declare(strict_types=1);

namespace Modules\Reviews\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use Modules\Core\Controllers\BaseController;

class QuestionsController extends BaseController
{
    public function page(?int $slamId = null): string
    {
        $slam = $slamId !== null ? service('slams')->find($slamId) : null;
        $q    = service('questions');

        return $this->render('Modules\Reviews\Views\questions', [
            'title' => $slam ? 'Review questions — ' . $slam['name'] : 'Default question templates', 'slam' => $slam,
            'locked' => ['user' => $q->isLocked($slamId, 'user'), 'judge' => $q->isLocked($slamId, 'judge')],
            'questions' => ['user' => $q->list($slamId, 'user'), 'judge' => $q->list($slamId, 'judge')],
            'otherSlams' => array_values(array_filter(service('slams')->all(), static fn ($s) => (int) $s['id'] !== $slamId)),
        ]);
    }

    private function slamIdFrom(array $in): ?int
    {
        return isset($in['slam_id']) && $in['slam_id'] !== '' && $in['slam_id'] !== 'template' ? (int) $in['slam_id'] : null;
    }

    public function create(): ResponseInterface
    {
        $in = $this->input();

        return $this->api(fn () => ['id' => service('questions')->create($this->slamIdFrom($in), (string) ($in['audience'] ?? ''), $in, $this->actor())]);
    }

    public function update(int $id): ResponseInterface
    {
        $in = $this->input();

        return $this->api(function () use ($id, $in) {
            service('questions')->update($id, $in, $this->actor());

            return ['ok' => true];
        });
    }

    public function delete(int $id): ResponseInterface
    {
        return $this->api(function () use ($id) {
            service('questions')->delete($id, $this->actor());

            return ['ok' => true];
        });
    }

    public function reorder(): ResponseInterface
    {
        $in = $this->input();

        return $this->api(function () use ($in) {
            service('questions')->reorder($this->slamIdFrom($in), (string) ($in['audience'] ?? ''), (array) ($in['ids'] ?? []), $this->actor());

            return ['ok' => true];
        });
    }

    public function copyFrom(int $fromSlam): ResponseInterface
    {
        $in = $this->input();

        return $this->api(fn () => ['copied' => service('questions')->copyFromSlam($fromSlam, (int) ($in['slam_id'] ?? 0), $this->actor())]);
    }
}
