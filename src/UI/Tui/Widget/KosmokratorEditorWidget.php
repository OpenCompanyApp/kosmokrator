<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget;

use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Widget\EditorWidget;

final class KosmokratorEditorWidget extends EditorWidget
{
    /**
     * @return array<string, string[]>
     */
    protected static function getDefaultKeybindings(): array
    {
        return array_merge(parent::getDefaultKeybindings(), [
            'copy' => [],
            'select_cancel' => [Key::ESCAPE, 'ctrl+c'],
        ]);
    }
}
