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
}
