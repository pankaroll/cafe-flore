<?php
declare(strict_types=1);
require dirname(__DIR__).'/private/src/App.php';
use CafeFlore\App;
use CafeFlore\ApiError;

$app=(new ReflectionClass(App::class))->newInstanceWithoutConstructor();
$app->config=['public_url'=>'https://cafe.example'];
$checks=0;
function check(bool $value,string $label):void{global $checks;if(!$value)throw new RuntimeException($label);$checks++;}
foreach([
    ['https://CAFE.example:443/tasks','https://cafe.example'],
    ['http://cafe.example:80','http://cafe.example'],
    ['https://cafe.example:8443','https://cafe.example:8443'],
] as [$url,$expected])check(App::origin($url)===$expected,'normalized origin '.$url);

foreach([
    ['GET',null,null,200],
    ['HEAD',null,null,200],
    ['POST','https://cafe.example','application/json',200],
    ['PATCH','https://cafe.example','application/json; charset=utf-8',200],
    ['DELETE','https://cafe.example','application/json',200],
    ['POST','https://evil.example','application/json',403],
    ['POST','https://cafe.example.evil.example','application/json',403],
    ['POST','https://cafe.example@evil.example','application/json',403],
    ['POST','https://www.cafe.example','application/json',403],
    ['POST','http://cafe.example','application/json',403],
    ['POST',null,'application/json',403],
    ['POST','null','application/json',403],
    ['POST','https://cafe.example','text/plain',415],
] as [$method,$origin,$type,$expected]){
    $_SERVER=['REQUEST_METHOD'=>$method,'HTTP_HOST'=>'cafe.example','HTTP_SEC_FETCH_SITE'=>'same-origin'];
    if($origin!==null)$_SERVER['HTTP_ORIGIN']=$origin;
    if($type!==null)$_SERVER['CONTENT_TYPE']=$type;
    try{$app->validateRequest();$status=200;}catch(ApiError $e){$status=$e->status;}
    check($status===$expected,'origin guard '.$method.' '.($origin??'missing').' '.($type??'missing'));
}
$app->config['public_url']='https://CAFE.example:443';
$_SERVER=['REQUEST_METHOD'=>'POST','HTTP_ORIGIN'=>'https://cafe.example','CONTENT_TYPE'=>'application/json'];
$app->validateRequest();check(true,'browser default-port serialization matches config');
echo json_encode(['request_origin_checks'=>$checks,'result'=>'passed'])."\n";
