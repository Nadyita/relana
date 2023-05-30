<?php

declare(strict_types=1);

namespace Nadyita\Relana;

use function assert;
use function is_array;
use Attribute;
use EventSauce\ObjectHydrator\{ObjectMapper, PropertyCaster, PropertySerializer};
use LogicException;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final class CastListToEnumType implements PropertyCaster, PropertySerializer {
	public const NATIVE_TYPES = ['bool', 'boolean', 'int', 'integer', 'float', 'double', 'string', 'array', 'object', 'null'];

	/** @param array<string,class-string> $mapping */
	public function __construct(
		private string $propertyName,
		private array $mapping,
	) {
	}

	public function cast(mixed $value, ObjectMapper $hydrator): mixed {
		assert(is_array($value), 'value is expected to be an array');

		return $this->castToObjectType($value, $hydrator);
	}

	public function serialize(mixed $value, ObjectMapper $hydrator): mixed {
		assert(is_array($value), 'value should be an array');

		foreach ($value as $i => $item) {
			$value[$i] = $hydrator->serializeObject($item);
		}

		return $value;
	}

	/**
	 * @param array<mixed,mixed>[] $value
	 *
	 * @return array<int,object>
	 */
	private function castToObjectType(array $value, ObjectMapper $hydrator): array {
		$result = [];
		foreach ($value as $i => $item) {
			$type = $item[$this->propertyName]??null;
			if (!isset($type)) {
				throw new LogicException("No {$this->propertyName} found in object of array.");
			}
			$className = $this->mapping[$type] ?? null;
			if (!isset($className)) {
				throw new LogicException("Unable to map type '{$type}' to class.");
			}
			$result[$i] = $hydrator->hydrateObject($className, $item);
		}

		return $result;
	}
}
