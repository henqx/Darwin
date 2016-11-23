<?php
require('config.php'); //Provides q function for concise MySQL queries
require('utilities.php'); //Utility functions for math operations (e.g. Wilson intervals)
require 'vendor/autoload.php'; //Klogger class for logging
require 'darwin.php'; //Darwin class

//Init logger
$logger = new Katzgrau\KLogger\Logger('logs', Psr\Log\LogLevel::INFO, ['filename' => 'darwin.loh']);

//Start timer
$timer = new Timer($con1, 'Darwin', 'init');

//Wire in Darwing with DB connection, timer and logger. Also perform small, low-intensity queries to acquire domain and autoresponder information
$darwin = new Darwin($con1, $timer, $logger);

//Aggregate stats for both creatives and aggregates
$timer->lap('getStats');
$darwin->getStats();
$logger->info('getStats completed');

//Run all battles concerning inividual combinations
$timer->lap('war');
$darwin->war();
$logger->info('war completed');

//Determine which creative combinations will be elmininated
$timer->lap('aftermathCreatives');
$darwin->aftermathCreatives();
$logger->info('aftermathCreatives completed');

//Determine which block combinations will be elmininated
$timer->lap('aftermathBlocks');
$darwin->aftermathBlocks();
$logger->info('aftermathBlocks completed');

//Run all battles on an aggregate basis
$timer->lap('warAgg');
$darwin->warAgg();
$logger->info('warAgg completed');

//Eliminate those creative combinations that have not reached the set requirements in regards to aggregate performance
$timer->lap('aftermathAggCreatives');
$darwin->aftermathAggCreatives();
$logger->info('aftermathAggCreatives completed');

//Eliminate those block combinations that have not reached the set requirements in regards to aggregate performance
$timer->lap('aftermathAggBlocks');
$darwin->aftermathAggBlocks();
$logger->info('aftermathAggBlocks completed');

$timer->lap('dummy'); //Dummy lap in order to receive the time for aggBlocks
//print_r($timer->array);
$timer->log();
