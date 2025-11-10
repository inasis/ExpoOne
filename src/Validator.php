<?php
declare(strict_types=1);

namespace ExpoOne;

/**
 * Performs security validation for embedded PHP code within templates.
 * 
 * The Validator scans PHP code for dangerous functions, variables, and syntax 
 * to prevent execution of unsafe code in the rendering environment.
 */
class Validator
{
    /**
     * List of dangerous PHP functions that must not appear in template code.
     *
     * @var string[]
     */
    private const DANGEROUS_FUNCTIONS = [
        'eval', 'exec', 'system', 'shell_exec', 'passthru', 'proc_open',
        'file_get_contents', 'file_put_contents', 'fopen', 'fwrite',
        'include', 'require', 'include_once', 'require_once',
        'unlink', 'rmdir', 'mkdir', 'chmod', 'chown'
    ];

    /**
     * List of PHP superglobal variables that are forbidden for direct use.
     *
     * @var string[]
     */
    private const DANGEROUS_VARIABLES = [
        '$_GET', '$_POST', '$_REQUEST', '$_COOKIE', '$_SERVER', '$_ENV'
    ];

    /**
     * Validates a given PHP code snippet to ensure it does not contain 
     * unsafe operations or forbidden constructs.
     *
     * The method scans for:
     *  - Dangerous functions
     *  - Direct access to superglobals
     *  - Shell execution using backticks
     *
     * @param string $code The PHP code string to validate.
     * @throws ParseException If dangerous functions, variables, or syntax are found.
     */
    public static function validatePhpCode(string $code): void
    {
        $cleanCode = self::removeCommentsAndStrings($code);
        
        // Check for dangerous function usage
        foreach (self::DANGEROUS_FUNCTIONS as $func) {
            if (preg_match('/\b' . preg_quote($func) . '\s*\(/i', $cleanCode)) {
                throw new ParseException("Dangerous function '{$func}' is not allowed in template");
            }
        }

        // Check for forbidden superglobals
        foreach (self::DANGEROUS_VARIABLES as $var) {
            if (strpos($cleanCode, $var) !== false) {
                throw new ParseException("Direct access to superglobal '{$var}' is not allowed in template");
            }
        }

        // Check for shell execution via backticks
        if (strpos($cleanCode, '`') !== false) {
            throw new ParseException("Shell execution using backticks is not allowed in template");
        }
    }

    /**
     * Removes comments and string literals from PHP code.
     *
     * This ensures the validator only inspects executable portions of code 
     * and ignores occurrences of dangerous keywords inside comments or strings.
     *
     * @param string $code The PHP code to sanitize.
     * @return string The cleaned code with comments and strings removed.
     */
    private static function removeCommentsAndStrings(string $code): string
    {
        // Remove multi-line comments
        $code = preg_replace('/\/\*.*?\*\//s', '', $code);

        // Remove single-line comments
        $code = preg_replace('/\/\/.*$/m', '', $code);

        // Replace double-quoted strings with empty placeholders
        $code = preg_replace('/"([^"\\\\]|\\\\.)*"/', '""', $code);

        // Replace single-quoted strings with empty placeholders
        $code = preg_replace("/'([^'\\\\]|\\\\.)*'/", "''", $code);

        return $code;
    }
}
