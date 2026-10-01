<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Services\TlsService;

/** TLS certificate management. */
final class TlsController extends Controller
{
    public function status(Request $request): Response
    {
        return Response::json((new TlsService())->status());
    }

    public function csr(Request $request): Response
    {
        [$commonName, $san, $keyType] = $this->subject($request);

        return Response::json(['certificate' => (new TlsService())->createCsr($commonName, $san, $keyType)], 201);
    }

    public function selfSigned(Request $request): Response
    {
        [$commonName, $san, $keyType] = $this->subject($request);

        return Response::json(['certificate' => (new TlsService())->generateSelfSigned($commonName, $san, $keyType)], 201);
    }

    public function import(Request $request): Response
    {
        $certPem = $request->bodyString('cert_pem', '', TlsService::MAX_PEM_BYTES);
        if (trim($certPem) === '') {
            throw HttpException::badRequest('Missing cert_pem');
        }

        return Response::json(['certificate' => (new TlsService())->importCert($certPem)]);
    }

    /** @param array<string,string> $params */
    public function activate(Request $request, array $params): Response
    {
        $service = new TlsService();
        $service->activate($this->intParam($params, 'id', 'Certificate not found'));

        return Response::json(['status' => $service->status()]);
    }

    /** @return array{0:string,1:list<string>,2:string} */
    private function subject(Request $request): array
    {
        $san = $request->bodyField('san', []);
        if (is_string($san)) {
            $san = $san === '' ? [] : preg_split('/\s*,\s*/', $san);
        }
        if (!is_array($san)) {
            throw HttpException::badRequest('Field "san" must be a list');
        }
        // SECURITY: CN and SAN entries are strictly validated in
        // TlsService::validateSubject() (OpenSSL config injection fix).
        return [
            $request->bodyString('common_name', '', 64),
            array_values($san),
            $request->bodyString('key_type', 'rsa3072', 16),
        ];
    }
}
