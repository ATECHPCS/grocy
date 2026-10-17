<?php
namespace GrocyAI\Services;
use PDO;
final class GrocyAiShoppingCaptureMigration
{
 public static function Bootstrap(PDO $db):void
 {
  GrocyAiCaptureResearchMigration::Bootstrap($db);
  $db->beginTransaction();
  try {
   $db->exec('CREATE TABLE IF NOT EXISTS grocy_ai_shopping_selection(trip_id INTEGER PRIMARY KEY REFERENCES grocy_ai_capture_trips(id),list_id INTEGER)');
   $db->exec('CREATE TABLE IF NOT EXISTS grocy_ai_shopping_versions(row_id INTEGER PRIMARY KEY,version INTEGER NOT NULL DEFAULT 1)');
   $db->exec('INSERT OR IGNORE INTO grocy_ai_shopping_versions(row_id) SELECT id FROM shopping_list');
   foreach(['INSERT'=>'NEW','UPDATE'=>'NEW','DELETE'=>'OLD'] as $event=>$row) {
    $old=$event==='UPDATE'? 'INSERT INTO grocy_ai_shopping_versions(row_id) SELECT OLD.id WHERE OLD.id != NEW.id ON CONFLICT(row_id) DO UPDATE SET version=version+1; ':'';
    $db->exec("CREATE TRIGGER IF NOT EXISTS grocy_ai_shopping_version_".strtolower($event)." AFTER $event ON shopping_list BEGIN $old INSERT INTO grocy_ai_shopping_versions(row_id) VALUES($row.id) ON CONFLICT(row_id) DO UPDATE SET version=version+1; END");
   }
   $db->exec("CREATE TABLE IF NOT EXISTS grocy_ai_shopping_operations(trip_id INTEGER NOT NULL REFERENCES grocy_ai_capture_trips(id),operation_id TEXT NOT NULL,request_json TEXT NOT NULL,kind TEXT NOT NULL,state TEXT NOT NULL,line_id INTEGER REFERENCES grocy_ai_capture_lines(id) ON DELETE SET NULL,row_id INTEGER,list_id INTEGER,product_id INTEGER,amount REAL,row_shape TEXT,observed_version TEXT,write_version TEXT,changed_done INTEGER NOT NULL DEFAULT 0,needs_amount INTEGER NOT NULL DEFAULT 0,error_code TEXT,settlement_json TEXT,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(trip_id,operation_id))");
   $db->exec("CREATE TABLE IF NOT EXISTS grocy_ai_shopping_audit(id INTEGER PRIMARY KEY,trip_id INTEGER NOT NULL,operation_id TEXT,actor TEXT NOT NULL,action TEXT NOT NULL,result_json TEXT NOT NULL,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
   foreach(['UPDATE','DELETE'] as $event)$db->exec("CREATE TRIGGER IF NOT EXISTS grocy_ai_shopping_audit_no_".strtolower($event)." BEFORE $event ON grocy_ai_shopping_audit BEGIN SELECT RAISE(ABORT,'shopping audit is append-only'); END");
   $db->exec("CREATE TRIGGER IF NOT EXISTS grocy_ai_shopping_request_immutable BEFORE UPDATE OF request_json,operation_id,trip_id,kind ON grocy_ai_shopping_operations BEGIN SELECT RAISE(ABORT,'shopping operation identity is immutable'); END");
   $db->commit();
  }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 }
}
