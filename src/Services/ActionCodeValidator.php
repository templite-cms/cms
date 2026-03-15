<?php

namespace Templite\Cms\Services;

/**
 * Валидатор безопасности PHP-кода Actions.
 *
 * Использует token_get_all() для анализа кода вместо regex-blacklist.
 * Whitelist-подход: разрешены только явно указанные функции и классы.
 * Предотвращает RCE через обход blacklist (C-01, C-03).
 */
class ActionCodeValidator
{
    /**
     * Whitelist разрешённых функций.
     * Только безопасные функции для работы с данными.
     */
    protected const ALLOWED_FUNCTIONS = [
        // Массивы
        'array_chunk', 'array_combine', 'array_count_values', 'array_diff',
        'array_diff_assoc', 'array_diff_key', 'array_fill', 'array_fill_keys',
        'array_filter', 'array_flip', 'array_intersect', 'array_intersect_assoc',
        'array_intersect_key', 'array_key_exists', 'array_key_first', 'array_key_last',
        'array_keys', 'array_map', 'array_merge', 'array_merge_recursive',
        'array_pad', 'array_pop', 'array_push', 'array_rand',
        'array_reduce', 'array_replace', 'array_reverse', 'array_search',
        'array_shift', 'array_slice', 'array_splice', 'array_sum',
        'array_unique', 'array_unshift', 'array_values', 'array_walk',
        'array_column', 'array_product',

        // Строки
        'str_contains', 'str_starts_with', 'str_ends_with', 'str_replace',
        'str_ireplace', 'str_pad', 'str_repeat', 'str_split', 'str_word_count',
        'strlen', 'strpos', 'strrpos', 'stripos', 'strripos',
        'strtolower', 'strtoupper', 'substr', 'substr_count', 'substr_replace',
        'trim', 'ltrim', 'rtrim', 'ucfirst', 'lcfirst', 'ucwords',
        'implode', 'explode', 'join', 'nl2br', 'wordwrap',
        'sprintf', 'number_format', 'mb_strlen', 'mb_substr', 'mb_strtolower',
        'mb_strtoupper', 'mb_strpos', 'mb_convert_encoding',
        'preg_match', 'preg_match_all', 'htmlspecialchars', 'htmlentities',
        'strip_tags', 'addslashes', 'stripslashes', 'rawurlencode', 'rawurldecode',
        'urlencode', 'urldecode', 'base64_encode', 'base64_decode',
        'md5', 'sha1',

        // Числа / типы
        'count', 'sizeof', 'intval', 'floatval', 'strval', 'boolval',
        'abs', 'ceil', 'floor', 'round', 'max', 'min', 'rand', 'mt_rand',
        'is_array', 'is_string', 'is_int', 'is_float', 'is_numeric',
        'is_bool', 'is_null', 'is_object', 'isset', 'empty', 'unset',
        'in_array', 'sort', 'asort', 'arsort', 'ksort', 'krsort', 'usort',
        'uasort', 'uksort', 'range', 'compact', 'extract',

        // JSON
        'json_encode', 'json_decode', 'json_last_error', 'json_last_error_msg',

        // Дата/время
        'date', 'time', 'mktime', 'strtotime', 'gmdate',
        'date_create', 'date_format', 'date_diff',

        // Laravel helpers
        'now', 'collect', 'optional', 'data_get', 'data_set',
        'head', 'last', 'blank', 'filled', 'value',
        'e', 'class_basename', 'throw_if', 'throw_unless',
        'retry', 'transform', 'with',
        'request', 'response', 'abort', 'abort_if', 'abort_unless',
        'redirect', 'back', 'url', 'route', 'asset',
        'trans', '__', 'app',
        'logger', 'info', 'report',
        'cache', 'session', 'old', 'csrf_token',

        // Прочие безопасные
        'array_is_list', 'class_exists', 'method_exists', 'property_exists',
        'get_class', 'is_a', 'instanceof',
    ];

