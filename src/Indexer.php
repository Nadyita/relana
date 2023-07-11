<?php

declare(strict_types=1);

namespace Nadyita\Relana;

use EventSauce\ObjectHydrator\{ObjectMapperUsingReflection, UnableToHydrateObject};
use Monolog\Logger;
use Nadyita\Relana\OSM\{ElementType, OverpassElement, OverpassNode, OverpassRelation, OverpassResult, OverpassWay, Relation as OSMRelation, Way};

class Indexer {
	/** @var array<int,OverpassRelation> */
	private array $relations=[];

	/** @var array<int,OverpassNode> */
	private array $nodes=[];

	/** @var array<int,OverpassWay> */
	private array $ways=[];
	private bool $fromCache = false;

	public function __construct(
		private Logger $logger,
	) {
	}

	public function errorPage(int $code, string $msg): void {
		header("Status: {$code}");
		header("Cotent-Type: text/html");
		$lines = [
			'<!DOCTYPE html>',
			'<html lang="en">',
			'  <head>',
			'    <meta charset="UTF-8">',
			'    <meta name="viewport" content="width=device-width, initial-scale=1">',
			"    <title>Error {$code}</title>",
			'  </head>',
			'  <body>',
			"    <h1>Error {$code}</h1>",
			'    <p>' . htmlentities($msg) . '</p>',
			'  </body>',
			'</html>',
		];
		echo(join(PHP_EOL, $lines));
	}

	public function getName(OverpassRelation $relation): string {
		$name = $relation->tags['name']
			?? $relation->tags['ref']
			?? $relation->tags['description']
			?? (
				isset($relation->tags['from'], $relation->tags['to'])
				? "Von {$relation->tags['from']} nach {$relation->tags['to']}"
				: "Unbenannte Route"
			);
		if (isset($relation->tags['stage'])) {
			$name .= " (Etappe " . $relation->tags['stage'] . ")";
		}
		return $name;
	}

