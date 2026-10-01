<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Services\ExportService;

/** Offline export / import. */
final class ExportController extends Controller
{
    public function index(Request $request): Response
    {
        return Response::json(['exports' => (new ExportService())->list()]);
    }

    public function create(Request $request): Response
    {
        // Large exports can take minutes; do not let PHP's max_execution_time
        // abort a half-written archive (nginx timeout is raised accordingly).
        @set_time_limit(0);
        ignore_user_abort(true);

        return Response::json(['export' => (new ExportService())->create()], 201);
    }

    /** @param array<string,string> $params */
    public function download(Request $request, array $params): Response
    {
        $exportId = $this->uuidParam($params, 'id', 'Export not found');
        $path = (new ExportService())->download($exportId);
        if ($path === null) {
            throw HttpException::notFound('Export not found');
        }

        // FIX: streamed with readfile() instead of file_get_contents(), which
        // loaded multi-GB archives into memory (app mem_limit is 512 MB).
        return Response::file($path, basename($path), 'application/gzip');
    }

    public function import(Request $request): Response
    {
        $strategy = $request->bodyString('strategy', 'skip', 16);
        if (!in_array($strategy, ['skip', 'overwrite'], true)) {
            throw HttpException::badRequest('Unknown conflict strategy');
        }

        // Archive upload (exported .tar.gz) -> full, verified, ID-preserving import.
        if ($request->files !== []) {
            $file = $request->files[0];
            // FIX: the upload status was never checked (a failed or oversized
            // upload produced a confusing PharData error).
            if (!$file->isOk()) {
                throw HttpException::badRequest($file->errorMessage());
            }
            @set_time_limit(0);
            ignore_user_abort(true);

            return Response::json((new ExportService())->importArchive($file->tmpName, ['strategy' => $strategy]));
        }

        // Legacy JSON manifest path (backwards compatible).
        $manifest = $request->bodyField('manifest');
        if (!is_array($manifest)) {
            throw HttpException::badRequest('Expected an export archive upload or "manifest" object');
        }

        return Response::json((new ExportService())->import($manifest));
    }
}
