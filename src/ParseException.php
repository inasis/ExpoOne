<?php
declare(strict_types=1);

namespace ExpoOne;

/**
 * ParseException for template parse errors
 */
class ParseException extends \RuntimeException 
{
    private int $column;
    private string $context;

    public function __construct(string $message, int $line = 0, int $column = 0, string $context = '')
    {
        $this->line = $line;
        $this->column = $column;
        $this->context = $context;
        
        $fullMessage = $message;
        if ($line > 0) {
            $fullMessage .= " at line {$line}";
            if ($column > 0) {
                $fullMessage .= ", column {$column}";
            }
        }
        if ($context) {
            $fullMessage .= ". Context: {$context}";
        }
        
        parent::__construct($fullMessage);
    }

    public function getColumn(): int { return $this->column; }
    public function getContext(): string { return $this->context; }
}