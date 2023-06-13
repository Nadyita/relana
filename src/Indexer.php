<?php

declare(strict_types=1);

namespace Nadyita\Relana;

use EventSauce\ObjectHydrator\{ObjectMapperUsingReflection, UnableToHydrateObject};
use Exception;
use Monolog\Logger;
use Nadyita\Relana\OSM\{ElementType, OverpassElement, OverpassNode, OverpassRelation, Relation as OSMRelation, OverpassResult, OverpassWay, Way};
use Nadyita\Relana\OSM;

class Indexer {
	/** @var array<int,OverpassRelation> */
	private array $relations=[];
	private OSM\Result $result;
	private bool $fromCache = false;

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
		foreach ($result->elements as $ele) {
			if ($ele instanceof OverpassRelation) {
				$this->relations[$ele->id] = $ele;
			}
		}
		$this->result = $result->toResult();
		$html = $this->generateIndex($ids, $result);
		header("Cache-Control: no-cache");
		echo($html);
	}

	/** @param int[] $ids */
	public function generateIndex(array $ids, OverpassResult $result): string {
		$blocks = [];
		$strayRelations = [];
		foreach ($ids as $id) {
			$relation = $this->getRelation($id, $result);
			if ($relation->members[0]->type !== OSM\ElementType::Relation) {
				$strayRelations []= $relation;
			}
		}
		usort($strayRelations, function (OverpassRelation $r1, OverpassRelation $r2): int {
			return strnatcmp($r1->tags['name'], $r2->tags['name']);
		});
		foreach ($strayRelations as $relation) {
			$blocks []= $this->renderRelation($relation, $result);
		}
		foreach ($ids as $id) {
			$relation = $this->getRelation($id, $result);
			if ($relation->members[0]->type === OSM\ElementType::Relation) {
				$blocks []= $this->renderRelation($relation, $result);
			}
		}
		$pre = file_get_contents(dirname(__DIR__) . "/pre.html");
		$refreshLink = "";
		if ($this->fromCache) {
			$refreshLink = '<div><a class="btn btn-primary float-end" role="button" href="/rels.php?ids=' . join(",", $ids) . '&amp;no_cache=1">Routenliste neu laden</a></div>';
		}
		$pre = str_replace("{refresh-link}", $refreshLink, $pre);
		$post = file_get_contents(dirname(__DIR__) . "/post.html");
		header("Content-type: text/html");
		return $pre.join("\n", $blocks)."\n".$post;
	}

	private function getRelationIcon(OverpassRelation $relation): string {
		return "<img src=\"/check.php?id={$relation->id}\" />";
		$main = new Main($this->logger);
		$result = clone($this->result);
		$result->elements []= $relation->toRelation();
		if ($main->validateRelation($result)) {
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
				htmlentities($relation->tags['name']) . "</h1>".
				"<ul class=\"list-group\">");
			return join("\n", $blocks);
		}
		return "<!-- " . htmlentities($relation->tags["name"]) . " --><li class=\"list-group-item\"><span class=\"me-3\">".
			$this->getRelationIcon($relation) . "</span>".
			"<a target=\"_blank\" href=\"http://ra.osmsurround.org/analyzeRelation?relationId={$relation->id}&_noCache=on\">".
			htmlentities($relation->tags["name"]) . "</a></li>";
	}

	private function getRelation(int $id): OverpassRelation {
		return $this->relations[$id];
	}

	/**
	 * @param int[] $ids
	 */
	public function downloadRelationList(array $ids): OverpassResult {
		$cacheFile = dirname(__DIR__) . "/cache-" . join(",", $ids).".json";
		$stat = @stat($cacheFile);
		if ($stat !== false && !isset($_REQUEST['no_cache'])) {
			$result = file_get_contents($cacheFile);
			$this->fromCache = true;
		} else {
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
			file_put_contents($cacheFile, $result);
			$this->fromCache = false;
		}

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