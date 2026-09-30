<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Uuid;

final class ModelService
{
    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return Db::fetchAll(
            'SELECT id, name, version, repo, parameters, dimension, max_input_tokens,
                    normalization, distance_metric, active, created_at, updated_at
             FROM embedding_models ORDER BY name'
        );
    }

    /** @return array<string,mixed>|null */
    public function findByName(string $name): ?array
    {
        return Db::fetchOne(
            'SELECT id, name, version, repo, parameters, dimension, max_input_tokens,
                    normalization, distance_metric, active, created_at, updated_at
             FROM embedding_models WHERE name = ?',
            [$name]
        );
    }

    /** @return array<string,mixed>|null */
    public function active(): ?array
    {
        return Db::fetchOne(
            'SELECT id, name, version, repo, parameters, dimension, max_input_tokens,
                    normalization, distance_metric, active, created_at, updated_at
             FROM embedding_models WHERE active = 1 LIMIT 1'
        );
    }

    /**
     * Sync the local embedding_models table from the embedding service catalog.
     * Called by the worker at startup and on-demand so the dimension metadata is
     * always authoritative (never hard-coded).
     */
    public function syncFromCatalog(EmbeddingClient $embedding): int
    {
        $catalog = $embedding->models();
        $models = $catalog['available'] ?? [];
        $count = 0;
        foreach ($models as $model) {
            if (!is_array($model) || empty($model['name'])) {
                continue;
            }
            $exists = Db::fetchOne('SELECT id FROM embedding_models WHERE name = ?', [$model['name']]);
            $params = [
                'name' => (string) $model['name'],
                'version' => (string) ($model['version'] ?? '1.0'),
                'repo' => (string) ($model['repo'] ?? ''),
                'parameters' => (string) ($model['parameters'] ?? ''),
                'dimension' => (int) ($model['dimension'] ?? 0),
                'max_input_tokens' => (int) ($model['max_input_tokens'] ?? 32768),
                'normalization' => (string) ($model['normalization'] ?? 'l2'),
                'distance_metric' => (string) ($model['distance_metric'] ?? 'cosine'),
            ];
            if ($exists) {
                Db::execute(
                    'UPDATE embedding_models SET version=?, repo=?, parameters=?, dimension=?,
                            max_input_tokens=?, normalization=?, distance_metric=? WHERE name=?',
                    [
                        $params['version'], $params['repo'], $params['parameters'], $params['dimension'],
                        $params['max_input_tokens'], $params['normalization'], $params['distance_metric'],
                        $params['name'],
                    ]
                );
            } else {
                Db::execute(
                    'INSERT INTO embedding_models (name, version, repo, parameters, dimension, max_input_tokens, normalization, distance_metric)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $params['name'], $params['version'], $params['repo'], $params['parameters'],
                        $params['dimension'], $params['max_input_tokens'], $params['normalization'], $params['distance_metric'],
                    ]
                );
            }
            $count++;
        }

        return $count;
    }

    /**
     * Activate a model: set the single active flag in DB and ask the embedding
     * service to load it. Returns the model row or throws on unknown model.
     *
     * @return array<string,mixed>
     */
    public function activate(string $name): array
    {
        $model = $this->findByName($name);
        if ($model === null) {
            throw new \InvalidArgumentException('Unknown embedding model: ' . $name);
        }
        $embedding = new EmbeddingClient();
        $embedding->activate($name);
        Db::transaction(function () use ($name): void {
            Db::execute('UPDATE embedding_models SET active = 0');
            Db::execute('UPDATE embedding_models SET active = 1 WHERE name = ?', [$name]);
        });
        Audit::record('model.activate', 'embedding_model', $name);

        return $this->findByName($name) ?? $model;
    }
}
