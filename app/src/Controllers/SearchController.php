<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Services\SearchService;

/** Semantic search. */
final class SearchController extends Controller
{
    public function search(Request $request): Response
    {
        $query = $request->bodyString('query', '', SearchService::MAX_QUERY_LENGTH);
        $limit = filter_var($request->bodyField('limit', 10), FILTER_VALIDATE_INT);

        return Response::json(['results' => (new SearchService())->search($query, $limit === false ? 10 : $limit)]);
    }
}
