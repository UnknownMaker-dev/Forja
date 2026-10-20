<?php

declare(strict_types=1);

namespace Unknown\Forja\storage;

use Closure;
use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;
use function is_string;

/**
 * @internal grava um JSON já codificado numa thread assíncrona; use {@see JsonFile::writeAsync()}
 */
final class WriteJsonTask extends AsyncTask {

	private const LOCAL_CALLBACK = "onDone";

	/**
	 * @param (Closure(bool): void)|null $onDone
	 */
	public function __construct(
		private string $path,
		private string $contents,
		?Closure $onDone
	) {
		$this->storeLocal(self::LOCAL_CALLBACK, $onDone);
	}

	public function onRun() : void {
		$this->setResult(JsonFile::writeAtomic($this->path, $this->contents));
	}

	public function onCompletion() : void {
		JsonFile::finishAsync($this->path);

		$error = $this->getResult();
		$success = !is_string($error);
		if(!$success){
			Server::getInstance()->getLogger()->error("Falha ao gravar '{$this->path}': $error");
		}

		/** @var (Closure(bool): void)|null $onDone */
		$onDone = $this->fetchLocal(self::LOCAL_CALLBACK);
		if($onDone !== null){
			$onDone($success);
		}
	}
}
