<?php

namespace Kosmokrator\Tests\Unit\UI\Ansi;

use Kosmokrator\UI\Ansi\KosmokratorTerminalTheme;
use Kosmokrator\UI\Theme;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tempest\Highlight\Tokens\TokenTypeEnum;

class KosmokratorTerminalThemeTest extends TestCase
{
    private KosmokratorTerminalTheme $theme;

    protected function setUp(): void
    {
        $this->theme = new KosmokratorTerminalTheme;
    }

    public function test_detect_language_php(): void
    {
        $this->assertSame('php', KosmokratorTerminalTheme::detectLanguage('file.php'));
    }

    #[DataProvider('javascriptExtensionsProvider')]
    public function test_detect_language_javascript_variants(string $filename, string $expected): void
    {
        $this->assertSame($expected, KosmokratorTerminalTheme::detectLanguage($filename));
    }

    public static function javascriptExtensionsProvider(): array
    {
        return [
            'js' => ['app.js', 'javascript'],
            'mjs' => ['module.mjs', 'javascript'],
            'cjs' => ['common.cjs', 'javascript'],
            'ts' => ['types.ts', 'javascript'],
            'tsx' => ['component.tsx', 'javascript'],
        ];
    }

    public function test_detect_language_python(): void
    {
        $this->assertSame('python', KosmokratorTerminalTheme::detectLanguage('script.py'));
    }

    public function test_detect_language_unknown_extension(): void
    {
        $this->assertSame('', KosmokratorTerminalTheme::detectLanguage('file.xyz'));
    }

    public function test_detect_language_no_extension(): void
    {
        $this->assertSame('', KosmokratorTerminalTheme::detectLanguage('Makefile'));
    }

    public function test_detect_language_case_insensitive(): void
    {
        $this->assertSame('php', KosmokratorTerminalTheme::detectLanguage('file.PHP'));
    }

    #[DataProvider('allKnownExtensionsProvider')]
    public function test_detect_language_all_known_extensions(string $filename, string $expected): void
    {
        $this->assertSame($expected, KosmokratorTerminalTheme::detectLanguage($filename));
    }

    public static function allKnownExtensionsProvider(): array
    {
        return [
            'sql' => ['query.sql', 'sql'],
            'html' => ['page.html', 'html'],
            'htm' => ['page.htm', 'html'],
            'css' => ['style.css', 'css'],
            'scss' => ['style.scss', 'css'],
            'json' => ['data.json', 'json'],
            'xml' => ['config.xml', 'xml'],
            'svg' => ['icon.svg', 'xml'],
            'yaml' => ['config.yaml', 'yaml'],
            'yml' => ['config.yml', 'yaml'],
            'md' => ['README.md', 'markdown'],
            'markdown' => ['doc.markdown', 'markdown'],
            'dockerfile' => ['app.dockerfile', 'dockerfile'],
            'env' => ['config.env', 'dotenv'],
            'ini' => ['settings.ini', 'ini'],
            'cfg' => ['app.cfg', 'ini'],
            'conf' => ['server.conf', 'ini'],
            'twig' => ['template.twig', 'twig'],
            'diff' => ['changes.diff', 'diff'],
            'patch' => ['fix.patch', 'diff'],
        ];
    }

    public function test_before_returns_ansi_for_known_token_types(): void
    {
        $knownTypes = [
            TokenTypeEnum::KEYWORD,
            TokenTypeEnum::OPERATOR,
            TokenTypeEnum::TYPE,
            TokenTypeEnum::VALUE,
            TokenTypeEnum::NUMBER,
            TokenTypeEnum::LITERAL,
            TokenTypeEnum::VARIABLE,
            TokenTypeEnum::PROPERTY,
            TokenTypeEnum::GENERIC,
            TokenTypeEnum::COMMENT,
            TokenTypeEnum::ATTRIBUTE,
            TokenTypeEnum::HIDDEN,
        ];

        foreach ($knownTypes as $type) {
            $result = $this->theme->before($type);
            $this->assertNotEmpty($result, "before() for {$type->name} should return ANSI code");
        }
    }

    public function test_before_returns_empty_for_injection(): void
    {
        $this->assertSame('', $this->theme->before(TokenTypeEnum::INJECTION));
    }

    public function test_after_returns_reset_for_all_token_types(): void
    {
        $types = [
            TokenTypeEnum::KEYWORD,
            TokenTypeEnum::OPERATOR,
            TokenTypeEnum::INJECTION,
            TokenTypeEnum::HIDDEN,
        ];

        foreach ($types as $type) {
            $this->assertSame(Theme::reset(), $this->theme->after($type));
        }
    }
}
