<?php declare(strict_types=1);

namespace Nadyita\Relana\OSM;

enum ElementType: string {
	case Node = "node";
	case Way = "way";
	case Relation = "relation";
}
