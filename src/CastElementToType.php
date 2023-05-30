<?php

declare(strict_types=1);

namespace Nadyita\Relana;

use Attribute;
use EventSauce\ObjectHydrator\{ObjectMapper, PropertyCaster};
use LogicException;

#[Attribute(Attribute::TARGET_PARAMETER)]
class CastElementToType implements PropertyCaster {
	/** @param array<string,class-string> $typeToClassMap */
	public function __construct(
		private string $propertyName,
		private array $typeToClassMap,
	) {
	}

	public function cast(mixed $value, ObjectMapper $mapper): mixed {
		assert(is_array($value));

		$type = $value[$this->propertyName] ?? null;
		if (!isset($type)) {
			throw new LogicException("No {$this->propertyName} given.");
		}
		$className = $this->typeToClassMap[$type] ?? null;

		if (!isset($className)) {
			throw new LogicException("Unable to map type '{$type}' to class.");
		}

		return $mapper->hydrateObject($className, $value);
	}
}