    /**
     * Whitelist разрешённых классов для оператора new.
     */
    protected const ALLOWED_CLASSES = [
        'stdClass',
        '\\stdClass',
        'DateTime',
        '\\DateTime',
        'DateTimeImmutable',
        '\\DateTimeImmutable',
        'DateInterval',
        '\\DateInterval',
        'Carbon\\Carbon',
        '\\Carbon\\Carbon',
        'Illuminate\\Support\\Collection',
        '\\Illuminate\\Support\\Collection',
        'Illuminate\\Support\\Carbon',
        '\\Illuminate\\Support\\Carbon',
        'Illuminate\\Support\\Str',
        '\\Illuminate\\Support\\Str',
        'Illuminate\\Support\\Arr',
        '\\Illuminate\\Support\\Arr',
        'Illuminate\\Database\\Eloquent\\Builder',
        '\\Illuminate\\Database\\Eloquent\\Builder',
    ];

    /**
     * Токены PHP, полностью запрещённые в коде Actions.
     */
    protected const FORBIDDEN_TOKENS = [
        T_EVAL,          // eval()
        T_EXIT,          // exit / die
        T_HALT_COMPILER, // __halt_compiler
        T_INCLUDE,       // include
        T_INCLUDE_ONCE,  // include_once
        T_REQUIRE,       // require
        T_REQUIRE_ONCE,  // require_once
        T_INLINE_HTML,   // Inline HTML (не ожидается в PHP-классе, кроме <?php)
    ];

    /**
     * Имена запрещённых токенов для сообщений об ошибках.
     */
    protected const FORBIDDEN_TOKEN_NAMES = [
        T_EVAL          => 'eval',
        T_EXIT          => 'exit/die',
        T_HALT_COMPILER => '__halt_compiler',
        T_INCLUDE       => 'include',
        T_INCLUDE_ONCE  => 'include_once',
        T_REQUIRE       => 'require',
        T_REQUIRE_ONCE  => 'require_once',
        T_INLINE_HTML   => 'inline HTML',
    ];

    /**
     * Валидация PHP-кода Action.
     *
     * @param string $code Полный PHP-код файла Action
     * @return array Массив ошибок (пустой = код безопасен)
     */
    public function validate(string $code): array
    {
        $errors = [];

        // 1. Проверка на backtick-оператор (shell execution)
        if (str_contains($code, '`')) {
            // Проверяем, что backtick не внутри строки
            $backtickErrors = $this->checkBackticks($code);
            $errors = array_merge($errors, $backtickErrors);
        }

        // 2. Токенизация
        $tokens = @token_get_all($code);
        if ($tokens === false) {
            $errors[] = 'Не удалось разобрать PHP-код (синтаксическая ошибка).';
            return $errors;
        }

        // 3. Проверка структуры: ровно один класс, реализующий BlockActionInterface
        $structureErrors = $this->validateStructure($tokens);
        $errors = array_merge($errors, $structureErrors);

        // 4. Проверка запрещённых токенов
        $tokenErrors = $this->validateTokens($tokens);
        $errors = array_merge($errors, $tokenErrors);

        // 5. Проверка вызовов функций (whitelist)
        $callErrors = $this->validateFunctionCalls($tokens);
        $errors = array_merge($errors, $callErrors);

        // 6. Проверка оператора new (whitelist классов)
        $newErrors = $this->validateNewOperator($tokens);
        $errors = array_merge($errors, $newErrors);

        // 7. Проверка variable functions ($var())
        $varFuncErrors = $this->validateVariableFunctions($tokens);
        $errors = array_merge($errors, $varFuncErrors);

        return $errors;
    }

    /**
     * Проверить, является ли код безопасным.
     */
    public function isValid(string $code): bool
    {
        return empty($this->validate($code));
    }

    /**
     * Вычислить SHA-256 хэш содержимого файла.
     */
    public function hashFile(string $path): string
    {
        return hash_file('sha256', $path);
    }

    /**
     * Вычислить SHA-256 хэш строки кода.
     */
    public function hashCode(string $code): string
    {
        return hash('sha256', $code);
    }

