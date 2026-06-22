<?php

use App\Mcp\Servers\TribesMcpServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp', TribesMcpServer::class)
    ->middleware(['auth:sanctum', 'abilities:mcp']);
