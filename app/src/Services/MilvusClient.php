<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Http\JsonClient;

/**
 * Client for the Milvus RESTful v2 API (standalone, served on the gRPC/proxy
 * port 19530). Collection-per-model strategy: each embedding model gets its own
 * collection named `docvec_{sanitized-model-name}` with a dimension matching the model.
 */
final class MilvusClient
{
    private JsonClient $client;

    private JsonClient $healthClient;

    public function __construct()
    {
        $host = Config::string('MILVUS_HOST', 'milvus');
        $port = Config::string('MILVUS_PORT', '19530');
        $this->client = new JsonClient(sprintf('http://%s:%s', $host, $port), 120);

        // Milvus serves the RESTful v2 API on the gRPC/proxy port (19530) but the
        // liveness probe `/healthz` on the metrics port (9091). Keep them separate.
        $metricsPort = Config::string('MILVUS_METRICS_PORT', '9091');
        $this->healthClient = new JsonClient(sprintf('http://%s:%s', $host, $metricsPort), 120);
    }

    public function health(): bool
    {
        try {
            $result = $this->healthClient->raw('GET', '/healthz');

            return $result['status'] === 200;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function collectionFor(string $modelName): string
    {
        $slug = preg_replace('/[^A-Za-z0-9]+/', '_', strtolower($modelName));
        $slug = trim((string) $slug, '_');

        return 'docvec_' . ($slug === '' ? 'default' : $slug);
    }

    /** @param array<string,mixed> $indexParams */
    public function createCollection(string $collection, int $dimension, string $metric, array $indexParams = []): void
    {
        $indexParams = $indexParams === [] ? [[
            'fieldName' => 'vector',
            'indexName' => 'vector_idx',
            'metricType' => strtoupper($metric),
            'indexType' => 'AUTOINDEX',
            'params' => (object) [],
        ]] : $indexParams;

        $this->assertOk($this->client->post('/v2/vectordb/collections/create', [
            'collectionName' => $collection,
            'dbName' => 'default',
            'schema' => [
                'autoID' => true,
                'fields' => [
                    ['fieldName' => 'id', 'dataType' => 'Int64', 'isPrimary' => true],
                    ['fieldName' => 'document_id', 'dataType' => 'VarChar', 'elementTypeParams' => ['max_length' => 36]],
                    ['fieldName' => 'chunk_index', 'dataType' => 'Int64'],
                    ['fieldName' => 'vector', 'dataType' => 'FloatVector', 'elementTypeParams' => ['dim' => $dimension]],
                ],
            ],
            'indexParams' => $indexParams,
        ]));
    }

    /** @return list<string> */
    public function listCollections(): array
    {
        // The REST API expects a JSON object (`{}`) for this endpoint; an empty
        // PHP array would encode as `[]`, which Milvus rejects.
        $response = $this->assertOk($this->client->post('/v2/vectordb/collections/list', new \stdClass()));

        $data = $response['data'] ?? [];
        $names = [];
        foreach ($data as $row) {
            // `data` is a flat list of collection-name strings.
            if (is_string($row)) {
                $names[] = $row;
            }
        }

        return $names;
    }

    public function hasCollection(string $collection): bool
    {
        return in_array($collection, $this->listCollections(), true);
    }

    /** @return array<string,mixed>|null */
    public function describeCollection(string $collection): ?array
    {
        try {
            return $this->assertOk($this->client->post('/v2/vectordb/collections/describe', ['collectionName' => $collection]));
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed> */
    public function collectionStats(string $collection): array
    {
        return $this->assertOk($this->client->post('/v2/vectordb/collections/get_stats', ['collectionName' => $collection]));
    }

    /**
     * Insert rows. Each row must contain 'vector' plus metadata fields.
     *
     * @param list<array<string,mixed>> $rows
     */
    public function insert(string $collection, array $rows): void
    {
        $this->assertOk($this->client->post('/v2/vectordb/entities/insert', [
            'collectionName' => $collection,
            'data' => $rows,
        ]));
    }

    /**
     * Flush a collection so freshly inserted vectors move into sealed segments
     * and become visible to `get_stats` (rowCount) and to count-based queries.
     */
    public function flush(string $collection): void
    {
        $this->assertOk($this->client->post('/v2/vectordb/collections/flush', [
            'collectionName' => $collection,
        ]));
    }

    /** @param list<list<float>> $vectors @param array<string,mixed> $searchParams @return array<string,mixed> */
    public function search(string $collection, array $vectors, int $limit, string $metric, array $searchParams = []): array
    {
        $searchParams = $searchParams === [] ? ['metricType' => strtoupper($metric)] : $searchParams;

        return $this->assertOk($this->client->post('/v2/vectordb/entities/search', [
            'collectionName' => $collection,
            'data' => $vectors,
            'annsField' => 'vector',
            'limit' => $limit,
            'outputFields' => ['document_id', 'chunk_index'],
            'searchParams' => $searchParams,
        ]));
    }

    public function deleteByFilter(string $collection, string $filter): void
    {
        $this->assertOk($this->client->post('/v2/vectordb/entities/delete', [
            'collectionName' => $collection,
            'filter' => $filter,
        ]));
    }

    public function dropCollection(string $collection): void
    {
        $this->assertOk($this->client->post('/v2/vectordb/collections/drop', ['collectionName' => $collection]));
    }

    /**
     * Milvus v2 returns HTTP 200 even for logical errors, with a `{code, message}`
     * body. Throw on non-zero codes so failures surface instead of being swallowed.
     *
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private function assertOk(array $response): array
    {
        if (isset($response['code']) && (int) $response['code'] !== 0) {
            throw new \RuntimeException(sprintf(
                'Milvus error %s: %s',
                (string) $response['code'],
                (string) ($response['message'] ?? 'unknown error')
            ));
        }

        return $response;
    }
}
