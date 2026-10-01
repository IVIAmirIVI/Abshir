<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerRefundEvidenceService
{
    public const DISK = 'customer_refunds';

    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function inspect(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        $size = $file->getSize();
        $mime = $path ? (new \finfo(FILEINFO_MIME_TYPE))->file($path) : false;
        if (! $file->isValid() || ! $path || ! is_int($size) || $size < 1 || $size > 5 * 1024 * 1024
            || ! is_string($mime) || ! isset(self::TYPES[$mime])) {
            throw ValidationException::withMessages(['evidence' => 'Refund evidence must be a JPEG, PNG, or WebP image of at most 5 MB.']);
        }
        $sha = hash_file('sha256', $path);
        if (! is_string($sha)) {
            throw ValidationException::withMessages(['evidence' => 'Refund evidence could not be read.']);
        }

        return ['mime' => $mime, 'size' => $size, 'sha256' => $sha,
            'name' => basename(str_replace('\\', '/', $file->getClientOriginalName())), 'extension' => self::TYPES[$mime]];
    }

    public function store(UploadedFile $file, array $metadata): string
    {
        $path = Str::uuid().'.'.$metadata['extension'];
        if (! $file->storeAs('proof', $path, self::DISK)) {
            throw ValidationException::withMessages(['evidence' => 'Refund evidence could not be stored.']);
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
        $bytes = Storage::disk(self::DISK)->get($path);
        if (strlen($bytes) !== $size || ! hash_equals($sha256, hash('sha256', $bytes))
            || (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) !== $mime) {
            throw ValidationException::withMessages(['evidence' => 'Refund evidence failed integrity verification.']);
        }

        return $bytes;
    }

    public function delete(string $path): void
    {
        Storage::disk(self::DISK)->delete($path);
    }
}
