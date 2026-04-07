# ImageWidget — Inline Terminal Image Rendering

> **Module**: `src/UI/Tui/Widget/ImageWidget.php` + supporting protocol classes
> **Dependencies**: Symfony TUI `AbstractWidget`, `ImageProtocolInterface` (Console component), `TerminalInterface`
> **Blocks**: None (standalone widget)
> **Status**: Design

## 1. Overview

The ImageWidget renders images inline within the TUI conversation flow. It detects which image protocol the terminal supports (Kitty, iTerm2, Sixel, or none), encodes the image accordingly, and falls back to ASCII/braille art or a placeholder when no protocol is available.

### Use Cases

| Use Case | Example |
|----------|---------|
| Architecture diagrams | Rendered from Mermaid → PNG, displayed inline |
| Screenshot display | AI-generated screenshots shown in conversation |
| Logo/branding | Intro screen logo rendered as terminal graphics |
| Chart output | Data visualizations rendered inline |

## 2. Existing Infrastructure

### 2.1 Symfony Console Image Protocols

The Symfony Console component already ships protocol implementations under `Symfony\Component\Console\Terminal\Image\`:

**`ImageProtocolInterface`** — contract with three methods:
```php
interface ImageProtocolInterface
{
    public function detectPastedImage(string $data): bool;
    public function decode(string $data): array;       // ['data' => string, 'format' => string|null]
    public function encode(string $imageData, ?int $maxWidth = null): string;
    public function getName(): string;
}
```

**`KittyGraphicsProtocol`** — APC-based (`\x1b_G<control>;<base64>\x1b\\`):
- Supports chunked transfer (4096-byte chunks) with `m=0/1` continuation flag
- Detects PNG/JPG/GIF/WEBP by magic bytes
- Supports `f=100` (PNG auto-format) and `c=<width>` column constraint
- **Important**: Kitty images persist in terminal memory until explicitly deleted (`a=d`)

**`ITerm2Protocol`** — OSC 1337-based (`\x1b]1337;File=<args>:<base64>\x07`):
- Supports `inline=1`, `width=<pixels>`, `preserveAspectRatio=1`
- Single-shot encoding (no chunking needed — iTerm2 handles arbitrary sizes)
- Uses BEL (`\x07`) as terminator

### 2.2 TUI Widget Lifecycle

From `AbstractWidget`:
- `render(RenderContext): string[]` — returns one string per terminal row
- `collectTerminalCleanupSequence(): string` — override to emit cleanup escape sequences on detach. The `WidgetTree::detach()` method calls this and writes the result to the terminal.
- `invalidate()` — bumps render revision, clears cache, propagates to parent
- The **Renderer** already exempts lines containing image sequences from width validation via `AnsiUtils::containsImage()` (checks for `\x1b_G` or `\x1b]1337;File=`)

### 2.3 Terminal Detection

- `Terminal::isKittyProtocolActive()` detects Kitty keyboard protocol support (queried via `\x1b[?u` at startup)
- iTerm2 detection requires checking `$_SERVER['TERM_PROGRAM'] === 'iTerm.app'`
- Sixel detection requires querying `DA1` response for Sixel support (`\x1b[c` → check for attribute `4`)
- No existing `supportsImage()` method on `TerminalInterface` — we must add one

### 2.4 Width Validation Exception

The Renderer's `renderWidget()` already skips width validation for lines containing image escape sequences:
```php
// Renderer.php:186
if ('' === $line || AnsiUtils::containsImage($line)) {
    continue;
}
```

This means our widget can safely emit protocol-encoded image lines without triggering `RenderException`.

## 3. Architecture

### 3.1 Component Diagram

```
ImageWidget (extends AbstractWidget)
├── ImageProtocolDetector
│   ├── KittyGraphicsProtocol (existing, Console component)
│   ├── ITerm2Protocol (existing, Console component)
│   ├── SixelProtocol (new)
│   └── ChafaFallback (new, shells out to `chafa`)
└── ImageData (value object)
    ├── source: FilePath | RawBytes | Base64String
    ├── imageData: string (resolved raw bytes)
    └── format: png|jpg|gif|webp|null

TerminalInterface (extended)
└── getImageProtocol(): ?ImageProtocolInterface  (new)
```

### 3.2 Data Flow

```
1. ImageWidget::setImage(file/bytes/base64)
       ↓
2. ImageWidget::render(RenderContext)
   ├── Resolve image data → raw bytes
   ├── Query protocol from Terminal
   ├── If protocol available:
   │   ├── Calculate pixel dimensions from terminal columns/rows
   │   └── protocol->encode(imageData, maxWidth) → escape sequence
   └── If no protocol:
       ├── Try chafa (if available on $PATH)
       └── Else: render placeholder box
       ↓
3. Return string[] with escape sequences (one "line" per image)
       ↓
4. Renderer writes to terminal (width check skipped for image lines)
       ↓
5. WidgetTree::detach() → collectTerminalCleanupSequence()
   → Kitty: "\x1b_Ga=d,d=I\x1b\\" (delete image by ID)
```

## 4. Class Designs

### 4.1 `ImageWidget`

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget;

use Symfony\Component\Console\Terminal\Image\ImageProtocolInterface;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Renders images inline in the terminal using the best available protocol.
 *
 * Protocol detection order:
 * 1. Kitty Graphics Protocol (if terminal is Kitty or supports it)
 * 2. iTerm2 Inline Images (if TERM_PROGRAM=iTerm.app)
 * 3. Sixel (if terminal reports Sixel support via DA1)
 * 4. chafa fallback (if `chafa` binary is available on $PATH)
 * 5. ASCII placeholder (universal fallback)
 *
 * Image data can be provided as:
 * - File path (resolved lazily on first render)
 * - Raw binary bytes
 * - Base64-encoded string
 *
 * Kitty protocol images are assigned sequential IDs and explicitly
 * deleted when the widget is detached via collectTerminalCleanupSequence().
 *
 * @experimental
 */
class ImageWidget extends AbstractWidget
{
    /** Image source types */
    private const SOURCE_FILE = 'file';
    private const SOURCE_BYTES = 'bytes';
    private const SOURCE_BASE64 = 'base64';

    /** @var string|null Kitty image ID for cleanup (sequential) */
    private ?int $kittyImageId = null;

    /** @var string|null Cached encoded image for current dimensions */
    private ?string $cachedEncoding = null;

    /** @var int|null Columns used for cached encoding */
    private ?int $cachedColumns = null;

    /** @var string|null Resolved raw image bytes */
    private ?string $resolvedData = null;

    /** @var string|null Raw ASCII art fallback (from chafa or built-in) */
    private ?string $asciiArt = null;

    private string $sourceType;
    private string $sourceValue;
    private ?int $widthHint = null;   // explicit pixel width override
    private ?int $heightHint = null;  // explicit pixel height override
    private string $altText = '';
    private bool $preserveAspectRatio = true;

    private static int $kittyIdCounter = 0;

    // ─── Constructors ───────────────────────────────────────────

    public static function fromFile(string $path, string $altText = ''): self
    {
        $widget = new self();
        $widget->sourceType = self::SOURCE_FILE;
        $widget->sourceValue = $path;
        $widget->altText = $altText;
        return $widget;
    }

    public static function fromBytes(string $bytes, string $altText = ''): self
    {
        $widget = new self();
        $widget->sourceType = self::SOURCE_BYTES;
        $widget->sourceValue = $bytes;
        $widget->altText = $altText;
        return $widget;
    }

    public static function fromBase64(string $base64, string $altText = ''): self
    {
        $widget = new self();
        $widget->sourceType = self::SOURCE_BASE64;
        $widget->sourceValue = $base64;
        $widget->altText = $altText;
        return $widget;
    }

    // ─── Configuration ──────────────────────────────────────────

    public function setWidthHint(?int $pixels): self
    {
        if ($this->widthHint !== $pixels) {
            $this->widthHint = $pixels;
            $this->clearEncodingCache();
            $this->invalidate();
        }
        return $this;
    }

    public function setHeightHint(?int $pixels): self
    {
        if ($this->heightHint !== $pixels) {
            $this->heightHint = $pixels;
            $this->clearEncodingCache();
            $this->invalidate();
        }
        return $this;
    }

    public function setAltText(string $text): self
    {
        $this->altText = $text;
        $this->invalidate();
        return $this;
    }

    public function setPreserveAspectRatio(bool $preserve): self
    {
        if ($this->preserveAspectRatio !== $preserve) {
            $this->preserveAspectRatio = $preserve;
            $this->clearEncodingCache();
            $this->invalidate();
        }
        return $this;
    }

    // ─── Rendering ──────────────────────────────────────────────

    public function render(RenderContext $context): array
    {
        $imageData = $this->resolveImageData();
        if (null === $imageData || '' === $imageData) {
            return $this->renderPlaceholder($context, 'Image data unavailable');
        }

        $terminal = $this->getTerminal();
        $protocol = $this->detectProtocol($terminal);

        if (null !== $protocol) {
            return $this->renderWithProtocol($context, $protocol, $imageData);
        }

        // Try chafa fallback
        $asciiArt = $this->renderWithChafa($context, $imageData);
        if (null !== $asciiArt) {
            return $asciiArt;
        }

        // Final fallback: placeholder
        return $this->renderPlaceholder($context, $this->altText ?: 'Image');
    }

    public function collectTerminalCleanupSequence(): string
    {
        if (null === $this->kittyImageId) {
            return '';
        }

        $id = $this->kittyImageId;
        $this->kittyImageId = null;
        return "\x1b_Ga=d,d=I,i={$id}\x1b\\";
    }

    // ─── Internal ───────────────────────────────────────────────

    private function resolveImageData(): ?string
    {
        if (null !== $this->resolvedData) {
            return $this->resolvedData;
        }

        return match ($this->sourceType) {
            self::SOURCE_BYTES => $this->resolvedData = $this->sourceValue,
            self::SOURCE_BASE64 => $this->resolvedData = base64_decode($this->sourceValue, true) ?: null,
            self::SOURCE_FILE => $this->resolvedData = $this->resolveFile(),
        };
    }

    private function resolveFile(): ?string
    {
        if (!file_exists($this->sourceValue) || !is_readable($this->sourceValue)) {
            return null;
        }
        $data = file_get_contents($this->sourceValue);
        return false !== $data ? $data : null;
    }

    /**
     * Detect the best available image protocol for the current terminal.
     */
    private function detectProtocol(mixed $terminal): ?ImageProtocolInterface
    {
        // 1. Kitty: check if terminal is Kitty or supports Kitty graphics protocol
        if ($terminal instanceof \Symfony\Component\Tui\Terminal\Terminal
            && $terminal->isKittyProtocolActive()) {
            return new \Symfony\Component\Console\Terminal\Image\KittyGraphicsProtocol();
        }

        // Also check TERM env — Ghostty, WezTerm also support Kitty graphics
        $term = getenv('TERM') ?: '';
        $termProgram = getenv('TERM_PROGRAM') ?: '';
        if (in_array($termProgram, ['Ghostty', 'WezTerm'], true)
            || str_contains($term, 'kitty')) {
            return new \Symfony\Component\Console\Terminal\Image\KittyGraphicsProtocol();
        }

        // 2. iTerm2
        if ('iTerm.app' === $termProgram) {
            return new \Symfony\Component\Console\Terminal\Image\ITerm2Protocol();
        }

        // 3. Sixel: check for Sixel-capable terminals
        // (mintty, libsixel-enabled terminals, some MLterm)
        if ($this->terminalSupportsSixel()) {
            return new SixelProtocol();
        }

        return null;
    }

    /**
     * Check if the terminal supports Sixel graphics.
     *
     * This checks known Sixel-capable terminal names. A more robust
     * implementation would send DA1 (\x1b[c) and parse the response,
     * but that requires async terminal query support.
     */
    private function terminalSupportsSixel(): bool
    {
        $termProgram = getenv('TERM_PROGRAM') ?: '';
        return in_array($termProgram, ['mintty', 'mlterm'], true);
    }

    /**
     * Render using a terminal image protocol.
     *
     * @return string[]
     */
    private function renderWithProtocol(
        RenderContext $context,
        ImageProtocolInterface $protocol,
        string $imageData,
    ): array {
        $columns = $context->getColumns();
        $maxWidth = $this->widthHint ?? $this->columnsToPixels($columns);

        // Check cache
        if (null !== $this->cachedEncoding && $this->cachedColumns === $columns) {
            return [$this->cachedEncoding];
        }

        if ($protocol instanceof \Symfony\Component\Console\Terminal\Image\KittyGraphicsProtocol) {
            $encoded = $this->encodeKitty($protocol, $imageData, $maxWidth, $columns);
        } else {
            $encoded = $protocol->encode($imageData, $maxWidth);
        }

        $this->cachedEncoding = $encoded;
        $this->cachedColumns = $columns;

        // The image is a single escape sequence — rendered as one "line".
        // The terminal handles visual placement. We add a placeholder
        // row below so the layout knows how much vertical space the image takes.
        $imageRows = $this->estimateImageRows($imageData, $columns);
        $lines = [$encoded];

        // Pad with empty rows for layout (the image renders over them)
        for ($i = 1; $i < $imageRows; $i++) {
            $lines[] = '';
        }

        return $lines;
    }

    /**
     * Encode with Kitty protocol, including image ID assignment.
     */
    private function encodeKitty(
        \Symfony\Component\Console\Terminal\Image\KittyGraphicsProtocol $protocol,
        string $imageData,
        int $maxWidth,
        int $columns,
    ): string {
        // Assign a unique ID for this image (needed for cleanup)
        if (null === $this->kittyImageId) {
            $this->kittyImageId = ++self::$kittyIdCounter;
        }

        // Use the base encode, then patch in the image ID
        $encoded = $protocol->encode($imageData, $maxWidth);

        // Inject image ID into the first chunk's control data
        // Original: \x1b_Ga=T,f=100,c=N,m=0;<payload>\x1b\
        // Patched:  \x1b_Ga=T,f=100,c=N,i=ID,m=0;<payload>\x1b\
        $id = $this->kittyImageId;
        $encoded = preg_replace(
            '/\x1b_G([^;]*),m=/',
            "\x1b_G\$1,i={$id},m=",
            $encoded,
            1  // Only first occurrence
        );

        return $encoded;
    }

    /**
     * Attempt to render using chafa (CLI image-to-text converter).
     *
     * @return string[]|null Rendered lines, or null if chafa is unavailable
     */
    private function renderWithChafa(RenderContext $context, string $imageData): ?array
    {
        // Check if chafa is available
        static $chafaAvailable = null;
        if (null === $chafaAvailable) {
            $chafaAvailable = (bool) exec('which chafa 2>/dev/null');
        }

        if (!$chafaAvailable) {
            return null;
        }

        $columns = $context->getColumns();
        $rows = $this->estimateImageRows($imageData, $columns);

        // Write image data to a temp file for chafa
        $tmpFile = tempnam(sys_get_temp_dir(), 'tui_img_');
        if (false === $tmpFile) {
            return null;
        }
        file_put_contents($tmpFile, $imageData);

        // Run chafa with appropriate size
        $output = shell_exec(
            sprintf(
                'chafa --size=%dx%d --symbols=all %s 2>/dev/null',
                $columns,
                $rows,
                escapeshellarg($tmpFile)
            )
        );
        unlink($tmpFile);

        if (null === $output || '' === trim($output)) {
            return null;
        }

        return explode("\n", rtrim($output));
    }

    /**
     * Render a placeholder box when no image protocol is available.
     *
     * @return string[]
     */
    private function renderPlaceholder(RenderContext $context, string $label): array
    {
        $columns = $context->getColumns();
        $innerWidth = max(1, $columns - 2); // subtract box borders
        $height = max(3, min(8, (int) ceil($columns / 4)));

        $topBottom = '┌' . str_repeat('─', $innerWidth) . '┐';
        $middle = '│' . str_repeat(' ', $innerWidth) . '│';
        $bottom = '└' . str_repeat('─', $innerWidth) . '┘';

        $lines = [$topBottom];
        for ($i = 0; $i < $height - 2; $i++) {
            $lines[] = $middle;
        }

        // Center the label in the placeholder
        if ('' !== $label) {
            $labelWidth = mb_strwidth($label);
            $padLeft = (int) floor(($innerWidth - $labelWidth) / 2);
            $padRight = $innerWidth - $labelWidth - $padLeft;
            $centerRow = max(1, (int) floor(($height - 2) / 2));
            $lines[$centerRow] = '│' . str_repeat(' ', $padLeft) . $label . str_repeat(' ', $padRight) . '│';
        }

        $lines[] = $bottom;
        return $lines;
    }

    /**
     * Estimate how many terminal rows an image will occupy.
     */
    private function estimateImageRows(string $imageData, int $columns): int
    {
        $size = @getimagesizefromstring($imageData);
        if (false === $size) {
            return (int) ceil($columns * 0.5); // default aspect ratio ~2:1
        }

        [$pixelWidth, $pixelHeight] = $size;
        $displayPixelWidth = $this->columnsToPixels($columns);

        if ($this->preserveAspectRatio && $pixelWidth > 0) {
            $scale = $displayPixelWidth / $pixelWidth;
            $displayPixelHeight = (int) ($pixelHeight * $scale);
        } else {
            $displayPixelHeight = $this->heightHint ?? $displayPixelWidth;
        }

        return max(1, (int) ceil($this->pixelsToRows($displayPixelHeight)));
    }

    /**
     * Convert terminal columns to approximate pixel width.
     *
     * Assumes 8px-wide characters at the terminal's cell size.
     * This is a rough heuristic — exact pixel sizes require querying
     * the terminal's cell dimensions (Kitty supports `\x1b_Ga=q` for this).
     */
    private function columnsToPixels(int $columns): int
    {
        return $columns * 8;
    }

    /**
     * Convert pixel height to terminal rows.
     */
    private function pixelsToRows(int $pixels): float
    {
        return $pixels / 16; // assuming 16px-tall character cells
    }

    /**
     * Get the terminal from the widget context.
     */
    private function getTerminal(): ?\Symfony\Component\Tui\Terminal\TerminalInterface
    {
        return $this->getContext()?->getTerminal();
    }

    private function clearEncodingCache(): void
    {
        $this->cachedEncoding = null;
        $this->cachedColumns = null;
    }
}
```

### 4.2 `SixelProtocol` (new)

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Image;

use Symfony\Component\Console\Terminal\Image\ImageProtocolInterface;

/**
 * Sixel image protocol for terminals that support DEC Sixel graphics.
 *
 * Sixel uses DCS (Device Control String) sequences:
 * Format: DCS q <Sixel data> ST
 *
 * @see https://vt100.net/docs/vt3xx-gp/chapter14.html
 * @see https://github.com/libsixel/libsixel
 */
final class SixelProtocol implements ImageProtocolInterface
{
    public const DCS_START = "\x1bPq";
    public const ST = "\x1b\\";

    public function detectPastedImage(string $data): bool
    {
        return str_contains($data, self::DCS_START);
    }

    public function decode(string $data): array
    {
        // Sixel decode is complex and not needed for display-only use
        return ['data' => '', 'format' => null];
    }

    /**
     * Encode image data as Sixel.
     *
     * This requires the `img2sixel` binary from libsixel.
     * If unavailable, returns an empty string.
     */
    public function encode(string $imageData, ?int $maxWidth = null): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'sixel_');
        if (false === $tmpFile) {
            return '';
        }
        file_put_contents($tmpFile, $imageData);

        $cmd = 'img2sixel';
        if (null !== $maxWidth) {
            $cmd .= sprintf(' --width=%d', $maxWidth);
        }
        $cmd .= ' ' . escapeshellarg($tmpFile) . ' 2>/dev/null';

        $output = shell_exec($cmd);
        unlink($tmpFile);

        return is_string($output) ? $output : '';
    }

    public function getName(): string
    {
        return 'sixel';
    }
}
```

### 4.3 `AnsiUtils::containsImage()` Update

The existing method needs to also detect Sixel sequences:

```php
// Current (AnsiUtils.php:548)
public static function containsImage(string $line): bool
{
    return str_contains($line, "\x1b_G") || str_contains($line, "\x1b]1337;File=");
}

