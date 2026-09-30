<?php
function bluebotWalletAdjust(PDO $pdo,string $userId,int $signedAmount,string $sourceType,string $sourceId,?string $eventKey=null,array $metadata=[]): array {
 $userId=trim($userId);$sourceType=trim($sourceType);$sourceId=trim($sourceId);
 if($userId===''||$signedAmount===0||$sourceType===''||$sourceId==='')throw new InvalidArgumentException('Invalid wallet adjustment.');
 $eventKey=$eventKey?:hash('sha256',$sourceType.':'.$sourceId.':'.$userId.':'.$signedAmount);
 $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
 try{
  $q=$pdo->prepare("SELECT id FROM wallet_transactions WHERE event_key=? LIMIT 1");$q->execute([$eventKey]);
  if($q->fetchColumn()){if($owns)$pdo->commit();return ['applied'=>false,'reason'=>'duplicate'];}
  $q=$pdo->prepare("SELECT Balance FROM user WHERE id=? FOR UPDATE");$q->execute([$userId]);$before=$q->fetchColumn();
  if($before===false)throw new RuntimeException('Wallet user not found.');
  $after=(int)$before+$signedAmount;if($after<0)throw new DomainException('INSUFFICIENT_WALLET_BALANCE');
  $u=$pdo->prepare("UPDATE user SET Balance=? WHERE id=?");$u->execute([$after,$userId]);
  $i=$pdo->prepare("INSERT INTO wallet_transactions(event_key,user_id,direction,amount,balance_after,source_type,source_id,metadata) VALUES(?,?,?,?,?,?,?,?)");
  $json=$metadata?json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null;
  $i->execute([$eventKey,$userId,$signedAmount>0?'credit':'debit',abs($signedAmount),$after,$sourceType,$sourceId,$json]);
  if($owns)$pdo->commit();if(function_exists('clearSelectCache'))clearSelectCache('user');
  return ['applied'=>true,'balance_before'=>(int)$before,'balance_after'=>$after,'event_key'=>$eventKey];
 }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}
