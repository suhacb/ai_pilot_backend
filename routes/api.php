<?php

use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ModelController;
use App\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;

Route::post('/sessions',                  [SessionController::class,  'store']);
Route::post('/sessions/{id}/query',       [SessionController::class,  'query']);
Route::get('/sessions/{id}/steps',        [SessionController::class,  'steps']);

Route::get('/documents',                  [DocumentController::class, 'index']);
Route::post('/documents/ingest',          [DocumentController::class, 'ingest']);

Route::get('/models',                     [ModelController::class,    'index']);
