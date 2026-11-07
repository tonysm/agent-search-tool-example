<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Search the product help documentation by keyword. Returns the full text of the best-matching documents, each with its source.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class SearchKnowledgeBase extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $query = $request->validate(['query' => 'required|string|max:500'])['query'];
        $terms = Str::of($query)->lower()->explode(' ')->filter(fn (string $term): bool => mb_strlen($term) >= 3);
        $disk = Storage::disk('docs');

        $matches = collect($disk->allFiles())
            ->filter(fn (string $path): bool => str_ends_with($path, '.md'))
            ->mapWithKeys(fn (string $path): array => [$path => (string) $disk->get($path)])
            ->sortByDesc(fn (string $content): int => $this->countMatches($terms, $content))
            ->filter(fn (string $content): bool => $this->countMatches($terms, $content) > 0)
            ->take(3);

        return Response::text($matches->isEmpty()
            ? 'No matching documentation found. Try different keywords.'
            : $matches->map(fn (string $content, string $path): string => "Source: {$path}\n\n{$content}")->implode("\n\n---\n\n"));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
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
