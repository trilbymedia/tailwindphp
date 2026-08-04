<?php

declare(strict_types=1);

namespace TailwindPHP\Tests;

use PHPUnit\Framework\TestCase;
use TailwindPHP\Tailwind;

class IssueRegressionTest extends TestCase
{
    public function test_infer_data_type_dispatch_survives_vendor_namespace_prefixing(): void
    {
        $tempDir = sys_get_temp_dir() . '/tailwindphp-prefix-' . bin2hex(random_bytes(6));
        mkdir($tempDir);

        try {
            foreach (['segment.php', 'math-operators.php', 'is-color.php', 'infer-data-type.php'] as $file) {
                $source = file_get_contents(__DIR__ . '/../src/utils/' . $file);
                $source = str_replace(
                    'namespace TailwindPHP\\Utils;',
                    'namespace ScopedVendor\\TailwindPHP\\Utils;',
                    $source,
                );
                file_put_contents($tempDir . '/' . $file, $source);
            }

            file_put_contents(
                $tempDir . '/run.php',
                <<<'PHP'
<?php

declare(strict_types=1);

require __DIR__ . '/segment.php';
require __DIR__ . '/math-operators.php';
require __DIR__ . '/is-color.php';
require __DIR__ . '/infer-data-type.php';

use function ScopedVendor\TailwindPHP\Utils\inferDataType;

$cases = [
    ['15px', ['color', 'length'], 'length'],
    ['#ff0000', ['color', 'length'], 'color'],
    ['50%', ['percentage', 'length'], 'percentage'],
];

foreach ($cases as [$value, $types, $expected]) {
    $actual = inferDataType($value, $types);

    if ($actual !== $expected) {
        fwrite(STDERR, "{$value}: expected {$expected}, got " . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}
PHP,
            );

            $output = [];
            $exitCode = 0;
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tempDir . '/run.php') . ' 2>&1', $output, $exitCode);

            $this->assertSame(0, $exitCode, implode("\n", $output));
        } finally {
            foreach (glob($tempDir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($tempDir);
        }
    }

    public function test_minified_arbitrary_math_values_preserve_function_spacing(): void
    {
        $css = Tailwind::generate([
            'content' => '<div class="h-[clamp(9.375rem,7.635rem_+_8.699vw,15.8125rem)] w-[calc(100%_+_10px)] mb-[min(1rem_+_2vw,3rem)]"></div>',
            'css' => '@import "tailwindcss/utilities";',
            'minify' => true,
        ]);

        $this->assertStringContainsString(
            'height:clamp(9.375rem, 7.635rem + 8.699vw, 15.8125rem)',
            $css,
        );
        $this->assertStringContainsString('width:calc(100% + 10px)', $css);
        $this->assertStringContainsString('margin-bottom:min(1rem + 2vw, 3rem)', $css);
        $this->assertStringNotContainsString('7.635rem+8.699vw', $css);
        $this->assertStringNotContainsString('7.635rem + 8.699vw,15.8125rem', $css);
    }

    public function test_encoded_ampersands_in_class_attributes_are_decoded_before_candidate_extraction(): void
    {
        $raw = '<div class="[&_svg:not([class*=size-])]:size-4"></div>';
        $encoded = '<div class="[&amp;_svg:not([class*=size-])]:size-4"></div>';

        $this->assertSame(
            Tailwind::extractCandidates($raw),
            Tailwind::extractCandidates($encoded),
        );
        $this->assertSame(
            ['[&_svg:not([class*=size-])]:size-4'],
            Tailwind::extractCandidates($encoded),
        );
    }

    public function test_encoded_ampersand_arbitrary_selector_classes_generate_css(): void
    {
        $css = Tailwind::generate([
            'content' => '<a class="inline-flex [&amp;_svg:not([class*=size-])]:size-4"><svg width="24" height="24"></svg></a>',
            'css' => '@import "tailwindcss/utilities";',
            'minify' => true,
        ]);

        $this->assertStringContainsString('svg:not([class*=size-])', $css);
        $this->assertStringContainsString('width:calc(var(--spacing) * 4)', $css);
        $this->assertStringContainsString('height:calc(var(--spacing) * 4)', $css);
    }

    public function test_full_tailwind_import_preserves_canonical_layer_order(): void
    {
        $css = Tailwind::generate([
            'content' => '<div class="text-red-500"></div>',
            'css' => '@import "tailwindcss"; @layer base { .text-red-500 { color: blue; } }',
            'minify' => false,
        ]);

        $layerOrder = strpos($css, '@layer theme, base, components, utilities;');
        $baseLayer = strpos($css, '@layer base');
        $utilitiesLayer = strpos($css, '@layer utilities');
        $customBaseLayer = strrpos($css, '@layer base');

        $this->assertNotFalse($layerOrder);
        $this->assertNotFalse($baseLayer);
        $this->assertNotFalse($utilitiesLayer);
        $this->assertNotFalse($customBaseLayer);

        $this->assertLessThan($baseLayer, $layerOrder);
        $this->assertLessThan($utilitiesLayer, $layerOrder);
        $this->assertLessThan($customBaseLayer, $layerOrder);
        $this->assertMatchesRegularExpression('/@layer utilities\s*\{[^}]*\.text-red-500/s', $css);
    }

    /**
     * Utilities that compose a shorthand out of several `--tw-*` variables have to
     * register every member of the group with `@property`. Without the registration
     * the unset members make the composed value invalid at computed-value time, so
     * e.g. `translate-x-7` updates `--tw-translate-x` but leaves `translate` at
     * `none` and nothing moves.
     *
     * @dataProvider composedVariableGroups
     */
    public function test_composed_variable_groups_register_their_properties(string $class, array $expectedProperties): void
    {
        $css = Tailwind::generate([
            'content' => "<div class=\"{$class}\"></div>",
            'css' => '@import "tailwindcss";',
            'minify' => false,
        ]);

        foreach ($expectedProperties as $property) {
            $this->assertMatchesRegularExpression(
                '/@property\s+' . preg_quote($property, '/') . '\s*\{/',
                $css,
                "`{$class}` should register `@property {$property}`",
            );
        }
    }

    public static function composedVariableGroups(): array
    {
        $translate = ['--tw-translate-x', '--tw-translate-y', '--tw-translate-z'];
        $scale = ['--tw-scale-x', '--tw-scale-y', '--tw-scale-z'];
        $transform = ['--tw-rotate-x', '--tw-rotate-y', '--tw-rotate-z', '--tw-skew-x', '--tw-skew-y'];
        $filter = [
            '--tw-blur', '--tw-brightness', '--tw-contrast', '--tw-grayscale', '--tw-hue-rotate',
            '--tw-invert', '--tw-opacity', '--tw-saturate', '--tw-sepia', '--tw-drop-shadow',
        ];
        $backdropFilter = [
            '--tw-backdrop-blur', '--tw-backdrop-brightness', '--tw-backdrop-contrast',
            '--tw-backdrop-grayscale', '--tw-backdrop-hue-rotate', '--tw-backdrop-invert',
            '--tw-backdrop-opacity', '--tw-backdrop-saturate', '--tw-backdrop-sepia',
        ];
        $numeric = [
            '--tw-ordinal', '--tw-slashed-zero', '--tw-numeric-figure',
            '--tw-numeric-spacing', '--tw-numeric-fraction',
        ];
        $contain = ['--tw-contain-size', '--tw-contain-layout', '--tw-contain-paint', '--tw-contain-style'];
        $touchAction = ['--tw-pan-x', '--tw-pan-y', '--tw-pinch-zoom'];
        $borderSpacing = ['--tw-border-spacing-x', '--tw-border-spacing-y'];

        return [
            'translate-x-7' => ['translate-x-7', $translate],
            'translate-4' => ['translate-4', $translate],
            'translate-y-full' => ['translate-y-full', $translate],
            'translate-3d' => ['translate-3d', $translate],
            'scale-50' => ['scale-50', $scale],
            'scale-x-50' => ['scale-x-50', $scale],
            'scale-3d' => ['scale-3d', $scale],
            'rotate-x-45' => ['rotate-x-45', $transform],
            'skew-x-6' => ['skew-x-6', $transform],
            'transform' => ['transform', $transform],
            'blur-sm' => ['blur-sm', $filter],
            'grayscale' => ['grayscale', $filter],
            'filter' => ['filter', $filter],
            'backdrop-blur-sm' => ['backdrop-blur-sm', $backdropFilter],
            'backdrop-filter' => ['backdrop-filter', $backdropFilter],
            'duration-300' => ['duration-300', ['--tw-duration']],
            'ease-out' => ['ease-out', ['--tw-ease']],
            'leading-6' => ['leading-6', ['--tw-leading']],
            'tracking-wide' => ['tracking-wide', ['--tw-tracking']],
            'tabular-nums' => ['tabular-nums', $numeric],
            'contain-layout' => ['contain-layout', $contain],
            'touch-pan-x' => ['touch-pan-x', $touchAction],
            'snap-x' => ['snap-x', ['--tw-scroll-snap-strictness']],
            'border-spacing-2' => ['border-spacing-2', $borderSpacing],
        ];
    }

    /**
     * The reported symptom: only `--tw-translate-x` changes between the three
     * states of a toggle, so `translate` has to stay valid with the other axes unset.
     */
    public function test_translate_x_composes_a_usable_translate_declaration(): void
    {
        $css = Tailwind::generate([
            'content' => '<div class="translate-x-0 translate-x-7 translate-x-14"></div>',
            'css' => '@import "tailwindcss";',
            'minify' => false,
        ]);

        foreach (['0', '7', '14'] as $step) {
            $this->assertMatchesRegularExpression(
                '/\.translate-x-' . $step . '\s*\{[^}]*translate:\s*var\(--tw-translate-x\)\s*var\(--tw-translate-y\)/',
                $css,
            );
        }

        $this->assertMatchesRegularExpression(
            '/@property\s+--tw-translate-y\s*\{[^}]*initial-value:\s*0/s',
            $css,
        );
    }
}
