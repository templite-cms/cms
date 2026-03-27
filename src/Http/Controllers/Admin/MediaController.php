<?php

namespace Templite\Cms\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Templite\Cms\Http\CmsResponse;
use Templite\Cms\Models\FileFolder;

class MediaController extends Controller
{
    /**
     * Медиа-менеджер (файлы + папки).
     * Экран: Media/Index
     */
    public function index()
    {
        $folders = FileFolder::withCount('files')
            ->with(['children' => fn ($q) => $q->withCount('files')->orderBy('order')])
            ->whereNull('parent_id')
            ->orderBy('order')
            ->get();

        return CmsResponse::page('packages/templite/cms/resources/js/entries/media-index.js', [
            'folders' => $folders,
            'maxUploadSize' => config('cms.max_upload_size', 10), // MB
            'allowedExtensions' => config('cms.allowed_extensions', [
                'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg',
                'mp4', 'webm', 'mp3', 'wav',
                'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
                'zip', 'rar', 'txt', 'csv',
            ]),
        ], ['title' => 'Медиа']);
    }
}
