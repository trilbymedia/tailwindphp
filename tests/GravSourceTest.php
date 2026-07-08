<?php

declare(strict_types=1);

namespace TailwindPHP\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GravSourceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/tailwindphp_grav_' . uniqid();
        mkdir($this->root . '/theme/css', 0755, true);
        mkdir($this->root . '/theme/templates', 0755, true);
        mkdir($this->root . '/theme/node_modules/noisy', 0755, true);
        mkdir($this->root . '/user/pages/01.home', 0755, true);
        mkdir($this->root . '/user/config', 0755, true);
        mkdir($this->root . '/user/plugins/form/templates', 0755, true);
        mkdir($this->root . '/user/plugins/admin/templates', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
    }

    #[Test]
    public function cli_scans_absolute_grav_source_paths_and_applies_negations(): void
    {
        file_put_contents($this->root . '/theme/css/site.css', sprintf(
            <<<'CSS'
@import "tailwindcss";
@source "%s/user/pages";
@source "%s/user/config";
@source "%s/user/plugins/form/templates";
@source not "%s/user/plugins/admin/templates";
CSS,
            $this->root,
            $this->root,
            $this->root,
            $this->root,
        ));

        file_put_contents($this->root . '/user/pages/01.home/default.md', "---\nhero_classes: \"bg-blue-500 md:grid-cols-2\"\n---\n");
        file_put_contents($this->root . '/user/config/site.yaml', "body_classes: 'text-white p-4'\n");
        file_put_contents($this->root . '/user/plugins/form/templates/form.html.twig', '<button class="rounded-md hover:bg-blue-600">Send</button>');
        file_put_contents($this->root . '/user/plugins/admin/templates/admin.html.twig', '<div class="hidden">Admin</div>');
        file_put_contents($this->root . '/theme/templates/theme.html.twig', '<section class="lg:grid-cols-3">Theme</section>');
        file_put_contents($this->root . '/theme/node_modules/noisy/package.js', 'const ignored = "accent-auto";');

        $output = $this->root . '/theme/build.css';
        $command = sprintf(
            '%s %s -i %s -o %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/bin/tailwindphp'),
            escapeshellarg($this->root . '/theme/css/site.css'),
            escapeshellarg($output),
        );

        $originalDir = getcwd();
        chdir($this->root . '/theme');
        try {
            exec($command, $lines, $exitCode);
        } finally {
            chdir($originalDir);
        }

        $this->assertSame(0, $exitCode, implode("\n", $lines));

        $css = file_get_contents($output);
        $this->assertIsString($css);
        $this->assertStringContainsString('.bg-blue-500', $css);
        $this->assertStringContainsString('.md\\:grid-cols-2', $css);
        $this->assertStringContainsString('.text-white', $css);
        $this->assertStringContainsString('.p-4', $css);
        $this->assertStringContainsString('.rounded-md', $css);
        $this->assertStringContainsString('.hover\\:bg-blue-600', $css);
        $this->assertStringContainsString('.lg\\:grid-cols-3', $css);
        $this->assertStringNotContainsString('.hidden', $css);
        $this->assertStringNotContainsString('.accent-auto', $css);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }

        rmdir($dir);
    }
}
