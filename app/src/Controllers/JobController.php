<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Services\JobService;

/** Import jobs. */
final class JobController extends Controller
{
    public function index(Request $request): Response
    {
        $limit = $request->queryInt('limit', 100, 1, 500);
        $offset = $request->queryInt('offset', 0, 0, PHP_INT_MAX);

        return Response::json(['jobs' => (new JobService())->list($limit, $offset)]);
    }

    public function create(Request $request): Response
    {
        // FIX: inputs are type-checked (arrays are rejected instead of being
        // cast to "Array") and the service validates model + source directory.
        $job = (new JobService())->create([
            'name' => $request->bodyString('name', '', 255),
            'source_directory' => $request->bodyString('source_directory', '', 1024),
            'recursive' => $request->bodyBool('recursive', true),
            'embedding_model' => $request->bodyString('embedding_model', '', 191),
        ]);

        return Response::json(['job' => $job], 201);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params): Response
    {
        $job = (new JobService())->getByUuid($this->uuidParam($params, 'id', 'Job not found'));
        if ($job === null) {
            throw HttpException::notFound('Job not found');
        }

        return Response::json(['job' => $job]);
    }

    /** @param array<string,string> $params */
    public function cancel(Request $request, array $params): Response
    {
        $jobId = $this->uuidParam($params, 'id', 'Job not found');
        $service = new JobService();
        $job = $service->getByUuid($jobId);
        if ($job === null) {
            throw HttpException::notFound('Job not found');
        }
        $service->cancel((int) $job['id']);

        return Response::json(['job' => $service->getByUuid($jobId)]);
    }

    /** @param array<string,string> $params */
    public function resume(Request $request, array $params): Response
    {
        $jobId = $this->uuidParam($params, 'id', 'Job not found');
        $service = new JobService();
        $job = $service->getByUuid($jobId);
        if ($job === null) {
            throw HttpException::notFound('Job not found');
        }
        $service->resume((int) $job['id']);

        return Response::json(['job' => $service->getByUuid($jobId)]);
    }
}
