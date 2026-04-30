<?php

declare(strict_types=1);

namespace Kosmokrator\Command;

/**
 * Marker for immediate slash commands that mutate runtime state and must wait
 * until the current agent turn has finished.
 */
interface DefersWhileAgentRuns {}