	public function run(?string $ids=null): void {
		if (!isset($ids)) {
			$this->errorPage(400, "Missing parameter: ids");
			return;
		}
		$ids = array_map("intval", explode(",", $ids));
		$result = $this->downloadRelationList($ids, ($_REQUEST['no_cache'] ?? null) === '1');
		foreach ($result->elements as $ele) {
			if ($ele instanceof OverpassRelation) {
				$this->relations[$ele->id] = $ele;
			} elseif ($ele instanceof OverpassNode) {
				$this->nodes[$ele->id] = $ele;
			} elseif ($ele instanceof OverpassWay) {
				$this->ways[$ele->id] = $ele;
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
		if (count($strayRelations)) {
			usort($strayRelations, function (OverpassRelation $r1, OverpassRelation $r2): int {
				return strnatcmp($this->getName($r1), $this->getName($r2));
			});
			foreach ($strayRelations as $relation) {
				$blocks []= $this->renderRelation($relation, $result);
			}
			natsort($blocks);
			$blocks = [
				"<h1 class=\"mt-5\">".
					"Einzel-Routen".
				"</h1>",
				"<ul class=\"list-group\">",
				...$blocks,
				"</ul>",
			];
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
			$refreshLink = '<div class="fixed-top p-1"><a class="btn btn-primary float-end" role="button" href="/rels.php?relations=' . join(",", $ids) . '&amp;no_cache=1">Routenliste neu laden</a></div>';
		}
		$pre = str_replace("{refresh-link}", $refreshLink, $pre);
		$post = file_get_contents(dirname(__DIR__) . "/post.html");
		assert($post !== false);
		header("Content-type: text/html");
		return $pre.join("\n", $blocks)."\n".$post;
	}

	/** @param int[] $ids */
	public function downloadRelationList(array $ids, bool $forceDownload=false): OverpassResult {
		$cacheFile = dirname(__DIR__) . "/cache-" . join(",", $ids).".json";
		$stat = @stat($cacheFile);
		if ($stat !== false && !$forceDownload) {
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
			$this->errorPage(404, "Relation " . join(", ", $ids) . " not found.");
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

	public function getWayLength(OverpassWay $way): float {
		$totalLength = 0;
		for ($i = 1; $i < count($way->nodes); $i++) {
			$from = $this->nodes[$way->nodes[$i-1]];
			$to = $this->nodes[$way->nodes[$i]];
			$totalLength += $way->haversineGreatCircleDistance($from, $to);
		}
		return $totalLength;
	}

	public function getRelationLength(OverpassRelation $rel): float {
		$totalLength = 0;
		foreach ($rel->members as $member) {
			if ($member->type === ElementType::Way) {
				$totalLength += $this->getWayLength($this->ways[$member->ref]);
			}
		}
		return $totalLength;
	}

	private function getRelationIcon(OverpassRelation $relation): string {
		return "<img class=\"img-fluid\" src=\"/check.php?id={$relation->id}\" />";
	}

	private function getSymbol(OverpassRelation $relation): string {
		if (isset($relation->tags['wiki:symbol'])) {
			$normPath = str_replace(" ", "_", $relation->tags['wiki:symbol']);
			$md5 = md5($normPath);
			$fullPath = substr($md5, 0, 1) . "/" . substr($md5, 0, 2) . "/{$normPath}";
			return "<img class=\"symbol\" src=\"https://wiki.openstreetmap.org/w/images/{$fullPath}\"/>";
		}
		if (isset($relation->tags['osmc:symbol'])) {
			return "<img class=\"symbol\" src=\"https://hiking.waymarkedtrails.org/api/v1/symbols/from_tags/NAT?".
				http_build_query(["osmc:symbol" => $relation->tags['osmc:symbol']]).
				"\"/>";
		}
		return "";
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
			if (count($blocks)) {
				$fromTo = [];
				if (isset($relation->tags['from'])) {
					$fromTo []= "from {$relation->tags['from']}";
				}
				if (isset($relation->tags['to'])) {
					$fromTo []= "to {$relation->tags['to']}";
				}
				$relName = $this->getName($relation);
				$blocks = [
					"<h1 class=\"mt-5\">".
						htmlentities($relName).
						((isset($relation->tags['ref']) && ($relation->tags['ref'] !== $relName))
						? ' <span class="text-black-50">('.
							htmlentities($relation->tags['ref']).
							')</span>'
						: '').
						(isset($relation->tags['wiki:symbol'])
						? $this->getSymbol($relation)
						: '').
					"&ensp;<a class=\"fs-6 icon-link link-underline link-underline-opacity-0 link-underline-opacity-50-hover\" title=\"Download route as GPX\" href=\"/gpx.php?id={$relation->id}\">".
						'GPX <svg class="bi" aria-hidden="true"><use xlink:href="#download"></use></svg>'.
						"</a>".
					"</h1>".
					(
						isset($relation->tags['alt_name'])
						? PHP_EOL . '<div class="small">a.k.a. '.
							htmlentities($relation->tags['alt_name']).
							'</div>'
						: ''
					).
					(isset($relation->tags['description'])
						? PHP_EOL.'<div class="small text-black-50">'.
							htmlentities($relation->tags['description']).
							'</div>'
						: ''),
					(
						count($fromTo)
						? PHP_EOL . ucfirst(join(" ", $fromTo))
						: ""
					),
					"<ul class=\"list-group\">",
					...$blocks,
					"</ul>",
				];
			}
			return join("\n", $blocks);
		}
		$url = "http://ra.osmsurround.org/analyzeRelation?relationId={$relation->id}&_noCache=on";
		$name = htmlentities($this->getName($relation));
		$icon = $this->getRelationIcon($relation);
		$distance = $relation->tags['distance'] ?? null;
		if (!isset($distance)) {
			$distance = $this->getRelationLength($relation) / 1000;
		}
		$distance = number_format(round((float)$distance, 2), 2);
		$fromTo = [];
		if (isset($relation->tags['from'])) {
			$fromTo []= "<strong>From</strong> " . htmlentities($relation->tags['from']);
		}
		if (isset($relation->tags['to'])) {
			$fromTo []= "<strong>to</strong> " . htmlentities($relation->tags['to']);
		}
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
						((isset($relation->tags['ref']) && $relation->tags['ref'] !== $name)
							? " <span class=\"text-black-50\">(" . htmlentities($relation->tags['ref']) . ")</span>"
							: "").
					'</p>'.PHP_EOL.
					(count($fromTo)
						? "<div class=\"small\">" . join(" ", $fromTo) . '</div>'
						: "").
					((isset($relation->tags['symbol']) || isset($relation->tags['wiki:symbol']) || isset($relation->tags['osmc:symbol']))
						? "<div class=\"small\"><strong>Symbol</strong>: ".
							$this->getSymbol($relation).
							(isset($relation->tags['symbol']) ? (" " . htmlentities($relation->tags['symbol']))
							: '') . '</div>'
						: "").
					(isset($relation->tags['description'])
						? "<div class=\"small\"><strong>Description</strong>: " . htmlentities($relation->tags['description']) . '</div>'
						: "").
				'</div>'.PHP_EOL.
				'<span class="badge bg-primary rounded-pill float-end">'.
					$distance . ' km'.
				'</span>'.PHP_EOL.
				"<span class=\"badge float-end\">". PHP_EOL.
					"<a class=\"icon-link link-underline link-underline-opacity-0 link-underline-opacity-50-hover\" title=\"Download route as GPX\" href=\"/gpx.php?id={$relation->id}\">".
						'GPX <svg class="bi" aria-hidden="true"><use xlink:href="#download"></use></svg>'.
					"</a>" . PHP_EOL.
				"</span>" . PHP_EOL.
			'</li>';
	}

	private function getRelation(int $id): OverpassRelation {
		return $this->relations[$id];
	}
}
