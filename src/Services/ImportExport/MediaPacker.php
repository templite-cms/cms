<?php

namespace Templite\Cms\Services\ImportExport;

use Illuminate\Support\Facades\Storage;
use ZipArchive;

class MediaPacker
{
    public function pack(ZipArchive $zip, array $filePaths, string $disk = 'public'): void
    {
        $storage = Storage::disk($disk);

        foreach (array_unique($filePaths) as $path) {
            if ($path && $storage->exists($path)) {
                $zip->addFromString(
                    "media/files/{$path}",
                    $storage->get($path)
                );
            }
        }
    }

    public function unpack(ZipArchive $zip, string $disk = 'public'): void
    {
        $storage = Storage::disk($disk);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_starts_with($name, 'media/files/') && !str_ends_with($name, '/')) {
                $relativePath = substr($name, strlen('media/files/'));
                $storage->put($relativePath, $zip->getFromIndex($i));
            }
        }
    }
}
