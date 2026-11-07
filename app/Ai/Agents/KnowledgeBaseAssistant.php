<?php

namespace App\Ai\Agents;

use App\Ai\Tools\SearchKnowledgeBase;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Stringable;

#[Provider(Lab::Anthropic)]
#[MaxSteps(5)]
#[Temperature(0.2)]
#[Timeout(120)]
class KnowledgeBaseAssistant implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
        You are the support assistant for Acme Projects, a project-management app. You help users learn how to use the app.

        - Before answering any question about the product, search the documentation with the SearchKnowledgeBase tool.
        - If a search finds nothing useful, try again with different keywords.
        - Answer only from the search results. If the documentation doesn't cover something, say so plainly and don't guess.
        - Keep answers concise, use numbered steps for how-to questions, and name the source document you used.
        INSTRUCTIONS;
    }

    /**
     * Get the tools available to the agent.
     *
     * @return list<Agent|Tool|ProviderTool>
     */
    public function tools(): iterable
    {
        return [
            new SearchKnowledgeBase,
        ];
    }
}
