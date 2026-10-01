<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Services\DirectoryBrowser;
use App\Services\UploadService;

/** Input directory browser and document upload. */
final class FileController extends Controller
{
    public function browse(Request $request): Response
    {
        // Path traversal is rejected by PathGuard (InvalidArgumentException -> 400).
        return Response::json((new DirectoryBrowser())->list($request->query('path', '')));
    }

    public function upload(Request $request): Response
    {
        $saved = (new UploadService())->store($request->bodyString('path', '', 1024), $request->files);

        return Response::json(['uploaded' => $saved], 201);
    }
}
