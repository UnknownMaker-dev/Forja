<?php

declare(strict_types=1);

namespace Unknown\Forja\util;

use function implode;
use function intdiv;
use function mb_strtolower;
use function preg_match;
use function preg_match_all;
use function trim;
use const PHP_INT_MAX;

/**
 * Converte durações entre texto e segundos.
 *
 * Unidades aceitas no parse: `s` (segundos), `m`/`min` (minutos), `h` (horas),
 * `d` (dias) e `w` (semanas). Número sozinho = segundos.
 */
final class Duration {

	private const UNITS = [
		"w" => 604800,
		"d" => 86400,
		"h" => 3600,
		"min" => 60,
		"m" => 60,
		"s" => 1
	];

	private function __construct() {}

	/**
	 * "90" ⇒ 90, "30m" ⇒ 1800, "1h30m" ⇒ 5400, "1d 2h" ⇒ 93600.
	 *
	 * @return int|null segundos, ou null se o texto for inválido (vazio, negativo, decimal, unidade desconhecida)
	 */
	public static function parse(string $text) : ?int {
		$text = mb_strtolower(trim($text), "UTF-8");
		if($text === ""){
			return null;
		}
		if(preg_match('/^\d{1,12}$/', $text) === 1){
			return (int) $text;
		}
		if(preg_match('/^(\d{1,12}\s*(w|d|h|min|m|s)\s*)+$/', $text) !== 1){
			return null;
		}

		preg_match_all('/(\d{1,12})\s*(w|d|h|min|m|s)/', $text, $matches);
		$total = 0;
		foreach($matches[1] as $i => $amount){
			$seconds = (int) $amount * self::UNITS[$matches[2][$i]];
			if($total > PHP_INT_MAX - $seconds){
				return null;
			}
			$total += $seconds;
		}
		return $total;
	}

	/**
	 * 93900 ⇒ "1d 2h 5min". Zero ou negativo ⇒ "0s".
	 */
	public static function format(int $seconds) : string {
		if($seconds <= 0){
			return "0s";
		}
		$parts = [];
		foreach(["d" => 86400, "h" => 3600, "min" => 60, "s" => 1] as $unit => $size){
			if($seconds >= $size){
				$parts[] = intdiv($seconds, $size) . $unit;
				$seconds %= $size;
			}
		}
		return implode(" ", $parts);
	}
}
