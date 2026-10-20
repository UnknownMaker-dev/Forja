<?php

declare(strict_types=1);

namespace Unknown\Forja\config;

use Exception;
use Logger;
use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use Symfony\Component\Filesystem\Path;
use function basename;
use function file_exists;
use function is_file;

/**
 * Carrega configs sem nunca derrubar o servidor.
 *
 * ```php
 * $config = SafeConfig::load($this, "config.yml");
 * $limite = $config->int("loja.limite", 10, min: 1, max: 64);
 * ```
 */
final class SafeConfig {

	private function __construct() {}

	/**
	 * Carrega um arquivo da pasta de dados do plugin. Se faltar, salva o padrão do
	 * resources/ (quando existir). Arquivo ilegível ou YAML inválido: registra o erro
	 * (arquivo e motivo), não mexe no arquivo e devolve uma view vazia, de modo que
	 * todos os getters devolvem o padrão.
	 *
	 * @param string $file caminho relativo à pasta de dados (ex.: "config.yml", "lojas/padrao.yml")
	 */
	public static function load(Plugin $plugin, string $file, bool $saveDefault = true) : ConfigView {
		$path = Path::join($plugin->getDataFolder(), $file);
		if($saveDefault && $plugin instanceof PluginBase && !file_exists($path)){
			try{
				$plugin->saveResource($file);
			}catch(Exception $e){
				$plugin->getLogger()->error("Não foi possível salvar o padrão de '$file': " . $e->getMessage());
			}
		}
		return self::fromFile($path, $plugin->getLogger(), $file);
	}

	/**
	 * Carrega um arquivo por caminho absoluto (YAML, JSON ou outro formato que o
	 * {@see Config} reconheça pela extensão). Arquivo ausente devolve uma view vazia.
	 *
	 * @param string|null $sourceName nome nos avisos; null = nome do arquivo
	 */
	public static function fromFile(string $path, Logger $logger, ?string $sourceName = null) : ConfigView {
		$sourceName ??= basename($path);
		if(!is_file($path)){
			return ConfigView::fromArray([], $logger, $sourceName);
		}

		try{
			$data = (new Config($path, Config::DETECT))->getAll();
		}catch(Exception $e){
			$logger->error("[$sourceName] não foi possível ler o arquivo: " . $e->getMessage() . ". O arquivo não foi alterado; usando os valores padrão até ser corrigido.");
			$data = [];
		}
		return ConfigView::fromArray($data, $logger, $sourceName);
	}
}
