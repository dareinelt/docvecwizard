<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Http\JsonClient;

/** Client for the embedding microservice (FastAPI). */
final class EmbeddingClient
{
    private JsonClient $client;

    public function __construct()
    {
        $host = Config::string('EMBEDDING_HOST', 'embedding');
        $port = Config::string('EMBEDDING_PORT', '8000');
        $this->client = new JsonClient(sprintf('http://%s:%s', $host, $port), 300);
    }

    /** @return array<string,mixed> */
    public function health(): array
    {
        return $this->client->get('/health');
    }

    /** @return array<string,mixed> */
    public function activeModel(): array
    {
        return $this->client->get('/model/active');
    }

    /** @return array<string,mixed> */
    public function models(): array
    {
        return $this->client->get('/model');
    }

    /** @param array<string,mixed> $payload */
    public function activate(string $name): array
    {
        return $this->client->post('/model/active', ['name' => $name]);
    }

    /** @param list<string> $texts @return list<list<float>> vectors in the same order as $texts */
    public function embedBatch(array $texts): array
    {
        $response = $this->client->post('/embed/batch', ['texts' => $texts]);
        $embeddings = $response['embeddings'] ?? [];

        return is_array($embeddings) ? $embeddings : [];
    }
}