    /**
     * Проверить целостность файла по хэшу.
     *
     * @param string $path Путь к файлу
     * @param string $expectedHash Ожидаемый SHA-256 хэш
     * @return bool true если хэш совпадает
     */
    public function verifyIntegrity(string $path, string $expectedHash): bool
    {
        if (!file_exists($path)) {
            return false;
        }

        return hash_equals($expectedHash, $this->hashFile($path));
    }

    /**
     * Получить whitelist разрешённых функций.
     *
     * @return string[]
     */
    public function getAllowedFunctions(): array
    {
        return self::ALLOWED_FUNCTIONS;
    }

    /**
     * Получить whitelist разрешённых классов.
     *
     * @return string[]
     */
    public function getAllowedClasses(): array
    {
        return self::ALLOWED_CLASSES;
    }

    /**
     * Проверка backtick-оператора вне строковых литералов.
     */
    protected function checkBackticks(string $code): array
    {
        $errors = [];
        $tokens = @token_get_all($code);

        foreach ($tokens as $token) {
            // Backtick operator — это отдельный не-массивный токен '`'
            if (!is_array($token) && $token === '`') {
                $errors[] = 'Использование backtick-оператора (`) запрещено — позволяет выполнение shell-команд.';
                break; // Достаточно одной ошибки
            }
        }

        return $errors;
    }

    /**
     * Валидация структуры файла: ровно один класс, реализующий BlockActionInterface.
     */
    protected function validateStructure(array $tokens): array
    {
        $errors = [];
        $classCount = 0;
        $implementsInterface = false;
        $inClassDeclaration = false;

        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                // Открывающая фигурная скобка завершает объявление класса
                if ($token === '{' && $inClassDeclaration) {
                    $inClassDeclaration = false;
                }
                continue;
            }

            [$tokenId, $tokenValue] = $token;

