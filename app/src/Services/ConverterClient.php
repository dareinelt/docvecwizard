<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Http\JsonClient;

/** Client for the document-converter microservice (FastAPI). */
final class ConverterClient
{
    private JsonClient $client;

    public function __construct()
    {
        $host = Config::string('CONVERTER_HOST', 'converter');
        $port = Config::string('CONVERTER_PORT', '8001');
        $this->client = new JsonClient(sprintf('http://%s:%s', $host, $port), 300);
    }

    /** @return array<string,mixed> */
    public function health(): array
    {
        return $this->client->get('/health');
    }

    /**
     * Convert a file to plain text.
     *
     * @return array{text:string,metadata:array<string,mixed>}
     */
    public function convert(string $absolutePath): array
    {
        return $this->client->post('/convert', ['path' => $absolutePath]);
    }
}
