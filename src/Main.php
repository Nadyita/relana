<?php

declare(strict_types=1);

namespace Nadyita\Relana;

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
			$checkWay = $lastWay;
			if (isset($branchBase)) {
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
			} elseif (isset($branchBase)) {
				$this->logger->info("Second Branch entered\n");
			}
			if ($way->isRoundabout()) {
				$lastWay = $way;
				continue;
			}
			if ($connectingNode === $way->getLastNode()) {
				$way->nodes = array_reverse($way->nodes);
			}
			if (strlen($ele->role) && !isset($branchBase)) {
				/** @var Way */
				$branchBase = $lastWay;
				$this->logger->info("Making Way #{id} ({name}) the new branch base.\n", [
					"id" => $branchBase->id,
					"name" => $branchBase->getDisplayName(),
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
}
