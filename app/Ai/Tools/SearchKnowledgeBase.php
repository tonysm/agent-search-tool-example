<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchKnowledgeBase implements Tool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Search the product help documentation by keyword. Returns the full text of the best-matching documents, each with its source.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $query = $request->validate(['query' => 'required|string'])['query'];
        $terms = Str::of($query)->lower()->explode(' ')->filter();
        $disk = Storage::disk('docs');

        $matches = collect($disk->allFiles())
            ->filter(fn (string $path): bool => str_ends_with($path, '.md'))
            ->mapWithKeys(fn (string $path): array => [$path => (string) $disk->get($path)])
            ->sortByDesc(fn (string $content): int => $this->countMatches($terms, $content))
            ->filter(fn (string $content): bool => $this->countMatches($terms, $content) > 0)
            ->take(3);

        return $matches->isEmpty()
            ? 'No matching documentation found. Try different keywords.'
            : $matches->map(fn (string $content, string $path): string => "Source: {$path}\n\n{$content}")->implode("\n\n---\n\n");
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('A few keywords separated by spaces, such as "payment method". Not a full sentence.')
                ->required(),
        ];
    }

    /**
     * Count how many times the search terms appear in the content.
     *
     * @param  Collection<int, string>  $terms
     */
    private function countMatches(Collection $terms, string $content): int
    {
        return $terms->sum(fn (string $term): int => substr_count(mb_strtolower($content), $term));
    }
}
