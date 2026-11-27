<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
ini_set('display_errors','0');
require dirname(__DIR__,2).'/private/src/App.php';
use CafeFlore\App;
use CafeFlore\ApiError;
try{
    $configFile=dirname(__DIR__,2).'/private/config.php';
    if(!is_file($configFile))throw new ApiError(503,'Aplikacja nie jest zainstalowana. Właściciel powinien otworzyć setup.php.');
    $app=new App(require $configFile);$app->validateRequest();$method=$_SERVER['REQUEST_METHOD']??'GET';
    $body=in_array($method,['GET','HEAD'],true)?[]:App::body();
    $route=(string)($_GET['route']??'');
    $result=$app->dispatch($route,$method,$body);
    if($method==='POST'&&in_array($route,['tasks','members','routines'],true)&&($result['created']??true)!==false)http_response_code(201);
    echo App::json($result);
}catch(ApiError $e){http_response_code($e->status);echo App::json(['error'=>$e->getMessage()]);}
catch(PDOException $e){
    if((int)($e->errorInfo[1]??0)===1062){http_response_code(409);echo App::json(['error'=>'Ten kod lub rekord jest już zajęty. Odśwież dane i spróbuj ponownie.']);}
    else{error_log('Cafe Flore DB: '.(string)$e->getCode());http_response_code(500);echo App::json(['error'=>'Nie udało się zapisać zmian. Spróbuj ponownie.']);}
}catch(Throwable $e){error_log('Cafe Flore: '.get_class($e));http_response_code(500);echo App::json(['error'=>'Nie udało się wykonać operacji. Sprawdź konfigurację serwera.']);}
