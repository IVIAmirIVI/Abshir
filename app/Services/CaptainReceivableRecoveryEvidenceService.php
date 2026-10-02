<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CaptainReceivableRecoveryEvidenceService
{
    public const DISK = 'captain_receivable_recoveries';

    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function inspect(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        $size = $file->getSize();
        $mime = $path ? (new \finfo(FILEINFO_MIME_TYPE))->file($path) : false;
        if (! $file->isValid() || ! $path || ! is_int($size) || $size < 1 || $size > 5 * 1024 * 1024
            || ! is_string($mime) || ! isset(self::TYPES[$mime])) {
            throw ValidationException::withMessages(['evidence' => __('domain.recovery_evidence_must_be_a_jpeg_png_or_webp_image_of_at_most_5_mb')]);
        }
        $sha = hash_file('sha256', $path);
        if (! is_string($sha)) {
            throw ValidationException::withMessages(['evidence' => __('domain.recovery_evidence_could_not_be_read')]);
        }

        return ['mime' => $mime, 'size' => $size, 'sha256' => $sha,
            'name' => basename(str_replace('\\', '/', $file->getClientOriginalName())), 'extension' => self::TYPES[$mime]];
    }

    public function store(UploadedFile $file, array $metadata): string
    {
        $path = Str::uuid().'.'.$metadata['extension'];
        if (! $file->storeAs('proof', $path, self::DISK)) {
            throw ValidationException::withMessages(['evidence' => __('domain.recovery_evidence_could_not_be_stored')]);
        }
        $storedPath = 'proof/'.$path;
        try {
            $this->bytes($storedPath, $metadata['sha256'], $metadata['mime'], $metadata['size']);
        } catch (\Throwable $exception) {
            $this->delete($storedPath);
            throw $exception;
        }

        return $storedPath;
    }

    public function bytes(string $path, string $sha256, string $mime, int $size): string
    {
        if (! preg_match('/^proof\/[0-9a-f-]{36}\.(jpg|png|webp)$/D', $path)) {
            throw ValidationException::withMessages(['evidence' => __('domain.invalid_private_recovery_path')]);
        }
        $disk = Storage::disk(self::DISK);
        $root = realpath($disk->path(''));
        $resolved = realpath($disk->path($path));
        $rootPrefix = $root ? rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR : '';
        $insideRoot = $root && $resolved && (PHP_OS_FAMILY === 'Windows'
            ? str_starts_with(strtolower($resolved), strtolower($rootPrefix))
            : str_starts_with($resolved, $rootPrefix));
        if (! $insideRoot) {
            throw ValidationException::withMessages(['evidence' => __('domain.recovery_evidence_must_remain_inside_its_private_root')]);
        }
        $bytes = Storage::disk(self::DISK)->get($path);
        if (strlen($bytes) !== $size || ! hash_equals($sha256, hash('sha256', $bytes))
            || (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) !== $mime) {
            throw ValidationException::withMessages(['evidence' => __('domain.recovery_evidence_failed_integrity_verification')]);
        }

        return $bytes;
    }

    public function delete(string $path): void
    {
        Storage::disk(self::DISK)->delete($path);
    }
}
