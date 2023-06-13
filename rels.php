<?php declare(strict_types=1);
require_once __DIR__ . "/vendor/autoload.php";

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\{StreamHandler};
use Monolog\Processor\PsrLogMessageProcessor;
use Monolog\{Level, Logger};
use Nadyita\Relana\Indexer;

$logger = new Logger('Relana');
$handler = new StreamHandler(__DIR__ . "/relana.log", Level::Notice);
$handler->setFormatter(new LineFormatter("[%level_name%] %message%\n"));
$logger->pushHandler($handler);
$logger->pushProcessor(new PsrLogMessageProcessor(null, true));
$indexer = new Indexer($logger);
$relations = $_REQUEST['relations'] ?? '7700619,15971668,2012761,11552385,86399,15824677,7163517,67063,67062,6479318,67064,15976004';
$indexer->run($relations);
