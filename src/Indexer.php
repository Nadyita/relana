<?php

declare(strict_types=1);

namespace Nadyita\Relana;

use EventSauce\ObjectHydrator\{ObjectMapperUsingReflection, UnableToHydrateObject};
use Monolog\Logger;
use Nadyita\Relana\OSM\{ElementType, OverpassElement, OverpassNode, OverpassRelation, OverpassResult, OverpassWay, Relation as OSMRelation, Way};

class Indexer {
	/** @var array<int,OverpassRelation> */
	private array $relations=[];
	private bool $fromCache = false;

	public function __construct(
		private Logger $logger,
	) {
	}

	public function errorPage(string $msg): void {
	}

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
		$html = $this->generateIndex($ids, $result);
		header("Cache-Control: no-cache");
		echo($html);
	}

	/** @param int[] $ids */
	public function generateIndex(array $ids, OverpassResult $result): string {
		$blocks = [];
		$strayRelations = [];
		foreach ($ids as $id) {
			$relation = $this->getRelation($id);
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
			$relation = $this->getRelation($id);
			if ($relation->members[0]->type === OSM\ElementType::Relation) {
				$blocks []= $this->renderRelation($relation, $result);
			}
		}
		$pre = file_get_contents(dirname(__DIR__) . "/pre.html");
		assert($pre !== false);
		$refreshLink = "";
		if ($this->fromCache) {
			$refreshLink = '<div><a class="btn btn-primary float-end" role="button" href="/rels.php?ids=' . join(",", $ids) . '&amp;no_cache=1">Routenliste neu laden</a></div>';
		}
		$pre = str_replace("{refresh-link}", $refreshLink, $pre);
		$post = file_get_contents(dirname(__DIR__) . "/post.html");
		assert($post !== false);
		header("Content-type: text/html");
		return $pre.join("\n", $blocks)."\n".$post;
	}

	/** @param int[] $ids */
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
					'content' => $postdata,
				],
			];

			$context  = stream_context_create($opts);

			$result = @file_get_contents('https://overpass-api.de/api/interpreter', false, $context);
			if ($result !== false) {
				file_put_contents($cacheFile, $result);
				$this->fromCache = false;
			}
		}

		if ($result === false) {
			$this->errorPage("Unable to download relations.");
			exit(1);
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

	private function getRelationIcon(OverpassRelation $relation): string {
		return "<img class=\"img-fluid\" src=\"/check.php?id={$relation->id}\" />";
	}

	private function renderRelation(OverpassRelation $relation, OverpassResult $result): string {
		if (count($relation->members) === 0) {
			return "";
		}
		$blocks = [];
		if ($relation->members[0]->type === ElementType::Relation) {
			foreach ($relation->members as $member) {
				if ($member->type !== ElementType::Relation) {
					continue;
				}
				$member = $this->getRelation($member->ref);
				if ($member instanceof OverpassRelation) {
					$blocks []= $this->renderRelation($member, $result);
				}
				natsort($blocks);
			}
			array_unshift(
				$blocks,
				"</ul>\n".
				"<h1 class=\"mt-5\">".
					htmlentities($relation->tags['name']).
				"</h1>\n".
				"<ul class=\"list-group\">"
			);
			return join("\n", $blocks);
		}
		$url = "http://ra.osmsurround.org/analyzeRelation?relationId={$relation->id}&_noCache=on";
		$name = htmlentities($relation->tags["name"]);
		$icon = $this->getRelationIcon($relation);
		return "<!-- {$name} -->".
			'<li class="list-group-item d-flex align-items-start">'.PHP_EOL.
				'<span class="float-start d-inline-flex align-items-center justify-content-center me-2">'.
					$icon.
				'</span>'.PHP_EOL.
				'<div class="w-100">'.PHP_EOL.
					'<p class="mb-1">'.PHP_EOL.
						"<a class=\"link-offset-2 link-underline link-underline-opacity-0\" target=\"_blank\" href=\"{$url}\">".
							$name.
						"</a>".PHP_EOL.
						(isset($relation->tags['ref'])
							? " <span class=\"text-black-50\">(" . htmlentities($relation->tags['ref']) . ")</span>"
							: "").
					'</p>'.PHP_EOL.
					(isset($relation->tags['symbol'])
						? "<div class=\"small\"><strong>Symbol</strong>: " . htmlentities($relation->tags['symbol']) . '</div>'
						: "").
					(isset($relation->tags['description'])
						? "<div class=\"small\"><strong>Description</strong>: " . htmlentities($relation->tags['description']) . '</div>'
						: "").
				'</div>'.PHP_EOL.
				(isset($relation->tags['distance'])
					? '<span class="badge bg-primary rounded-pill float-end">'.
							htmlentities($relation->tags['distance']). 'km'.
						'</span>'.PHP_EOL
					: '').
			'</li>';
	}

	private function getRelation(int $id): OverpassRelation {
		return $this->relations[$id];
	}
}
