<?php

use App\Mcp\Servers\TarsServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp', TarsServer::class)->middleware('mcp.auth');
