<?php

declare(strict_types=1);

namespace Unknown\Forja\data;

/**
 * Acumula os erros reportados pelos deserializers durante um update().
 */
final class ValidationErrors {

	/** @var list<string> */
	private array $errors = [];

	public function addError(string $message) : void {
		$this->errors[] = $message;
	}

	public function hasErrors() : bool {
		return count($this->errors) > 0;
	}

	/**
	 * @return list<string>
	 */
	public function getErrors() : array {
		return $this->errors;
	}
}
