<?php

namespace Templite\Cms\Services\Ai\Runs;

/** Куда исполнитель хода отдаёт события. */
interface EventSink
{
    /** @param array<string,mixed> $data */
    public function emit(string $type, array $data = []): void;

    /** Записать накопленное. */
    public function flush(): void;
}
