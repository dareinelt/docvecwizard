<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Http\JsonClient;
use App\Http\UpstreamException;

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

    /**
     * Currently loaded model as reported by GET /model (there is no GET
     * /model/active endpoint; activation is POST /model/active).
     *
     * @return array{active:?string,info:?array<string,mixed>}
     */
    public function activeModel(): array
    {
        $response = $this->client->get('/model');
        $active = $response['active'] ?? null;
        $info = $response['active_info'] ?? null;

        return [
            'active' => is_string($active) && $active !== '' ? $active : null,
            'info' => is_array($info) ? $info : null,
        ];
    }

    /** @return array<string,mixed> */
    public function models(): array
    {
        return $this->client->get('/model');
    }

    /** @return array<string,mixed> */
    public function activate(string $name): array
    {
        return $this->client->post('/model/active', ['name' => $name]);
    }

    /**
     * Embed texts. When $model is given the service verifies it matches the
     * loaded model and answers 409 otherwise, which surfaces here as
     * ModelMismatchException (instead of vectors of the wrong dimension).
     *
     * @param list<string> $texts
     * @return list<list<float>> vectors in the same order as $texts
     * @throws ModelMismatchException
     * @throws UpstreamException
     */
    public function embedBatch(array $texts, ?string $model = null): array
    {
        $payload = ['texts' => $texts];
        if ($model !== null && $model !== '') {
            $payload['model'] = $model;
        }
        try {
            $response = $this->client->post('/embed/batch', $payload);
        } catch (UpstreamException $e) {
            if ($e->status === 409 && $model !== null) {
                $loaded = preg_match('/loaded (\S+)$/', $e->getMessage(), $m) === 1 ? $m[1] : '';
                throw new ModelMismatchException($model, $loaded);
            }
            throw $e;
        }
        $embeddings = $response['embeddings'] ?? [];

        return is_array($embeddings) ? $embeddings : [];
    }

    /**
     * Pure check that every returned vector has the expected dimension.
     *
     * @param list<mixed> $vectors
     * @throws \RuntimeException with a German, user-safe message
     */
    public static function assertDimension(array $vectors, int $expected, string $model): void
    {
        foreach ($vectors as $i => $vector) {
            $actual = is_array($vector) ? count($vector) : 0;
            if ($actual !== $expected) {
                throw new \RuntimeException(sprintf(
                    'Vektordimension %d passt nicht zur erwarteten Dimension %d des Modells "%s" (Vektor %d). Bitte aktives Modell prüfen.',
                    $actual,
                    $expected,
                    $model,
                    (int) $i
                ));
            }
        }
    }
}
