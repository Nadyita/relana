<?php

declare(strict_types=1);
require_once __DIR__ . "/vendor/autoload.php";

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\{StreamHandler};
use Monolog\Processor\PsrLogMessageProcessor;
use Monolog\{Level, Logger};
use Nadyita\Relana\Main;
use Nadyita\Relana\Relation;

function showPic(string $file): void {
	header("Content-type: image/svg+xml", true);
	$data = file_get_contents($file);
	header("Content-Length: " . strlen($data), true);
	echo ($data);
}
$logger = new Logger('Relana');
$handler = new StreamHandler(__DIR__ . "/relana.log", Level::Notice);
$handler->setFormatter(new LineFormatter("[%level_name%] %message%\n"));
$logger->pushHandler($handler);
$logger->pushProcessor(new PsrLogMessageProcessor(null, true));
$main = new Main($logger);
$relationStub = new Relation(name: "Dummy", id: (int)($_REQUEST['id'] ?? 1));
try {
	$relation = $main->downloadRelation($relationStub);
} catch (Throwable) {
	showPic(__DIR__ . "/img/cancel.svg");
	exit(0);
}	
if ($main->validateRelation($relation)) {
	showPic(__DIR__ . "/img/approval.svg");
} else {
	showPic(__DIR__ . "/img/broken_link.svg");
}
