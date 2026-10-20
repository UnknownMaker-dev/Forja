<?php

declare(strict_types=1);

namespace Unknown\Forja\storage;

use Closure;
use GlobalLogger;
use JsonException;
use Logger;
use pocketmine\Server;
use Throwable;
use function bin2hex;
use function copy;
use function crc32;
use function date;
use function dirname;
use function fclose;
use function fflush;
use function file_exists;
use function file_get_contents;
use function fopen;
use function fsync;
use function fwrite;
use function hrtime;
use function is_array;
use function is_dir;
use function is_file;
use function json_decode;
use function json_encode;
use function mkdir;
use function random_bytes;
use function rename;
use function strlen;
use function unlink;
use function usleep;
use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Leitura e gravação seguras de arquivos JSON.
 *
 * - A gravação é atômica: escreve num arquivo temporário e troca com `rename`, então
 *   uma queda no meio nunca deixa o arquivo pela metade.
 * - JSON inválido nunca é apagado: o arquivo é movido para `<arquivo>.corrompido-<data>`.
 * - Gravações assíncronas do mesmo arquivo rodam em ordem, e uma gravação síncrona
 *   espera as assíncronas pendentes desse arquivo (dados antigos nunca sobrescrevem novos).
 */
final class JsonFile {

	public const ENCODE_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

	/** Tempo máximo que uma gravação síncrona espera as assíncronas do mesmo arquivo. */
	private const PENDING_WAIT_SECONDS = 10;

	/** @var array<string, int> caminho => gravações assíncronas ainda não concluídas */
	private static array $pending = [];

	private function __construct() {}

	/**
	 * @param Logger|null $logger onde registrar problemas; null = logger global
	 * @return array<mixed> conteúdo do arquivo; [] se não existir ou estiver corrompido
	 */
	public static function read(string $path, ?Logger $logger = null) : array {
		$logger ??= GlobalLogger::get();
		// Uma gravação assíncrona ainda pendente deixaria a leitura com conteúdo antigo.
		self::waitPending($path, $logger);
		if(!file_exists($path)){
			return [];
		}

		try{
			$contents = @file_get_contents($path);
		}catch(Throwable $e){
			$contents = false;
		}
		if($contents === false){
			$logger->error("Não foi possível ler '$path'. O arquivo não foi alterado.");
			return [];
		}

		try{
			$data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
			if(!is_array($data)){
				throw new JsonException("o conteúdo não é um objeto nem uma lista");
			}
			return $data;
		}catch(JsonException $e){
			$backup = self::quarantine($path);
			if($backup !== null){
				$logger->error("JSON inválido em '$path' (" . $e->getMessage() . "). O arquivo foi movido para '$backup' e um novo será criado.");
			}else{
				$logger->error("JSON inválido em '$path' (" . $e->getMessage() . ") e não foi possível movê-lo. Corrija o arquivo manualmente.");
			}
			return [];
		}
	}

	/**
	 * Grava de forma atômica, criando as pastas que faltarem. Falhas são registradas
	 * no logger e não lançam exceção.
	 *
	 * @param array<mixed> $data
	 */
	public static function write(string $path, array $data, ?Logger $logger = null) : void {
		self::tryWrite($path, $data, $logger);
	}

	/**
	 * Igual a {@see write()}, mas informa se deu certo.
	 *
	 * @param array<mixed> $data
	 */
	public static function tryWrite(string $path, array $data, ?Logger $logger = null) : bool {
		$logger ??= GlobalLogger::get();
		$contents = self::encode($path, $data, $logger);
		if($contents === null){
			return false;
		}

		self::waitPending($path, $logger);
		$error = self::writeAtomic($path, $contents);
		if($error !== null){
			$logger->error("Falha ao gravar '$path': $error");
			return false;
		}
		return true;
	}

