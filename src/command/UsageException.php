<?php

declare(strict_types=1);

namespace Unknown\Forja\command;

use RuntimeException;

/**
 * Lançada quando faltam argumentos. O {@see BaseCommand} responde com
 * "§cUso: /<comando> <subcomando> <uso>".
 */
final class UsageException extends RuntimeException {}
