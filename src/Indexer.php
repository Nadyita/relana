<?php

declare(strict_types=1);

namespace Nadyita\Relana;

use EventSauce\ObjectHydrator\{ObjectMapperUsingReflection, UnableToHydrateObject};
use Exception;
use Monolog\Logger;
use Nadyita\Relana\OSM\{ElementType, OverpassRelation, Relation as OSMRelation, OverpassResult, Way};

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
			array_unshift($blocks, "<tr class=\"table-primary\"><td colspan=\"2\" class=\"text-center\">".
				"<a target=\"_blank\" href=\"http://ra.osmsurround.org/analyzeRelation?relationId={$relation->id}&_noCache=on\">".
				"<strong>" . htmlentities($relation->tags['name']) . "</strong></a></td></tr>");
			return join("\n", $blocks);
		}
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