	/**
	 * Codifica agora (thread principal) e grava numa thread assíncrona, de forma atômica.
	 *
	 * @param array<mixed> $data
	 * @param (Closure(bool): void)|null $onDone chamado na thread principal com o resultado
	 */
	public static function writeAsync(string $path, array $data, ?Closure $onDone = null) : void {
		$server = Server::getInstance();
		$logger = $server->getLogger();
		$contents = self::encode($path, $data, $logger);
		if($contents === null){
			if($onDone !== null){
				$onDone(false);
			}
			return;
		}

		self::$pending[$path] = (self::$pending[$path] ?? 0) + 1;
		$pool = $server->getAsyncPool();
		// O mesmo arquivo sempre vai para o mesmo worker, então as gravações dele rodam em ordem.
		$worker = crc32($path) % $pool->getSize();
		$pool->submitTaskToWorker(new WriteJsonTask($path, $contents, $onDone), $worker);
	}

	/**
	 * @internal chamado pelo {@see WriteJsonTask} na thread principal
	 */
	public static function finishAsync(string $path) : void {
		if(isset(self::$pending[$path]) && --self::$pending[$path] <= 0){
			unset(self::$pending[$path]);
		}
	}

	/**
	 * Grava o texto num temporário ao lado do destino e troca com rename.
	 *
	 * @internal usado também pela thread assíncrona
	 * @return string|null mensagem de erro, ou null se deu certo
	 */
	public static function writeAtomic(string $path, string $contents) : ?string {
		$temp = null;
		try{
			$dir = dirname($path);
			if(!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)){
				return "não foi possível criar a pasta '$dir'";
			}

			$temp = $path . "." . bin2hex(random_bytes(4)) . ".tmp";
			$handle = @fopen($temp, "wb");
			if($handle === false){
				return "não foi possível criar '$temp'";
			}
			$written = @fwrite($handle, $contents);
			$synced = @fflush($handle) && @fsync($handle);
			@fclose($handle);
			if($written !== strlen($contents) || !$synced){
				return "escrita incompleta (disco cheio?)";
			}
			if(!@rename($temp, $path)){
				return "não foi possível substituir o arquivo";
			}
			$temp = null;
			return null;
		}catch(Throwable $e){
			return $e->getMessage();
		}finally{
			if($temp !== null && is_file($temp)){
				@unlink($temp);
			}
		}
	}

	/**
	 * @param array<mixed> $data
	 */
	private static function encode(string $path, array $data, Logger $logger) : ?string {
		try{
			return json_encode($data, self::ENCODE_FLAGS | JSON_THROW_ON_ERROR) . "\n";
		}catch(JsonException $e){
			$logger->error("Não foi possível codificar os dados de '$path' em JSON: " . $e->getMessage() . ". Nada foi gravado.");
			return null;
		}
	}

	private static function waitPending(string $path, Logger $logger) : void {
		if(!isset(self::$pending[$path])){
			return;
		}
		$pool = Server::getInstance()->getAsyncPool();
		$deadline = hrtime(true) + self::PENDING_WAIT_SECONDS * 1_000_000_000;
		while(isset(self::$pending[$path]) && hrtime(true) < $deadline){
			$pool->collectTasks();
			if(isset(self::$pending[$path])){
				usleep(1000);
			}
		}
		if(isset(self::$pending[$path])){
			$logger->warning("Gravações assíncronas de '$path' ainda pendentes após " . self::PENDING_WAIT_SECONDS . "s; gravando mesmo assim.");
		}
	}

	/**
	 * Move um arquivo corrompido para o lado, sem apagar.
	 *
	 * @return string|null novo caminho, ou null se não deu
	 */
	private static function quarantine(string $path) : ?string {
		$base = $path . ".corrompido-" . date("Ymd-His");
		$target = $base;
		for($i = 1; file_exists($target); $i++){
			$target = "$base-$i";
		}
		try{
			if(@rename($path, $target)){
				return $target;
			}
			// rename pode falhar entre dispositivos/permissões: tenta copiar (o original fica)
			if(@copy($path, $target)){
				@unlink($path);
				return $target;
			}
		}catch(Throwable){
			// cai no retorno abaixo
		}
		return null;
	}
}
