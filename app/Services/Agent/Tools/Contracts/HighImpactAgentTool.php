<?php

namespace App\Services\Agent\Tools\Contracts;

/**
 * Marker: tool mutates persistent data or sends outbound messages.
 *
 * When the current agent run is "tainted" (untrusted content was read this run —
 * see {@see ReturnsUntrustedContent}), the agent loop BLOCKS these tools and
 * surfaces a human-confirmation requirement instead of executing them. This is
 * the lethal-trifecta mitigation: untrusted content + high-impact action must
 * never combine silently.
 */
interface HighImpactAgentTool {}
