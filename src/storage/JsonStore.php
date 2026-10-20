<?php

declare(strict_types=1);

namespace Unknown\Forja\storage;

use InvalidArgumentException;
use Logger;
use pocketmine\player\Player;
use pocketmine\plugin\Plugin;
use pocketmine\scheduler\ClosureTask;
use pocketmine\scheduler\TaskHandler;
use function array_key_exists;
use function strtolower;

/**
 * Mapa chave ⇒ valor em memória, guardado num arquivo JSON.
 *
 * ```php
 * $this->saldos = new JsonStore($this->getDataFolder() . "saldos.json");
 * $this->saldos->startAutosave($this, 300);
 * $this->saldos->set(JsonStore::playerKey($player), 100);
 * // onDisable: $this->saldos->save();
 * ```
 *
 * Os valores precisam ser serializáveis em JSON (escalares e arrays).
 */
final class JsonStore {

	/** @var array<mixed> */
	private array $data;
	private bool $dirty = false;
	private ?TaskHandler $autosave = null;

	public function __construct(
		private string $path,
		private ?Logger $logger = null
	) {
		$this->data = JsonFile::read($path, $logger);
	}

	/** Chave padrão para dados de jogador: o nome em minúsculas. */
	public static function playerKey(Player|string $player) : string {
		return strtolower($player instanceof Player ? $player->getName() : $player);
	}

	public function getPath() : string {
		return $this->path;
	}

	public function get(string $key, mixed $default = null) : mixed {
		return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
	}

	public function set(string $key, mixed $value) : void {
		if(array_key_exists($key, $this->data) && $this->data[$key] === $value){
			return;
		}
		$this->data[$key] = $value;
		$this->dirty = true;
	}

	public function remove(string $key) : void {
		if(array_key_exists($key, $this->data)){
			unset($this->data[$key]);
			$this->dirty = true;
		}
	}

	public function has(string $key) : bool {
		return array_key_exists($key, $this->data);
	}

	/**
	 * @return array<mixed>
	 */
	public function all() : array {
		return $this->data;
	}

	/** True se há alterações ainda não gravadas. */
	public function isDirty() : bool {
		return $this->dirty;
	}

	/** Marca como alterado (ex.: depois de mudar um objeto guardado por referência). */
	public function markDirty() : void {
		$this->dirty = true;
	}

	/** Grava agora, na thread principal. Use no onDisable. */
	public function save() : void {
		if(JsonFile::tryWrite($this->path, $this->data, $this->logger)){
			$this->dirty = false;
		}
	}

	/** Grava numa thread assíncrona. Se falhar, os dados voltam a ficar sujos. */
	public function saveAsync() : void {
		$this->dirty = false;
		JsonFile::writeAsync($this->path, $this->data, function(bool $success) : void {
			if(!$success){
				$this->dirty = true;
			}
		});
	}

	/**
	 * Grava de forma assíncrona a cada `$seconds` segundos, só se houver alterações.
	 * Chamar de novo troca o intervalo. A tarefa para sozinha quando o plugin é desabilitado.
	 */
	public function startAutosave(Plugin $plugin, int $seconds) : void {
		if($seconds <= 0){
			throw new InvalidArgumentException("O intervalo do autosave precisa ser maior que 0");
		}
		$this->stopAutosave();
		$this->autosave = $plugin->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() : void {
			if($this->dirty){
				$this->saveAsync();
			}
		}), $seconds * 20);
	}

	public function stopAutosave() : void {
		$this->autosave?->cancel();
		$this->autosave = null;
	}
}
