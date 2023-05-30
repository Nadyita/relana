<?php declare(strict_types=1);

namespace Nadyita\Relana\OSM;

class RelationMember {
	public function __construct(
		public ElementType $type,
		public int $ref,
		public string $role,
	) {
	}
}
