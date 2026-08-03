<?php

declare(strict_types=1);

namespace Cpsit\CpsUtility\Fixture;

/**
 * Splits raw SQL file contents into a list of individually executable
 * statements.
 *
 * Recognises and skips, without treating their contents as syntax:
 *  - `--` line comments
 *  - `#` line comments
 *  - `/ * ... * /` block comments (including multi-line)
 *  - single-quoted string literals ('...'), with '' and \' escaping
 *  - double-quoted identifiers ("..."), with "" and \" escaping
 *  - backtick-quoted identifiers (`...`), with `` escaping
 *
 * Statements are split only on a `;` character that is outside all of the
 * above contexts. Empty statements (comment-only input, blank input,
 * trailing/leading whitespace) are omitted from the result.
 */
final class SqlStatementSplitter
{
    /**
     * @return list<string>
     */
    public function split(string $sql): array
    {
        $length = strlen($sql);
        $statements = [];
        $current = '';
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if (($char === '-' && $next === '-') || $char === '#') {
                $i = $this->skipToEndOfLine($sql, $i, $length);
                continue;
            }

            if ($char === '/' && $next === '*') {
                $i = $this->skipBlockComment($sql, $i, $length);
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                [$literal, $i] = $this->consumeQuoted($sql, $i, $length, $char);
                $current .= $literal;
                continue;
            }

            if ($char === ';') {
                $statements[] = trim($current);
                $current = '';
                $i++;
                continue;
            }

            $current .= $char;
            $i++;
        }

        if (trim($current) !== '') {
            $statements[] = trim($current);
        }

        return array_values(array_filter(
            $statements,
            static fn(string $statement): bool => $statement !== ''
        ));
    }

    private function skipToEndOfLine(string $sql, int $i, int $length): int
    {
        while ($i < $length && $sql[$i] !== "\n") {
            $i++;
        }

        return $i;
    }

    private function skipBlockComment(string $sql, int $i, int $length): int
    {
        $i += 2; // consume "/*"

        while ($i < $length) {
            if ($sql[$i] === '*' && $i + 1 < $length && $sql[$i + 1] === '/') {
                return $i + 2;
            }
            $i++;
        }

        return $i;
    }

    /**
     * Consumes a quoted string/identifier starting at $i (which points at
     * the opening quote character $quoteChar), honouring doubled-quote
     * escaping ('', "", ``) and, for ' and ", backslash escaping (\', \").
     * Backtick identifiers do not support backslash escaping (matches
     * MySQL behaviour: only doubling the backtick escapes it).
     *
     * @return array{0: string, 1: int} the consumed literal (including both
     *     quote characters) and the index just after the closing quote
     */
    private function consumeQuoted(string $sql, int $i, int $length, string $quoteChar): array
    {
        $literal = $quoteChar;
        $i++;

        while ($i < $length) {
            $char = $sql[$i];

            if ($quoteChar !== '`' && $char === '\\' && $i + 1 < $length) {
                $literal .= $char . $sql[$i + 1];
                $i += 2;
                continue;
            }

            if ($char === $quoteChar) {
                if ($i + 1 < $length && $sql[$i + 1] === $quoteChar) {
                    $literal .= $quoteChar . $quoteChar;
                    $i += 2;
                    continue;
                }

                $literal .= $quoteChar;
                return [$literal, $i + 1];
            }

            $literal .= $char;
            $i++;
        }

        return [$literal, $i];
    }
}
