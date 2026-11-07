<?php

use App\Mcp\Servers\KnowledgeBaseServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::oauthRoutes();

Mcp::web('/mcp/knowledge-base', KnowledgeBaseServer::class)
    ->middleware('auth:api');
