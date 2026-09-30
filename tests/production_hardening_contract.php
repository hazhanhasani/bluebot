<?php
$root=dirname(__DIR__);
$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
$tables=$read('db/tables.php');$fn=$read('function.php');$manager=$read('src/Services/DigitalServiceManager.php');$api=$read('api/utils.php');$verify=$read('api/verify.php');$cache=$read('app/.htaccess');$migration=$read('db/migrations/013_referral_invitation_unique.php');
$checks=[
 [str_contains($tables,"'wallet_transactions'"),'wallet ledger table not registered'],
 [str_contains($tables,"'referral_commission_reversals'"),'referral reversal table not registered'],
 [str_contains($tables,"'referral_first_purchase_claims'"),'first-purchase claim table not registered'],
 [str_contains($tables,"'api_rate_limits'"),'API rate-limit table not registered'],
 [str_contains($migration,'uniq_user_invitation_code'),'invitation code unique migration missing'],
 [str_contains($migration,'random_bytes(8)'),'invitation code collision backfill missing'],
 [str_contains($fn,'bluebotWalletAdjust'),'central wallet ledger not loaded/used'],
 [str_contains($fn,'referral_first_purchase_claims'),'atomic first purchase claim missing'],
 [str_contains($fn,'reverseReferralCommission'),'commission reversal missing'],
 [str_contains($manager,"'digital_service_refund'"),'digital refunds are not ledgered'],
 [!str_contains($manager,'UPDATE user SET Balance = Balance + ?'),'digital service direct refund mutation remains'],
 [str_contains($api,'requireApiRateLimit'),'API rate limiting missing'],
 [str_contains($verify,"requireApiRateLimit('telegram-verify'"),'Telegram verify throttling missing'],
 [str_contains($cache,'max-age=31536000, immutable'),'hashed Mini App caching missing'],
 [is_file($root.'/scripts/restore-backup.php'),'guarded restore verifier missing'],
];
foreach($checks as [$ok,$message]){if(!$ok){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
echo "Production hardening contract OK.\n";
