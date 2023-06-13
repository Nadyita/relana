<?php declare(strict_types=1);

namespace Nadyita\Relana\OSM;

use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;

class OverpassRelation extends OverpassElement {
	/**
	 * @param array<string,string> $tags
	 * @param RelationMember[]     $members
	 */
	public function __construct(
		public int $id,
		#[CastListToType(RelationMember::class)]
		public array $members=[],
		public array $tags=[],
		public ElementType $type=ElementType::Relation,
	) {
	}

	public function toRelation(): Relation {
		return new Relation(
			id: $this->id,
			timestamp: "2000-01-01 00:00:00T00:00",
			version: 1,
			changeset: 1,
			user: null,
			uid: null,
			members: $this->members,
			tags: $this->tags,
		);
	}
}
