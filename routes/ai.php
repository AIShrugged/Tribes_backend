<?php

use App\Mcp\Servers\HrServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp', HrServer::class)
    ->middleware(['auth:sanctum']);
