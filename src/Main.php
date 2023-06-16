<?php

declare(strict_types=1);

namespace Nadyita\Relana;

use DateTimeImmutable;
use DateTimeZone;
use EventSauce\ObjectHydrator\{ObjectMapperUsingReflection, UnableToHydrateObject};
use Exception;
use Monolog\Logger;
use Nadyita\Relana\OSM\{ElementType, Relation as OSMRelation, Result, Way};

class Main {
	public function __construct(
		private Logger $logger,
	) {
	}

	/** @return Relation[] */
	public function readConfig(): array {
		$configFile = dirname(__DIR__) . "/relations.json";
		$json = file_get_contents($configFile);
		if ($json === false) {
			throw new Exception("Cannot open config file '{$configFile}'");
		}
		$data = json_decode($json, true);
		$mapper = new ObjectMapperUsingReflection();
		try {
			return $mapper->hydrateObjects(Relation::class, $data)->toArray();
		} catch (UnableToHydrateObject $e) {
			$this->logger->critical("Error hydrating data: {error}", [
				"error" => $e->getMessage(),
			]);
			throw $e;
		}
	}

	public function downloadRelation(Relation $relation): Result {
		$this->logger->info("Downloading relation {id}", [
			"id" => $relation->id,
		]);
		$url = "https://api.openstreetmap.org/api/0.6/relation/{$relation->id}/full.json";
		$data = @file_get_contents($url);
		if ($data === false) {
			throw new Exception("Unable to open {$url}");
		}
		$json = json_decode($data, true);
		$mapper = new ObjectMapperUsingReflection();
		try {
			$result = $mapper->hydrateObject(Result::class, $json);
		} catch (UnableToHydrateObject $e) {
			$this->logger->critical("Error hydrating data: {error}", [
				"error" => $e->getMessage(),
			]);
			throw $e;
		}
		return $result;
	}

	public function run(): void {
		$relations = $this->readConfig();
		foreach ($relations as $relation) {
			$data = $this->downloadRelation($relation);
			if ($this->validateRelation($data)) {
				echo("Relation #{$relation->id} ({$relation->name}): [OK]\n");
			} else {
				echo("Relation #{$relation->id} ({$relation->name}): [ERR]\n");
			}
		}
	}

