foreach (DB::select("SELECT TABLE_NAME tn, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) c, NON_UNIQUE nu FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='laravel' AND TABLE_NAME IN ('wallets','caps','agent_info','user_roles') AND INDEX_NAME<>'PRIMARY' GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE ORDER BY TABLE_NAME") as $r)
  echo str_pad($r->tn,12).str_pad($r->c,22).($r->nu ? 'index' : 'UNIQUE').PHP_EOL;
echo '--- column types ---'.PHP_EOL;
foreach (DB::select("SELECT TABLE_NAME tn, COLUMN_NAME cn, COLUMN_TYPE ct FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='laravel' AND (COLUMN_NAME LIKE '%rate%' OR COLUMN_NAME LIKE '%amount%' OR COLUMN_NAME='balance') ORDER BY TABLE_NAME, COLUMN_NAME") as $r)
  echo str_pad($r->tn,14).str_pad($r->cn,28).$r->ct.PHP_EOL;