// Updated — also detect Sixel DCS
public static function containsImage(string $line): bool
{
    return str_contains($line, "\x1b_G")          // Kitty
        || str_contains($line, "\x1b]1337;File=")  // iTerm2
        || str_contains($line, "\x1bPq");           // Sixel
}
```

### 4.4 `WidgetContext` — Terminal Access

The `ImageWidget` needs access to the `TerminalInterface` to query protocol support. Currently `WidgetContext` does not expose the terminal directly. We need either:

**Option A** — Add `getTerminal()` to `WidgetContext`:
```php
// In WidgetContext
public function getTerminal(): TerminalInterface
{
    return $this->terminal;
}
```

**Option B** — Pass the protocol detector as a constructor dependency to `ImageWidget`.

**Recommendation**: Option A is cleaner since `WidgetContext` already holds the terminal (it's injected into `WidgetTree`).

## 5. Kitty Image Lifecycle

Kitty's graphics protocol assigns persistent IDs to images loaded into the terminal's GPU memory. Unlike iTerm2 (which renders inline and forgets), Kitty images must be explicitly deleted:

### 5.1 Image Placement

```
\x1b_Ga=T,f=100,c=80,i=42,m=0;<base64_chunk>\x1b\     # first chunk
\x1b_Gm=1;<base64_chunk>\x1b\                           # continuation
\x1b_Gm=0;<final_chunk>\x1b\                            # last chunk
```

- `a=T` — transmit and display
- `f=100` — PNG format (auto-detect)
- `c=80` — display width in columns
- `i=42` — image ID (for later deletion)
- `m=0` — last chunk, `m=1` — more chunks follow

### 5.2 Image Deletion

When the `ImageWidget` is removed from the tree, `WidgetTree::detach()` calls `collectTerminalCleanupSequence()`:

```php
public function collectTerminalCleanupSequence(): string
{
    if (null === $this->kittyImageId) {
        return '';
    }
    $id = $this->kittyImageId;
    $this->kittyImageId = null;
    return "\x1b_Ga=d,d=I,i={$id}\x1b\\";  // delete image by ID
}
```

### 5.3 Resize Handling

On terminal resize (`SIGWINCH`), the cached encoding becomes stale. The widget must:

1. Detect dimension change in `render()` (compare `$context->getColumns()` to `$this->cachedColumns`)
2. Re-encode with new width constraint
3. Delete the old Kitty image and transmit a new one

This happens naturally because:
- `SIGWINCH` → `Terminal` clears cached dimensions → `onResize` callback → full re-render
- The render cache is keyed on `(columns, rows)`, so dimension changes invalidate it
- `render()` checks `$this->cachedColumns === $columns` and re-encodes if different

For Kitty specifically, we must also emit a deletion for the old image ID before transmitting the new one. We handle this by:

```php
private function encodeKitty(...): string
{
    // If re-encoding (resize), clean up the old image first
    $cleanup = '';
    if (null !== $this->kittyImageId && $this->cachedColumns !== $columns) {
        $oldId = $this->kittyImageId;
        $cleanup = "\x1b_Ga=d,d=I,i={$oldId}\x1b\\";
    }

    // Assign new ID for the re-encoded image
    $this->kittyImageId = ++self::$kittyIdCounter;

    $encoded = $protocol->encode($imageData, $maxWidth);
    // ... inject ID ...

    return $cleanup . $encoded;
}
```

## 6. Pixel Dimension Estimation

Terminal image protocols need pixel dimensions, but the TUI layout system works in character cells. We need a mapping:

| Query Method | Protocol | Accuracy |
|-------------|----------|----------|
| `getimagesizefromstring()` | N/A (reads image metadata) | Exact image size |
| Kitty `a=q,i=1` | Kitty | Exact cell size in pixels |
| Assumed 8×16px | All | Rough heuristic |

### Strategy

1. Use `getimagesizefromstring()` to get the image's intrinsic pixel dimensions
2. Calculate display width from `$context->getColumns() × cellWidth`
3. Scale height preserving aspect ratio
4. Convert back to rows: `pixelHeight / cellHeight`

**Cell size query** (future enhancement):
- Kitty: `\x1b_Ga=q,i=1,s=1,v=1\x1b\\` → response includes cell width/height
- Generic: assume 8×16 (most monospace fonts at standard DPI)

## 7. Fallback Chain

```
┌─────────────────────────────────────────────────────────────┐
│ detectProtocol()                                            │
│                                                             │
│  Kitty? ──yes──► KittyGraphicsProtocol::encode()           │
│     │                                                       │
│    no                                                       │
│     │                                                       │
│  iTerm2? ──yes──► ITerm2Protocol::encode()                 │
│     │                                                       │
│    no                                                       │
│     │                                                       │
│  Sixel? ──yes──► SixelProtocol::encode() (via img2sixel)   │
│     │                                                       │
│    no                                                       │
│     │                                                       │
│  chafa? ──yes──► chafa --symbols=all (ANSI art)             │
│     │                                                       │
│    no                                                       │
│     │                                                       │
│  placeholder ──► Box with centered alt text                 │
└─────────────────────────────────────────────────────────────┘
```

### chafa Integration

[chafa](https://hpjansson.org/chafa/) is a CLI tool that converts images to Unicode/ANSI art. It's available in most package managers:

```bash
# macOS
brew install chafa

