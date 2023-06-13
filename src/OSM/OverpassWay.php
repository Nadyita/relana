<?php declare(strict_types=1);

namespace Nadyita\Relana\OSM;

use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;

class OverpassWay extends OverpassElement {
	/**
	 * @param array<string,string> $tags
	 * @param int[]                $nodes
	 */
	public function __construct(
		public int $id,
		#[CastListToType("int")]
		public array $nodes=[],
		public array $tags=[],
		public ElementType $type=ElementType::Way,
	) {
	}

	public function toWay(): Way {
		return new Way(
			id: $this->id,
			timestamp: "2000-01-01 00:00:00T00:00",
			version: 1,
			changeset: 1,
			user: null,
			uid: null,
			nodes: $this->nodes,
			tags: $this->tags,
		);
	}

}
