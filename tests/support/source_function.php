<?php

declare(strict_types=1);

// Load the actual request helpers without starting the configured API/database.
// Keep the regression independent of installation secrets and external panels.
function loadTestSourceFunction(string $path, string $name): void
{
    $tokens = token_get_all((string) file_get_contents($path));
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
            continue;
        }

        $j = $i + 1;
        while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if (!is_array($tokens[$j] ?? null) || $tokens[$j][1] !== $name) {
            continue;
        }

        $source = '';
        $depth = 0;
        $bodyStarted = false;
        for (; $i < $count; $i++) {
            $token = $tokens[$i];
            $source .= is_array($token) ? $token[1] : $token;
            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $bodyStarted = true;
                $depth++;
            } elseif ($token === '}' && $bodyStarted && --$depth === 0) {
                eval($source);
                return;
            }
        }
    }

    throw new RuntimeException('Test function not found: ' . $name);
}
