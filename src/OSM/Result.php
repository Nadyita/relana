<?php declare(strict_types=1);

namespace Nadyita\Relana\OSM;

use Nadyita\Relana\CastListToEnumType;

class Result {
	/** @param array<Node|Way|Relation> $elements */
	public function __construct(
		public string $version,
		public string $generator,
		public string $copyright,
		public string $attribution,
		public string $license,
		#[CastListToEnumType(
			propertyName: "type",
			mapping: [
				"node" => Node::class,
				"way" => Way::class,
				"relation" => Relation::class,
			]
		)]
		public array $elements,
	) {
	}
}
