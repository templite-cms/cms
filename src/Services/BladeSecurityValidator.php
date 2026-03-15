<?php

namespace Templite\Cms\Services;

/**
 * Валидатор безопасности Blade-шаблонов.
 *
 * Использует whitelist-подход для Blade-директив и блокирует
 * опасные функции/конструкции в выражениях {{ }}.
 * Защищает от SSTI (Server-Side Template Injection).
 */
class BladeSecurityValidator
{
    /**
     * Разрешённые Blade-директивы (whitelist).
     *
     * Только эти директивы можно использовать в пользовательских шаблонах.
     */
    protected array $allowedDirectives = [
        'if',
        'else',
        'elseif',
        'endif',
        'foreach',
        'endforeach',
        'for',
        'endfor',
        'while',
        'endwhile',
        'empty',
        'endempty',
        'isset',
        'endisset',
        'unless',
        'endunless',
        'switch',
        'case',
        'break',
        'default',
        'endswitch',
        'yield',
        'section',
        'endsection',
        'show',
    ];

    /**
     * Явно запрещённые директивы (для понятных сообщений об ошибках).
     *
     * Эти директивы блокируются в любом случае (они не в whitelist),
     * но выделены отдельно для более информативных сообщений.
     */
    protected array $explicitlyBlockedDirectives = [
        'php'            => '@php directive (arbitrary PHP execution)',
        'endphp'         => '@endphp directive',
        'includeIf'      => '@includeIf directive (dynamic file inclusion)',
        'includeWhen'    => '@includeWhen directive (dynamic file inclusion)',
        'includeUnless'  => '@includeUnless directive (dynamic file inclusion)',
        'includeFirst'   => '@includeFirst directive (dynamic file inclusion)',
        'each'           => '@each directive (dynamic file inclusion)',
        'component'      => '@component directive (arbitrary component loading)',
        'slot'           => '@slot directive',
        'extends'        => '@extends directive (layout inheritance)',
        'livewire'       => '@livewire directive (Livewire component loading)',
        'verbatim'       => '@verbatim directive',
        'inject'         => '@inject directive (service container injection)',
        'eval'           => '@eval directive',
    ];

    /**
     * Запрещённые паттерны в Blade-шаблонах (не директивы).
     *
     * Ключ -- регулярное выражение, значение -- описание нарушения.
     */
    protected array $forbiddenPatterns = [
        '/<\?php/'              => '<?php tag',
        '/<\?=/'                => '<?= tag',
    ];

    /**
     * Запрещённые функции/конструкции внутри выражений {{ }}.
     *
     * Эти вызовы могут привести к утечке конфигурации,
     * доступу к сервис-контейнеру или обходу безопасности.
     */
    protected array $forbiddenExpressionCalls = [
        'eval'       => 'eval() function call',
        'exec'       => 'exec() function call',
        'system'     => 'system() function call',
        'passthru'   => 'passthru() function call',
        'shell_exec' => 'shell_exec() function call',
        'proc_open'  => 'proc_open() function call',
        'popen'      => 'popen() function call',
        'config'     => 'config() function call (configuration access)',
        'env'        => 'env() function call (environment variable access)',
        'app'        => 'app() function call (service container access)',
        'resolve'    => 'resolve() function call (service container access)',
        'session'    => 'session() function call (session access)',
        'request'    => 'request() function call (request object access)',
    ];

    /**
     * Запрещённые статические вызовы внутри выражений {{ }}.
     */
    protected array $forbiddenStaticCalls = [
        'Container' => 'Container:: static call (service container access)',
        'Facade'    => 'Facade:: static call (facade access)',
    ];

    /**
     * Проверить Blade-шаблон на запрещённые конструкции.
     *
     * @param string $content Содержимое Blade-шаблона
     * @return array Список нарушений (пустой массив = всё ок)
     */
    public function validate(string $content): array
    {
        $violations = [];

        // 1. Проверка запрещённых паттернов (raw PHP, unescaped output)
        foreach ($this->forbiddenPatterns as $pattern => $description) {
            if (preg_match($pattern, $content)) {
                $violations[] = $description;
            }
        }

        // 2. Whitelist-проверка директив: найти все @directive и проверить по whitelist
        $violations = array_merge($violations, $this->validateDirectives($content));

        // 3. Проверка выражений внутри {{ }} и {!! !!} на запрещённые вызовы
        $violations = array_merge($violations, $this->validateExpressions($content));

        return array_unique($violations);
    }

    /**
     * Проверить, является ли Blade-шаблон безопасным.
     *
     * @param string $content Содержимое Blade-шаблона
     * @return bool true если шаблон безопасен
     */
    public function isValid(string $content): bool
    {
        return empty($this->validate($content));
    }

