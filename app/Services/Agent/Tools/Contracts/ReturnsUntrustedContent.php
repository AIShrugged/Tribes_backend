<?php

namespace App\Services\Agent\Tools\Contracts;

/**
 * Marker: tool returns untrusted, user-generated content (e.g. meeting
 * transcripts, uploaded files).
 *
 * Executing such a tool TAINTS the run: any subsequent {@see HighImpactAgentTool}
 * call in the same run is blocked and requires explicit human confirmation.
 */
interface ReturnsUntrustedContent {}
