<?php declare(strict_types=1);

namespace Nadyita\Relana\OSM;

class Node extends Element {
	/** @param array<string,string> $tags */
	public function __construct(
		public int $id,
		public string $timestamp,
		public int $version,
		public int $changeset,
		public string $user,
		public int $uid,
		public float $lat,
		public float $lon,
		public array $tags=[],
		public ElementType $type=ElementType::Node,
	) {
	}
}
