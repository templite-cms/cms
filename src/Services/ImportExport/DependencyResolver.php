<?php

namespace Templite\Cms\Services\ImportExport;

use Templite\Cms\Contracts\Exportable;

class DependencyResolver
{
    /** @var array<string, Exportable> visited: "type:identifier" => Exportable */
    protected array $visited = [];

    /** @var array<string, Exportable> result in topological order */
    protected array $resolved = [];

    /**
     * Принимает массив корневых сущностей, возвращает полный список с зависимостями.
     * Порядок: сначала зависимости (листья), потом зависящие (корни).
     *
     * @param Exportable[] $entities
     * @return Exportable[]
     */
    public function resolve(array $entities): array
    {
        $this->visited = [];
        $this->resolved = [];

        foreach ($entities as $entity) {
            $this->visit($entity);
        }

        return array_values($this->resolved);
    }

    protected function visit(Exportable $entity): void
    {
        $key = $entity->getExportType() . ':' . $entity->getExportIdentifier();

        if (isset($this->visited[$key])) {
            return;
        }

        $this->visited[$key] = $entity;

        foreach ($entity->getDependencies() as $dep) {
            $this->visit($dep);
        }

        $this->resolved[$key] = $entity;
    }

    /**
     * Подсчитывает сводку: ['blocks' => 5, 'pages' => 3, ...]
     *
     * @param Exportable[] $entities
     * @return array<string, int>
     */
    public static function summarize(array $entities): array
    {
        $summary = [];
        foreach ($entities as $entity) {
            $type = $entity->getExportType();
            $summary[$type] = ($summary[$type] ?? 0) + 1;
        }
        return $summary;
    }
}
