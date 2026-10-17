<?php
declare(strict_types=1);
require_once __DIR__.'/shopping_capture.php';
use GrocyAI\Controllers\Api\GrocyAiShoppingCaptureController;
if(!defined('GROCY_USER_ID'))define('GROCY_USER_ID',1);
if(!defined('GROCY_MODE'))define('GROCY_MODE','production');
require_once __DIR__.'/../src/GrocyAiCaptureProductService.php';
$controllerFile=__DIR__.'/../src/GrocyAiShoppingCaptureController.php';if(is_file($controllerFile))require_once $controllerFile;
shopCheck(class_exists(GrocyAiShoppingCaptureController::class),'Shopping capture API missing');
$db=shopFixture();$db->exec("CREATE TABLE user_permissions_resolved(id INTEGER PRIMARY KEY,user_id INTEGER,permission_name TEXT); INSERT INTO user_permissions_resolved(user_id,permission_name) VALUES(1,'STOCK_PURCHASE'),(1,'SHOPPINGLIST'),(1,'SHOPPINGLIST_ITEMS_ADD'),(1,'MASTER_DATA_EDIT')");
$reflection=new ReflectionClass(Grocy\Services\DatabaseService::class);foreach(['DbConnectionRaw'=>$db,'DbConnection'=>new LessQL\Database($db),'instance'=>$reflection->newInstance()] as $k=>$v)$reflection->getProperty($k)->setValue(null,$v);
$controller=(new ReflectionClass(GrocyAiShoppingCaptureController::class))->newInstanceWithoutConstructor();
function shopApi(string $action,array $body=[],array $args=[]):Psr\Http\Message\ResponseInterface {
 global $controller;$req=(new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST','/test')->withParsedBody($body);
 return $controller->$action($req,(new Slim\Psr7\Factory\ResponseFactory())->createResponse(),$args+['tripId'=>'1']);
}
shopCheck(shopApi('Read')->getStatusCode()===200,'read succeeds');
foreach([[],['list_id'=>'1'],['list_id'=>1,'actor'=>'2'],['list_id'=>true]] as $bad)shopCheck(shopApi('Select',$bad)->getStatusCode()===400,'reject malformed selection');
shopCheck(shopApi('Select',['list_id'=>1])->getStatusCode()===200,'selection');
shopCheck(shopApi('Read',[],['tripId'=>'2'])->getStatusCode()===404,'foreign trip denied');
foreach(['01','0','1e1',[],1] as $bad)shopCheck(shopApi('Read',[],['tripId'=>$bad])->getStatusCode()===400,'strict route IDs');
$row=(new GrocyAI\Services\GrocyAiShoppingCaptureService($db))->Read(1,'1')['items'][0];
$scan=shopApi('Scan',['operation_id'=>'api-scan','barcode'=>'012345678905','row_id'=>1,'expected_version'=>$row['version']]);shopCheck($scan->getStatusCode()===200,'targeted unknown scan');
$draft=$db->query('SELECT revision FROM grocy_ai_capture_research_drafts')->fetchColumn();
$curate=['mode'=>'link','revision'=>(int)$draft,'expected_version'=>$row['version'],'product_id'=>2];
$response=shopApi('Curate',$curate,['operationId'=>'api-scan']);shopCheck($response->getStatusCode()===200,'immediate UPC link');
$response=shopApi('Curate',$curate,['operationId'=>'api-scan']);shopCheck($response->getStatusCode()===200,'approval retry is idempotent');
shopCheck((int)$db->query("SELECT count(*) FROM product_barcodes WHERE barcode='012345678905'")->fetchColumn()===1,'one permanent barcode');
$settle=shopApi('Settle',['row_id'=>1,'expected_version'=>$row['version']],['operationId'=>'api-scan']);shopCheck($settle->getStatusCode()===200,'settlement after link');
shopCheck(shopApi('Curate',$curate+['actor'=>'2'],['operationId'=>'api-scan'])->getStatusCode()===400,'curation rejects extra keys');
shopCheck(shopApi('Scan',['operation_id'=>'api-bad','barcode'=>'96385074','row_id'=>'2','expected_version'=>'1'])->getStatusCode()===400,'strict body ID');
// New child creation reuses the native approval transaction and its idempotent audit.
$db->exec("ALTER TABLE products ADD location_id INTEGER; ALTER TABLE products ADD product_group_id INTEGER; CREATE TABLE locations(id INTEGER PRIMARY KEY,name TEXT,active INTEGER DEFAULT 1); INSERT INTO locations VALUES(1,'Pantry',1); CREATE TABLE product_groups(id INTEGER PRIMARY KEY,name TEXT,active INTEGER DEFAULT 1); CREATE TABLE quantity_unit_conversions(id INTEGER PRIMARY KEY,product_id INTEGER,from_qu_id INTEGER,to_qu_id INTEGER,factor REAL)");
$row=(new GrocyAI\Services\GrocyAiShoppingCaptureService($db))->Read(1,'1')['items'][0];
shopCheck(shopApi('Scan',['operation_id'=>'api-create','barcode'=>'036000291452','row_id'=>1,'expected_version'=>$row['version']])->getStatusCode()===200,'unknown child scan');
$revision=(int)$db->query("SELECT revision FROM grocy_ai_capture_research_drafts WHERE scanned_barcode='036000291452'")->fetchColumn();
$create=['mode'=>'create','revision'=>$revision,'expected_version'=>$row['version'],'fields'=>['name'=>'New branded milk','location_id'=>1,'qu_id_purchase'=>1,'qu_id_stock'=>1,'parent_mode'=>'existing','parent_product_id'=>1]];
$bad=$create;$bad['fields']['parent_product_id']=3;
shopCheck(shopApi('Curate',$bad,['operationId'=>'api-create'])->getStatusCode()===400,'cannot create under an unrelated parent');
$bad=$create;$bad['fields']['qu_id_stock']=2;
shopCheck(shopApi('Curate',$bad,['operationId'=>'api-create'])->getStatusCode()===400,'incompatible units rejected');
shopCheck(shopApi('Curate',$create,['operationId'=>'api-create'])->getStatusCode()===200,'new branded child approved');
shopCheck(shopApi('Curate',$create,['operationId'=>'api-create'])->getStatusCode()===200,'new product replay');
shopCheck((int)$db->query("SELECT count(*) FROM products WHERE name='New branded milk' AND parent_product_id=1")->fetchColumn()===1,'one branded child');
// Changing the native row between scan and confirmation cannot approve the wrong identity.
shopCheck(shopApi('Scan',['operation_id'=>'api-stale','barcode'=>'9780201379624','row_id'=>1,'expected_version'=>$row['version']])->getStatusCode()===200,'stale curation scan');
$revision=(int)$db->query("SELECT revision FROM grocy_ai_capture_research_drafts WHERE scanned_barcode='9780201379624'")->fetchColumn();
$db->exec("UPDATE shopping_list SET product_id=3 WHERE id=1");
shopCheck(shopApi('Curate',['mode'=>'link','revision'=>$revision,'expected_version'=>$row['version'],'product_id'=>2],['operationId'=>'api-stale'])->getStatusCode()===409,'curation guards concurrent list edits inside approval lock');
shopCheck((int)$db->query("SELECT count(*) FROM product_barcodes WHERE barcode='9780201379624'")->fetchColumn()===0,'stale approval writes no barcode');
$db->exec("DELETE FROM user_permissions_resolved WHERE permission_name='MASTER_DATA_EDIT'");
try{shopApi('Curate',$curate,['operationId'=>'api-scan']);throw new RuntimeException('Curation permission missing');}catch(Grocy\Controllers\Users\PermissionMissingException $e){}
$db->exec("DELETE FROM user_permissions_resolved WHERE permission_name='SHOPPINGLIST_ITEMS_ADD'");
try{shopApi('Undo',[],['operationId'=>'api-scan']);throw new RuntimeException('List permission missing');}catch(Grocy\Controllers\Users\PermissionMissingException $e){}
shopCheck((int)$db->query('SELECT count(*) FROM stock_log')->fetchColumn()===0,'API no stock write');
echo "shopping_capture_api: all checks passed\n";
