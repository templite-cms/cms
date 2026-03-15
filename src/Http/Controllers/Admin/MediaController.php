<?php

namespace Templite\Cms\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Templite\Cms\Models\FileFolder;

class MediaController extends Controller
{
    /**
     * Медиа-менеджер (файлы + папки).
     * Экран: Media/Index
     */
    public function index(): Response
    {
        $folders = FileFolder::withCount('files')
            ->with(['children' => fn ($q) => $q->withCount('files')->orderBy('order')])
            ->whereNull('parent_id')
            ->orderBy('order')
            ->get();

        return Inertia::render('Media/Index', [
            'folders' => $folders,
            'maxUploadSize' => config('cms.max_upload_size', 10), // MB
            'allowedExtensions' => config('cms.allowed_extensions', [
                'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg',
                'mp4', 'webm', 'mp3', 'wav',
                'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
                'zip', 'rar', 'txt', 'csv',
            ]),
        ]);
    }
}
