<?php declare(strict_types=1);

namespace Nadyita\Relana\OSM;

use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;

class Relation extends Element {
	/**
	 * @param array<string,string> $tags
	 * @param RelationMember[]     $members
	 */
	public function __construct(
		public int $id,
		public string $timestamp,
		public int $version,
		public int $changeset,
		public string $user,
		public int $uid,
		#[CastListToType(RelationMember::class)]
		public array $members=[],
		public array $tags=[],
		public ElementType $type=ElementType::Relation,
	) {
	}
}
