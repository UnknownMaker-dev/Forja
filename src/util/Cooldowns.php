<?php

declare(strict_types=1);

namespace Unknown\Forja\util;

use function hrtime;
use function max;
use function str_starts_with;

/**
 * Tempos de espera por chave, em memória. Crie uma instância por plugin/recurso:
 *
 * ```php
 * $this->cooldowns = new Cooldowns();
 * $chave = "kit:" . strtolower($player->getName());
 * if($this->cooldowns->isActive($chave)){
 *     $player->sendMessage("Aguarde " . Duration::format((int) ceil($this->cooldowns->remaining($chave))));
 *     return;
 * }
 * $this->cooldowns->set($chave, 60);
 * ```
 *
 * Usa relógio monotônico (mudar a hora do sistema não afeta).
 */
final class Cooldowns {

	/** @var array<string, float> chave => momento (s) em que expira */
	private array $expires = [];
	private int $operations = 0;

	public function set(string $key, float $seconds) : void {
		if($seconds <= 0){
			unset($this->expires[$key]);
			return;
		}
		$this->expires[$key] = self::now() + $seconds;
		if(++$this->operations % 256 === 0){
			$this->prune();
		}
	}

	/** Segundos restantes (0 se não estiver ativo). */
	public function remaining(string $key) : float {
		$expires = $this->expires[$key] ?? null;
		if($expires === null){
			return 0.0;
		}
		$left = $expires - self::now();
		if($left <= 0){
			unset($this->expires[$key]);
			return 0.0;
		}
		return $left;
	}

	public function isActive(string $key) : bool {
		return $this->remaining($key) > 0;
	}

	public function clear(string $key) : void {
		unset($this->expires[$key]);
	}

	/** Remove todas as chaves que começam com o prefixo (ex.: os de um jogador que saiu). */
	public function clearPrefix(string $prefix) : void {
		foreach($this->expires as $key => $_){
			if(str_starts_with((string) $key, $prefix)){
				unset($this->expires[$key]);
			}
		}
	}

	public function clearAll() : void {
		$this->expires = [];
	}

	private function prune() : void {
		$now = self::now();
		foreach($this->expires as $key => $expires){
			if($expires <= $now){
				unset($this->expires[$key]);
			}
		}
	}

	private static function now() : float {
		return max(0, hrtime(true)) / 1_000_000_000;
	}
}
