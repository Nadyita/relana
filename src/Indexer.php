<?php

declare(strict_types=1);

namespace Nadyita\Relana;

use EventSauce\ObjectHydrator\{ObjectMapperUsingReflection, UnableToHydrateObject};
use Exception;
use Monolog\Logger;
use Nadyita\Relana\OSM\{ElementType, OverpassNode, OverpassRelation, Relation as OSMRelation, OverpassResult, OverpassWay, Way};
use Nadyita\Relana\OSM;

class Indexer {
	public function __construct(
		private Logger $logger,
	) {
	}

	public function errorPage(string $msg): void {}

	public function run(?string $ids=null): void {
		if (!isset($ids)) {
			$this->errorPage("Missing parameter: ids");
			return;
		}
		$ids = array_map("intval", explode(",", $ids));
		$result = $this->downloadRelationList($ids);
		$html = $this->generateIndex($ids, $result);
		echo($html);
	}

	/** @param int[] $ids */
	public function generateIndex(array $ids, OverpassResult $result): string {
		$blocks = [];
		foreach ($ids as $id) {
			$relation = $this->getRelation($id, $result);
			$blocks []= $this->renderRelation($relation, $result);
		}
		$pre = file_get_contents(dirname(__DIR__) . "/pre.html");
		$post = file_get_contents(dirname(__DIR__) . "/post.html");
		header("Content-type: text/html");
		return $pre.join("\n", $blocks)."\n".$post;
	}

	private function getRelationIcon(OverpassRelation $relation, OverpassResult $result): string {
		$main = new Main($this->logger);
		$nElements = [];
		foreach ($result->elements as $element) {
			if ($element instanceof OverpassNode) {
				$nElements []= new OSM\Node(
					id: $element->id,
					timestamp: "2000-01-01 00:00:00T00:00",
					version: 1,
					changeset: 1,
					user: null,
					uid: null,
					lat: $element->lat,
					lon: $element->lon,
					tags: $element->tags,
				);
			} elseif ($element instanceof OverpassWay) {
				$nElements []= new OSM\Way(
					id: $element->id,
					timestamp: "2000-01-01 00:00:00T00:00",
					version: 1,
					changeset: 1,
					user: null,
					uid: null,
					nodes: $element->nodes,
					tags: $element->tags,
				);
			} else {
				$nElements []= new OSM\Relation(
					id: $element->id,
					timestamp: "2000-01-01 00:00:00T00:00",
					version: 1,
					changeset: 1,
					user: null,
					uid: null,
					members: $element->members,
					tags: $element->tags,
				);
			}
		}
		$nRelation = new OSM\Relation(
			id: $relation->id,
			timestamp: "2000-01-01 00:00:00T00:00",
			version: 1,
			changeset: 1,
			user: null,
			uid: null,
			members: $relation->members,
			tags: $relation->tags,
		);
		$nResult = new OSM\Result(
			version: $result->version,
			generator: $result->generator,
			copyright: "none",
			attribution: "none",
			license: "none",
			elements: [...$nElements, $nRelation],
		);
		if ($main->validateRelation($nResult)) {
			return "<img src=\"/img/approval.svg\"/>";
		}
		return "<img src=\"/img/broken_link.svg\"/>";
	}

	private function renderRelation(OverpassRelation $relation, OverpassResult $result): string {
		if (count($relation->members) === 0) {
			return "";
		}
		if ($relation->members[0]->type === ElementType::Relation) {
			foreach ($relation->members as $member) {
				if ($member->type !== ElementType::Relation) {
					continue;
				}
				$member = $this->getRelation($member->ref, $result);
				if ($member instanceof OverpassRelation) {
					$blocks []= $this->renderRelation($member, $result);
				}
				natsort($blocks);
			}
			array_unshift($blocks, "</ul>\n<h1 class=\"mt-5\">".
				"<a target=\"_blank\" href=\"http://ra.osmsurround.org/analyzeRelation?relationId={$relation->id}&_noCache=on\">".
				htmlentities($relation->tags['name']) . "</a></h1>".
				"<ul class=\"list-group\">");
			// array_unshift($blocks, "<tr class=\"table-primary\"><td colspan=\"2\" class=\"text-center\">".
			// 	"<a target=\"_blank\" href=\"http://ra.osmsurround.org/analyzeRelation?relationId={$relation->id}&_noCache=on\">".
			// 	"<strong>" . htmlentities($relation->tags['name']) . "</strong></a></td></tr>");
			return join("\n", $blocks);
		}
		return "<li class=\"list-group-item\"><span class=\"me-3\">".
			$this->getRelationIcon($relation, $result) . "</span>".
			"<a target=\"_blank\" href=\"http://ra.osmsurround.org/analyzeRelation?relationId={$relation->id}&_noCache=on\">".
			htmlentities($relation->tags["name"]) . "</a></li>";
		return "<tr><td>" . htmlentities($relation->tags['name']) . "</td>".
			"<td><a target=\"_blank\" href=\"http://ra.osmsurround.org/analyzeRelation?relationId={$relation->id}&_noCache=on\">".
			"<img src=\"/check.php?id={$relation->id}\" /></a></td></tr>";
	}

	private function getRelation(int $id, OverpassResult $result): OverpassRelation {
		foreach ($result->elements as $ele) {
			if ($ele->id === $id && $ele instanceof OverpassRelation) {
				return $ele;
			}
		}
		throw new Exception("Boom!");
	}

	/**
	 * @param int[] $ids
	 */
	public function downloadRelationList(array $ids): OverpassResult {
		$postdata = http_build_query([
			'data' => '[out:json][timeout:25];relation(id:' . join(",", $ids) . ');>>;out;',
		]);

		$opts = [
			'http' => [
				'method' => 'POST',
				'header' => 'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
				'content' => $postdata
			]
		];

		$context  = stream_context_create($opts);

		$result = file_get_contents('https://overpass-api.de/api/interpreter', false, $context);
		
		$data = json_decode($result, true);
		$mapper = new ObjectMapperUsingReflection();
		try {
			return $mapper->hydrateObject(OverpassResult::class, $data);
		} catch (UnableToHydrateObject $e) {
			$this->logger->critical("Error hydrating data: {error}", [
				"error" => $e->getMessage(),
			]);
			throw $e;
		}
	}
}