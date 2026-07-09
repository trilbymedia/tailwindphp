<?php

declare(strict_types=1);

namespace TailwindPHP\Minifier;

/**
 * CSS Minifier - Reduces CSS file size while preserving functionality.
 *
 * Optimizations:
 * - Remove comments
 * - Remove unnecessary whitespace
 * - Shorten hex colors (#ffffff → #fff)
 * - Remove units from zero values (0px → 0)
 * - Shorten font-weight keywords (normal → 400, bold → 700)
 * - Remove empty rules
 *
 * Does NOT:
 * - Merge duplicate selectors (makes debugging harder)
 * - Combine shorthand properties (can affect cascade)
 */
class CssMinifier
{
    /**
     * Minify a CSS string.
     *
     * @param string $css The CSS to minify
     * @return string Minified CSS
     */
    public static function minify(string $css): string
    {
        $css = self::removeComments($css);
        $css = self::removeWhitespace($css);
        $css = self::shortenHexColors($css);
        $css = self::removeZeroUnits($css);
        $css = self::shortenFontWeight($css);
        $css = self::removeEmptyRules($css);

        return trim($css);
    }

    /**
     * Remove CSS comments.
     */
    private static function removeComments(string $css): string
    {
        return preg_replace('/\/\*[\s\S]*?\*\//', '', $css);
    }

    /**
     * Remove unnecessary whitespace.
     */
    private static function removeWhitespace(string $css): string
    {
        $css = preg_replace('/\s+/', ' ', $css);

        $out = '';
        $depth = 0;         // parenthesis nesting depth
        $inDecl = false;    // are we directly inside a declaration block?
        $blockStack = [];   // saved $inDecl for each open brace
        $prelude = '';      // text of the current selector / at-rule prelude
        $len = strlen($css);

        for ($i = 0; $i < $len; $i++) {
            $ch = $css[$i];

            if ($ch === '(') {
                $depth++;
                $prelude .= $ch;
                $out .= $ch;

                continue;
            }

            if ($ch === ')') {
                $depth = max(0, $depth - 1);
                $prelude .= $ch;
                $out .= $ch;

                continue;
            }

            // A `{` opens a block; decide whether its body holds declarations
            // (a selector or a declaration-style at-rule like @font-face) or
            // nested rules (@media/@supports/@keyframes/...). Only inside a
            // declaration block is a `:` a property/value separator.
            if ($ch === '{') {
                $blockStack[] = $inDecl;
                $inDecl = self::opensDeclarationBlock($prelude);
                $prelude = '';
                $out .= $ch;

                continue;
            }

            if ($ch === '}') {
                $inDecl = array_pop($blockStack) ?? false;
                $prelude = '';
                $out .= $ch;

                continue;
            }

            if ($ch === ';') {
                $prelude = '';
                $out .= $ch;

                continue;
            }

            if ($ch === ' ') {
                // Keep the space in the prelude so at-rule keywords stay separated
                // from their arguments (e.g. `@layer utilities`, not the run-on
                // `@layerutilities`) when opensDeclarationBlock() inspects it.
                $prelude .= ' ';

                $prev = $out !== '' ? substr($out, -1) : '';
                $next = $i + 1 < $len ? $css[$i + 1] : '';

                $stripAfter = '{};:(';
                $stripBefore = '{};,)';

                if ($depth === 0) {
                    $stripAfter .= ',';
                }

                // A space before `:` is a descendant combinator before a
                // pseudo-class in selector context (e.g. `.prose :where(h1)`) and
                // must be preserved, but a property/value separator inside a
                // declaration block or a media feature inside parens
                // (e.g. `color :red`, `(min-width :640px)`) and must be stripped.
                $stripColonBefore = $inDecl || $depth > 0;

                if (str_contains($stripAfter, $prev)
                    || str_contains($stripBefore, $next)
                    || ($next === ':' && $stripColonBefore)) {
                    continue;
                }

                // Only strip around selector combinators at depth 0
                if ($depth === 0) {
                    $combinators = '>~+';
                    if (str_contains($combinators, $prev) || str_contains($combinators, $next)) {
                        continue;
                    }
                }

                $out .= $ch;

                continue;
            }

            $prelude .= $ch;
            $out .= $ch;
        }

        return str_replace(';}', '}', $out);
    }

    /**
     * Decide whether the block opened after the given prelude contains
     * declarations (true) or nested rules (false). Selectors and declaration-
     * style at-rules (@font-face, @page, @property, keyframe steps) hold
     * declarations; grouping/conditional at-rules (@media, @supports, @container,
     * @layer, @scope, @keyframes, ...) hold nested rules.
     */
    private static function opensDeclarationBlock(string $prelude): bool
    {
        $prelude = ltrim($prelude);

        if ($prelude === '' || $prelude[0] !== '@') {
            return true;
        }

        // Extract the at-rule keyword (letters/dashes after the `@`).
        preg_match('/^@-?[a-z]+(?:-[a-z]+)*/i', $prelude, $m);
        $name = strtolower(ltrim($m[0] ?? '', '@'));
        // Normalize a vendor prefix (e.g. -webkit-keyframes -> keyframes).
        $name = preg_replace('/^(webkit|moz|ms|o)-/', '', $name);

        $ruleListAtRules = [
            'media', 'supports', 'container', 'layer', 'scope',
            'document', 'keyframes',
        ];

        return !in_array($name, $ruleListAtRules, true);
    }

    /**
     * Shorten 6-digit hex colors to 3-digit where possible.
     * #ffffff → #fff, #aabbcc → #abc
     */
    private static function shortenHexColors(string $css): string
    {
        return preg_replace_callback(
            '/#([0-9a-fA-F])\1([0-9a-fA-F])\2([0-9a-fA-F])\3\b/',
            fn ($m) => '#' . strtolower($m[1] . $m[2] . $m[3]),
            $css,
        );
    }

    /**
     * Remove units from zero values.
     * 0px → 0, 0rem → 0, 0em → 0
     *
     * Exceptions: 0s and 0ms (time values need units in animations)
     */
    private static function removeZeroUnits(string $css): string
    {
        // Match 0 followed by a unit, but not 0s or 0ms (time units)
        // Also preserve 0% in some contexts (gradients, etc.)
        return preg_replace(
            '/\b0(px|rem|em|ex|ch|vw|vh|vmin|vmax|cm|mm|in|pt|pc)\b/',
            '0',
            $css,
        );
    }

    /**
     * Shorten font-weight keywords to numeric values.
     * normal → 400, bold → 700
     */
    private static function shortenFontWeight(string $css): string
    {
        $css = preg_replace('/font-weight:normal\b/', 'font-weight:400', $css);
        $css = preg_replace('/font-weight:bold\b/', 'font-weight:700', $css);

        return $css;
    }

    /**
     * Remove empty rules.
     * .foo {} → (removed)
     */
    private static function removeEmptyRules(string $css): string
    {
        // Remove rules with empty declaration blocks
        // Match selector(s) followed by empty braces
        return preg_replace('/[^{}]+\{\s*\}/', '', $css);
    }
}