    /**
     * Валидация при рендере -- выбрасывает исключение если шаблон небезопасен.
     *
     * Используется для защиты от подмены файлов шаблонов на диске.
     *
     * @param string $content Содержимое Blade-шаблона
     * @throws \Templite\Cms\Exceptions\UnsafeTemplateException
     */
    public function validateOrFail(string $content): void
    {
        $violations = $this->validate($content);

        if (!empty($violations)) {
            throw new \Templite\Cms\Exceptions\UnsafeTemplateException(
                'Шаблон содержит запрещённые конструкции: ' . implode(', ', $violations),
                $violations
            );
        }
    }

    /**
     * Статический метод для быстрой валидации при рендере.
     *
     * @param string $content Содержимое Blade-шаблона
     * @throws \Templite\Cms\Exceptions\UnsafeTemplateException
     */
    public static function assertSafe(string $content): void
    {
        (new static())->validateOrFail($content);
    }

    /**
     * Получить список запрещённых паттернов.
     *
     * @return array<string, string>
     */
    public function getForbiddenPatterns(): array
    {
        return $this->forbiddenPatterns;
    }

    /**
     * Получить список разрешённых директив.
     *
     * @return array<int, string>
     */
    public function getAllowedDirectives(): array
    {
        return $this->allowedDirectives;
    }

    /**
     * Проверить Blade-директивы по whitelist.
     *
     * Находит все @directive конструкции в шаблоне и проверяет,
     * что каждая из них входит в список разрешённых.
     *
     * @param string $content
     * @return array Список нарушений
     */
    protected function validateDirectives(string $content): array
    {
        $violations = [];

        // Находим все Blade-директивы: @word (исключая email-подобные конструкции)
        // Исключаем @-символ внутри email-адресов и CSS (@media, @keyframes, @import, @font-face, @charset)
        // Blade-директивы всегда начинаются с начала строки или после пробела/скобки
        if (preg_match_all('/(?<![a-zA-Z0-9._-])@([a-zA-Z][a-zA-Z0-9_]*)/', $content, $matches)) {
            $cssAtRules = [
                'media', 'keyframes', 'import', 'font-face', 'charset',
                'supports', 'layer', 'page', 'property', 'counter-style',
                'namespace', 'container', 'scope', 'starting-style',
            ];

            foreach ($matches[1] as $directive) {
                // Пропускаем CSS at-rules
                if (in_array(strtolower($directive), $cssAtRules, true)) {
                    continue;
                }

                // Пропускаем CMS Blade-компоненты (x-cms::*)
                if ($directive === 'x') {
                    continue;
                }

                // Проверяем по whitelist
                if (!in_array($directive, $this->allowedDirectives, true)) {
                    // Выдаём информативное сообщение если директива явно заблокирована
                    if (isset($this->explicitlyBlockedDirectives[$directive])) {
                        $violations[] = $this->explicitlyBlockedDirectives[$directive];
                    } else {
                        $violations[] = "@{$directive} directive is not allowed";
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * Проверить выражения внутри {{ }} на запрещённые вызовы функций.
     *
     * @param string $content
     * @return array Список нарушений
     */
    protected function validateExpressions(string $content): array
    {
        $violations = [];

        // Извлекаем все выражения внутри {{ ... }} и {!! ... !!}
        if (preg_match_all('/\{\{(.*?)\}\}|\{!!(.*?)!!\}/s', $content, $matches)) {
            $expressions = array_merge(
                array_filter($matches[1], fn($v) => $v !== ''),
                array_filter($matches[2], fn($v) => $v !== '')
            );

            foreach ($expressions as $expr) {
                // Проверяем запрещённые вызовы функций
                foreach ($this->forbiddenExpressionCalls as $func => $description) {
                    if (preg_match('/\b' . preg_quote($func, '/') . '\s*\(/', $expr)) {
                        $violations[] = $description;
                    }
                }

                // Проверяем запрещённые статические вызовы
                foreach ($this->forbiddenStaticCalls as $class => $description) {
                    if (preg_match('/\b' . preg_quote($class, '/') . '\s*::/', $expr)) {
                        $violations[] = $description;
                    }
                }
            }
        }

        // Также проверяем запрещённые вызовы вне {{ }} (в сыром контексте)
        foreach ($this->forbiddenExpressionCalls as $func => $description) {
            // Только для опасных системных функций (eval, exec, system, etc.)
            if (in_array($func, ['eval', 'exec', 'system', 'passthru', 'shell_exec', 'proc_open', 'popen'], true)) {
                if (preg_match('/\b' . preg_quote($func, '/') . '\s*\(/', $content)) {
                    $violations[] = $description;
                }
            }
        }

        return $violations;
    }
}
