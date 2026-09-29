<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderReturnEvidenceService
{
    public const DISK = 'order_returns';

    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function inspect(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        $size = $file->getSize();
        $mime = $path ? (new \finfo(FILEINFO_MIME_TYPE))->file($path) : false;
        if (! $file->isValid() || ! $path || ! is_int($size) || $size < 1 || $size > 5 * 1024 * 1024
            || ! is_string($mime) || ! isset(self::TYPES[$mime])) {
            throw ValidationException::withMessages(['evidence' => 'Evidence must be a JPEG, PNG, or WebP image of at most 5 MB.']);
        }
        $sha256 = hash_file('sha256', $path);
        if (! is_string($sha256)) {
            throw ValidationException::withMessages(['evidence' => 'Evidence could not be read.']);
        }

        return ['mime' => $mime, 'size' => $size, 'sha256' => $sha256,
            'name' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255),
            'extension' => self::TYPES[$mime]];
    }

    public function store(UploadedFile $file, array $metadata): string
    {
        $path = 'proof/'.Str::uuid().'.'.$metadata['extension'];
        if (! $file->storeAs('proof', basename($path), self::DISK)) {
            throw ValidationException::withMessages(['evidence' => 'Evidence could not be stored.']);
        }
        try {
            $this->bytes($path, $metadata['sha256'], $metadata['size'], $metadata['mime']);
        } catch (\Throwable $exception) {
            $this->delete($path);
            throw $exception;
        }

        return $path;
    }

    public function bytes(string $path, string $sha256, int $size, string $mime): string
    {
        $bytes = Storage::disk(self::DISK)->get($path);
        if (strlen($bytes) !== $size || ! hash_equals($sha256, hash('sha256', $bytes))
            || (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) !== $mime) {
            throw ValidationException::withMessages(['evidence' => 'Stored evidence failed integrity verification.']);
        }

        return $bytes;
    }

    public function delete(string $path): void
    {
        Storage::disk(self::DISK)->delete($path);
    }
}
