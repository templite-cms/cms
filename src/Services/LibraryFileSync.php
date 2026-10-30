<?php

namespace Templite\Cms\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\FilesystemException;
use Templite\Cms\Exceptions\LibraryFileWriteException;
use Templite\Cms\Models\Library;

/**
 * Синхронизация списка файлов библиотеки с переданным массивом.
 * Записи с id обновляются, без id — создаются, отсутствующие удаляются
 * вместе с физическим файлом на диске.
 */
class LibraryFileSync
{
    /**
     * @param  array<int, array<string, mixed>>  $files
     */
    public function sync(Library $library, array $files): void
    {
        $library->loadMissing('files');

        // Slug санитизируем один раз и дальше пользуемся только им: он идёт
        // и в путь каталога, и в имя файла. Валидация контроллера сюда его
        // пропускает уже проверенным, но Library::fromImportArray() берёт slug
        // прямо из meta.json архива, а этот сервис — общий для обоих путей.
        $slug = basename($library->slug);
        $dir = 'cms/libraries/' . $slug;
        $failureMessage = "Не удалось сохранить файл библиотеки «{$slug}». Проверьте права на запись в storage.";

        $keepIds = [];

        foreach (array_values($files) as $index => $input) {
            $id = isset($input['id']) ? (int) $input['id'] : null;

            // Повторный id в списке не должен схлопывать два элемента в одну
            // запись: второе вхождение трактуем как новый файл. Иначе три
            // элемента на входе тихо дали бы две записи и дыру в sort_order.
            $existing = $id && !in_array($id, $keepIds, true)
                ? $library->files->firstWhere('id', $id)
                : null;

            $type = $input['type'];

            $attributes = [
                'type' => $type,
                'source' => $input['source'],
                'version' => $this->nullIfBlank($input['version'] ?? null),
                'loading' => $type === 'js' ? $this->nullIfBlank($input['loading'] ?? null) : null,
                'media' => $type === 'css' ? $this->nullIfBlank($input['media'] ?? null) : null,
                'sort_order' => $index,
            ];

            // Файл, осиротевший на этой итерации. Стираем его только после
            // того, как новое состояние записи доехало до БД: иначе упавший
            // update оставил бы строку указывать на уже удалённый файл.
            $orphanPath = null;

            if ($input['source'] === 'cdn') {
                // Переключение local → cdn: старый файл больше не нужен
                if ($existing && $existing->source === 'local') {
                    $orphanPath = $existing->path;
                }

                $attributes['url'] = $input['url'];
                $attributes['path'] = null;
                $attributes['original_name'] = null;
            } else {
                $attributes['url'] = null;
                $upload = $input['upload'] ?? null;

                if ($upload instanceof UploadedFile) {
                    $extension = $type === 'js' ? 'js' : 'css';
                    $fileName = $slug . '-' . Str::random(8) . '.' . $extension;

                    // Порядок важен: сначала пишем новый файл, и только потом
                    // удаляем старый. Наоборот — неудачная перезаливка снесла бы
                    // рабочий файл и оставила запись, которой нечего отдавать.
                    try {
                        $path = $upload->storeAs($dir, $fileName, 'public');
                    } catch (FilesystemException $e) {
                        // throw = false у диска public гасит только
                        // UnableToWriteFile и UnableToSetVisibility. Отказ на
                        // создании каталога прилетает сюда исключением, и
                        // наружу его пускать нельзя: в тексте Flysystem лежит
                        // абсолютный путь на сервере. Причина остаётся в trace.
                        throw new LibraryFileWriteException($failureMessage, 0, $e);
                    }

                    // Отказ самой записи, наоборот, приходит возвратом false.
                    // Без проверки он лёг бы в varchar строкой "0" — запись
                    // выглядела бы исправной, а каждая страница получила бы
                    // вечный 404 на /storage/0.
                    if ($path === false) {
                        throw new LibraryFileWriteException($failureMessage);
                    }

                    // Совпадение имён здесь маловероятно, но удалить только что
                    // записанный файл дороже, чем лишнее сравнение.
                    if ($existing && $existing->source === 'local' && $existing->path !== $path) {
                        $orphanPath = $existing->path;
                    }

                    $attributes['path'] = $path;
                    // Имя приходит от клиента и ничем не ограничено по длине,
                    // а колонка — varchar(255): без усечения вставка упала бы
                    // с ошибкой 1406. Поле чисто отображаемое, отказывать
                    // пользователю из-за длины подписи незачем.
                    $attributes['original_name'] = mb_substr($upload->getClientOriginalName(), 0, 255);
                } else {
                    // Файл не перезаливали — сохраняем то, что уже лежит
                    $attributes['path'] = $existing?->path;
                    $attributes['original_name'] = $existing?->original_name;
                }
            }

            if ($existing) {
                $existing->update($attributes);
                $keepIds[] = $existing->id;
            } else {
                $keepIds[] = $library->files()->create($attributes)->id;
            }

            $this->deleteFile($orphanPath);
        }

        foreach ($library->files as $file) {
            if (in_array($file->id, $keepIds, true)) {
                continue;
            }

            $orphanPath = $file->source === 'local' ? $file->path : null;

            // Тот же порядок, что и выше: пока запись не удалена, её файл
            // трогать нельзя — упавший delete оставил бы битую ссылку.
            $file->delete();

            $this->deleteFile($orphanPath);
        }

        $library->unsetRelation('files');
    }

    protected function deleteFile(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    /**
     * Пустое значение — это null, а не пустая строка: с фронта version и
     * media приходят как '', а в колонке должен лежать NULL.
     *
     * Строку '0' и число 0 метод намеренно пропускает: это осмысленные
     * значения, и empty()/blank() здесь бы их съели.
     */
    protected function nullIfBlank(mixed $value): ?string
    {
        // Нескаляр в строку не приводится: массив уронил бы синхронизацию
        // в ErrorException, объект без __toString — в Error.
        if (!is_scalar($value)) {
            return null;
        }

        $value = is_string($value) ? trim($value) : (string) $value;

        return $value === '' ? null : $value;
    }
}
