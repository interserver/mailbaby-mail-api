<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

use Webman\Route;
Route::get('/ping', function($request) {
	return response('Server is up and running', 200);
});
Route::options('/ping', function($request) {
	return response('Server is up and running', 200);
});

Route::group('/mail', function() {
	Route::any('', [app\controller\Mail::class, 'index']);
    Route::any('/send', [app\controller\Mail::class, 'send']);
    Route::any('/advsend', [app\controller\Mail::class, 'advsend']);
    Route::any('/log', [app\controller\Mail::class, 'log']);
    Route::get('/rules', [app\controller\Mail\Rules::class, 'get']);
    Route::post('/rules', [app\controller\Mail\Rules::class, 'post']);
    Route::delete('/rules', [app\controller\Mail\Rules::class, 'delete']);
    Route::get('/stats', [app\controller\Mail\Stats::class, 'get']);
    Route::get('/blocks', [app\controller\Mail\Blocks::class, 'get']);
    Route::post('/blocks', [app\controller\Mail\Blocks::class, 'post']);
    Route::delete('/blocks', [app\controller\Mail\Blocks::class, 'delete']);
    Route::get('/alerts', [app\controller\Mail\Alerts::class, 'get']);
    Route::post('/alerts', [app\controller\Mail\Alerts::class, 'post']);
    Route::delete('/alerts', [app\controller\Mail\Alerts::class, 'delete']);

})->middleware([
	app\middleware\AuthCheck::class
]);
// Set cross domain for all OPTIONS requests
Route::options('[{path:.+}]', function (){
    return response('');
});
Route::disableDefaultRoute();