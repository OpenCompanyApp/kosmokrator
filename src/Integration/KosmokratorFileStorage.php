<?php

declare(strict_types=1);

namespace Kosmokrator\Integration;

use OpenCompany\IntegrationCore\Contracts\AgentFileStorage;

class KosmokratorFileStorage implements AgentFileStorage
{
    public function saveFile(
        object $agent,
        string $filename,
        string $content,
        string $mimeType,
        ?string $subfolder = null,
    ): array {
        $baseDir = getcwd().'/.kosmo/output';
        $dir = $subfolder !== null
            ? $baseDir.'/'.$subfolder
            : $baseDir;

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path = $dir.'/'.$filename;
        file_put_contents($path, $content);

        return [
            'id' => $filename,
            'path' => $path,
            'url' => $path,
        ];
    }
}
