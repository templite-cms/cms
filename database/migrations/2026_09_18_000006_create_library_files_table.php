<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained('libraries')->cascadeOnDelete();
            $table->string('type', 10);           // js | css
            $table->string('source', 10);         // local | cdn
            $table->string('path')->nullable();   // для local: cms/libraries/{slug}/{file}
            $table->string('url', 500)->nullable(); // для cdn
            $table->string('original_name')->nullable();
            // 255, как в старой libraries.version: импорт пакета писал туда
            // значение без проверки длины, а усекать его при переносе нельзя.
            // Ограничение max:50 остаётся в валидации нового ввода, не в схеме.
            $table->string('version', 255)->nullable();
            $table->string('loading', 10)->nullable(); // js: defer | async | module
            $table->string('media', 50)->nullable();   // css: media-атрибут
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['library_id', 'sort_order']);
        });

        // Перенос данных из старых колонок libraries
        $now = now();
        $rows = [];

        foreach (DB::table('libraries')->orderBy('id')->get() as $lib) {
            $cdnPair = [['css', $lib->css_cdn, 'url'], ['js', $lib->js_cdn, 'url']];
            $localPair = [['css', $lib->css_file, 'path'], ['js', $lib->js_file, 'path']];

            [$primary, $fallback] = $lib->load_strategy === 'cdn'
                ? [$cdnPair, $localPair]
                : [$localPair, $cdnPair];

            // Вторая пара подхватывается, только если первая пуста: у библиотеки
            // с некорректным load_strategy (старый импорт ставил 'defer') иначе
            // молча потерялись бы единственные заполненные колонки.
            $candidates = array_filter($primary, fn ($c) => !empty($c[1]));
            if (!$candidates) {
                $candidates = array_filter($fallback, fn ($c) => !empty($c[1]));
            }

            $order = 0;
            foreach ($candidates as [$type, $value, $column]) {
                $rows[] = [
                    'library_id' => $lib->id,
                    'type' => $type,
                    // Источник берём из колонки, а не из load_strategy: при
                    // фолбэке они расходятся.
                    'source' => $column === 'url' ? 'cdn' : 'local',
                    'path' => $column === 'path' ? $value : null,
                    'url' => $column === 'url' ? $value : null,
                    'original_name' => $column === 'path' ? basename($value) : null,
                    'version' => $lib->version ?: null,
                    'loading' => null,
                    'media' => null,
                    'sort_order' => $order++,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('library_files')->insert($chunk);
        }

        Schema::table('libraries', function (Blueprint $table) {
            $table->dropColumn(['version', 'js_file', 'css_file', 'js_cdn', 'css_cdn', 'load_strategy']);
        });
    }

    /**
     * Откат лоссовый: в четыре старых слота влезают только первый js и первый
     * css. Третий и последующие файлы, пофайловые loading/media, версии всех
     * файлов кроме первого и смешанные источники (часть local, часть cdn)
     * пропадают вместе с таблицей. Возвращать схему назад стоит только сразу
     * после миграции, пока библиотеки ещё не отредактировали.
     */
    public function down(): void
    {
        Schema::table('libraries', function (Blueprint $table) {
            $table->string('version')->nullable();
            $table->string('js_file')->nullable();
            $table->string('css_file')->nullable();
            // Шире прежних 255: library_files.url это varchar(500), и откат
            // на длинной ссылке иначе падает с 1406. Старый API валидировал
            // js_cdn как max:500 при колонке на 255 — тут это расхождение
            // заодно и закрывается, сужать обратно не нужно.
            $table->string('js_cdn', 500)->nullable();
            $table->string('css_cdn', 500)->nullable();
            $table->string('load_strategy')->default('local');
        });

        foreach (DB::table('libraries')->orderBy('id')->get() as $lib) {
            $files = DB::table('library_files')
                ->where('library_id', $lib->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            if ($files->isEmpty()) {
                continue;
            }

            $first = $files->first();
            $js = $files->firstWhere('type', 'js');
            $css = $files->firstWhere('type', 'css');

            DB::table('libraries')->where('id', $lib->id)->update([
                'version' => $first->version,
                'load_strategy' => $first->source,
                'js_file' => $js && $js->source === 'local' ? $js->path : null,
                'css_file' => $css && $css->source === 'local' ? $css->path : null,
                'js_cdn' => $js && $js->source === 'cdn' ? $js->url : null,
                'css_cdn' => $css && $css->source === 'cdn' ? $css->url : null,
            ]);
        }

        Schema::dropIfExists('library_files');
    }
};
