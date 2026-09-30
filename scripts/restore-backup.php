<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../config.php';
$file=$argv[1]??'';$apply=in_array('--apply',$argv,true);$confirmed=in_array('--confirm=RESTORE',$argv,true);
if($file===''||!is_file($file)){fwrite(STDERR,"Usage: php scripts/restore-backup.php backup.sql [--apply --confirm=RESTORE]\n");exit(2);}
$sql=file_get_contents($file);if(!is_string($sql)||strlen($sql)<100||!str_contains($sql,'SET FOREIGN_KEY_CHECKS=0')||!str_contains($sql,'SET FOREIGN_KEY_CHECKS=1')){fwrite(STDERR,"Backup integrity markers are missing.\n");exit(3);}
echo "SHA256: ".hash_file('sha256',$file)."\nBytes: ".filesize($file)."\n";
if(!$apply){echo "Verification OK. No database changes made.\n";exit(0);}
if(!$confirmed){fwrite(STDERR,"Restore requires --confirm=RESTORE.\n");exit(4);}
$statements=preg_split('/;\s*(?:\r?\n|$)/',$sql)?:[];$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach($statements as $statement){$statement=trim($statement);if($statement!=='')$pdo->exec($statement);}
echo "Restore completed. Run diagnostics immediately.\n";
