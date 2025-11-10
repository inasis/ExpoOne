<?php
declare(strict_types=1);

require_once 'ParseException.php';

/**
 * Security validator for template code
 */
class Validator
{
    private const DANGEROUS_FUNCTIONS = [
        'eval', 'exec', 'system', 'shell_exec', 'passthru', 'proc_open',
        'file_get_contents', 'file_put_contents', 'fopen', 'fwrite',
        'include', 'require', 'include_once', 'require_once',
        'unlink', 'rmdir', 'mkdir', 'chmod', 'chown'
    ];

    private const DANGEROUS_VARIABLES = ['$_GET', '$_POST', '$_REQUEST', '$_COOKIE', '$_SERVER', '$_ENV'];

    public static function validatePhpCode(string $code): void
    {
        $cleanCode = self::removeCommentsAndStrings($code);
        
        foreach (self::DANGEROUS_FUNCTIONS as $func) {
            if (preg_match('/\b' . preg_quote($func) . '\s*\(/i', $cleanCode)) {
                throw new ParseException("Dangerous function '{$func}' is not allowed in template");
            }
        }

        foreach (self::DANGEROUS_VARIABLES as $var) {
            if (strpos($cleanCode, $var) !== false) {
                throw new ParseException("Direct access to superglobal '{$var}' is not allowed in template");
            }
        }

        if (strpos($cleanCode, '`') !== false) {
            throw new ParseException("Shell execution using backticks is not allowed in template");
        }
    }

    private static function removeCommentsAndStrings(string $code): string
    {
        $code = preg_replace('/\/\*.*?\*\//s', '', $code);
        $code = preg_replace('/\/\/.*$/m', '', $code);
        $code = preg_replace('/"([^"\\\\]|\\\\.)*"/', '""', $code);
        $code = preg_replace("/'([^'\\\\]|\\\\.)*'/", "''", $code);
        return $code;
    }
}