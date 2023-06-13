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

	public function toNode(): Node {
		return new Node(
			id: $this->id,
			timestamp: "2000-01-01 00:00:00T00:00",
			version: 1,
			changeset: 1,
			user: null,
			uid: null,
			lat: $this->lat,
			lon: $this->lon,
			tags: $this->tags,
		);
	}
}
