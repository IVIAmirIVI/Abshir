<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CaptainFinancialEvidenceService
{
    public const DISK = 'captain_financial_evidence';

    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /** @return array{mime: string, size: int, sha256: string, name: string, extension: string} */
    public function inspect(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        $size = $file->getSize();
        $mime = $path ? (new \finfo(FILEINFO_MIME_TYPE))->file($path) : false;
        if (! $file->isValid() || ! $path || ! is_int($size) || $size < 1 || $size > 5 * 1024 * 1024 || ! is_string($mime) || ! isset(self::TYPES[$mime])) {
            throw ValidationException::withMessages(['evidence' => __('domain.evidence_must_be_a_jpeg_png_or_webp_image_of_at_most_5_mb')]);
        }
        $sha = hash_file('sha256', $path);
        if (! is_string($sha)) {
            throw ValidationException::withMessages(['evidence' => __('domain.evidence_could_not_be_read')]);
        }

        return ['mime' => $mime, 'size' => $size, 'sha256' => $sha,
            'name' => basename(str_replace('\\', '/', $file->getClientOriginalName())), 'extension' => self::TYPES[$mime]];
    }

    public function store(UploadedFile $file, array $metadata): string
    {
        $path = 'captain-operations/'.Str::uuid().'.'.$metadata['extension'];
        if (! $file->storeAs('captain-operations', basename($path), self::DISK)) {
            throw ValidationException::withMessages(['evidence' => __('domain.evidence_could_not_be_stored')]);
        }
        try {
            $bytes = Storage::disk(self::DISK)->get($path);
            if (strlen($bytes) !== $metadata['size'] || ! hash_equals($metadata['sha256'], hash('sha256', $bytes))
                || (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) !== $metadata['mime']) {
                throw ValidationException::withMessages(['evidence' => __('domain.stored_evidence_failed_integrity_verification')]);
            }
        } catch (\Throwable $exception) {
            Storage::disk(self::DISK)->delete($path);
            throw $exception;
        }

        return $path;
    }

    public function bytes(string $path, string $sha256): string
    {
        $bytes = Storage::disk(self::DISK)->get($path);
        if (! hash_equals($sha256, hash('sha256', $bytes))) {
            throw ValidationException::withMessages(['evidence' => __('domain.evidence_failed_integrity_verification')]);
        }

        return $bytes;
    }

    public function delete(string $path): void
    {
        Storage::disk(self::DISK)->delete($path);
    }
}
