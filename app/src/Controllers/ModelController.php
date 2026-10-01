<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Services\Audit;
use App\Services\EmbeddingClient;
use App\Services\ModelService;

/** Embedding model catalogue. */
final class ModelController extends Controller
{
    public function index(Request $request): Response
    {
        return Response::json(['models' => (new ModelService())->all()]);
    }

    public function activate(Request $request): Response
    {
        $name = trim($request->bodyString('name', '', 191));
        if ($name === '') {
            throw HttpException::badRequest('Missing model name');
        }
        // Unknown models raise InvalidArgumentException (-> 400); embedding
        // service failures raise UpstreamException (-> generic 502).
        return Response::json(['model' => (new ModelService())->activate($name)]);
    }

    public function sync(Request $request): Response
    {
        $count = (new ModelService())->syncFromCatalog(new EmbeddingClient());
        Audit::record('model.sync', 'embedding_model', null, ['synced' => $count]);

        return Response::json(['synced' => $count]);
    }
}