	public function validateRelation(Result $result): bool {
		/** @var OSMRelation */
		$relation = array_pop($result->elements);
		if ($relation->tags['type'] !== 'route') {
			throw new Exception("Relation ID {$relation->id} is not a route");
		}
		$nodes = [];
		$ways = [];
		foreach ($result->elements as $way) {
			if ($way->type === ElementType::Node) {
				$nodes[$way->id] = $way;
			} elseif ($way->type === ElementType::Way) {
				$ways[$way->id] = $way;
			}
		}

		/** @var ?Way */
		$lastWay = null;

		/** @var ?Way */
		$branchBase = null;
		$checkNum = 1;
		foreach ($relation->members as $ele) {
			if ($ele->type !== ElementType::Way) {
				$this->logger->info("Skipping {type} #{id}\n", [
					"type" => $ele->type->name,
					$ele->ref,
				]);
				continue;
			}
			$way = $ways[$ele->ref]??null;
			if (!isset($way) || !($way instanceof Way)) {
				return false;
			}
			$this->logger->info("Examining {type} #{id} ({name}, {count} nodes)\n", [
				"type" => $ele->type->name,
				"id" => $ele->ref,
				"name" => $way->getDisplayName(),
				"count" => count($way->nodes),
			]);
			if (!isset($lastWay)) {
				$this->logger->debug("No last way set\n");
				if (in_array($ele->role, ["forward", "backward"])) {
					$this->logger->debug("First way has role {role}\n", [
						"role" => $ele->role,
					]);
					$lastSegment = $relation->members[count($relation->members)-1];
					$this->logger->debug("Last relation segment is {type} #{id}\n", [
						"type" => $lastSegment->type->name,
						"id" => $lastSegment->ref,
					]);
					if ($ele->type === ElementType::Way) {
						/** @var Way */
						$branchBase = $ways[$lastSegment->ref];
						$this->logger->info("Making this Way the new branch base\n");
					}
					if ($ele->role === 'backward') {
						$this->logger->debug("First item's role forces node reversal\n");
						$way->nodes = array_reverse($way->nodes);
					}
				}
				$this->logger->info("Making {type} #{id} ({name}) the last way\n", [
					"type" => $ele->type->name,
					"id" => $ele->ref,
					"name" => $way->getDisplayName(),
				]);
				$lastWay = $way;
				continue;
			}
			if (isset($branchBase) && $way === $lastWay && strlen($ele->role)) {
				$lastWay = $branchBase;
				unset($branchBase);
				continue;
			}
			$checkWay = $lastWay;
			if (isset($branchBase) && strlen($ele->role)) {
				$checkWay = $branchBase;
			}
			$connectingNode = $checkWay->getConnectingNode($way, $ele->role, $checkNum++);
			if (!isset($connectingNode)) {
				$this->logger->info("No connection between Way (#{lastway_id}) and Way #{id}\n", [
					"lastway_id" => $checkWay->id,
					"id" => $way->id,
				]);
				if (isset($branchBase)) {
					$this->logger->info("Checking Branching\n");
					$connectingNode = $lastWay->getConnectingNode($way, $ele->role, $checkNum++);
					if (!isset($connectingNode)) {
						$this->logger->error(
							"In relation {relation_id} ({relation_name}), the ".
							"way {way_id} is neither extending Way ".
							"#{lastway_id} nor the branch base Way ".
							"#{branchbase_id}",
							[
								"relation_id" => $relation->id,
								"relation_name" => $relation->tags['name'],
								"way_id" => $way->id,
								"lastway_id" => $lastWay->id,
								"branchbase_id" => $branchBase->id,
							]
						);
						$this->logger->error("Way #{way_id} consists of these nodes: {nodes}\n", [
						"way_id" => $way->id,
						"nodes" => join(", ", $way->nodes),
					]);
						$this->logger->error("Way #{way_id} consists of these nodes: {nodes}\n", [
						"way_id" => $lastWay->id,
						"nodes" => join(", ", $lastWay->nodes),
					]);
						$this->logger->error("Way #{way_id} consists of these nodes: {nodes}\n", [
						"way_id" => $branchBase->id,
						"nodes" => join(", ", $branchBase->nodes),
					]);
						return false;
					}
				} else {
					$this->logger->error(
						"In relation {relation_id} ({relation_name}), the way ".
						"{way_id} is not extending Way #{lastway_id}",
						[
							"relation_id" => $relation->id,
							"relation_name" => $relation->tags['name'],
							"way_id" => $way->id,
							"lastway_id" => $lastWay->id,
						]
					);
					$this->logger->error("Way #{way_id} consists of these nodes: {nodes}\n", [
						"way_id" => $way->id,
						"nodes" => join(", ", $way->nodes),
					]);
					$this->logger->error("Way #{way_id} consists of these nodes: {nodes}\n", [
						"way_id" => $lastWay->id,
						"nodes" => join(", ", $lastWay->nodes),
					]);
					return false;
				}
			} elseif (isset($branchBase) && strlen($ele->role)) {
				$this->logger->info("Second Branch entered\n");
			}
			if ($way->isRoundabout()) {
				// $lastWay = $way;
				// continue;
			} elseif ($connectingNode === $way->getLastNode()) {
				$way->nodes = array_reverse($way->nodes);
			}
			if (strlen($ele->role) && !isset($branchBase)) {
				/** @var Way */
				$branchBase = $lastWay;
				$this->logger->info("Making Way #{id} ({name}, {count} nodes) the new branch base.\n", [
					"id" => $branchBase->id,
					"name" => $branchBase->getDisplayName(),
					"count" => count($branchBase->nodes),
				]);
			}
			if (empty($ele->role) && isset($branchBase)) {
				$this->logger->info("Branching finished\n");
				unset($branchBase);
			}
			$lastWay = $way;
		}
		return true;
	}

	public function exportGPX(int $id): void {
		$indexer = new Indexer($this->logger);
		$data = $indexer->downloadRelationList([$id], true);
		if (empty($data->elements)) {
			$indexer->errorPage(404, "Relation {$id} not found.");
			return;
		}
		$fileName = "route";
		foreach ($data->elements as $ele) {
			if ($ele instanceof OSM\OverpassRelation && $ele->id === $id) {
				if (isset($ele->tags['name'])) {
					$fileName = preg_replace(
						"/-{2,}/",
						"-",
						preg_replace(
							"/\s+/",
							"-",
							$ele->tags['name']
						)
					);
				}
			}
		}
		header("Content-Type: application/gpx+xml");
		$gpx = $this->resultToGPX($data, $id);
		header("Content-Length: " . strlen($gpx));
		header("Content-Disposition: attachment; filename={$fileName}.gpx");
		echo($gpx);
	}

