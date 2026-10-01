<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Http\HttpException;
use App\Http\UploadedFile;
use App\Security\PathGuard;

/**
 * Stores uploaded documents in the input directory.
 *
 * FIXES compared to the former controller code:
 *  - multi-file uploads (`files[]`) work (see UploadedFile::normalise),
 *  - PHP upload errors, size limit and file type are checked,
 *  - existing files are no longer overwritten silently (409 instead),
 *  - all files are validated before the first one is stored.
 */
final class UploadService
{
    public const MAX_FILES = 100;

    /**
     * @param list<UploadedFile> $files
     * @return list<string> stored file names
     */
    public function store(string $relativeDir, array $files): array
    {
        $relativeDir = PathGuard::normalise($relativeDir);
        $dir = PathGuard::resolve(Config::string('INPUT_ROOT', '/srv/data/input'), $relativeDir);
        if (!is_dir($dir)) {
            throw HttpException::badRequest('Zielverzeichnis existiert nicht.');
        }
        if ($files === []) {
            throw HttpException::badRequest('Keine Datei ausgewählt.');
        }
        if (count($files) > self::MAX_FILES) {
            throw HttpException::badRequest(sprintf('Höchstens %d Dateien pro Upload.', self::MAX_FILES));
        }

        $maxBytes = self::parseSize(Config::string('UPLOAD_MAX_SIZE', '100M'));
        $planned = [];
        foreach ($files as $file) {
            $name = trim(basename(str_replace('\\', '/', $file->name)));
            if (!$file->isOk()) {
                throw HttpException::badRequest(sprintf('%s: %s', $name !== '' ? $name : 'Datei', self::translateError($file)));
            }
            if (!PathGuard::validateName($name)) {
                throw HttpException::badRequest(sprintf('Ungültiger Dateiname: %s', mb_substr($name, 0, 120)));
            }
            if ($maxBytes > 0 && $file->size > $maxBytes) {
                throw HttpException::badRequest(sprintf('%s ist größer als das Limit von %s.', $name, Config::string('UPLOAD_MAX_SIZE', '100M')));
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, ProcessingService::SUPPORTED_EXTENSIONS, true)) {
                throw HttpException::badRequest(sprintf('Dateityp .%s wird nicht unterstützt.', $ext));
            }
            $dest = $dir . '/' . $name;
            if (isset($planned[$dest]) || file_exists($dest)) {
                throw HttpException::conflict(sprintf('Die Datei %s existiert bereits im Zielverzeichnis.', $name));
            }
            $planned[$dest] = $file;
        }

        $saved = [];
        foreach ($planned as $dest => $file) {
            if (!move_uploaded_file($file->tmpName, $dest)) {
                throw new \RuntimeException('Failed to store uploaded file ' . basename($dest));
            }
            @chmod($dest, 0644);
            $saved[] = basename($dest);
        }
        Audit::record('document.upload', 'directory', $relativeDir === '' ? '/' : $relativeDir, ['files' => $saved]);

        return $saved;
    }

    /** Parse php.ini style sizes ("100M", "2G", "512K", "1048576"). */
    public static function parseSize(string $value): int
    {
        $value = trim($value);
        if (preg_match('/^(\d+)\s*([KMG]?)B?$/i', $value, $m) !== 1) {
            return 0;
        }
        $number = (int) $m[1];

        return match (strtoupper($m[2])) {
            'K' => $number * 1024,
            'M' => $number * 1024 ** 2,
            'G' => $number * 1024 ** 3,
            default => $number,
        };
    }

    private static function translateError(UploadedFile $file): string
    {
        return match ($file->error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Datei überschreitet die maximale Upload-Größe.',
            UPLOAD_ERR_PARTIAL => 'Datei wurde nur teilweise übertragen.',
            UPLOAD_ERR_NO_FILE => 'Keine Datei übertragen.',
            default => 'Upload fehlgeschlagen.',
        };
    }
}
