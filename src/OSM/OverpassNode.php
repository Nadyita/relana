<?php declare(strict_types=1);

namespace Nadyita\Relana\OSM;

class OverpassNode extends OverpassElement {
	/** @param array<string,string> $tags */
	public function __construct(
		public int $id,
		public float $lat,
		public float $lon,
		public array $tags=[],
		public ElementType $type=ElementType::Node,
	) {
	}
}
