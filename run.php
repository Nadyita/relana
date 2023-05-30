<?php declare(strict_types=1);
require_once __DIR__ . "/vendor/autoload.php";

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\{StreamHandler};
use Monolog\Processor\PsrLogMessageProcessor;
use Monolog\{Level, Logger};
use Nadyita\Relana\Main;

$logger = new Logger('Relana');
$handler = new StreamHandler(__DIR__ . "/relana.log", Level::Notice);
$handler->setFormatter(new LineFormatter("[%level_name%] %message%\n"));
$logger->pushHandler($handler);
$logger->pushProcessor(new PsrLogMessageProcessor(null, true));
$main = new Main($logger);
$main->run();
