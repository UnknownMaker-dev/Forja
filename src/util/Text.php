<?php

declare(strict_types=1);

namespace Unknown\Forja\util;

use function is_nan;
use function max;
use function min;
use function preg_replace_callback;
use function round;
use function str_repeat;
use function strtolower;

/**
 * Ajudantes de texto.
 */
final class Text {

	private function __construct() {}

	/**
	 * Troca `&` por `§` antes de códigos de cor/formato (`&a`, `&l`, `&r`...).
	 * Um `&` que não é seguido de um código válido (ex.: "Tom & Jerry", "&z") fica como está.
	 */
	public static function color(string $text) : string {
		return preg_replace_callback('/&([0-9a-vA-V])/u', static fn(array $m) : string => "§" . strtolower($m[1]), $text) ?? $text;
	}

	/**
	 * Barra de progresso: `$ratio` de 0 a 1 (valores fora são limitados).
	 * Ex.: progressBar(0.5, 10) ⇒ "§a|§a|§a|§a|§a|§7|§7|§7|§7|§7|"
	 */
	public static function progressBar(float $ratio, int $len = 20, string $full = "§a|", string $empty = "§7|") : string {
		if($len <= 0){
			return "";
		}
		$ratio = is_nan($ratio) ? 0.0 : max(0.0, min(1.0, $ratio));
		$filled = (int) round($ratio * $len);
		return str_repeat($full, $filled) . str_repeat($empty, $len - $filled);
	}
}
