<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\CrudController;

Route::controller(CrudController::class)->group(function (): void {
    Route::match(['get', 'post'], '/select/{module}/{entity}', 'list')->name('list');
    Route::match(['get', 'post'], '/facets/{module}/{entity}', 'facets')->name('facets');
    Route::match(['get', 'post'], '/aggregate/{module}/{entity}', 'aggregate')->name('aggregate')->middleware('throttle:aggregate');
    Route::get('/freshness/{module}/{entity}', 'freshness')->name('freshness');
    Route::get('/detail/{module}/{entity}', 'detail')->name('detail');
    Route::get('/tree/{module}/{entity}', 'tree')->name('tree');
    Route::match(['get', 'post'], '/search/{module}/{entity?}', 'search')->name('search');
    Route::get('/history/{module}/{entity}', 'history')->name('history');
    Route::post('/revert/{module}/{entity}', 'revert')->name('revert');
    Route::post('/insert/{module}/{entity}', 'insert')->name('insert');
    Route::match(['patch', 'put'], '/update/{module}/{entity}', 'update')->name('replace');
    Route::match(['delete', 'post'], '/delete/{module}/{entity}', 'delete')->name('delete');
});
