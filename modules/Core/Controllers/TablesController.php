<?php

declare(strict_types=1);

namespace Modules\Core\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/** POST /api/tables/{name} (DataTables server-side) and filtered CSV exports. */
class TablesController extends BaseController
{
    public function data(string $name): ResponseInterface
    {
        return $this->api(function () use ($name) {
            session()->close(); // read-only: do not hold the session lock

            return service('dataTables')->handle($name, $this->input(), $this->actor());
        });
    }

    public function export(string $name): ResponseInterface
    {
        $req = [
            'filters' => (array) ($this->request->getGet('filters') ?? []),
            'search'  => ['value' => (string) $this->request->getGet('search')],
            'order'   => (array) ($this->request->getGet('order') ?? []),
            'columns' => (array) ($this->request->getGet('columns') ?? []),
        ];
        try {
            $csv = service('dataTables')->exportCsv($name, $req, $this->actor());
        } catch (\Modules\Core\Libraries\DomainException $e) {
            return $this->jsonError($e);
        }

        return $this->download($csv, $name . '-' . date('Ymd-His') . '.csv', 'text/csv; charset=utf-8');
    }
}
