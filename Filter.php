<?php
declare(strict_types=1);

/**
 * Filter processor for variable output
 */
class Filter
{
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

    private static array $escapeHandlers = [
        'auto' => 'htmlspecialchars',
        'autoescape' => 'htmlspecialchars',
        'escape' => 'htmlspecialchars',
        'autolang' => 'htmlspecialchars',
        'noescape' => null
    ];

    /**
     * Parse variable expression with filters
     * Example: $var|lower|escape or $timestamp|date:'n/j H:i'
     */
    public static function parseVariableExpression(string $expr): string
    {
        // Split by pipe to get variable and filters
        $parts = explode('|', $expr);
        $var = trim(array_shift($parts));
        
        // Validate variable expression
        if (!preg_match('/^[\$a-zA-Z_][\w\[\]\->\$\(\)\'"., ]*$/', $var)) {
            throw new ParseException("Invalid variable expression: {$var}");
        }

        $code = $var;
        $needsEscape = true;
        $escapeHandler = 'htmlspecialchars';

        // Process each filter in sequence
        foreach ($parts as $filterExpr) {
            $filterExpr = trim($filterExpr);
            
            // Check for escape handler
            if (isset(self::$escapeHandlers[$filterExpr])) {
                $escapeHandler = self::$escapeHandlers[$filterExpr];
                $needsEscape = ($escapeHandler !== null);
                continue;
            }

            // Parse filter name and option (filter:option)
            $colonPos = strpos($filterExpr, ':');
            if ($colonPos !== false) {
                $filterName = trim(substr($filterExpr, 0, $colonPos));
                $option = trim(substr($filterExpr, $colonPos + 1));
            } else {
                $filterName = $filterExpr;
                $option = null;
            }

            // Special handling for link filter
            if ($filterName === 'link') {
                return self::applyLinkFilter($code, $option, $escapeHandler);
            }

            // Apply regular filter
            $code = self::applyFilter($code, $filterName, $option);
            
            // Update escape requirement based on filter config
            if (isset(self::$filters[$filterName])) {
                $filterConfig = self::$filters[$filterName];
                if (isset($filterConfig['needsEscape'])) {
                    $needsEscape = $filterConfig['needsEscape'];
                }
            }
        }

        // Apply final escape if needed
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
     * Apply filter to variable
     */
    private static function applyFilter(string $var, string $filterName, ?string $option): string
    {
        if (!isset(self::$filters[$filterName])) {
            throw new ParseException("Unknown filter: {$filterName}");
        }

        $filter = self::$filters[$filterName];
        $method = $filter['method'];

        // Use default option if not provided
        if ($option === null && isset($filter['defaultOption'])) {
            $option = $filter['defaultOption'];
        }
        
        if ($method === 'date') {
            return "date({$option}, \${$var})";
        }
        if ($method === 'number_shorten') {
            return "number_format(\${$var}/1000, {$option}).'K'";
        }

        // Apply filter method
        return $option !== null ? "{$method}(\${$var}, {$option})" : "{$method}(\${$var})";
    }

    /**
     * Apply escape handler
     */
    private static function applyEscape(string $var, string $handler): string
    {
        if ($handler === 'htmlspecialchars') {
            return "{$handler}(\${$var}, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')";
        }

        return "{$handler}(\${$var})";
    }

    /**
     * Special handling for link filter
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