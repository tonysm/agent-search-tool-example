<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\SearchKnowledgeBase;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Acme Projects Knowledge Base')]
#[Version('0.0.1')]
#[Instructions('Search the Acme Projects help documentation to answer questions about using the app. Search with a few keywords, try different keywords if nothing useful comes back, and cite the "Source:" file of each document you use.')]
class KnowledgeBaseServer extends Server
{
    protected array $tools = [
        SearchKnowledgeBase::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
