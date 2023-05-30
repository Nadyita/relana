<?php declare(strict_types=1);

namespace Nadyita\Relana\OSM;

use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;

class Way extends Element {
	/**
	 * @param array<string,string> $tags
	 * @param int[]                $nodes
	 */
	public function __construct(
		public int $id,
		public string $timestamp,
		public int $version,
		public int $changeset,
		public string $user,
		public int $uid,
		#[CastListToType("int")]
		public array $nodes=[],
		public array $tags=[],
		public ElementType $type=ElementType::Way,
	) {
	}

	public function isRoundabout(): bool {
		return in_array(
			$this->tags['junction'] ?? null,
			['roundabout', 'circular'],
			true
		);
	}

	public function getFirstNode(): int {
		return $this->nodes[0];
	}

	public function getLastNode(): int {
		return $this->nodes[count($this->nodes)-1];
	}

	public function print(): string {
		return "Way ({$this->id}):\n * ".
			join("\n * ", $this->nodes);
	}

	public function getConnectingNode(Way $otherWay, ?string $direction=null, int $checkNum): ?int {
		$ourFirst = $this->getFirstNode();
		$theirFirst = $otherWay->getFirstNode();
		$theirLast = $otherWay->getLastNode();
		$ourLast = $this->getLastNode();

		if ($this->isRoundabout()) {
			if (in_array($theirFirst, $this->nodes, true)) {
				return $theirFirst;
			}
			if (in_array($theirLast, $this->nodes, true)) {
				return $theirLast;
			}
			return null;
		}
		if ($otherWay->isRoundabout()) {
			if ($checkNum === 1 && in_array($ourFirst, $otherWay->nodes, true)) {
				return $ourFirst;
			}
			if (in_array($ourLast, $otherWay->nodes, true)) {
				return $ourLast;
			}
			return null;
		}
		if ($checkNum === 1) {
			if ($ourFirst === $theirFirst) {
				return $theirFirst;
			}
			if ($ourFirst === $theirLast) {
				return $theirLast;
			}
		}
		if ($ourLast === $theirFirst) {
			return $theirFirst;
		}
		if ($ourLast === $theirLast) {
			return $theirLast;
		}
		return null;
	}

	public function getDisplayName(): string {
		$wayName = $this->tags['name'] ?? null;
		if (isset($wayName)) {
			return $wayName;
		}
		$wayName = "Unknown";
		if (isset($this->tags["highway"])) {
			$wayName = 'Unnamed ' . $this->tags['highway'];
		}
		return $wayName;
	}
}
