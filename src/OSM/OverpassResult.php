<?php declare(strict_types=1);

namespace Nadyita\Relana\OSM;

use EventSauce\ObjectHydrator\PropertyCasters\CastToType;
use Nadyita\Relana\CastListToEnumType;

class OverpassResult {
	/** @param array<OverpassNode|OverpassWay|OverpassRelation> $elements */
	public function __construct(
		#[CastToType("string")] public string $version,
		public string $generator,
		#[CastListToEnumType(
			propertyName: "type",
			mapping: [
				"node" => OverpassNode::class,
				"way" => OverpassWay::class,
				"relation" => OverpassRelation::class,
			]
		)]
		public array $elements,
	) {
	}

	public function toResult(): Result {
		$elements = [];
		foreach ($this->elements as $element) {
			if ($element instanceof OverpassNode) {
				$elements []= $element->toNode();
			} elseif ($element instanceof OverpassWay) {
				$elements []= $element->toWay();
			} else {
				$elements []= $element->toRelation();
			}
		}
		return new Result(
			version: $this->version,
			generator: $this->generator,
			copyright: "none",
			attribution: "none",
			license: "none",
			elements: $elements,
		);
	}
}
