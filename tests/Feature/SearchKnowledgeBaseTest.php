<?php

use App\Ai\Tools\SearchKnowledgeBase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Tools\Request;

beforeEach(function () {
    Storage::fake('docs');
});

function searchKnowledgeBase(string $query): string
{
    return (string) (new SearchKnowledgeBase)->handle(new Request(['query' => $query]));
}

test('it returns the whole matching file with its source', function () {
    Storage::disk('docs')->put('billing.md', "# Billing\n\n## Payment method\n\nClick Replace card.\n\n## Refunds\n\nEmail the billing team.");
    Storage::disk('docs')->put('tasks.md', "# Tasks\n\nCreate a task.");

    $result = searchKnowledgeBase('payment method');

    expect($result)
        ->toStartWith('Source: billing.md')
        ->toContain('Click Replace card.')
        ->toContain('Email the billing team.')
        ->not->toContain('tasks.md');
});

test('it returns the file with the most matches first', function () {
    Storage::disk('docs')->put('a-few.md', 'One password here.');
    Storage::disk('docs')->put('b-many.md', 'A password, another password, and a third password.');

    $result = searchKnowledgeBase('password');

    expect(strpos($result, 'b-many.md'))->toBeLessThan(strpos($result, 'a-few.md'));
});

test('it matches plurals', function () {
    Storage::disk('docs')->put('billing.md', 'Download your invoices as PDFs.');

    expect(searchKnowledgeBase('invoice'))->toContain('Download your invoices as PDFs.');
});

test('it ignores the case of the query and the content', function () {
    Storage::disk('docs')->put('billing.md', 'Open the Invoices page.');

    expect(searchKnowledgeBase('INVOICES'))->toContain('Open the Invoices page.');
});

test('it limits the number of results', function () {
    collect(range(1, 5))->each(fn (int $number) => Storage::disk('docs')->put("guide-{$number}.md", 'About tasks.'));

    expect(substr_count(searchKnowledgeBase('tasks'), 'Source: '))->toBe(3);
});

test('it reports when nothing matches', function () {
    Storage::disk('docs')->put('billing.md', "# Billing\n\n## Plans\n\nPricing details.");

    expect(searchKnowledgeBase('kubernetes'))->toBe('No matching documentation found. Try different keywords.');
});

test('it ignores files that are not markdown', function () {
    Storage::disk('docs')->put('notes.txt', 'A secret refund policy.');

    expect(searchKnowledgeBase('refund'))->toBe('No matching documentation found. Try different keywords.');
});

test('it rejects a missing query', function () {
    (new SearchKnowledgeBase)->handle(new Request([]));
})->throws(ValidationException::class);

test('the bundled docs can answer a common question', function () {
    Storage::forgetDisk('docs');

    expect(searchKnowledgeBase('payment method'))->toStartWith('Source: billing.md');
});
