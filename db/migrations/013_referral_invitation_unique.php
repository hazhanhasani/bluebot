<?php
return static function(PDO $pdo,Schema $schema): void {
 if(!$schema->tableExists('user')||!$schema->hasColumn('user','codeInvitation'))return;
 $rows=$pdo->query("SELECT id,codeInvitation FROM user ORDER BY id")->fetchAll(PDO::FETCH_ASSOC)?:[];$seen=[];
 $update=$pdo->prepare("UPDATE user SET codeInvitation=? WHERE id=?");
 foreach($rows as $row){$code=trim((string)($row['codeInvitation']??''));
  if($code===''||isset($seen[$code])){do{$code=bin2hex(random_bytes(8));}while(isset($seen[$code]));$update->execute([$code,(string)$row['id']]);}
  $seen[$code]=true;
 }
 $check=$pdo->query("SHOW INDEX FROM user WHERE Key_name='uniq_user_invitation_code'")->fetch(PDO::FETCH_ASSOC);
 if(!$check)$pdo->exec("ALTER TABLE user ADD UNIQUE INDEX uniq_user_invitation_code (codeInvitation)");
};