<?php

namespace Templite\Cms\Services\Ai\Tools;

use Templite\Cms\Models\File;

/**
 * Барьер карантина: сгенерированная картинка попадает в данные блока или
 * пресета только после того, как пользователь увидел её в чате и подтвердил.
 */
trait ChecksPendingImages
{
    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>|null отказ для агента либо null, если неподтверждённых картинок нет
     */
    protected function denyIfPendingImages(array $data): ?array
    {
        $pending = $this->pendingFileIds($data);

        if ($pending === []) {
            return null;
        }

        return [
            'error' => 'image_not_confirmed',
            'file_ids' => $pending,
            'message' => 'Эти изображения ещё не подтверждены пользователем. Дождись подтверждения в чате — подставлять их нельзя.',
        ];
    }

    /**
     * Идентификаторы файлов из карантина, встречающиеся в данных.
     *
     * Проверяются все числа: тип поля здесь неизвестен, а файлов на
     * подтверждении обычно один-два — лишний запрос дешевле пропущенной вставки.
     *
     * @param  array<string,mixed>  $data
     * @return array<int,int>
     */
    protected function pendingFileIds(array $data): array
    {
        $numbers = [];

        array_walk_recursive($data, function ($value) use (&$numbers) {
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                $numbers[] = (int) $value;
            }
        });

        if ($numbers === []) {
            return [];
        }

        return File::whereIn('id', array_unique($numbers))
            ->where('ai_pending', true)
            ->pluck('id')
            ->all();
    }
}
