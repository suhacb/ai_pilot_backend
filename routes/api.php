<?php

use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ModelController;
use App\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;

Route::get('/sessions',                   [SessionController::class,  'index']);
Route::post('/sessions',                  [SessionController::class,  'store']);
Route::get('/sessions/{id}',              [SessionController::class,  'show']);
Route::delete('/sessions/{id}',           [SessionController::class,  'destroy']);
Route::post('/sessions/{id}/query',       [SessionController::class,  'query']);
Route::post('/sessions/{id}/cancel',      [SessionController::class,  'cancel']);
Route::get('/sessions/{id}/steps',        [SessionController::class,  'steps']);

Route::get('/documents',                  [DocumentController::class, 'index']);
Route::post('/documents/ingest',          [DocumentController::class, 'ingest']);

Route::get('/models',                     [ModelController::class,    'index']);
Route::delete('/models/{id}',             [ModelController::class,    'destroy']);
