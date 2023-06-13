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
}
