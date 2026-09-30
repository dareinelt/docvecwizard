# HTTPS / TLS-Zertifikate

## Übersicht

Der gesamte Verkehr läuft über HTTPS. Die TLS-Terminierung übernimmt nginx
(`web`-Dienst) auf Port `443` (extern `8443`).

```text
Browser ──HTTPS:8443──► web (nginx) ──FastCGI:9000──► app (PHP-FPM)
```

## Standard: selbstsigniertes Zertifikat

Beim ersten Start wird ein selbstsigniertes Zertifikat für `APP_HOSTNAMES`
erzeugt und unter `web/ssl/` abgelegt:

```text
web/ssl/cert.pem
web/ssl/key.pem
```

Der Browser zeigt deshalb eine Zertifikatswarnung, die einmalig bestätigt
werden muss.

## Zertifikats-Workflows

Der `TlsService` (`app/src/Services/TlsService.php`) unterstützt:

### 1. Selbstsigniertes Zertifikat

`POST /api/tls/selfsigned` – erzeugt ein selbstsigniertes Zertifikat.

Parameter: `common_name`, `san` (Subject Alternative Names), `key_type`
(rsa2048/rsa3072/rsa4096/ec256/ec384), `days` (Standard 825).

### 2. CSR erzeugen

`POST /api/tls/csr` – erzeugt einen Certificate Signing Request.

Der CSR wird von einer CA signiert; das Zertifikat kann anschließend importiert
werden.

### 3. Zertifikat importieren

`POST /api/tls/import` – importiert ein Zertifikat (PEM) plus privaten Schlüssel.

### 4. Zertifikat aktivieren

`POST /api/tls/{id}/activate` – aktiviert ein Zertifikat. Dabei wird das
Zertifikat nach `web/ssl/` geschrieben; anschließend `web` neu laden:

```bash
docker compose restart web
```

## Schlüsseltypen

| key_type | Algorithmus |
| --- | --- |
| rsa2048 | RSA 2048 Bit |
| rsa3072 | RSA 3072 Bit (Standard) |
| rsa4096 | RSA 4096 Bit |
| ec256 | ECDSA P-256 |
| ec384 | ECDSA P-384 |

## Sicherheit

- Private Schlüssel werden **verschlüsselt** in der Datenbank
  (`tls_certificates.private_key_enc`) gespeichert.
- Erst bei Aktivierung wird der Schlüssel nach `web/ssl/` geschrieben.
- Der `web`-Container mountet `web/ssl/` nach `/etc/nginx/ssl`.

## Oberfläche

Die Ansicht **TLS-Zertifikat** zeigt:

- aktuellen Status (CN, SAN, Gültigkeit, Fingerprint)
- alle Zertifikats-Datensätze
- Aktionen: CSR, selbstsigniert, Import, Aktivieren

## Hinweise

- `APP_HOSTNAMES` bestimmt CN/SAN des selbstsignierten Zertifikats.
- Für Produktivbetrieb ein echtes, CA-signiertes Zertifikat verwenden.
