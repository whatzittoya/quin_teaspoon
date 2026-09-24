<?php

use App\Controllers\AuthController;
use App\Controllers\HomeController;
use App\Controllers\SalesController;
use App\Controllers\SchedulerController;
use App\Controllers\SetupController;

$app->get('/setup', [SetupController::class, 'page']);
$app->post('/setup', [SetupController::class, 'save']);

$app->get('/', [AuthController::class, 'loginPage']);
$app->post('/login', [AuthController::class, 'login']);
$app->get('/logout', [AuthController::class, 'logout']);

$app->get('/home', [HomeController::class, 'index']);

$app->get('/api/sales', [SalesController::class, 'list']);
$app->get('/api/sales/report', [SalesController::class, 'report']);
$app->get('/api/sales/download', [SalesController::class, 'download']);
$app->post('/api/sales/upload', [SalesController::class, 'upload']);
$app->get('/report', [SalesController::class, 'reportPage']);

$app->get('/api/scheduler/status', [SchedulerController::class, 'status']);
$app->post('/api/scheduler/toggle', [SchedulerController::class, 'toggle']);
$app->post('/api/scheduler/time', [SchedulerController::class, 'setTime']);
