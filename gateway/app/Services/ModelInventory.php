<?php

namespace App\Services;

use App\Models\AiModel;
use App\Services\Ollama\OllamaClient;

/** Syncs the models table with Ollama /api/tags. Read-only towards Ollama. */
class ModelInventory
{
    public function __construct(private readonly OllamaClient $ollama) {}

    /** @return array{installed: int, missing: int} */
    public function sync(): array
    {
        $tags = $this->ollama->tags();
        $now = now();
        $seen = [];
        foreach ($tags as $t) {
            $tag = (string) ($t['name'] ?? $t['model'] ?? '');
            if ($tag === '' || strlen($tag) > 120) {
                continue;
            }
            $seen[] = $tag;
            $model = AiModel::firstOrNew(['tag' => $tag]);
            $model->fill([
                'digest' => isset($t['digest']) ? substr((string) $t['digest'], 0, 80) : null,
                'size_bytes' => isset($t['size']) ? (int) $t['size'] : null,
                'installed' => true,
                'checked_at' => $now,
            ]);
            if (! $model->exists) {
                $model->enabled = false;
            }
            $model->save();
        }
        $missing = AiModel::whereNotIn('tag', $seen ?: ['__none__'])->update(['installed' => false, 'enabled' => false, 'checked_at' => $now->format('Y-m-d H:i:s.u')]);

        return ['installed' => count($seen), 'missing' => $missing];
    }
}
