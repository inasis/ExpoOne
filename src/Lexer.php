<?php
declare(strict_types=1);

namespace ExpoOne;

/**
 * HtmlLexer: 토큰 분석 및 검증
 */
class Lexer
{
    private Tokenizer $tokenizer;

    public function __construct()
    {
        $this->tokenizer = new Tokenizer();
    }

    /**
     * HTML을 토큰으로 분해하고 디렉티브 형식을 검증
     */
    public function analyze(string $html): array
    {
        $tokens = $this->tokenizer->tokenize($html);
        $this->validateDirectiveFormats($tokens);
        return $tokens;
    }

    /**
     * 주석 디렉티브 형식 규칙 검증:
     * 주석이 '@'로 시작하는 경우(디렉티브), 괄호 (...) 제거 후 공백이 없어야 함
     * 
     * 유효한 예: "@if($a)" or "@else" or "@foreach($x as $y)"
     * 무효한 예: "@ if($a)" or "@if ( $a )"  <-- 괄호 밖 공백 불허
     */
    private function validateDirectiveFormats(array $tokens): void
    {
        foreach ($tokens as $idx => $t) {
            // 타입 안정성 검증
            if (!isset($t['type'], $t['value'])) {
                throw new ParseException("Invalid token structure at index #{$idx}");
            }

            if ($t['type'] !== 'comment') {
                continue;
            }

            $text = trim($t['value']);
            
            // 빈 주석이거나 디렉티브가 아닌 경우 스킵
            if ($text === '' || $text[0] !== '@') {
                continue;
            }

            $this->validateSingleDirective($text, $idx);
        }
    }

    /**
     * 단일 디렉티브의 형식을 검증
     */
    private function validateSingleDirective(string $text, int $tokenIndex): void
    {
        // 괄호를 재귀적으로 제거 (중첩 괄호 지원)
        $withoutParens = $this->removeBalancedParentheses($text);
        
        if ($withoutParens === null) {
            throw new ParseException(
                "Directive format error at token #{$tokenIndex}: " .
                "Unbalanced parentheses detected. Found: '{$text}'"
            );
        }

        // 괄호 제거 후 공백 검증
        if (preg_match('/\s/', $withoutParens)) {
            throw new ParseException(
                "Directive format error at token #{$tokenIndex}: " .
                "Whitespace outside parentheses is not allowed.\n" .
                "Found: '{$text}'\n" .
                "Valid examples: '@if(\$condition)', '@else', '@foreach(\$items as \$item)'"
            );
        }

        // 추가 검증: '@' 바로 뒤에 공백이 있는지 확인
        if (strlen($text) > 1 && ctype_space($text[1])) {
            throw new ParseException(
                "Directive format error at token #{$tokenIndex}: " .
                "Whitespace immediately after '@' is not allowed. Found: '{$text}'"
            );
        }
    }

    /**
     * 균형잡힌 괄호를 재귀적으로 제거
     * 문자열 리터럴 내부의 괄호는 무시
     * 
     * @return string|null 성공 시 괄호가 제거된 문자열, 괄호가 불균형이면 null
     */
    private function removeBalancedParentheses(string $text): ?string
    {
        $result = '';
        $length = strlen($text);
        $depth = 0;
        $inString = false;
        $stringChar = '';
        $escaped = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];

            // 이스케이프 처리
            if ($escaped) {
                $escaped = false;
                continue;
            }

            if ($char === '\\') {
                $escaped = true;
                continue;
            }

            // 문자열 리터럴 처리
            if (($char === '"' || $char === "'") && !$inString) {
                $inString = true;
                $stringChar = $char;
                continue;
            } elseif ($char === $stringChar && $inString) {
                $inString = false;
                $stringChar = '';
                continue;
            }

            // 문자열 내부에 있으면 괄호를 무시
            if ($inString) {
                continue;
            }

            // 괄호 처리
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth < 0) {
                    return null; // 불균형 괄호
                }
            } elseif ($depth === 0) {
                // 괄호 밖의 문자만 결과에 추가
                $result .= $char;
            }
        }

        // 괄호가 닫히지 않았으면 null 반환
        return ($depth === 0 && !$inString) ? $result : null;
    }
}