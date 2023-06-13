<?php declare(strict_types=1);

namespace Nadyita\Relana\OSM;

class OverpassElement {
	/** @param array<string,string> $tags */
	public function __construct(
		public ElementType $type,
		public int $id,
		public array $tags=[],
	) {
	}
}
