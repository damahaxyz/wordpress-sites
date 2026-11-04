<?php
/** Tokenizes samples as data. Never includes, evaluates or executes sample code. */
declare(strict_types=1);
$report = [];
$dangerous = ['eval', 'assert', 'exec', 'system', 'shell_exec', 'passthru', 'proc_open', 'popen', 'pcntl_exec', 'create_function', 'base64_decode', 'gzinflate', 'gzuncompress', 'str_rot13'];
foreach (glob('/samples/*', GLOB_ONLYDIR) as $directory) {
    $item = ['plugin' => basename($directory), 'php_files' => 0, 'execution_or_encoding_calls' => [], 'dynamic_calls' => []];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isLink() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $item['php_files']++;
        $tokens = array_values(array_filter(token_get_all(file_get_contents($file->getPathname())), static fn ($t) => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        foreach ($tokens as $i => $token) {
            if (! is_array($token)) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($directory) + 1);
            $previous = $tokens[$i - 1] ?? null;
            $method = is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NULLSAFE_OBJECT_OPERATOR], true);
            if ($token[0] === T_EVAL || ($token[0] === T_STRING && in_array(strtolower($token[1]), $dangerous, true) && ($tokens[$i + 1] ?? null) === '(' && ! $method)) {
                $item['execution_or_encoding_calls'][] = ['file' => $relative, 'line' => $token[2], 'call' => $token[1]];
            }
            if ($token[0] === T_VARIABLE && ($tokens[$i + 1] ?? null) === '(' && ! $method) {
                $item['dynamic_calls'][] = ['file' => $relative, 'line' => $token[2], 'call' => $token[1]];
            }
        }
    }
    $report[] = $item;
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
