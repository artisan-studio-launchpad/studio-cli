<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Bridge;

use RuntimeException;

/**
 * Something that changes the developer's code was asked for while the AI
 * bridge is not connected. The message is written for the developer.
 */
final class BridgeNotConnected extends RuntimeException {}
