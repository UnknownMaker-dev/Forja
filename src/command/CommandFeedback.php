<?php

declare(strict_types=1);

namespace Unknown\Forja\command;

use RuntimeException;

/**
 * Interrompe o comando e envia a mensagem ao autor. Útil para validações
 * dentro de {@see SubCommand::run()}:
 *
 * ```php
 * throw new CommandFeedback("§cEssa loja não existe.");
 * ```
 */
final class CommandFeedback extends RuntimeException {}
