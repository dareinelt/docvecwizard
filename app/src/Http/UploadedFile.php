<?php

declare(strict_types=1);

namespace App\Http;

/**
 * One uploaded file, normalised from PHP's $_FILES structure. PHP represents
 * `files[]` multi-uploads as parallel arrays (name => [...], tmp_name => [...]);
 * the previous code iterated $_FILES directly and therefore cast those arrays
 * to the string "Array", which broke every multi-file upload from the UI.
 */
final class UploadedFile
{
    public function __construct(
        public readonly string $field,
        public readonly string $name,
        public readonly string $tmpName,
        public readonly int $size,
        public readonly int $error,
    ) {
    }

    /**
     * @param array<string,mixed> $files raw $_FILES
     * @return list<self>
     */
    public static function normalise(array $files): array
    {
        $result = [];
        foreach ($files as $field => $spec) {
            if (!is_array($spec) || !array_key_exists('name', $spec)) {
                continue;
            }
            if (is_array($spec['name'])) {
                foreach (array_keys($spec['name']) as $i) {
                    if (is_array($spec['name'][$i])) {
                        continue; // deeper nesting is not supported by any endpoint
                    }
                    $result[] = new self(
                        (string) $field,
                        (string) $spec['name'][$i],
                        (string) ($spec['tmp_name'][$i] ?? ''),
                        (int) ($spec['size'][$i] ?? 0),
                        (int) ($spec['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                    );
                }
                continue;
            }
            $result[] = new self(
                (string) $field,
                (string) $spec['name'],
                (string) ($spec['tmp_name'] ?? ''),
                (int) ($spec['size'] ?? 0),
                (int) ($spec['error'] ?? UPLOAD_ERR_NO_FILE),
            );
        }

        return $result;
    }

    public function isOk(): bool
    {
        return $this->error === UPLOAD_ERR_OK && $this->tmpName !== '' && is_uploaded_file($this->tmpName);
    }

    public function errorMessage(): string
    {
        return match ($this->error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds the maximum upload size',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => 'Server could not store the upload',
            default => 'Invalid upload',
        };
    }
}
