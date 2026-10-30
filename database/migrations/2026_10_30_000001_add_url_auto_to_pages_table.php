<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->boolean('url_auto')->default(true)->after('url');
        });

        // URL, который уже разошёлся с деревом (например, родителя удалили),
        // замораживаем — иначе первое же сохранение страницы перепишет SEO-адрес.
        $pages = DB::table('pages')->get(['id', 'url', 'alias', 'parent_id'])->keyBy('id');

        foreach ($pages as $page) {
            $parent = $page->parent_id ? $pages->get($page->parent_id) : null;
            $expected = ($parent ? rtrim($parent->url, '/') : '') . '/' . Str::slug((string) $page->alias);

            $matches = $page->url === $expected
                || preg_match('#^' . preg_quote($expected, '#') . '-\d+$#', $page->url);

            if (! $matches) {
                DB::table('pages')->where('id', $page->id)->update(['url_auto' => false]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn('url_auto');
        });
    }
};