            // Считаем объявления классов (не анонимных)
            if ($tokenId === T_CLASS) {
                // Проверяем, что это не анонимный класс (new class { ... })
                // Ищем назад, пропуская whitespace, — если перед class стоит new, это анонимный класс
                $isAnonymous = false;
                for ($j = $i - 1; $j >= 0; $j--) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                        continue;
                    }
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_NEW) {
                        $isAnonymous = true;
                    }
                    break;
                }

                if (!$isAnonymous) {
                    $classCount++;
                    $inClassDeclaration = true;
                }
            }

            // Ищем implements BlockActionInterface
            if ($tokenId === T_IMPLEMENTS) {
                // Сканируем вперёд до '{' чтобы найти все имплементируемые интерфейсы
                for ($j = $i + 1; $j < count($tokens); $j++) {
                    if (!is_array($tokens[$j])) {
                        if ($tokens[$j] === '{') {
                            break;
                        }
                        continue;
                    }

                    if ($tokens[$j][0] === T_STRING || $tokens[$j][0] === T_NAME_QUALIFIED || $tokens[$j][0] === T_NAME_FULLY_QUALIFIED) {
                        $name = $tokens[$j][1];
                        if (
                            $name === 'BlockActionInterface'
                            || str_ends_with($name, '\\BlockActionInterface')
                        ) {
                            $implementsInterface = true;
                        }
                    }
                }
            }
        }

        if ($classCount === 0) {
            $errors[] = 'Файл не содержит объявления класса.';
        } elseif ($classCount > 1) {
            $errors[] = "Файл содержит {$classCount} классов — допускается ровно один.";
        }

        if ($classCount > 0 && !$implementsInterface) {
            $errors[] = 'Класс должен реализовывать интерфейс BlockActionInterface.';
        }

        return $errors;
    }

    /**
     * Проверка запрещённых токенов.
     */
    protected function validateTokens(array $tokens): array
    {
        $errors = [];
        $foundForbidden = [];

        foreach ($tokens as $token) {
            if (!is_array($token)) {
                continue;
            }

            [$tokenId, $tokenValue, $line] = $token;

            if (in_array($tokenId, self::FORBIDDEN_TOKENS, true)) {
                // T_INLINE_HTML: допускаем пустые пробельные символы перед <?php
                if ($tokenId === T_INLINE_HTML && trim($tokenValue) === '') {
                    continue;
                }

                $name = self::FORBIDDEN_TOKEN_NAMES[$tokenId] ?? token_name($tokenId);

                // Не дублируем одинаковые ошибки
                if (!isset($foundForbidden[$name])) {
                    $errors[] = "Использование \"{$name}\" запрещено (строка {$line}).";
                    $foundForbidden[$name] = true;
                }
            }
        }

        return $errors;
    }

    /**
     * Проверка вызовов функций: разрешены только из whitelist.
     *
     * Обнаруживает паттерн: T_STRING + '(' — вызов функции.
     * Методы объектов ($obj->method()) и статические вызовы (Class::method())
     * пропускаются, т.к. контролируются через whitelist классов в new.
     */
    protected function validateFunctionCalls(array $tokens): array
    {
        $errors = [];
        $reported = [];

        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                continue;
            }

            [$tokenId, $tokenValue, $line] = $token;

            // Ищем T_STRING за которым следует '('
            if ($tokenId !== T_STRING) {
                continue;
            }

            // Проверяем, что следующий значимый токен — '('
            $nextIndex = $this->findNextNonWhitespace($tokens, $i);
            if ($nextIndex === null) {
                continue;
            }

            $nextToken = $tokens[$nextIndex];
            if (is_array($nextToken) || $nextToken !== '(') {
                continue;
            }

            // Проверяем, что это не метод объекта или статический вызов
            $prevIndex = $this->findPrevNonWhitespace($tokens, $i);
            if ($prevIndex !== null) {
                $prevToken = $tokens[$prevIndex];
                if (is_array($prevToken)) {
                    // T_OBJECT_OPERATOR (->), T_NULLSAFE_OBJECT_OPERATOR (?->),
                    // T_DOUBLE_COLON (::) — это вызовы методов, не функций
                    if (in_array($prevToken[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                        continue;
                    }
                    // T_FUNCTION — это определение функции, не вызов
                    if ($prevToken[0] === T_FUNCTION) {
                        continue;
                    }
                }
            }

            $funcName = strtolower($tokenValue);

            // Проверяем whitelist
            if (!$this->isFunctionAllowed($tokenValue)) {
                $key = $funcName;
                if (!isset($reported[$key])) {
                    $errors[] = "Вызов функции \"{$tokenValue}\" запрещён (строка {$line}). Используйте только функции из whitelist.";
                    $reported[$key] = true;
                }
            }
        }

        return $errors;
    }

    /**
     * Проверка оператора new: разрешены только классы из whitelist.
     */
    protected function validateNewOperator(array $tokens): array
    {
        $errors = [];
        $reported = [];

        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];

            if (!is_array($token) || $token[0] !== T_NEW) {
                continue;
            }

            $line = $token[2];

            // Собираем имя класса после new
            $className = '';
            $j = $i + 1;

            // Пропускаем пробелы
            while ($j < count($tokens) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }

            // Проверяем, что это не анонимный класс (new class { })
            if ($j < count($tokens) && is_array($tokens[$j]) && $tokens[$j][0] === T_CLASS) {
                // Анонимные классы запрещены — могут содержать произвольный код
                $errors[] = "Анонимные классы (new class) запрещены (строка {$line}).";
                continue;
            }

            // Собираем полное имя класса (может содержать namespace разделители)
            while ($j < count($tokens)) {
                if (is_array($tokens[$j])) {
                    $tid = $tokens[$j][0];
                    if (
                        $tid === T_STRING
                        || $tid === T_NAME_QUALIFIED
                        || $tid === T_NAME_FULLY_QUALIFIED
                        || $tid === T_NS_SEPARATOR
                    ) {
                        $className .= $tokens[$j][1];
                        $j++;
                        continue;
                    }
                    if ($tid === T_WHITESPACE) {
                        $j++;
                        continue;
                    }
                }
                break;
            }

            $className = trim($className);

            if ($className === '') {
                // new $var — variable class instantiation
                $errors[] = "Создание объектов через переменную (new \$var) запрещено (строка {$line}).";
                continue;
            }

            if (!$this->isClassAllowed($className)) {
                $key = strtolower($className);
                if (!isset($reported[$key])) {
                    $errors[] = "Создание объекта класса \"{$className}\" запрещено (строка {$line}). Разрешены только: " . implode(', ', $this->getSimpleClassNames()) . '.';
                    $reported[$key] = true;
                }
            }
        }

        return $errors;
    }

    /**
     * Проверка variable functions: $var() — запрещены.
     */
    protected function validateVariableFunctions(array $tokens): array
    {
        $errors = [];

        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];

            if (!is_array($token) || $token[0] !== T_VARIABLE) {
                continue;
            }

            // Проверяем, что следующий значимый токен — '('
            $nextIndex = $this->findNextNonWhitespace($tokens, $i);
            if ($nextIndex === null) {
                continue;
            }

            $nextToken = $tokens[$nextIndex];
            if (is_array($nextToken) || $nextToken !== '(') {
                continue;
            }

            // Проверяем, что перед переменной нет -> или :: (вызов метода на переменной — ок)
            $prevIndex = $this->findPrevNonWhitespace($tokens, $i);
            if ($prevIndex !== null && is_array($tokens[$prevIndex])) {
                if (in_array($tokens[$prevIndex][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                    continue;
                }
            }

            $line = $token[2];
            $varName = $token[1];
            $errors[] = "Variable function \"{$varName}()\" запрещена (строка {$line}) — может использоваться для вызова произвольных функций.";
        }

        return $errors;
    }

    /**
     * Проверить, разрешена ли функция.
     */
    protected function isFunctionAllowed(string $functionName): bool
    {
        $lower = strtolower($functionName);

        // Прямое совпадение
        foreach (self::ALLOWED_FUNCTIONS as $allowed) {
            if (strtolower($allowed) === $lower) {
                return true;
            }
        }

        return false;
    }

    /**
     * Проверить, разрешён ли класс для new.
     */
    protected function isClassAllowed(string $className): bool
    {
        // Нормализуем имя класса
        $normalized = ltrim($className, '\\');

        foreach (self::ALLOWED_CLASSES as $allowed) {
            $allowedNormalized = ltrim($allowed, '\\');
            if (strcasecmp($normalized, $allowedNormalized) === 0) {
                return true;
            }
        }

        // Разрешаем модели Templite (Eloquent)
        if (str_starts_with($normalized, 'Templite\\Cms\\Models\\')) {
            return true;
        }

        // Разрешаем модели приложения
        if (str_starts_with($normalized, 'App\\Models\\')) {
            return true;
        }

        return false;
    }

    /**
     * Получить простые имена классов для сообщений об ошибках.
     */
    protected function getSimpleClassNames(): array
    {
        $names = [];
        foreach (self::ALLOWED_CLASSES as $class) {
            $parts = explode('\\', ltrim($class, '\\'));
            $name = end($parts);
            $names[$name] = $name;
        }
        $names['Templite\\Cms\\Models\\*'] = 'Templite\\Cms\\Models\\*';
        $names['App\\Models\\*'] = 'App\\Models\\*';

        return array_values($names);
    }

    /**
     * Найти следующий не-whitespace токен.
     */
    protected function findNextNonWhitespace(array $tokens, int $from): ?int
    {
        for ($i = $from + 1; $i < count($tokens); $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                continue;
            }
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_COMMENT) {
                continue;
            }
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_DOC_COMMENT) {
                continue;
            }
            return $i;
        }
        return null;
    }

    /**
     * Найти предыдущий не-whitespace токен.
     */
    protected function findPrevNonWhitespace(array $tokens, int $from): ?int
    {
        for ($i = $from - 1; $i >= 0; $i--) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                continue;
            }
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_COMMENT) {
                continue;
            }
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_DOC_COMMENT) {
                continue;
            }
            return $i;
        }
        return null;
    }
}