	private function resultToGPX(OSM\OverpassResult $result, int $id): string {
		/** @var array<int,OSM\OverpassNode> */
		$nodes = [];

		/** @var array<int,OSM\OverpassWay> */
		$ways = [];
		$mainName = null;
		$mainDesc = "OSM data converted to GPX by relana";
		foreach ($result->elements as $ele) {
			if ($ele instanceof OSM\OverpassNode) {
				$nodes[$ele->id] = $ele;
			} elseif ($ele instanceof OSM\OverpassWay) {
				$ways[$ele->id] = $ele;
			} elseif ($ele instanceof OSM\OverpassRelation && $ele->id === $id) {
				$mainName = $ele->tags['name'] ?? null;
				if (isset($ele->tags['description'])) {
					$mainDesc = $ele->tags['description'];
				}
			}
		}
		$time = (new DateTimeImmutable("now", new DateTimeZone("UTC")))
			->format("Y-m-d\TH:i:s\Z");
		$lines = [
			'<?xml version="1.0" encoding="UTF-8" standalone="no" ?>',
			'<gpx xmlns="http://www.topografix.com/GPX/1/1" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.topografix.com/GPX/1/1 http://www.topografix.com/GPX/1/1/gpx.xsd" version="1.1" creator="relana">',
			'  <metadata>',
		];
		if (isset($mainName)) {
			$lines []= '    <name>' . htmlspecialchars($mainName, ENT_NOQUOTES) . '</name>';
		}
		$lines = array_merge(
			$lines,
			[
				'    <desc>' . htmlspecialchars($mainDesc, ENT_NOQUOTES) . '</desc>',
				'    <copyright author="OpenStreetMap and Contributors">',
				'      <license>https://www.openstreetmap.org/copyright</license>',
				'    </copyright>',
				"    <time>{$time}</time>",
				'  </metadata>',
			]
		);
		foreach ($result->elements as $ele) {
			if (!($ele instanceof OSM\OverpassRelation)) {
				continue;
			}
			$type = $ele->tags['type'] ?? null;
			if ($type !== 'route') {
				continue;
			}
			$lines []= $this->relationToTrk($ele, $nodes, $ways);
		}
		$lines []= "</gpx>";
		return join(PHP_EOL, $lines);
	}

	/**
	 * @param array<int,OSM\OverpassNode> $nodes
	 * @param array<int,OSM\OverpassWay>  $ways
	 */
	private function relationToTrk(OSM\OverpassRelation $relation, array $nodes, array $ways): string {
		$lines = [
			"  <trk>",
			"    <name>" . htmlspecialchars($relation->tags['name'], ENT_NOQUOTES) . "</name>",
		];
		if (isset($relation->tags['note'])) {
			$lines []= "    <cmt>" . htmlspecialchars($relation->tags['note']) . '</cmt>';
		}
		if (isset($relation->tags['description'])) {
			$lines []= "    <desc>" . htmlspecialchars($relation->tags['description'], ENT_NOQUOTES) . "</desc>";
		}
		$lines []= "    <link href=\"http://osm.org/browse/relation/{$relation->id}\"/>";
		if (isset($relation->tags['route'])) {
			$lines []= "    <type>" . htmlspecialchars($relation->tags['route']) . ' route</type>';
		}
		$lines []= "    <trkseg>";
		$lastNode = null;
		for ($i = 0; $i < count($relation->members); $i++) {
			$member = $relation->members[$i];
			if ($member->type !== ElementType::Way) {
				continue;
			}
			$way = $ways[$member->ref];
			if (isset($lastNode)) {
				if ($way->nodes[count($way->nodes)-1] === $lastNode) {
					$way->nodes = array_reverse($way->nodes);
				} elseif ($way->nodes[0] !== $lastNode) {
					$lines []= "    </trkseg>";
					$lines []= "    <trkseg>";
					$lastNode = null;
				}
			}
			if (!isset($lastNode)) {
				$nextWayId = $relation->members[$i+1]??null;
				if (isset($nextWayId)) {
					$nextWay = $ways[$nextWayId->ref];
					if ($way->nodes[0] === $nextWay->nodes[0] || $way->nodes[0] === $nextWay->nodes[count($nextWay->nodes)-1]) {
						$way->nodes = array_reverse($way->nodes);
					}
				}
			}
			foreach ($way->nodes as $nodeId) {
				if ($nodeId === $lastNode) {
					continue;
				}
				$node = $nodes[$nodeId];
				$lines []= '      <trkpt lat="' . number_format($node->lat, 7) . '" '.
					'lon="' . number_format($node->lon, 7) . '"/>';
				$lastNode = $nodeId;
			}
		}
		$lines []= "    </trkseg>";
		$lines []= "  </trk>";
		return join("\n", $lines);
	}
}
