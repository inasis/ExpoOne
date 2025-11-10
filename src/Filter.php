<?php
declare(strict_types=1);

namespace ExpoOne;

/**
 * Handles variable filters and escaping within template expressions.
 * Provides a set of built-in filters (e.g. trim, upper, date, json_encode)
 * and escape handlers (e.g. htmlspecialchars, rawurlencode).
 */
class Filter
{
    /**
     * @var array<string, array<string, mixed>>
     * Registry of available filters and their configurations.
     */
    private static array $filters = [
        'escapejs' => [
            'method' => 'json_encode',
            'defaultOption' => 'JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP',
            'needsEscape' => false
        ],
        'json' => [
            'method' => 'json_encode',
            'needsEscape' => false
        ],
        'strip' => [
            'method' => 'strip_tags',
            'needsEscape' => true
        ],
        'trim' => [
            'method' => 'trim',
            'needsEscape' => true
        ],
        'urlencode' => [
            'method' => 'rawurlencode',
            'needsEscape' => false
        ],
        'lower' => [
            'method' => 'strtolower',
            'needsEscape' => true
        ],
        'upper' => [
            'method' => 'strtoupper',
            'needsEscape' => true
        ],
        'nl2br' => [
            'method' => 'nl2br',
            'needsEscape' => false
        ],
        'join' => [
            'method' => 'implode',
            'defaultOption' => "', '",
            'needsEscape' => true
        ],
        'date' => [
            'method' => 'date',
            'defaultOption' => "'Y-m-d H:i:s'",
            'needsEscape' => false
        ],
        'number_format' => [
            'method' => 'number_format',
            'needsEscape' => false
        ],
        'number_shorten' => [
            'method' => 'number_shorten',
            'defaultOption' => '2',
            'needsEscape' => false
        ]
    ];

    /**
     * @var array<string, string|null>
     * Escape handlers used for auto-escaping or disabling escaping.
     */
    private static array $escapeHandlers = [
        'auto' => 'htmlspecialchars',
        'autoescape' => 'htmlspecialchars',
        'escape' => 'htmlspecialchars',
        'autolang' => 'htmlspecialchars',
        'noescape' => null
    ];

    /**
     * Parses a variable expression that may include one or more filters.
     *
     * @param string $expr The raw variable expression (e.g. "$user | upper | escape").
     * @return string The generated PHP expression as a string.
     * @throws ParseException If the expression or filter is invalid.
     */
    public static function parseVariableExpression(string $expr): string
    {
        $parts = explode('|', $expr);
        $var = trim(array_shift($parts));
        
        if (!preg_match('/^[\$a-zA-Z_][\w\[\]\->\$\(\)\'"., ]*$/', $var)) {
            throw new ParseException("Invalid variable expression: {$var}");
        }

        $code = $var;
        $needsEscape = true;
        $escapeHandler = 'htmlspecialchars';

        foreach ($parts as $filterExpr) {
            $filterExpr = trim($filterExpr);
            
            // Handle escape-related filters
            if (isset(self::$escapeHandlers[$filterExpr])) {
                $escapeHandler = self::$escapeHandlers[$filterExpr];
                $needsEscape = ($escapeHandler !== null);
                continue;
            }

            // Split into filter name and option (e.g., "date:'Y-m-d'")
            $colonPos = strpos($filterExpr, ':');
            if ($colonPos !== false) {
                $filterName = trim(substr($filterExpr, 0, $colonPos));
                $option = trim(substr($filterExpr, $colonPos + 1));
            } else {
                $filterName = $filterExpr;
                $option = null;
            }

            // Handle special case for "link" filter
            if ($filterName === 'link') {
                return self::applyLinkFilter($code, $option, $escapeHandler);
            }

            // Apply general filters
            $code = self::applyFilter($code, $filterName, $option);
            
            // Update escape requirements based on filter definition
            if (isset(self::$filters[$filterName])) {
                $filterConfig = self::$filters[$filterName];
                if (isset($filterConfig['needsEscape'])) {
                    $needsEscape = $filterConfig['needsEscape'];
                }
            }
        }

        // Apply final escaping if required
        if ($needsEscape && $escapeHandler) {
            if ($escapeHandler === 'htmlspecialchars') {
                $code = "{$escapeHandler}(\${$code}, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')";
            } else {
                $code = "{$escapeHandler}(\${$code})";
            }
        }

        return $code;
    }

    /**
     * Applies a registered filter to a variable.
     *
     * @param string $var The variable name (without `$` prefix).
     * @param string $filterName The name of the filter.
     * @param string|null $option Optional filter parameter.
     * @return string The PHP expression applying the filter.
     * @throws ParseException If the filter does not exist.
     */
    private static function applyFilter(string $var, string $filterName, ?string $option): string
    {
        if (!isset(self::$filters[$filterName])) {
            throw new ParseException("Unknown filter: {$filterName}");
        }

        $filter = self::$filters[$filterName];
        $method = $filter['method'];

        if ($option === null && isset($filter['defaultOption'])) {
            $option = $filter['defaultOption'];
        }
        
        if ($method === 'date') {
            return "date({$option}, \${$var})";
        }
        if ($method === 'number_shorten') {
            return "number_format(\${$var}/1000, {$option}).'K'";
        }

        return $option !== null ? "{$method}(\${$var}, {$option})" : "{$method}(\${$var})";
    }

    /**
     * Applies the selected escape handler to a variable.
     *
     * @param string $var The variable name (without `$` prefix).
     * @param string $handler The escape handler function name.
     * @return string The PHP expression for escaped output.
     */
    private static function applyEscape(string $var, string $handler): string
    {
        if ($handler === 'htmlspecialchars') {
            return "{$handler}(\${$var}, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')";
        }

        return "{$handler}(\${$var})";
    }

    /**
     * Applies the "link" filter, converting a variable into an HTML anchor element.
     * 
     * @param string $var The variable name (without `$` prefix).
     * @param string|null $option Optional link text.
     * @param string|null $escapeHandler Escape handler for sanitization.
     * @return string The PHP expression generating the HTML link.
     */
    private static function applyLinkFilter(string $var, ?string $option, ?string $escapeHandler): string
    {
        if ($escapeHandler === 'htmlspecialchars') {
            $escapedVar = "{$escapeHandler}(\${$var}, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')";
        } else {
            $escapedVar = "\$$var";
        }
        
        if ($option) {
            if ($escapeHandler === 'htmlspecialchars') {
                $escapedOption = "{$escapeHandler}((string)$option, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')";
            } else {
                $escapedOption = (string)$option;
            }
            return "'<a href=\"' . ({$escapedVar}) . '\">' . ({$escapedOption}) . '</a>'";
        }
        
        return "'<a href=\"' . ({$escapedVar}) . '\">' . ({$escapedVar}) . '</a>'";
    }
}
