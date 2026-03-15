<?php

namespace Templite\Cms\Contracts;

use Templite\Cms\Services\ImportExport\ImportContext;

/**
 * Контракт для экспортируемых/импортируемых сущностей CMS.
 *
 * Реализуется моделями, которые могут быть экспортированы в JSON
 * и импортированы обратно (блоки, страницы, шаблоны и т.д.).
 *
 * Ключевые принципы:
 * - Экспорт без ID -- связи описываются через slug/key
 * - Зависимости разрешаются автоматически через getDependencies()
 * - Импорт использует ImportContext для маппинга identifier -> model
 */
interface Exportable
{
    /**
     * Тип сущности для экспорта.
     *
     * Используется как ключ в манифесте и ImportContext.
     * Примеры: 'block', 'page', 'template', 'page_type', 'action', 'component'.
     *
     * @return string
     */
    public function getExportType(): string;

    /**
     * Уникальный идентификатор сущности для экспорта.
     *
     * Должен быть стабильным и уникальным в пределах типа.
     * Обычно это slug или составной ключ.
     *
     * @return string
     */
    public function getExportIdentifier(): string;

    /**
     * Массив зависимых Exportable-сущностей.
     *
     * Используется для автоматического включения связанных моделей
     * в пакет экспорта и определения порядка импорта.
     *
     * @return Exportable[]
     */
    public function getDependencies(): array;

    /**
     * Данные сущности для экспорта в JSON.
     *
     * Правила:
     * - Без числовых ID (они не переносимы между БД)
     * - Связи описываются через slug/key зависимостей
     * - Медиафайлы ссылаются по относительному пути
     *
     * @return array
     */
    public function toExportArray(): array;

    /**
     * Создать или обновить модель из импортированных данных.
     *
     * Использует ImportContext для разрешения связей:
     * slug зависимости -> ID модели в текущей БД.
     *
     * @param array $data Данные из toExportArray()
     * @param ImportContext $ctx Контекст импорта с маппингом сущностей
     * @return static Созданная или обновлённая модель
     */
    public static function fromImportArray(array $data, ImportContext $ctx): static;
}
