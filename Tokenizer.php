<?php
declare(strict_types=1);

/**
 * Tokenizer: HTML 문자열을 토큰으로 분해 (순수 파싱만 담당)
 */
class Tokenizer
{
    /**
     * HTML 문자열을 토큰 배열로 변환
     * 
     * @param string $html
     * @return array 토큰 배열
     */
    public function tokenize(string $html): array
    {
        $tokens = [];
        $len = strlen($html);
        $i = 0;
        $buffer = '';
        $inTag = false;
        $inQuote = false;
        $quoteChar = '';

        while ($i < $len) {
            // PHP block 감지: {@ ... }
            if (!$inTag && !$inQuote && substr($html, $i, 2) === '{@') {
                $this->flushTextBuffer($tokens, $buffer);
                $i = $this->tokenizePhpBlock($html, $i, $len, $tokens);
                continue;
            }

            // 주석 감지
            if (!$inTag && !$inQuote && $this->isCommentStart($html, $i)) {
                $this->flushTextBuffer($tokens, $buffer);
                $i = $this->tokenizeComment($html, $i, $len, $tokens);
                continue;
            }

            $ch = $html[$i];

            // 태그 시작
            if (!$inTag && $ch === '<' && !$inQuote) {
                $this->flushTextBuffer($tokens, $buffer);
                $inTag = true;
                $inQuote = false;
                $quoteChar = '';
                $buffer = $ch;
                $i++;
                continue;
            }

            // 태그 내부 처리
            if ($inTag) {
                $buffer .= $ch;

                // 인용부호 토글 (간단한 처리)
                if (($ch === '"' || $ch === "'") && ($i === 0 || $html[$i-1] !== '\\')) {
                    if ($inQuote && $ch === $quoteChar) {
                        $inQuote = false;
                        $quoteChar = '';
                    } elseif (!$inQuote) {
                        $inQuote = true;
                        $quoteChar = $ch;
                    }
                }

                // 태그 종료
                if ($ch === '>' && !$inQuote) {
                    $parsed = $this->parseTag($buffer);
                    $tokens[] = [
                        'type' => 'tag',
                        'value' => $buffer,
                        'parsed' => $parsed
                    ];
                    $buffer = '';
                    $inTag = false;
                }

                $i++;
                continue;
            }

            // 일반 텍스트
            $buffer .= $ch;
            $i++;
        }

        // 남은 버퍼 처리
        $this->flushRemainingBuffer($tokens, $buffer, $inTag);

        return $tokens;
    }

    /**
     * PHP 블록 토큰화: {@ ... }
     * 내부는 완전한 raw text로 처리
     */
    private function tokenizePhpBlock(string $html, int $start, int $len, array &$tokens): int
    {
        $depth = 1;
        $i = $start + 2; // Skip '{@'
        $content = '';

        while ($i < $len && $depth > 0) {
            $ch = $html[$i];

            if ($ch === '{' && $i + 1 < $len && $html[$i + 1] === '@') {
                $depth++;
                $content .= '{@';
                $i += 2;
                continue;
            }

            if ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    // 종료
                    break;
                }
                $content .= $ch;
                $i++;
                continue;
            }

            $content .= $ch;
            $i++;
        }

        // Add as text token (will be processed by renderer)
        $tokens[] = [
            'type' => 'text',
            'value' => '{@' . $content . '}'
        ];

        return $i + 1; // Skip closing '}'
    }

    /**
     * 주석 시작 여부 확인
     */
    private function isCommentStart(string $html, int $pos): bool
    {
        return substr($html, $pos, 4) === '<!--';
    }

    /**
     * 주석을 토큰화하고 다음 위치 반환
     */
    private function tokenizeComment(string $html, int $start, int $len, array &$tokens): int
    {
        $commentEnd = strpos($html, '-->', $start + 4);
        
        if ($commentEnd === false) {
            // 종료되지 않은 주석
            $commentValue = substr($html, $start + 4);
            $tokens[] = [
                'type' => 'comment',
                'value' => $commentValue
            ];
            return $len;
        }
        
        // 정상 종료된 주석
        $commentValue = substr($html, $start + 4, $commentEnd - $start - 4);
        $tokens[] = [
            'type' => 'comment',
            'value' => $commentValue
        ];
        return $commentEnd + 3;
    }

    /**
     * 텍스트 버퍼를 토큰으로 추가
     */
    private function flushTextBuffer(array &$tokens, string &$buffer): void
    {
        if ($buffer !== '') {
            $tokens[] = ['type' => 'text', 'value' => $buffer];
            $buffer = '';
        }
    }

    /**
     * 남은 버퍼 처리
     */
    private function flushRemainingBuffer(array &$tokens, string $buffer, bool $inTag): void
    {
        if ($buffer === '') {
            return;
        }

        if ($inTag) {
            $tokens[] = [
                'type' => 'tag',
                'value' => $buffer,
                'parsed' => $this->parseTag($buffer)
            ];
        } else {
            $tokens[] = ['type' => 'text', 'value' => $buffer];
        }
    }

    /**
     * 기본 태그 파서
     */
    private function parseTag(string $tag): array
    {
        $isClosing = (bool) preg_match('/^<\s*\//', $tag);
        $isSelfClosing = (bool) preg_match('/\/\s*>$/', $tag);

        // 태그명 추출
        $tagName = $this->extractTagName($tag, $isClosing);

        // 속성 추출
        $attributes = [];
        if (!$isClosing) {
            $attributes = $this->extractAttributes($tag, $tagName);
        }

        return [
            'tagName' => $tagName,
            'isClosing' => $isClosing,
            'isSelfClosing' => $isSelfClosing,
            'attributes' => $attributes,
        ];
    }

    /**
     * 태그명 추출
     */
    private function extractTagName(string $tag, bool $isClosing): string
    {
        $pattern = $isClosing 
            ? '/<\s*\/\s*([a-zA-Z0-9:_-]+)/'
            : '/<\s*([a-zA-Z0-9:_-]+)/';
        
        if (preg_match($pattern, $tag, $m)) {
            return $m[1];
        }
        
        return '';
    }

    /**
     * 속성 추출
     */
    private function extractAttributes(string $tag, string $tagName): array
    {
        $attributes = [];
        
        // 태그명 이후부터 속성 파싱
        $afterTagName = preg_replace('/^<\s*' . preg_quote($tagName, '/') . '\s*/i', '', $tag);
        $afterTagName = preg_replace('/\/?\s*>$/', '', $afterTagName);
        
        $pattern = '/([a-zA-Z0-9:_-]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/';
        
        if (preg_match_all($pattern, $afterTagName, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $name = $match[1];
                
                if (isset($match[2]) && $match[2] !== '') {
                    $value = $match[2];
                } elseif (isset($match[3]) && $match[3] !== '') {
                    $value = $match[3];
                } elseif (isset($match[4]) && $match[4] !== '') {
                    $value = $match[4];
                } else {
                    $value = true;
                }
                
                $attributes[$name] = $value;
            }
        }
        
        return $attributes;
    }
}