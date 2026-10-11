<?php
// READ-ONLY query runner (SELECT/SHOW/DESCRIBE only; session set READ ONLY). usage: php dbq.php "SQL"
$env=[];foreach(file(__DIR__.'/../../api/.env',FILE_IGNORE_NEW_LINES) as $l){if(preg_match('/^(DB_\w+)=(.*)$/',$l,$m))$env[$m[1]]=trim($m[2],'"');}
$pdo=new PDO("mysql:host={$env['DB_HOST']};port={$env['DB_PORT']};dbname={$env['DB_DATABASE']};charset=utf8mb4",$env['DB_USERNAME'],$env['DB_PASSWORD']??'');
$pdo->exec("SET SESSION TRANSACTION READ ONLY");
$sql=$argv[1];
if(!preg_match('/^\s*(select|show|describe)\b/i',$sql)){fwrite(STDERR,"only select\n");exit(1);}
$rows=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
if(!$rows){echo "(0 rows)\n";exit;}
echo implode("\t",array_keys($rows[0]))."\n";foreach($rows as $r)echo implode("\t",array_map(fn($v)=>$v??'NULL',$r))."\n";
