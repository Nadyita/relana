<?php declare(strict_types=1);

namespace Nadyita\Relana\OSM;

class Element {
	/** @param array<string,string> $tags */
	public function __construct(
		public ElementType $type,
		public int $id,
		public string $timestamp,
		public int $version,
		public int $changeset,
		public ?string $user=null,
		public ?int $uid=null,
		public array $tags=[],
	) {
	}
}