# Ubuntu/Debian
apt install chafa

# Usage
chafa --size=80x24 --symbols=all image.png
```

The widget shells out to `chafa` and captures its ANSI output. This provides a colorful (or monochrome) ASCII art representation on any terminal, regardless of image protocol support.

**Performance note**: `chafa` is relatively fast (< 100ms for typical images) but should be cached to avoid re-running on every render.

## 8. Template Integration

The ImageWidget should be usable from TUI templates:

```xml
<!-- In a template -->
<image src="/path/to/diagram.png" alt="Architecture Diagram" />
<image src="/path/to/logo.png" width="40" alt="KosmoKrator" />
```

Template parsing maps attributes:
- `src` → `fromFile($src)`
- `width` / `height` → `setWidthHint()` / `setHeightHint()`
- `alt` → `setAltText()`

## 9. Testing Strategy

### 9.1 Unit Tests

| Test | Description |
|------|-------------|
| `testFromFileResolvesCorrectly` | File path → raw bytes |
| `testFromBase64DecodesCorrectly` | Base64 → raw bytes |
| `testFromBytesPassesThrough` | Raw bytes unchanged |
| `testMissingFileRendersPlaceholder` | Nonexistent file → placeholder |
| `testKittyProtocolCleanup` | `collectTerminalCleanupSequence()` returns `\x1b_Ga=d,...\x1b\\` |
| `testKittyImageIdAssigned` | Sequential IDs assigned correctly |
| `testCacheInvalidationOnResize` | Different column count → re-encode |
| `testPlaceholderRendering` | Box dimensions match context |
| `testProtocolDetectionKitty` | Kitty terminal → KittyGraphicsProtocol |
| `testProtocolDetectionITerm2` | iTerm2 → ITerm2Protocol |
| `testProtocolDetectionFallback` | Unknown terminal → null protocol |
| `testAltTextCenteredInPlaceholder` | Label centered in box |

### 9.2 Integration Tests

- Render ImageWidget in a `ContainerWidget` with known dimensions
- Verify the output lines contain the expected escape sequences
- Test the full lifecycle: create → attach → render → detach → cleanup
- Test resize: change dimensions → verify new encoding

### 9.3 Manual Testing Checklist

- [ ] Kitty terminal: image renders inline, deleted on widget removal
- [ ] iTerm2: image renders inline
- [ ] Terminal without image support: placeholder shows correctly
- [ ] Terminal with chafa: colorful ASCII art renders
- [ ] Resize: image re-renders at new size
- [ ] Multiple images: each gets unique Kitty ID, all cleaned up on detach

## 10. Performance Considerations

| Concern | Mitigation |
|---------|-----------|
| Large images → slow base64 encoding | Encode once, cache per dimensions |
| chafa subprocess overhead | Cache ASCII art output |
| Kitty chunking overhead | 4096-byte chunks are fast; base64 is the bottleneck |
| File I/O on every render | Resolve file data once in `resolveImageData()` |
| Memory for large images | Stream chunks instead of buffering entire image (future) |

## 11. Future Enhancements

1. **Pixel-perfect sizing**: Query Kitty cell dimensions with `\x1b_Ga=q` for accurate pixel-to-cell conversion
2. **Image caching**: Cache encoded images to disk with dimension keys
3. **Progressive loading**: For large images, show low-res first, then high-res
4. **Image scaling**: Use GD or Imagick to resize before encoding (reduces base64 payload)
5. **Animated GIF support**: Kitty protocol supports animations via frame control
6. **DA1 query for Sixel detection**: Send `\x1b[c` and parse response for Sixel attribute
7. **Clipboard integration**: Allow copying images to/from terminal clipboard via Kitty protocol
8. **VirtualTerminal support**: When `isVirtual()`, skip protocol detection entirely

## 12. Implementation Order

1. **Phase 1**: `ImageWidget` with Kitty + iTerm2 + placeholder fallback
2. **Phase 2**: `SixelProtocol` + detection
3. **Phase 3**: chafa integration
4. **Phase 4**: `WidgetContext::getTerminal()` + pixel dimension querying
5. **Phase 5**: Template integration (`<image>` tag)
6. **Phase 6**: Image pre-scaling (GD/Imagick)
7. **Phase 7**: Animated GIF support

## 13. File Manifest

| File | Action | Description |
|------|--------|-------------|
| `src/UI/Tui/Widget/ImageWidget.php` | Create | Main widget class |
| `src/UI/Tui/Image/SixelProtocol.php` | Create | Sixel encoding (via img2sixel) |
| `vendor/.../AnsiUtils.php` | Modify | Add Sixel detection to `containsImage()` |
| `vendor/.../WidgetContext.php` | Modify | Add `getTerminal()` method |
| `tests/UI/Tui/Widget/ImageWidgetTest.php` | Create | Unit tests |
