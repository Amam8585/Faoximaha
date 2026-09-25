<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Tehran');
const START_GIF='https://vste.s6.viptelbot.top/artin/new/start.gif';

foreach(['ProvisioningException.php','Contracts.php','CommandRunner.php','AtomicFilesystem.php','ConfigEditor.php','PdoDatabaseAdmin.php','TelegramClient.php','Provisioner.php'] as $provisioningFile)require_once __DIR__.'/provisioning/'.$provisioningFile;

function esc(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}

function cut(string $s,int $n):string{return function_exists('mb_substr')?mb_substr($s,0,$n,'UTF-8'):substr($s,0,$n);}

function starts(string $s,string $p):bool{return strncmp($s,$p,strlen($p))===0;}

function send(int $c,string $t,array $r=[]):array{$d=['chat_id'=>$c,'text'=>bold($t),'parse_mode'=>'HTML','disable_web_page_preview'=>'true'];if($r)$d['reply_markup']=ik($r);$reply=tg('sendMessage',$d);if(!($reply['ok']??false))throw new RuntimeException('Main message delivery failed');return$reply;}

function del(int $c,int $m):void{if($m>0)tg('deleteMessage',['chat_id'=>$c,'message_id'=>$m]);}

function ans(string $id,string $t='',bool $alert=false):void{if($id===''||isset($GLOBALS['answered_callbacks'][$id]))return;$GLOBALS['answered_callbacks'][$id]=true;tg('answerCallbackQuery',['callback_query_id'=>$id,'text'=>cut($t,180),'show_alert'=>$alert?'true':'false']);}

function dbread():array{
 $h=fopen(DB.'.lock','c+');if(!$h||!flock($h,LOCK_SH))throw new RuntimeException('Database lock failed');
 try{return dbload();}finally{flock($h,LOCK_UN);fclose($h);}
}

function dbmut(callable $fn){
 $h=fopen(DB.'.lock','c+');if(!$h||!flock($h,LOCK_EX))throw new RuntimeException('Database lock failed');$tmp='';
 try{$d=dbload();$r=$fn($d);$json=json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
 $tmp=tempnam(dirname(DB),'.bot-tmp-');if($tmp===false)throw new RuntimeException('Database temp failed');
 $f=fopen($tmp,'wb');if(!$f)throw new RuntimeException('Database write failed');
 try{$offset=0;while($offset<strlen($json)){$n=fwrite($f,substr($json,$offset));if(!$n)throw new RuntimeException('Database disk full');$offset+=$n;}fflush($f);if(function_exists('fsync'))fsync($f);}finally{fclose($f);}
 chmod($tmp,0600);if(!rename($tmp,DB))throw new RuntimeException('Database commit failed');return$r;
 }finally{if($tmp&&is_file($tmp))unlink($tmp);flock($h,LOCK_UN);fclose($h);}
}

function state(int $u):array{$d=dbread();return is_array($d['states'][(string)$u]??null)?$d['states'][(string)$u]:[];}

function setstate(int $u,?array $s):void{dbmut(function(&$d)use($u,$s){if($s===null)unset($d['states'][(string)$u]);else$d['states'][(string)$u]=$s;});}

function usertouch(array $f):bool{$u=(int)($f['id']??0);if(!$u)return false;return (bool)dbmut(function(&$d)use($u,$f){$k=(string)$u;$first=!isset($d['users'][$k]);$old=$d['users'][$k]??[];$d['users'][$k]=array_merge($old,['id'=>$u,'name'=>(string)($f['first_name']??''),'username'=>(string)($f['username']??''),'last_seen'=>time()],$first?['first_seen'=>time(),'banned'=>false]:[]);return $first;});}

function isAdmin(int $u):bool{if($u===ADMIN)return true;$d=dbload();return in_array($u,array_map('intval',(array)($d['settings']['admins']??[])),true);}
function adminIds():array{$d=dbread();$ids=[ADMIN];foreach((array)($d['settings']['admins']??[]) as $id){$id=(int)$id;if($id>0&&!in_array($id,$ids,true))$ids[]=$id;}return$ids;}
function banned(int $u):bool{if(isAdmin($u))return false;$d=dbread();return (bool)($d['users'][(string)$u]['banned']??false);}

function firstStartNotice(int $u):bool{return(bool)dbmut(function(&$d)use($u){$k=(string)$u;if(isAdmin($u)||($d['users'][$k]['start_notified']??false))return false;$d['users'][$k]['start_notified']=true;$d['users'][$k]['started_at']=time();return true;});}

function joinOk(int $u):bool{if(isAdmin($u))return true;$d=dbread();$s=$d['settings']??[];if(!($s['force_join']??false))return true;$ch=trim((string)($s['channel']??''));if($ch==='')return true;$r=tg('getChatMember',['chat_id'=>$ch,'user_id'=>$u]);if(!($r['ok']??false))return false;$st=(string)($r['result']['status']??'left');return in_array($st,['member','administrator','creator'],true)||($st==='restricted'&&($r['result']['is_member']??false));}

function joinGate(int $c,int $u):void{$d=dbread();$ch=trim((string)($d['settings']['channel']??''));$url='';if(starts($ch,'@'))$url='https://t.me/'.substr($ch,1);$kb=[];if($url!=='')$kb[]=[['text'=>'📢 عضویت در کانال','url'=>$url]];$kb[]=[['text'=>'✅ عضو شدم','callback_data'=>'check_join']];page($u,(int)(state($u)['mid']??0),"📢 | همراه ما باشید\n\nبرای ورود به فروشگاه، ابتدا عضو کانال شوید و سپس «عضو شدم» را بزنید.",$kb);}

function screen(int $c,int $u,int $m,bool $fromPhoto,string $t,array $kb,array $st=[]):void{if($fromPhoto)textScreen($c,$u,$m,$t,$kb,$st);else editScreen($c,$u,$m,$t,$kb,$st);}

function refreshAfterInput(int $c,int $u,int $userMid,int $botMid,string $t,array $kb,array $st=[]):void{editScreen($c,$u,$botMid,$t,$kb,$st);}

function digits(string $s):string{return str_replace([',','٬',' '],'',strtr($s,array_combine(preg_split('//u','۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩',-1,PREG_SPLIT_NO_EMPTY),str_split('01234567890123456789'))));}

function amount(string $s,bool $zero=false):?int{$s=digits(trim($s));if(!ctype_digit($s)||strlen($s)>10)return null;$n=(int)$s;return$n>=($zero?0:1)&&$n<=1000000000?$n:null;}

function money(int $n):string{return number_format($n,0,'.',',');}

function book(array &$d,int $uid,int $delta,string $type,string $ref,int $actor=0):int{
 if(!isset($d['users'][(string)$uid]))throw new RuntimeException('کاربر ثبت نشده است');
 foreach($d['ledger'] as $r)if($r['ref']===$ref)return(int)$r['balance'];
 $old=(int)($d['users'][(string)$uid]['wallet']??0);$new=$old+$delta;if($new<0||$new>PHP_INT_MAX-1000000000)throw new RuntimeException('موجودی کافی نیست یا مبلغ نامعتبر است');
 $d['users'][(string)$uid]['wallet']=$new;$id=++$d['seq']['ledger'];$d['ledger'][(string)$id]=['id'=>$id,'uid'=>$uid,'delta'=>$delta,'balance'=>$new,'type'=>$type,'ref'=>$ref,'actor'=>$actor,'created'=>time()];return$new;
}

function enqueue(array &$d,string $kind,array $payload,string $unique):int{
 foreach($d['jobs'] as $j)if(($j['unique']??'')===$unique)return(int)$j['id'];
 $id=++$d['seq']['job'];$d['jobs'][(string)$id]=['id'=>$id,'kind'=>$kind,'payload'=>$payload,'unique'=>$unique,'status'=>'queued','attempts'=>0,'next'=>0,'created'=>time()];return$id;
}

function notifyLater(array &$d,int $uid,string $text,string $ref,array $kb=[]):void{enqueue($d,'telegram',['method'=>'sendMessage','data'=>['chat_id'=>$uid,'text'=>bold($text),'parse_mode'=>'HTML','reply_markup'=>ik($kb)]],$ref);}

function checkDiscount(array $d,string $code,int $uid,string $plan):?array{
 $code=strtoupper(trim($code));$x=$d['discounts'][$code]??null;if(!is_array($x)||!($x['active']??false))return null;
 if((int)($x['percent']??0)<1||(int)$x['percent']>100)return null;
 if((int)($x['expires_at']??0)>0&&(int)$x['expires_at']<=time())return null;
 if((int)($x['max_uses']??0)>0&&(int)($x['uses']??0)>=(int)$x['max_uses'])return null;
 if((int)($x['per_user_limit']??0)>0&&(int)($x['user_uses'][(string)$uid]??0)>=(int)$x['per_user_limit'])return null;
 if(!empty($x['plan_codes'])&&!in_array($plan,$x['plan_codes'],true))return null;
 return$x+['code'=>$code];
}

function pagedRows(array $all,int $page):array{$page=max(0,min($page,max(0,(int)ceil(count($all)/8)-1)));return[array_slice($all,$page*8,8,true),$page];}

function supportMenu(int $c,int $u,int $m,bool $ph=false):void{screen($c,$u,$m,$ph,"💬 | کنار شما هستیم\n\nسؤال یا مشکلی دارید؟ برای ما تیکت بفرستید.\n<i>پاسخ‌ها و ادامه گفتگو در بخش «تیکت‌های من» در دسترس است.</i>",[[b('✍️ | تیکت جدید','sup_new')],[b('📋 | تیکت‌های من','sup_my')],back()],['step'=>'support_menu']);}
function newTicket(int $u,string $name,string $text):int{return (int)dbmut(function(&$d)use($u,$name,$text){$id=++$d['seq']['ticket'];$d['tickets'][(string)$id]=['id'=>$id,'uid'=>$u,'name'=>$name,'subject'=>cut($text,45),'status'=>'open','created'=>time(),'updated'=>time(),'messages'=>[['from'=>'user','text'=>$text,'time'=>time()]]];return$id;});}

function ticketAdd(int $id,string $from,string $text):bool{return(bool)dbmut(function(&$d)use($id,$from,$text){$k=(string)$id;if(!isset($d['tickets'][$k])||($d['tickets'][$k]['status']??'')!=='open'||(!isAdmin((int)($GLOBALS['actor']??0))&&(int)$d['tickets'][$k]['uid']!==($GLOBALS['actor']??0)))return false;$d['tickets'][$k]['messages'][]=['from'=>$from,'text'=>$text,'time'=>time()];$d['tickets'][$k]['updated']=time();return true;});}

function ticketClose(int $id):void{dbmut(function(&$d)use($id){$k=(string)$id;if(isset($d['tickets'][$k])&&(isAdmin((int)($GLOBALS['actor']??0))||(int)$d['tickets'][$k]['uid']===($GLOBALS['actor']??0))){$d['tickets'][$k]['status']='closed';$d['tickets'][$k]['updated']=time();}});}

function myTickets(int $c,int $u,int $m):void{$d=dbread();$a=[];foreach($d['tickets']??[] as $id=>$t)if((int)($t['uid']??0)===$u)$a[$id]=$t;uasort($a,fn($x,$y)=>(int)$y['updated']<=>(int)$x['updated']);$r=[];foreach($a as $id=>$t)$r[]=[['text'=>(($t['status']??'open')==='open'?'🟢 ':'🔴 ').'#'.$id.' '.cut((string)($t['subject']??'تیکت'),24),'callback_data'=>'ticket|'.$id]];if(!$r)$r[]=[['text'=>'تیکتی ندارید','callback_data'=>'noop']];$r[]=[['text'=>'➕ تیکت جدید','callback_data'=>'sup_new'],['text'=>'🔙 بازگشت','callback_data'=>'support']];editScreen($c,$u,$m,'📋 تیکت‌های من',$r,['step'=>'support_menu']);}

function ticketView(int $c,int $u,int $m,int $id,bool $admin=false):void{$d=dbread();$t=$d['tickets'][(string)$id]??null;if(!is_array($t)||(!$admin&&(int)($t['uid']??0)!==$u)){editScreen($c,$u,$m,'❌ تیکت پیدا نشد.',[[['text'=>'🔙 بازگشت','callback_data'=>$admin?'adm_tickets':'sup_my']]],['step'=>$admin?'admin':'support_menu']);return;}$txt='🎫 تیکت #'.$id."\nموضوع: ".esc((string)($t['subject']??'-'))."\nوضعیت: ".(($t['status']??'open')==='open'?'باز':'بسته')."\n\n";foreach(array_slice($t['messages']??[],-6) as $z)$txt.=(($z['from']??'user')==='admin'?'👨‍💻 پشتیبانی: ':'👤 کاربر: ').esc(cut((string)($z['text']??''),350))."\n\n";$r=[];if(($t['status']??'open')==='open')$r[]=[['text'=>'✍️ پاسخ','callback_data'=>($admin?'adm_reply|':'ticket_reply|').$id],['text'=>'🔒 بستن','callback_data'=>($admin?'adm_close|':'ticket_close|').$id]];$r[]=[['text'=>'🔙 بازگشت','callback_data'=>$admin?'adm_tickets':'sup_my']];editScreen($c,$u,$m,trim($txt),$r,['step'=>$admin?'admin':'support_menu']);}

function adminTickets(int $c,int $u,int $m):void{$d=dbread();$a=$d['tickets']??[];uasort($a,fn($x,$y)=>(int)($y['updated']??0)<=>(int)($x['updated']??0));$r=[];foreach($a as $id=>$t)$r[]=[['text'=>(($t['status']??'open')==='open'?'🟢 ':'🔴 ').'#'.$id.' '.cut((string)($t['name']??'کاربر'),18),'callback_data'=>'adm_ticket|'.$id]];if(!$r)$r[]=[['text'=>'تیکتی ثبت نشده','callback_data'=>'noop']];$r[]=[['text'=>'🔙 بازگشت','callback_data'=>'admin']];editScreen($c,$u,$m,'🎫 تیکت‌های پشتیبانی',$r,['step'=>'admin']);}

function sealPayload(array $a):string{$iv=random_bytes(12);$tag='';$cipher=openssl_encrypt(json_encode($a,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'aes-256-gcm',encryptionKey(),OPENSSL_RAW_DATA,$iv,$tag,'MuteShopKYC');if($cipher===false)throw new RuntimeException('Identity encryption failed');return base64_encode($iv.$tag.$cipher);}

function openPayload(array $r):array{if(empty($r['sealed']))return[];$raw=base64_decode($r['sealed'],true);if($raw===false||strlen($raw)<28)throw new RuntimeException('Identity data invalid');$plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',encryptionKey(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'MuteShopKYC');if($plain===false)throw new RuntimeException('Identity decrypt failed');return json_decode($plain,true,512,JSON_THROW_ON_ERROR);}

function acceptUpdate(array $up):void{
 $actor=(int)($up['callback_query']['from']['id']??$up['message']['from']['id']??0);$update=(int)($up['update_id']??-1);if($actor<=0||$update<0)return;
 $lock=fopen(DB.'.user.'.($actor%128).'.lock','c+');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('User lock failed');
 try{$go=dbmut(function(&$d)use($update){$k=(string)$update;if(isset($d['updates'][$k])&&$d['updates'][$k]['status']==='done')return false;$d['updates'][$k]=['status'=>'processing','at'=>time()];foreach($d['updates'] as $id=>$r)if((int)$r['at']<time()-7*86400)unset($d['updates'][$id]);return true;});if(!$go)return;
 $GLOBALS['actor']=$actor;$GLOBALS['update_id']=$update;dispatchUpdate($up);
 dbmut(function(&$d)use($update){$d['updates'][(string)$update]=['status'=>'done','at'=>time()];});
 }finally{flock($lock,LOCK_UN);fclose($lock);}
}



function api(string $token,string $method,array $data=[]):array{
 if(isset($GLOBALS['API_MOCK']))return($GLOBALS['API_MOCK'])($token,$method,$data);
 if(!preg_match('/^\d{5,16}:[A-Za-z0-9_-]{20,100}$/D',$token))return['ok'=>false,'description'=>'Invalid token'];
 $ch=curl_init('https://api.telegram.org/bot'.$token.'/'.$method);
 curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$data,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
 $raw=curl_exec($ch);$errno=curl_errno($ch);curl_close($ch);$r=is_string($raw)?json_decode($raw,true):null;
 return is_array($r)?$r:['ok'=>false,'transport_errno'=>$errno,'description'=>'Telegram connection failed'];
}
function tg(string $m,array $d=[]):array{if(isset($GLOBALS['TG_MOCK']))return($GLOBALS['TG_MOCK'])($m,$d);return api(TOKEN,$m,$d);}
function ik(array $r):string{
 foreach($r as &$row)foreach($row as &$b){$t=trim((string)$b['text']);if(!str_contains($t,' | ')){
 if(preg_match('/^(\X)\s+(.+)$/us',$t,$x)&&preg_match('/[\p{So}\x{2190}-\x{27FF}]/u',$x[1]))$t=$x[1].' | '.$x[2];else$t='🔹 | '.$t;
 }$b['text']=$t;unset($b['style']);}unset($b,$row);return json_encode(['inline_keyboard'=>$r],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}
function b(string $text,string $action):array{return['text'=>$text,'callback_data'=>$action];}
function back(string $to='home'):array{return[b('🔙 | بازگشت',$to)];}
function edit(int $c,int $m,string $t,array $r=[]):bool{
 if($m<=0)return false;$x=tg('editMessageText',['chat_id'=>$c,'message_id'=>$m,'text'=>bold($t),'parse_mode'=>'HTML','reply_markup'=>ik($r)]);
 return(bool)($x['ok']??false)||str_contains((string)($x['description']??''),'message is not modified');
}
function retireMenu(int $u,int $m):void{if($m<=0)return;dbmut(function(&$d)use($u,$m){$r=$d['users'][(string)$u]['retired_menus']??[];$r[(string)$m]=time();$d['users'][(string)$u]['retired_menus']=array_slice($r,-100,null,true);});}
function textScreen(int $c,int $u,int $old,string $t,array $kb,array $st=[]):int{
 $live=(int)(state($u)['mid']??0);if($live>0)$old=$live;$r=send($c,$t,$kb);$mid=(int)($r['result']['message_id']??0);if($mid){del($c,$old);retireMenu($u,$old);$st['mid']=$mid;$st['photo']=false;setstate($u,$st);}return$mid;
}
function editScreen(int $c,int $u,int $m,string $t,array $kb,array $st=[]):void{
 if(count($kb)>12&&!isset($st['_pages'])){$nonce=bin2hex(random_bytes(4));$st['_pages']=['nonce'=>$nonce,'text'=>$t,'rows'=>$kb];$last=array_pop($kb);$kb=array_slice($kb,0,8);$kb[]=[b('➡️ | بعدی','ui_page|'.$nonce.'|1')];$kb[]=$last;}
 if(($GLOBALS['incoming_text']??false)||($GLOBALS['photo_callback']??false)||!edit($c,$m,$t,$kb)){textScreen($c,$u,$m,$t,$kb,$st);$GLOBALS['photo_callback']=false;return;}
 $st['mid']=$m;$st['photo']=false;setstate($u,$st);
}
function page(int $u,int $m,string $t,array $kb=[],array $st=[]):void{if(!$kb)$kb=[back()];editScreen($u,$u,$m,$t,$kb,$st);}
function publicUrl():string{$d=dbread();return trim((string)($d['settings']['public_url']??''));}
function validUrl(string $v):bool{return filter_var($v,FILTER_VALIDATE_URL)!==false&&parse_url($v,PHP_URL_SCHEME)==='https'&&!parse_url($v,PHP_URL_QUERY)&&!parse_url($v,PHP_URL_FRAGMENT)&&!parse_url($v,PHP_URL_USER);}
function planAvailable(array $d,string $id):bool{$p=$d['bot_plans'][$id]??[];return(bool)($p&&($p['active']??false)&&($d['bot_categories'][(string)$p['category']]['active']??false)&&($d['templates'][$p['template']]['active']??false)&&(int)$p['price']>0&&(int)$p['days']>0&&(int)$p['stock']!==0);}
function catalog(int $u,int $m,string $cat=''):void{
 $d=dbread();$kb=[];
 if($cat===''){foreach($d['bot_categories'] as $id=>$r)if($r['active'])$kb[]=[b('📁 | '.$r['name'],'category|'.$id)];$t='💎 | مرحله ۱ از ۸ — انتخاب دسته ربات';}
 else{foreach($d['bot_plans'] as $id=>$p)if((string)$p['category']===$cat&&planAvailable($d,(string)$id))$kb[]=[b('🤖 | '.$p['name'].' — '.money((int)$p['price']).' تومان','plan|'.$id)];$t='💎 | مرحله ۱ از ۸ — انتخاب پلن';}
 if(!$kb)$t.="\n\nدر حال حاضر پلنی برای خرید فعال نیست.";$kb[]=back($cat===''?'home':'buy');page($u,$m,$t,$kb);
}
function planView(int $u,int $m,string $id):void{
 $d=dbread();$p=$d['bot_plans'][$id]??null;if(!$p||!planAvailable($d,$id)){catalog($u,$m);return;}
 $text='🦊 | '.esc($p['name'])."\n\n🧩 قالب: <u>foxima</u>\n📅 اعتبار اشتراک: ".$p['days']." روز\n💰 هزینه: <u>".money((int)$p['price'])." تومان</u>\n📦 ظرفیت باقی‌مانده: ".((int)$p['stock']===-1?'نامحدود':$p['stock'])."\n\n📝 امکانات پلن\n".esc($p['description']?:'پاسخ به /start با متن اختصاصی و مدیریت اشتراک از همین ربات.')."\n\n<i>پس از انتخاب، توکن ربات اختصاصی شما دریافت می‌شود.</i>";
 page($u,$m,$text,[[b('💎 | انتخاب این اشتراک','choose|'.$id)],back('category|'.$p['category'])]);
}
function quotePurchase(int $u,int $m,array $draft):void{
 $d=dbread();$p=$d['bot_plans'][(string)$draft['plan']]??[];if(!$p||!planAvailable($d,(string)$draft['plan'])){catalog($u,$m);return;}
 $code=(string)($d['users'][(string)$u]['coupon']??'');$disc=$code!==''?checkDiscount($d,$code,$u,(string)$draft['plan']):null;
 $price=(int)$p['price'];$pay=$price-(int)floor($price*(int)($disc['percent']??0)/100);
 $draft+=['step'=>'checkout','nonce'=>bin2hex(random_bytes(10)),'at'=>time()];$draft['price']=$price;$draft['pay']=$pay;$draft['code']=$disc?$code:'';$draft['days']=(int)$p['days'];$draft['template']=$p['template'];
 $iid=dbmut(function(&$d)use($u,&$draft,$p){foreach($d['invoices'] as &$old)if((int)($old['uid']??0)===$u&&($old['status']??'')==='unpaid'){$old['status']='cancelled';unset($old['draft']);}unset($old);$iid=++$d['seq']['invoice'];$draft['invoice_id']=$iid;$d['invoices'][(string)$iid]=['id'=>$iid,'kind'=>'bot','uid'=>$u,'amount'=>$draft['pay'],'status'=>'unpaid','created'=>time(),'expires'=>time()+86400,'quote_nonce'=>$draft['nonce'],'plan_name'=>$p['name'],'username'=>$draft['username'],'draft'=>$draft];return$iid;});
 page($u,$m,"🧾 | مرحله ۵ از ۸ — فاکتور #".$iid."\n\nپلن: ".esc($p['name'])."\nربات: @".esc($draft['username'])."\nمدت: ".$p['days']." روز\nقیمت پایه: ".($disc?'<s>'.money($price).'</s>':money($price))." تومان\nتخفیف: ".(int)($disc['percent']??0)."٪\nقابل پرداخت: ".money($pay)." تومان\nپس از پرداخت و تأیید مدیر، ربات راه‌اندازی می‌شود.",[[b('💳 | پرداخت از کیف پول','checkout|'.$draft['nonce'])],[b('💳 | شارژ کیف پول','wallet'),b('🎟 | افزودن تخفیف','invoice_coupon|'.$iid)],[b('❌ | لغو فاکتور','invoice_cancel|'.$iid)],back('buy')],$draft);
}
function checkout(int $u,string $nonce):array{
 return dbmut(function(&$d)use($u,$nonce){$s=$d['states'][(string)$u]??[];foreach($d['invoices'] as $row)if((int)($row['uid']??0)===$u&&($row['quote_nonce']??'')===$nonce&&($row['status']??'')==='unpaid'){$s=$row['draft'];break;}$fail=fn($t)=>['ok'=>false,'reason'=>$t];
 if(($s['step']??'')!=='checkout'||!hash_equals((string)($s['nonce']??''),$nonce)||time()-(int)($s['at']??0)>86400)return$fail('درخواست منقضی شده یا قبلاً پرداخت شده است.');
 if(!purchaseAllowed($d,$u))return$fail('خرید موقتاً در دسترس نیست؛ با پشتیبانی تماس بگیرید.');
 $id=(string)$s['plan'];$p=$d['bot_plans'][$id]??[];if(!planAvailable($d,$id)||(int)$p['price']!==(int)$s['price']||(int)$p['days']!==(int)$s['days']||$p['template']!==$s['template'])return$fail('پلن تغییر کرده است؛ دوباره انتخاب کنید.');
 $disc=$s['code']!==''?checkDiscount($d,$s['code'],$u,$id):null;if($s['code']!==''&&!$disc)return$fail('کد تخفیف دیگر معتبر نیست.');
 $price=(int)$p['price']-(int)floor((int)$p['price']*(int)($disc['percent']??0)/100);if($price!==(int)$s['pay'])return$fail('مبلغ تغییر کرده است.');
 foreach($d['bots'] as $bot)if((int)$bot['telegram_id']===(int)$s['telegram_id']&&$bot['status']!=='refunded')return$fail('این ربات قبلاً ثبت شده است.');
 if((int)($d['users'][(string)$u]['wallet']??0)<$price)return$fail('موجودی کیف پول کافی نیست.');
 $iid=(int)($s['invoice_id']??0);if(!$iid||($d['invoices'][(string)$iid]['status']??'')!=='unpaid')return$fail('فاکتور قبلاً پرداخت یا لغو شده است.');$sid=++$d['seq']['bot'];$oid=++$d['seq']['order'];book($d,$u,-$price,'bot_purchase','bot-buy:'.$nonce,$u);
 if((int)$p['stock']>0)$d['bot_plans'][$id]['stock']--;
 if($disc){$d['discounts'][$s['code']]['uses']=(int)($disc['uses']??0)+1;$d['discounts'][$s['code']]['user_uses'][(string)$u]=(int)($disc['user_uses'][(string)$u]??0)+1;}
 $d['bots'][(string)$sid]=['id'=>$sid,'uid'=>$u,'plan'=>$id,'name'=>$p['name'],'template'=>$p['template'],'days'=>(int)$p['days'],'price'=>$price,'telegram_id'=>(int)$s['telegram_id'],'username'=>$s['username'],'sealed'=>$s['sealed'],'webhook_secret'=>bin2hex(random_bytes(24)),'status'=>'pending','created'=>time(),'expires'=>0,'order_id'=>$oid,'invoice_id'=>$iid,'shop_name'=>(string)($s['shop_name']??$p['name']),'welcome'=>(string)($s['welcome']??'به ربات ما خوش آمدید.'),'visitors'=>[],'child_updates'=>[]];
 $d['orders'][(string)$oid]=['id'=>$oid,'kind'=>'bot','uid'=>$u,'bot_id'=>$sid,'amount'=>$price,'status'=>'pending','created'=>time()];
 $d['invoices'][(string)$iid]=['id'=>$iid,'kind'=>'bot','uid'=>$u,'bot_id'=>$sid,'amount'=>$price,'status'=>'paid','created'=>$s['at'],'paid_at'=>time(),'quote_nonce'=>$nonce,'plan_name'=>$p['name'],'username'=>$s['username'],'days'=>$s['days']];
 $d['states'][(string)$u]=['step'=>'bots','mid'=>$s['mid']??0];unset($d['users'][(string)$u]['coupon']);addBotEvent($d,(string)$sid,'پرداخت و ثبت سفارش',$u);
 notifyLater($d,ADMIN,'🛍 | سفارش ربات #'.$oid."\nکاربر: ".$u."\nربات: @".esc($s['username'])."\nپرداخت: ".money($price).' تومان','bot-order:'.$sid,[[b('🔎 | بررسی سفارش','ab|'.$sid)]]);
 return['ok'=>true,'sid'=>$sid];});
}
function statusLabel(string $s):string{return['pending'=>'🕓 مرحله ۶ از ۸ — پرداخت شده، منتظر تأیید مدیر','installing'=>'⚙️ مرحله ۷ از ۸ — در حال ساخت و اتصال','setup_failed'=>'⚠️ خطای اتصال','active'=>'🟢 مرحله ۸ از ۸ — فعال و تحویل‌شده','paused'=>'⏸ متوقف توسط مالک','suspended'=>'⛔ تعلیق توسط مدیر','expired'=>'⌛ منقضی','refunded'=>'↩️ مرجوع‌شده'][$s]??$s;}
function owns(array $bot,int $u):bool{return$bot&&(isAdmin($u)||(int)$bot['uid']===$u);}
function botView(int $u,int $m,string $sid):void{
 $d=dbread();$x=$d['bots'][$sid]??[];if(!owns($x,$u)){page($u,$m,'❌ | اشتراک پیدا نشد.');return;}$st=$x['status'];$kb=[];
 if(in_array($st,['active','paused','expired'],true)){
  $kb[]=[b('🔄 | رفرش','bot_refresh|'.$sid),b($st==='active'?'🔴 | خاموش کردن':'🟢 | روشن کردن','pause|'.$sid)];
  $kb[]=[b('⬆️ | آپدیت ربات','bot_update|'.$sid)];
  $row=[];if(isset($d['bot_plans'][$x['plan']]))$row[]=b('✅ | تمدید','renew|'.$sid);$row[]=b('🔒 | تغییر توکن','retoken|'.$sid);$kb[]=$row;
  if(isAdmin($u))$kb[]=[b('🗑 | حذف ربات','bot_delete|'.$sid),b('⛔ | تعلیق اشتراک','suspend|'.$sid)];else$kb[]=[b('🗑 | حذف ربات','bot_delete|'.$sid)];
 }
 if(isAdmin($u)){if(in_array($st,['pending','setup_failed','installing'],true))$kb[]=[b('✅ | تأیید و فعال‌سازی','install_confirm|'.$sid),b('↩️ | رد و بازپرداخت','refund_confirm|'.$sid)];if($st==='suspended')$kb[]=[b('🗑 | حذف ربات','bot_delete|'.$sid),b('✅ | رفع تعلیق','suspend|'.$sid)];$kb[]=[b('🧾 | فاکتور سفارش','invoice|'.$x['invoice_id'])];}
 $kb[]=back(isAdmin($u)?'a_bots':'my_bots');
 $created=!empty($x['created'])?date('Y/m/d H:i',(int)$x['created']):'—';
 $expires=!empty($x['expires'])?date('Y/m/d H:i',(int)$x['expires']):'از زمان فعال‌سازی محاسبه می‌شود';
 if(!empty($x['expires'])){$left=(int)$x['expires']-time();$remaining=$left<=0?'منقضی شده':max(1,(int)ceil($left/86400)).' روز';}else{$remaining='—';}
 $text='⚡ | جزئیات ربات #'.$sid."\n\n";
 $text.='🆔 شناسه: '.$sid."\n\n";
 $text.='<blockquote>🤖 | یوزرنیم: @'.esc($x['username'])."\n".'👤 | نام: '.esc($x['name'])."\n".'⚡ | نوع: '.esc($x['template']??'foxima')."\n".'📅 | پلن: '.esc($x['name'])."\n".'👥 | کاربران: '.count($x['visitors']??[])."\n".'📊 | وضعیت: '.statusLabel($st).'</blockquote>'."\n\n";
 $text.='<blockquote>🕘 | ساخته شده: '.$created."\n".'⏰ | انقضا: '.$expires."\n".'🗓 | زمان باقی‌مانده: '.$remaining.'</blockquote>';
 if($st==='pending')$text.="\n\n<i>پرداخت شما ثبت شده است؛ سفارش در انتظار بررسی و تأیید مدیریت قرار دارد.</i>";
 if($st==='setup_failed')$text.="\n\n⚠️ فعال‌سازی کامل نشده است؛ مدیریت می‌تواند دوباره تلاش کند یا مبلغ را بازگرداند.";
 if(!empty($x['reject_reason']))$text.="\n\n✍️ توضیح مدیریت: ".esc($x['reject_reason']);page($u,$m,$text,$kb);
}
function botList(int $u,int $m,bool $all=false):void{$kb=[];foreach(array_reverse(dbread()['bots'],true) as $id=>$x)if($all||(int)$x['uid']===$u)$kb[]=[b('🤖 | #'.$id.' @'.$x['username'].' · '.cut(statusLabel($x['status']),30),($all?'ab|':'bot|').$id)];$empty=!$kb;$kb[]=back($all?'admin':'home');page($u,$m,($all?'🤖 | مدیریت ربات‌ها':'📦 | اشتراک‌های من').($empty?"\n\nهنوز رباتی ثبت نشده است.":''),$kb);}
function templateRoot():string{return rtrim((string)(getenv('MUTESHOP_TEMPLATE_ROOT')?:'/opt/muteshop/template'),'/');}
function faoximaBotName(int|string $sid):string{$sid=(string)$sid;if(!preg_match('/^[1-9][0-9]{0,8}$/D',$sid))throw new RuntimeException('شناسه نصب Faoxima نامعتبر است.');return'bot_'.$sid;}
function faoximaVersion():string{$file=templateRoot().'/version';$v=is_file($file)?trim((string)file_get_contents($file)):'';return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D',$v)?$v:'current';}
function faoximaProvisioner():\Faoxima\Provisioning\Provisioner{
 $commands=new \Faoxima\Provisioning\CommandRunner();
 $database=new \Faoxima\Provisioning\PdoDatabaseAdmin(provisionPdo(),$commands);
 return new \Faoxima\Provisioning\Provisioner($database,new \Faoxima\Provisioning\TelegramClient(),$commands,[
  'web_root'=>(string)(getenv('FAOXIMA_WEB_ROOT')?:'/var/www/faoxima'),'source_root'=>templateRoot(),
  'state_root'=>(string)(getenv('FAOXIMA_STATE_ROOT')?:'/var/lib/faoxima-provisioner'),'lock_root'=>(string)(getenv('FAOXIMA_LOCK_ROOT')?:'/run/lock/faoxima-provisioner'),
  'domain'=>(string)(getenv('FAOXIMA_DOMAIN')?:'kanamir.faoximabot.xyz'),'url_prefix'=>'/faoxima',
  'php_binary'=>(string)(getenv('FAOXIMA_PHP_BINARY')?:'/usr/bin/php8.3'),'runtime_user'=>(string)(getenv('FAOXIMA_RUNTIME_USER')?:'www-data'),
 ]);
}
function provisionPdo():PDO{
 $host=(string)(getenv('MUTESHOP_MYSQL_HOST')?:'127.0.0.1');$port=(int)(getenv('MUTESHOP_MYSQL_PORT')?:3306);$user=trim((string)getenv('MUTESHOP_MYSQL_ADMIN_USER'));$pass=(string)getenv('MUTESHOP_MYSQL_ADMIN_PASS');
 $cfg='/etc/muteshop/mysql-provisioner.php';if($user===''&&is_readable($cfg)){$x=require $cfg;if(is_array($x)){$host=(string)($x['host']??$host);$port=(int)($x['port']??$port);$user=trim((string)($x['user']??''));$pass=(string)($x['pass']??'');}}
 if($user==='')throw new RuntimeException('تنظیمات MySQL Provisioner موجود نیست. MUTESHOP_MYSQL_ADMIN_USER یا /etc/muteshop/mysql-provisioner.php را تنظیم کنید.');
 return new PDO('mysql:host='.$host.';port='.$port.';charset=utf8mb4',$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_PERSISTENT=>false]);
}
function installBot(int $sid,int $actor):array{
 if(!isAdmin($actor))return['ok'=>false,'reason'=>'دسترسی ندارید.'];
 $lock=fopen(DB.'.bot.'.$sid.'.lock','c+');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))return['ok'=>false,'reason'=>'عملیات دیگری در حال اجراست.'];
 try{
  $x=dbmut(function(&$d)use($sid){$b=$d['bots'][(string)$sid]??[];if(!$b||!in_array($b['status'],['pending','setup_failed','installing'],true))return null;$d['bots'][(string)$sid]['status']='installing';return$b;});
  if(!$x)return['ok'=>false,'reason'=>'وضعیت سفارش تغییر کرده است.'];
  try{
   $token=openPayload($x)['token'];$owned=null;
   if(!empty($x['db_name'])&&!empty($x['db_user'])&&!empty($x['db_sealed'])){$sealedDb=openPayload(['sealed'=>$x['db_sealed']]);if(is_string($sealedDb['password']??null)&&$sealedDb['password']!=='')$owned=['name'=>(string)$x['db_name'],'user'=>(string)$x['db_user'],'password'=>$sealedDb['password']];}
   $rememberDatabase=function(array $credentials)use($sid):void{dbmut(function(&$d)use($sid,$credentials){$b=&$d['bots'][(string)$sid];$b['db_name']=$credentials['name'];$b['db_user']=$credentials['user'];$b['db_host']='localhost';$b['db_sealed']=sealPayload(['password'=>$credentials['password']]);$b['schema_at']=$b['schema_at']??time();});};
   $credentials=faoximaProvisioner()->installBot(faoximaBotName($sid),faoximaVersion(),$token,(string)$x['uid'],$owned,$rememberDatabase);
   dbmut(function(&$d)use($sid,$credentials){$b=&$d['bots'][(string)$sid];$b['db_name']=$credentials['name'];$b['db_user']=$credentials['user'];$b['db_host']='localhost';$b['db_sealed']=sealPayload(['password'=>$credentials['password']]);$b['schema_at']=time();$b['status']='active';$b['last_error']='';if(!$b['expires'])$b['expires']=time()+$b['days']*86400;$d['orders'][(string)$b['order_id']]['status']='active';addBotEvent($d,(string)$sid,'نصب مستقل Faoxima و اتصال مستقیم webhook',ADMIN);notifyLater($d,(int)$b['uid'],'✅ | ربات شما فعال شد: @'.esc($b['username']),'activated:'.$sid,[[b('🤖 | مدیریت ربات','bot|'.$sid)]]);});
   return['ok'=>true,'reason'=>'ربات فعال شد.'];
  }catch(Throwable $e){dbmut(function(&$d)use($sid,$e){if(isset($d['bots'][(string)$sid])){$d['bots'][(string)$sid]['status']='setup_failed';$d['bots'][(string)$sid]['last_error']='Provisioning: '.$e->getMessage();}});return['ok'=>false,'reason'=>'نصب مستقل Faoxima ناموفق بود: '.$e->getMessage()];}
 }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function refundBot(int $sid,int $actor,string $reason='لغو سفارش توسط مدیر'):array{
 if(!isAdmin($actor))return['ok'=>false,'reason'=>'دسترسی ندارید.'];$lock=fopen(DB.'.bot.'.$sid.'.lock','c+');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))return['ok'=>false,'reason'=>'اتصال در حال انجام است.'];
 try{return dbmut(function(&$d)use($sid,$actor,$reason){$x=$d['bots'][(string)$sid]??[];if(!$x||!in_array($x['status'],['pending','setup_failed','installing'],true))return['ok'=>false,'reason'=>'فقط سفارش فعال‌نشده قابل بازپرداخت است.'];$x=&$d['bots'][(string)$sid];
 book($d,(int)$x['uid'],(int)$x['price'],'bot_refund','bot-refund:'.$sid,$actor);$x['status']='refunded';$x['reject_reason']=$reason;addBotEvent($d,(string)$sid,'بازپرداخت: '.$reason,$actor);unset($x['sealed']);
 if(isset($d['bot_plans'][$x['plan']])&&(int)$d['bot_plans'][$x['plan']]['stock']>=0)$d['bot_plans'][$x['plan']]['stock']++;
 $d['orders'][(string)$x['order_id']]['status']='refunded';$d['invoices'][(string)$x['invoice_id']]['status']='refunded';
 notifyLater($d,(int)$x['uid'],'↩️ | سفارش ربات #'.$sid.' لغو و '.money((int)$x['price']).' تومان به کیف پول بازگشت. دلیل: '.esc($reason),'bot-refund-notice:'.$sid);return['ok'=>true,'reason'=>'بازپرداخت انجام شد.'];});}finally{flock($lock,LOCK_UN);fclose($lock);}
}
function renewBot(int $u,string $nonce):array{return dbmut(function(&$d)use($u,$nonce){$s=$d['states'][(string)$u]??[];$q=$s['quote']??[];$fail=fn($x)=>['ok'=>false,'reason'=>$x];
 if(($s['step']??'')!=='renew'||($q['nonce']??'')!==$nonce||time()-(int)($q['at']??0)>900||!purchaseAllowed($d,$u))return$fail('درخواست معتبر نیست.');$sid=(string)$q['sid'];$x=&$d['bots'][$sid];$p=$d['bot_plans'][$x['plan']??'']??[];
 if(!$x||(int)$x['uid']!==$u||!in_array($x['status'],['active','expired','paused'],true)||!($p['active']??false)||(int)$p['price']!==(int)$q['price']||(int)$p['days']!==(int)$q['days']||$x['expires']!==$q['expires'])return$fail('قیمت یا وضعیت ربات تغییر کرده است.');
 if((int)($d['users'][(string)$u]['wallet']??0)<(int)$q['price'])return$fail('موجودی کافی نیست.');book($d,$u,-(int)$q['price'],'bot_renew','renew:'.$nonce,$u);$x['expires']=max(time(),(int)$x['expires'])+(int)$q['days']*86400;addBotEvent($d,$sid,'تمدید '.$q['days'].' روزه',$u);if($x['status']==='expired')$x['status']='active';$iid=++$d['seq']['invoice'];$d['invoices'][(string)$iid]=['id'=>$iid,'kind'=>'bot_renew','uid'=>$u,'bot_id'=>$sid,'amount'=>(int)$q['price'],'status'=>'paid','created'=>time()];$d['states'][(string)$u]=['step'=>'bots','mid'=>$s['mid']??0];notifyLater($d,ADMIN,'🔄 | تمدید ربات #'.$sid.' به مدت '.$q['days'].' روز','renew-notice:'.$nonce);return['ok'=>true,'sid'=>$sid,'reason'=>'تمدید انجام شد.'];});}
function adminPlan(int $u,int $m,string $id):void{$d=dbread();$p=$d['bot_plans'][$id]??[];if(!$p){page($u,$m,'❌ | پلن وجود ندارد.',[back('a_plans')]);return;}$kb=[];$t='📦 | پلن #'.$id;foreach(fields() as $f=>$label){$t.="\n".$label.': '.esc((string)($p[$f]??''));$kb[]=[b($label,'ap_edit|'.$id.'|'.$f)];}$kb[]=[b($p['active']?'⏸ | غیرفعال کردن':'▶️ | فعال کردن','ap_toggle|'.$id),b('🗑 | حذف','delete_confirm|plan|'.$id)];$kb[]=back('a_plans');page($u,$m,$t,$kb);}
function adminCategory(int $u,int $m,string $id):void{$x=dbread()['bot_categories'][$id]??[];if(!$x){page($u,$m,'❌ | دسته پیدا نشد.',[back('a_categories')]);return;}page($u,$m,'📁 | '.esc($x['name']),[[b('✏️ | تغییر نام','ac_edit|'.$id)],[b($x['active']?'⏸ | غیرفعال کردن':'▶️ | فعال کردن','ac_toggle|'.$id),b('🗑 | حذف','delete_confirm|category|'.$id)],back('a_categories')]);}
function telegramPingMs():int{$t=microtime(true);$r=tg('getMe',[]);return($r['ok']??false)?max(1,(int)round((microtime(true)-$t)*1000)):0;}
function financeHome(int $u,int $m):void{$s=dbread()['settings'];$kb=[
 [b('💳 | کارت به کارت '.($s['gateway_card']?'🟢':'🔴'),'gateway_toggle|card')],
 [b('🌐 | اطلس پی '.($s['gateway_atlas']?'🟢':'🔴'),'gateway_toggle|atlas'),b('🔑 | API اطلس پی','gateway_api|atlas')],
 [b('🌐 | تون پی '.($s['gateway_toon']?'🟢':'🔴'),'gateway_toggle|toon'),b('🔑 | API تون پی','gateway_api|toon')],
 back('admin')];
 $txt="💰 | مالی و درگاه‌ها\n\n💳 کارت به کارت: ".($s['gateway_card']?'🟢 فعال':'🔴 غیرفعال')."\n🌐 اطلس پی: ".($s['gateway_atlas']?'🟢 فعال':'🔴 غیرفعال')." — API: ".($s['atlas_api']!==''?'ثبت شده':'ثبت نشده')."\n🌐 تون پی: ".($s['gateway_toon']?'🟢 فعال':'🔴 غیرفعال')." — API: ".($s['toon_api']!==''?'ثبت شده':'ثبت نشده')."\n\n<i>اتصال پرداخت API اطلس پی و تون پی فعلاً در حالت آماده‌سازی است و تراکنش واقعی از آن‌ها انجام نمی‌شود.</i>";page($u,$m,$txt,$kb);}
function statsPage(int $u,int $m):void{$d=dbread();$users=count($d['users']);$wallet=array_sum(array_map(fn($x)=>(int)($x['wallet']??0),$d['users']));$buyers=[];$sales=0;$renew=0;$salesCount=0;foreach($d['ledger'] as $l){$type=(string)($l['type']??'');$delta=(int)($l['delta']??0);if($type==='bot_purchase'&&$delta<0){$sales+=-$delta;$salesCount++;$buyers[(string)$l['uid']]=1;}elseif($type==='bot_renew'&&$delta<0){$renew+=-$delta;$buyers[(string)$l['uid']]=1;}}$active=array_filter($d['bots'],fn($x)=>($x['status']??'')==='active'&&(int)($x['expires']??0)>time());$activeSales=0;$forecast=0;$customers=[];foreach($d['bots'] as $x){if(($x['status']??'')!=='refunded')$customers[(string)($x['uid']??0)]=1;}foreach($active as $x){$activeSales+=(int)($x['price']??0);$days=max(1,(int)($x['days']??30));$forecast+=(int)round((int)($x['price']??0)*30/$days);}$conv=$users?count($buyers)*100/$users:0;$avg=count($buyers)?$sales/count($buyers):0;$renewPct=$sales?$renew*100/$sales:0;$ping=telegramPingMs();$approved=0;$paid=0;foreach($d['funding'] as $f){if(($f['status']??'')==='approved'){$approved++;$paid+=(int)($f['amount']??0);}}$txt="📊 آمار کلی ربات\n━━━━━━━━━━━━━━━━━━\n👥 تعداد کل کاربران: ".$users." نفر\n💳 کاربران دارای خرید: ".count($buyers)." نفر\n💰 موجودی کل کاربران: ".money((int)$wallet)." تومان\n\n🧾 تعداد کل فروش: ".$salesCount." عدد\n🧾 تعداد کل فروش سرویس های فعال: ".count($active)." عدد\n💵 جمع کل فروش : ".money($sales)." تومان\n💵 جمع کل فروش سرویس های فعال: ".money($activeSales)." تومان\n🔄 جمع کل تمدید: ".money($renew)." تومان\n📈 نرخ تبدیل به مشتری: ".number_format($conv,2)."٪\n💳 میانگین خرید هر مشتری: ".money((int)round($avg))." تومان\n📅 درآمد پیش‌بینی‌شده ماهانه: ".money($forecast)." تومان\n📊 درصد تمدید از فروش: ".number_format($renewPct,2)."٪\n\n👨‍💼 تعداد کل مشتری ها: ".count($customers)." نفر\n🧩 تعداد ربات‌ها: ".count($d['bots'])." عدد\n📡 پینگ ربات: ".($ping?:'—').($ping?' میلی‌ثانیه':'')."\n\n📌 نام درگاه : کارت به کارت\n موفق : ".$approved."\n - جمع پرداختی ها : ".money($paid);page($u,$m,$txt,[back('admin')]);}
function adminManagers(int $u,int $m):void{$ids=adminIds();$kb=[];$txt="👮 | مدیریت ادمین‌ها\n\n👑 | ادمین اصلی: <code>".ADMIN."</code>\n\nادمین‌های اضافه:";$extra=array_values(array_filter($ids,fn($id)=>$id!==ADMIN));if(!$extra)$txt.="\n— موردی ثبت نشده است.";foreach($extra as $id){$txt.="\n👤 | <code>".$id."</code>";$kb[]=[b('🗑 | حذف '.$id,'admin_del|'.$id)];}$kb[]=[b('➕ | افزودن ادمین','admin_add')];$kb[]=back('admin');page($u,$m,$txt,$kb,['step'=>'admin']);}
function adminSettings(int $u,int $m):void{$d=dbread();$s=$d['settings'];$kb=[];foreach(['public_url'=>'🌐 | آدرس HTTPS فایل ربات','card_number'=>'💳 | شماره کارت','card_owner'=>'👤 | صاحب کارت','channel'=>'📢 | کانال عضویت اجباری'] as $f=>$label)$kb[]=[b($label,'setting|'.$f)];foreach(['enabled'=>'فعالیت فروشگاه','force_join'=>'عضویت اجباری'] as $f=>$label)$kb[]=[b(($s[$f]?'🟢':'🔴').' | '.$label,'setting_toggle|'.$f)];$kb[]=back('admin');page($u,$m,"⚙️ | تنظیمات\n\nآدرس رباتساز: ".esc(publicUrl()?:'تنظیم نشده')."\nشماره کارت: ".esc($s['card_number']?:'تنظیم نشده')."\nصاحب کارت: ".esc($s['card_owner']?:'تنظیم نشده')."\nکانال: ".esc($s['channel']?:'تنظیم نشده'),$kb);}
function adminUser(int $u,int $m,string $id):void{$x=dbread()['users'][$id]??[];if(!$x){page($u,$m,'❌ | کاربر پیدا نشد.',[back('a_users')]);return;}page($u,$m,"👤 | کاربر #".$id."\n\n👤 نام: <span class=\"tg-spoiler\">".esc($x['name'])."</span>"."\nنام کاربری: @".esc($x['username']??'')."\nموجودی: ".money((int)($x['wallet']??0))." تومان\nوضعیت: ".(($x['banned']??false)?'مسدود':'آزاد'),[[b(($x['banned']??false)?'✅ | رفع مسدودی':'🚫 | مسدود کردن','user_ban|'.$id)],[b('➕ | افزایش موجودی','balance|'.$id.'|add'),b('➖ | کاهش موجودی','balance|'.$id.'|sub')],back('a_users')]);}
function ask(int $u,int $m,string $text,string $step,array $extra=[],string $back='admin'):void{page($u,$m,$text,[[b('❌ | انصراف',$back)]],array_merge($extra,['step'=>$step]));}
function cb(array $q):void{
 $u=(int)($q['from']['id']??0);$c=(int)($q['message']['chat']['id']??0);$m=(int)($q['message']['message_id']??0);$id=(string)($q['id']??'');$data=(string)($q['data']??'');if($u<=0||$c!==$u)return;
 $GLOBALS['incoming_text']=false;if(isset(dbread()['users'][(string)$u]['retired_menus'][(string)$m])){ans($id,'این منو قدیمی است؛ از آخرین پیام ربات استفاده کنید.',true);return;}$GLOBALS['photo_callback']=isset($q['message']['photo'])||isset($q['message']['animation']);$parts=explode('|',$data);$act=$parts[0];$v=$parts[1]??'';$f=$parts[2]??'';
 try{
 if(banned($u)){ans($id,'دسترسی مسدود است.',true);return;}
 $adminActions=['admin','ab','ap','ac','ad','au','delete_confirm','delete_commit','setting','setting_toggle','balance','balance_commit','user_ban','install_confirm','install_commit','refund_confirm','refund_commit','suspend'];
 if((in_array($act,$adminActions,true)||preg_match('/^(a_|ap_|ac_|ad_|adm_)/',$act))&&!isAdmin($u)){ans($id,'دسترسی ندارید.',true);return;}
 if($act==='check_join'){if(!joinOk($u)){ans($id,'عضویت تأیید نشد.',true);return;}page($u,$m,'🏠 | منوی اصلی',mainkb($u));return;}
 if(!joinOk($u)){joinGate($u,$u);return;}
 if(makerCallbacks($q))return;
 if($act==='noop')return;
 if($act==='ui_page'){$st=state($u);$ps=$st['_pages']??[];if(($ps['nonce']??'')!==$v)return;$rows=$ps['rows'];$last=array_pop($rows);$pn=max(0,min((int)$f,(int)ceil(count($rows)/8)-1));$kb=array_slice($rows,$pn*8,8);$nav=[];if($pn>0)$nav[]=b('⬅️ | قبلی','ui_page|'.$v.'|'.($pn-1));if(($pn+1)*8<count($rows))$nav[]=b('➡️ | بعدی','ui_page|'.$v.'|'.($pn+1));if($nav)$kb[]=$nav;$kb[]=$last;page($u,$m,$ps['text'],$kb,$st);return;}
 if($act==='home'){page($u,$m,'🏠 | منوی اصلی',mainkb($u));return;}
 if($act==='help'){page($u,$m,"📖 | راهنمای خرید\n\n۱. از خرید، نوع و مدت ربات را انتخاب کنید.\n۲. با /newbot در @BotFather ربات اختصاصی بسازید و توکنش را ارسال کنید.\n۳. فاکتور را بررسی کنید؛ تخفیف، شارژ کیف پول و ادامه همان فاکتور در دسترس است.\n۴. پس از پرداخت، مدیر سفارش را تأیید می‌کند؛ تا قبل از تأیید ربات فعال نیست.\n۵. پس از اتصال، اشتراک در «اشتراک‌های من» قابل مدیریت است.\n\nقالب foxima به فرمان /start پاسخ می‌دهد. متن استارت، توقف/شروع، تغییر توکن و تاریخچه از داخل اشتراک مدیریت می‌شوند.\nدر شارژ کارت‌به‌کارت، کد پیگیری و مبلغ واریز توسط مدیر بررسی می‌شود.\nاتصال، وبهوک قبلی ربات اختصاصی شما را جایگزین می‌کند.");return;}
 if(in_array($act,['buy','category','plan','choose','checkout'],true)){$d=dbread();if(!($d['settings']['enabled']??true)){ans($id,'فروش موقتاً متوقف است.',true);return;}}
 if($act==='buy'){catalog($u,$m);return;}if($act==='category'){catalog($u,$m,$v);return;}if($act==='plan'){planView($u,$m,$v);return;}
 if($act==='choose'){if(!planAvailable(dbread(),$v)){catalog($u,$m);return;}if(!validUrl(publicUrl())){ans($id,'راه‌اندازی فروشگاه هنوز توسط مدیر کامل نشده است.',true);return;}ask($u,$m,"🔑 | مرحله ۲ از ۸ — اتصال ربات\n\nتوکن رباتی را که در @BotFather ساخته‌اید ارسال کنید. اتصال پس از پرداخت و تأیید مدیر انجام می‌شود و وبهوک قبلی آن را جایگزین می‌کند.\nتوکن ربات اصلی فروشگاه پذیرفته نمی‌شود.",'purchase_token',['plan'=>$v],'buy');return;}
 if($act==='checkout'){$r=checkout($u,$v);if(!$r['ok']){ans($id,$r['reason'],true);return;}botView($u,$m,(string)$r['sid']);return;}
 if($act==='my_bots'){botList($u,$m);return;}if($act==='bot'||$act==='ab'){botView($u,$m,$v);return;}
 if($act==='bot_refresh'){botView($u,$m,$v);return;}
 if($act==='bot_update'){$x=dbread()['bots'][$v]??[];if(!owns($x,$u)||!in_array($x['status'],['active','paused','expired'],true)){ans($id,'این ربات قابل بروزرسانی نیست.',true);return;}try{faoximaProvisioner()->updateBot(faoximaBotName($v),faoximaVersion());dbmut(function(&$d)use($v,$u){if(isset($d['bots'][$v]))addBotEvent($d,$v,'بروزرسانی اتمیک سورس مستقل',$u);});ans($id,'ربات با موفقیت بروزرسانی شد.');botView($u,$m,$v);return;}catch(Throwable $e){ans($id,'بروزرسانی ربات ناموفق بود: '.$e->getMessage(),true);return;}}
 if($act==='bot_delete'){$x=dbread()['bots'][$v]??[];if(!owns($x,$u)){ans($id,'این ربات در دسترس نیست.',true);return;}page($u,$m,"🗑 | حذف ربات #".$v."\n\n<blockquote>⚠️ آیا از حذف این ربات مطمئن هستید؟\nاین عملیات از داخل پنل قابل بازگشت نیست.</blockquote>",[[b('❌ | بله، حذف شود','bot_delete_confirm|'.$v)],[b('🔙 | انصراف','bot|'.$v)]]);return;}
 if($act==='bot_delete_confirm'){$x=dbread()['bots'][$v]??[];if(!owns($x,$u)){ans($id,'این ربات قبلاً حذف شده یا در دسترس نیست.',true);botList($u,$m);return;}try{faoximaProvisioner()->deleteBot(faoximaBotName($v));dbmut(function(&$d)use($v,$u){if(!isset($d['bots'][$v])||!owns($d['bots'][$v],$u))return;unset($d['bots'][$v]);$d['states'][(string)$u]=['step'=>'bots'];});ans($id,'ربات با موفقیت حذف شد.');botList($u,$m);return;}catch(Throwable $e){ans($id,'حذف ربات rollback شد: '.$e->getMessage(),true);return;}}
 if(in_array($act,['pause','renew','retoken','bot_edit'],true)){$x=dbread()['bots'][$v]??[];if(!owns($x,$u)||!in_array($x['status'],['active','paused','expired'],true)){ans($id,'این ربات قابل مدیریت نیست.',true);return;}}
 if($act==='pause'){if(!in_array($x['status'],['active','paused'],true)){ans($id,'ربات منقضی را ابتدا تمدید کنید.',true);return;}$target=$x['status']==='active'?'paused':'active';try{faoximaProvisioner()->transition(faoximaBotName($v),$target);dbmut(function(&$d)use($u,$v,$target){$x=&$d['bots'][$v];if(owns($x,$u)){$x['status']=$target;addBotEvent($d,$v,$target==='paused'?'توقف توسط مالک':'شروع توسط مالک',$u);}});}catch(Throwable $e){ans($id,'تغییر وضعیت انجام نشد: '.$e->getMessage(),true);}botView($u,$m,$v);return;}
 if($act==='bot_edit'&&$f==='welcome'){ask($u,$m,'✏️ | متن شروع جدید را ارسال کنید (حداکثر ۱۵۰۰ نویسه).','bot_welcome',['sid'=>$v],'bot|'.$v);return;}
 if($act==='retoken'){ask($u,$m,'🔑 | توکن جدید همین ربات را ارسال کنید؛ شناسه ربات نباید تغییر کند.','retoken',['sid'=>$v],'bot|'.$v);return;}
 if($act==='renew'){$d=dbread();$x=$d['bots'][$v];$p=$d['bot_plans'][$x['plan']]??[];if((int)$x['uid']!==$u||!($p['active']??false)){ans($id,'تمدید این پلن در دسترس نیست.',true);return;}$q=['sid'=>$v,'price'=>(int)$p['price'],'days'=>(int)$p['days'],'expires'=>$x['expires'],'nonce'=>bin2hex(random_bytes(10)),'at'=>time()];page($u,$m,'🔄 | تمدید '.$q['days'].' روزه به مبلغ '.money($q['price']).' تومان',[[b('💳 | تأیید و پرداخت','renew_pay|'.$q['nonce'])],back('bot|'.$v)],['step'=>'renew','quote'=>$q]);return;}
 if($act==='renew_pay'){$r=renewBot($u,$v);if($r['ok']){try{faoximaProvisioner()->transition(faoximaBotName($r['sid']),'active');}catch(Throwable $e){dbmut(function(&$d)use($r){if(isset($d['bots'][(string)$r['sid']]))$d['bots'][(string)$r['sid']]['status']='expired';});$r=['ok'=>false,'reason'=>'تمدید ثبت شد اما فعال‌سازی webhook ناموفق بود؛ دوباره تلاش کنید.'];}}ans($id,$r['reason'],true);if($r['ok'])botView($u,$m,(string)$r['sid']);return;}
 if($act==='wallet'){wallet($u,$m);return;}
 if($act==='deposit'){$s=dbread()['settings'];$kb=[];if($s['gateway_card']??true)$kb[]=[b('💳 | کارت به کارت','deposit_gateway|card')];if($s['gateway_atlas']??false)$kb[]=[b('🌐 | اطلس پی','deposit_gateway|atlas')];if($s['gateway_toon']??false)$kb[]=[b('🌐 | تون پی','deposit_gateway|toon')];$kb[]=back('wallet');page($u,$m,'💰 | انتخاب روش افزایش موجودی',$kb);return;}
 if($act==='deposit_gateway'){if($v==='card'){$s=dbread()['settings'];if(!($s['gateway_card']??true)||!$s['card_number']||!$s['card_owner']){ans($id,'درگاه کارت به کارت در دسترس نیست.',true);return;}ask($u,$m,'💵 | مبلغ شارژ را به تومان ارسال کنید.','deposit_amount',['gateway'=>'card'],'wallet');return;}if($v==='toon'){$cfg=dbread()['settings'];if(!($cfg['gateway_toon']??false)||trim((string)($cfg['toon_api']??''))===''){ans($id,'درگاه تون پی فعال نیست یا API آن ثبت نشده است.',true);return;}ask($u,$m,'💵 | مبلغ شارژ از طریق تون پی را به تومان ارسال کنید.','deposit_amount',['gateway'=>'toon'],'wallet');return;}if($v==='atlas'){ans($id,'درگاه اطلس پی فعلاً در حال آماده‌سازی است.',true);return;}return;}
 if($act==='history'){$all=array_filter(dbread()['ledger'],fn($x)=>(int)$x['uid']===$u);$kb=[];foreach(array_reverse($all,true) as $x)$kb[]=[b('📜 | '.date('m/d H:i',$x['created']).' · '.($x['delta']>0?'+':'').money($x['delta']).' تومان','noop')];$kb[]=back('wallet');page($u,$m,'📜 | گردش کیف پول',$kb);return;}
 if($act==='invoices'){invoiceList($u,$m);return;}
 if($act==='discount_main'){ask($u,$m,'🎟 | کد تخفیف را ارسال کنید؛ روی خرید بعدی اعمال می‌شود.','coupon',[],'home');return;}
 if($act==='support'){supportMenu($u,$u,$m);return;}if($act==='sup_my'){myTickets($u,$u,$m);return;}if($act==='sup_new'){ask($u,$m,'✍️ | متن تیکت را ارسال کنید.','support_new',[],'support');return;}
 if($act==='ticket'||$act==='adm_ticket'){ticketView($u,$u,$m,(int)$v,isAdmin($u));return;}
 if($act==='ticket_reply'||$act==='adm_reply'){$t=dbread()['tickets'][$v]??[];if(!$t||(!isAdmin($u)&&(int)$t['uid']!==$u))return;ask($u,$m,'✍️ | پاسخ خود را ارسال کنید.','ticket_reply',['tid'=>$v],(isAdmin($u)?'adm_ticket|':'ticket|').$v);return;}
 if($act==='ticket_close'||$act==='adm_close'){ticketClose((int)$v);ticketView($u,$u,$m,(int)$v,isAdmin($u));return;}
 if($act==='admin'){adminHome($u,$m);return;}if($act==='adm_tickets'){adminTickets($u,$u,$m);return;}
 if($act==='a_bots'){botList($u,$m,true);return;}
 if($act==='a_categories'||$act==='a_plans'){$rows=dbread()[$act==='a_categories'?'bot_categories':'bot_plans'];$kb=[];foreach($rows as $key=>$x)$kb[]=[b(($x['active']?'🟢':'🔴').' | '.$x['name'],($act==='a_categories'?'ac|':'ap|').$key)];$kb[]=[b('➕ | افزودن',$act==='a_categories'?'ac_add':'ap_add')];$kb[]=back('admin');page($u,$m,$act==='a_categories'?'📁 | دسته‌بندی‌ها':'📦 | پلن‌های ربات',$kb);return;}
 if($act==='ac'){adminCategory($u,$m,$v);return;}if($act==='ap'){adminPlan($u,$m,$v);return;}
 if($act==='ac_add'||$act==='ac_edit'){ask($u,$m,'📁 | نام دسته را ارسال کنید.',$act,['cid'=>$v],'a_categories');return;}
 if($act==='ap_add'){ask($u,$m,'📦 | نام پلن جدید را ارسال کنید؛ پلن ابتدا غیرفعال ساخته می‌شود و مشخصات آن را جداگانه تنظیم می‌کنید.','ap_add',[],'a_plans');return;}
 if($act==='ap_edit'){if(!isset(fields()[$f]))return;if(in_array($f,['category'],true)){$rows=dbread()[$f==='category'?'bot_categories':'templates'];$kb=[];foreach($rows as $key=>$x)$kb[]=[b('🔹 | '.$x['name'],'ap_pick|'.$v.'|'.$f.'|'.$key)];$kb[]=back('ap|'.$v);page($u,$m,'🔎 | انتخاب '.fields()[$f],$kb);return;}ask($u,$m,'✏️ | مقدار جدید '.fields()[$f].' را ارسال کنید.','ap_field',['pid'=>$v,'field'=>$f],'ap|'.$v);return;}
 if($act==='ap_pick'){$value=$parts[3]??'';dbmut(function(&$d)use($v,$f,$value){if(isset($d['bot_plans'][$v])&&in_array($f,['category'],true)&&isset($d[$f==='category'?'bot_categories':'templates'][$value]))$d['bot_plans'][$v][$f]=$value;});adminPlan($u,$m,$v);return;}
 if($act==='ap_toggle'){$r=dbmut(function(&$d)use($v){if(!isset($d['bot_plans'][$v]))return false;$p=&$d['bot_plans'][$v];if(!$p['active']&&(!(int)$p['price']||!(int)$p['days']||!isset($d['bot_categories'][$p['category']])||!isset($d['templates'][$p['template']])))return false;$p['active']=!$p['active'];return true;});if(!$r)ans($id,'نام، قیمت، مدت، دسته و قالب را تکمیل کنید.',true);adminPlan($u,$m,$v);return;}
 if($act==='ac_toggle'){dbmut(function(&$d)use($v){if(isset($d['bot_categories'][$v]))$d['bot_categories'][$v]['active']=!$d['bot_categories'][$v]['active'];});adminCategory($u,$m,$v);return;}
 if($act==='delete_confirm'){if(!in_array($v,['category','plan','discount'],true))return;page($u,$m,'🗑 | حذف این مورد را تأیید می‌کنید؟',[[b('🗑 | تأیید حذف','delete_commit|'.$v.'|'.$f)],back('admin')],['step'=>'delete','kind'=>$v,'key'=>$f]);return;}
 if($act==='delete_commit'){$st=state($u);if(($st['step']??'')!=='delete'||($st['kind']??'')!==$v||($st['key']??'')!==$f)return;$r=dbmut(function(&$d)use($v,$f){if($v==='category'){foreach($d['bot_plans'] as $p)if((string)$p['category']===$f)return false;unset($d['bot_categories'][$f]);}elseif($v==='plan'){foreach($d['bots'] as $x)if((string)$x['plan']===$f)return false;unset($d['bot_plans'][$f]);}elseif($v==='discount'){if((int)($d['discounts'][$f]['uses']??0)>0)return false;unset($d['discounts'][$f]);}return true;});ans($id,$r?'حذف شد.':'این مورد استفاده شده است؛ غیرفعالش کنید.',true);adminHome($u,$m);return;}
 if($act==='a_finance'){financeHome($u,$m);return;}
 if($act==='gateway_toggle'){if(!in_array($v,['card','atlas','toon'],true))return;dbmut(function(&$d)use($v){$k='gateway_'.$v;$d['settings'][$k]=!($d['settings'][$k]??false);});financeHome($u,$m);return;}
 if($act==='gateway_api'){if(!in_array($v,['atlas','toon'],true))return;ask($u,$m,'🔑 | API درگاه '.($v==='atlas'?'اطلس پی':'تون پی').' را ارسال کنید. برای حذف API علامت - را بفرستید.','gateway_api',['gateway'=>$v],'a_finance');return;}
 if($act==='a_admins'){adminManagers($u,$m);return;}
 if($act==='admin_add'){ask($u,$m,'👮 | افزودن ادمین\n\nآیدی عددی تلگرام کاربر را ارسال کنید.','admin_add',[],'a_admins');return;}
 if($act==='admin_del'){if((int)$v===ADMIN){ans($id,'ادمین اصلی قابل حذف نیست.',true);return;}dbmut(function(&$d)use($v){$id=(int)$v;$d['settings']['admins']=array_values(array_filter(array_map('intval',(array)($d['settings']['admins']??[])),fn($x)=>$x!==$id));});adminManagers($u,$m);return;}
 if($act==='a_settings'){adminSettings($u,$m);return;}
 if($act==='setting'){if(!in_array($v,['public_url','card_number','card_owner','channel'],true))return;ask($u,$m,'✏️ | مقدار جدید را ارسال کنید.'.($v==='channel'?"\nنام عمومی کانال با @؛ ربات باید ادمین کانال باشد. برای حذف - بفرستید.":''),'setting',['field'=>$v],'a_settings');return;}
 if($act==='setting_toggle'){if(!in_array($v,['enabled','force_join'],true))return;$ok=dbmut(function(&$d)use($v){if($v==='force_join'&&!$d['settings']['channel'])return false;$d['settings'][$v]=!$d['settings'][$v];return true;});if(!$ok)ans($id,'ابتدا کانال را ثبت کنید.',true);adminSettings($u,$m);return;}
 if($act==='a_users'){$kb=[];foreach(dbread()['users'] as $key=>$x)$kb[]=[b('👤 | #'.$key.' '.cut($x['name'],25),'au|'.$key)];$kb[]=back('admin');page($u,$m,'👥 | کاربران',$kb);return;}if($act==='au'){adminUser($u,$m,$v);return;}
 if($act==='user_ban'){dbmut(function(&$d)use($v){if((int)$v!==ADMIN&&isset($d['users'][$v]))$d['users'][$v]['banned']=!($d['users'][$v]['banned']??false);});adminUser($u,$m,$v);return;}
 if($act==='balance'){if(!in_array($f,['add','sub'],true))return;ask($u,$m,'💵 | مبلغ تغییر موجودی را به تومان ارسال کنید.','balance',['uid'=>$v,'direction'=>$f],'au|'.$v);return;}
 if($act==='balance_commit'){$r=dbmut(function(&$d)use($u,$v){$s=$d['states'][(string)$u]??[];if(($s['step']??'')!=='balance_confirm'||($s['nonce']??'')!==$v)return false;if(!isset($d['users'][(string)$s['uid']])||(int)($d['users'][(string)$s['uid']]['wallet']??0)+(int)$s['delta']<0)return false;book($d,(int)$s['uid'],(int)$s['delta'],'admin_adjust','adjust:'.$v,$u);notifyLater($d,(int)$s['uid'],'💳 | تغییر موجودی توسط مدیر: '.money((int)$s['delta']).' تومان','adjust-notice:'.$v);$d['states'][(string)$u]=['step'=>'admin'];return true;});ans($id,$r?'ثبت شد.':'درخواست منقضی شده یا موجودی کافی نیست.',true);adminHome($u,$m);return;}
 if($act==='a_discounts'){$kb=[];foreach(dbread()['discounts'] as $key=>$x)$kb[]=[b(($x['active']?'🟢':'🔴').' | '.$key.' · '.$x['percent'].'٪','ad|'.$key)];$kb[]=[b('➕ | افزودن کد','ad_add')];$kb[]=back('admin');page($u,$m,'🎟 | کدهای تخفیف',$kb);return;}
 if($act==='ad_add'){ask($u,$m,'🎟 | کد انگلیسی را ارسال کنید (۲ تا ۲۴ نویسه).','ad_code',[],'a_discounts');return;}
 if($act==='ad'){$x=dbread()['discounts'][$v]??[];if(!$x)return;page($u,$m,'🎟 | '.esc($v)."\nدرصد: ".$x['percent']."\nمصرف: ".(int)($x['uses']??0).'/'.((int)($x['max_uses']??0)?:'∞')."\nانقضا: ".(!empty($x['expires_at'])?date('Y/m/d',$x['expires_at']):'ندارد'),[[b('✏️ | ویرایش شرایط','ad_edit|'.$v)],[b($x['active']?'⏸ | غیرفعال':'▶️ | فعال','ad_toggle|'.$v),b('🗑 | حذف','delete_confirm|discount|'.$v)],back('a_discounts')]);return;}
 if($act==='ad_toggle'){dbmut(function(&$d)use($v){if(isset($d['discounts'][$v]))$d['discounts'][$v]['active']=!$d['discounts'][$v]['active'];});adminHome($u,$m);return;}
 if($act==='ad_edit'){ask($u,$m,"🎟 | شرایط را در چهار خط بفرستید:\nدرصد (۱ تا ۱۰۰)\nسقف کل مصرف (۰ نامحدود)\nسقف هر کاربر (۰ نامحدود)\nاعتبار به روز (۰ بدون انقضا)",'ad_rules',['code'=>$v],'ad|'.$v);return;}
 if(in_array($act,['install_confirm','refund_confirm'],true)){page($u,$m,$act==='install_confirm'?'✅ | اتصال ربات و جایگزینی وبهوک آن تأیید شود؟':'↩️ | سفارش لغو و مبلغ به کیف پول برگردد؟',[[b('✅ | تأیید',$act==='install_confirm'?'install_commit|'.$v:'refund_commit|'.$v)],back('ab|'.$v)],['step'=>$act,'sid'=>$v]);return;}
 if(in_array($act,['install_commit','refund_commit'],true)){$st=state($u);if(($st['sid']??'')!==$v||($st['step']??'')!==($act==='install_commit'?'install_confirm':'refund_confirm'))return;$r=$act==='install_commit'?installBot((int)$v,$u):refundBot((int)$v,$u);ans($id,$r['reason'],true);botView($u,$m,$v);return;}
 if($act==='suspend'){$x=dbread()['bots'][$v]??[];if(!$x||!in_array($x['status'],['active','paused','expired','suspended'],true))return;$target=$x['status']==='suspended'?($x['expires']<=time()?'expired':(($x['before_suspend']??'active')==='paused'?'paused':'active')):'suspended';try{faoximaProvisioner()->transition(faoximaBotName($v),$target);dbmut(function(&$d)use($v,$target){$x=&$d['bots'][$v];if($target==='suspended')$x['before_suspend']=$x['status'];$x['status']=$target;addBotEvent($d,$v,$target==='suspended'?'تعلیق توسط مدیریت':'رفع تعلیق توسط مدیریت',ADMIN);});}catch(Throwable $e){ans($id,'تغییر تعلیق انجام نشد: '.$e->getMessage(),true);}botView($u,$m,$v);return;}
 if($act==='a_stats'){statsPage($u,$m);return;}
 ans($id,'این دکمه قدیمی یا نامعتبر است؛ /start را بزنید.',true);
 }finally{ans($id);}
}
function message(array $msg):void{
 $u=(int)$msg['from']['id'];$c=(int)$msg['chat']['id'];if($c!==$u)return;$t=trim((string)($msg['text']??''));$s=state($u);$step=(string)($s['step']??'');$mid=(int)($s['mid']??0);$userMid=(int)($msg['message_id']??0);
 if(banned($u))return;
 if(preg_match('~^/start(?:@\w+)?(?:\s|$)~',$t)){if(!joinOk($u)){joinGate($u,$u);return;}if(firstStartNotice($u))dbmut(function(&$d)use($u,$msg){notifyLater($d,ADMIN,'👤 | کاربر جدید: '.esc($msg['from']['first_name']??'')."\nشناسه: ".$u."\nنام کاربری: @".esc($msg['from']['username']??''),'new-user:'.$u);});welcome($u,$u,$msg['from']['first_name']??'');return;}
 if(!joinOk($u)){joinGate($u,$u);return;}
 if(makerMessages($msg))return;
 $error=function(string $text)use($u,$mid,$s){page($u,$mid,'❌ | '.$text,[[b('❌ | انصراف',isAdmin($u)?'admin':'home')]],$s);};
 $adminSteps=['ac_add','ac_edit','ap_add','ap_field','setting','gateway_api','balance','ad_code','ad_rules','admin_add'];if(in_array($step,$adminSteps,true)&&!isAdmin($u))return;
 if($step==='purchase_token'||$step==='retoken'){
 
 if(!preg_match('/^\d{5,16}:[A-Za-z0-9_-]{20,100}$/D',$t)||hash_equals(TOKEN,$t)||explode(':',$t)[0]===explode(':',TOKEN)[0]){$error('توکن معتبر ربات دیگری را ارسال کنید.');return;}
 $r=api($t,'getMe');if(!($r['ok']??false)||!($r['result']['is_bot']??false)||empty($r['result']['username'])){$error('اعتبار توکن تأیید نشد؛ توکن و دسترسی هاست به تلگرام را بررسی کنید.');return;}
 $botid=(int)$r['result']['id'];
 if($step==='retoken'){$sid=(string)$s['sid'];$x=dbread()['bots'][$sid]??[];if(!owns($x,$u)||$botid!==(int)$x['telegram_id']||!in_array($x['status'],['active','paused','expired'],true)){$error('توکن باید متعلق به همین ربات باشد.');return;}
 $lock=fopen(DB.'.bot.'.$sid.'.lock','c+');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){$error('عملیات دیگری در حال انجام است.');return;}
 try{faoximaProvisioner()->rotateToken(faoximaBotName($sid),$t);$sealed=sealPayload(['token'=>$t]);dbmut(function(&$d)use($sid,$u,$sealed){if(owns($d['bots'][$sid]??[],$u)){$d['bots'][$sid]['sealed']=$sealed;addBotEvent($d,$sid,'تغییر اتمیک توکن و webhook مستقیم',$u);}});botView($u,$mid,$sid);return;}catch(Throwable $e){$error('اتصال توکن جدید rollback شد: '.$e->getMessage());return;}finally{flock($lock,LOCK_UN);fclose($lock);}}
 foreach(dbread()['bots'] as $x)if((int)$x['telegram_id']===$botid&&$x['status']!=='refunded'){$error('این ربات قبلاً ثبت شده است.');return;}
 page($u,$mid,"🏷 | مرحله ۳ از ۸ — نام فروشگاه\n\nیک نام برای فروشگاه/ربات وارد کنید.\nمثال: Mute Shop",[[b('❌ | انصراف','buy')]],['step'=>'purchase_shop_name','plan'=>$s['plan'],'telegram_id'=>$botid,'username'=>$r['result']['username'],'sealed'=>sealPayload(['token'=>$t])]);return;
 }
 if($step==='purchase_shop_name'){
  $name=trim($t);if(mb_strlen($name)<2||mb_strlen($name)>60){$error('نام فروشگاه باید بین ۲ تا ۶۰ نویسه باشد.');return;}
  page($u,$mid,"💬 | مرحله ۴ از ۸ — پیام شروع\n\nمتنی که کاربر با /start می‌بیند را ارسال کنید.\nحداکثر ۱۰۰۰ نویسه.",[[b('❌ | انصراف','buy')]],array_merge($s,['step'=>'purchase_welcome','shop_name'=>$name]));return;
 }
 if($step==='purchase_welcome'){
  $welcome=trim($t);if(mb_strlen($welcome)<2||mb_strlen($welcome)>1000){$error('پیام شروع باید بین ۲ تا ۱۰۰۰ نویسه باشد.');return;}
  quotePurchase($u,$mid,['plan'=>$s['plan'],'telegram_id'=>$s['telegram_id'],'username'=>$s['username'],'sealed'=>$s['sealed'],'shop_name'=>$s['shop_name'],'welcome'=>$welcome]);return;
 }
 if($step==='coupon'){$code=strtoupper($t);$d=dbread();$valid=false;foreach($d['bot_plans'] as $pid=>$p)if(planAvailable($d,(string)$pid)&&checkDiscount($d,$code,$u,(string)$pid)){$valid=true;break;}if(!$valid){$error('کد برای هیچ پلن فعال قابل استفاده نیست یا منقضی شده است.');return;}dbmut(function(&$d)use($u,$code){$d['users'][(string)$u]['coupon']=$code;});page($u,$mid,'✅ | کد ثبت شد؛ هنگام تأیید سفارش، مبلغ تخفیف نمایش داده می‌شود.',[[b('💎 | خرید','buy')],back()]);return;}
 if($step==='support_new'||$step==='ticket_reply'){
 if(mb_strlen($t)<2||mb_strlen($t)>2000){$error('متن تیکت باید ۲ تا ۲۰۰۰ نویسه باشد.');return;}
 if($step==='support_new'){if(!empty($s['related_bot']))$t='اشتراک #'.$s['related_bot']."\n".$t;$tid=newTicket($u,$msg['from']['first_name']??'',$t);dbmut(function(&$d)use($tid,$u){notifyLater($d,ADMIN,'🎫 | تیکت جدید #'.$tid.' از کاربر '.$u,'ticket-new:'.$tid,[[b('💬 | پاسخ','adm_ticket|'.$tid)]]);});}
 else{$tid=(int)$s['tid'];if(!ticketAdd($tid,isAdmin($u)?'admin':'user',$t)){$error('تیکت بسته شده یا در دسترس نیست.');return;}$target=isAdmin($u)?(int)dbread()['tickets'][(string)$tid]['uid']:ADMIN;dbmut(function(&$d)use($tid,$target,$u){notifyLater($d,$target,'💬 | پاسخ جدید تیکت #'.$tid,'ticket-reply:'.$tid.':'.($GLOBALS['update_id']??bin2hex(random_bytes(6))),[[b('🎫 | مشاهده',(isAdmin($target)?'adm_ticket|':'ticket|').$tid)]]);});}
 ticketView($u,$u,$mid,$tid,isAdmin($u));return;
 }
 if($step==='bot_welcome'){$sid=(string)$s['sid'];$x=dbread()['bots'][$sid]??[];if(!owns($x,$u)||!in_array($x['status'],['active','paused','expired'],true)){$error('این اشتراک قابل ویرایش نیست.');return;}if($t===''||mb_strlen($t)>1500){$error('متن شروع باید بین ۱ تا ۱۵۰۰ نویسه باشد.');return;}dbmut(function(&$d)use($sid,$t){$d['bots'][$sid]['welcome']=$t;});botView($u,$mid,$sid);return;}
 if($step==='ac_add'||$step==='ac_edit'){if($t===''||mb_strlen($t)>50){$error('نام باید ۱ تا ۵۰ نویسه باشد.');return;}$cid=dbmut(function(&$d)use($s,$step,$t){$id=$step==='ac_add'?(string)++$d['seq']['bot_category']:(string)$s['cid'];if($step==='ac_edit'&&!isset($d['bot_categories'][$id]))return'';$d['bot_categories'][$id]=array_merge($d['bot_categories'][$id]??['active'=>true],['name'=>$t]);return$id;});adminCategory($u,$mid,$cid);return;}
 if($step==='ap_add'){if($t===''||mb_strlen($t)>70){$error('نام باید ۱ تا ۷۰ نویسه باشد.');return;}$pid=dbmut(function(&$d)use($t){$id=(string)++$d['seq']['bot_plan'];$d['bot_plans'][$id]=['id'=>(int)$id,'name'=>$t,'description'=>'','price'=>0,'days'=>30,'stock'=>-1,'category'=>'','template'=>'foxima','active'=>false];return$id;});adminPlan($u,$mid,$pid);return;}
 if($step==='ap_field'){$f=$s['field'];$value=$t;if(!isset(fields()[$f])||in_array($f,['category'],true))return;
 if(in_array($f,['price','days','stock'],true)){$value=$f==='stock'&&digits($t)==='-1'?-1:amount($t,$f==='stock');if($value===null||($f==='days'&&$value>3650)||($f==='stock'&&$value>1000000)){$error('عدد معتبر وارد کنید؛ مدت حداکثر ۳۶۵۰ روز است.');return;}}
 elseif($t===''||mb_strlen($t)>($f==='name'?70:1500)){$error('طول متن نامعتبر است.');return;}
 $pid=(string)$s['pid'];dbmut(function(&$d)use($pid,$f,$value){if(isset($d['bot_plans'][$pid]))$d['bot_plans'][$pid][$f]=$value;});adminPlan($u,$mid,$pid);return;
 }
 if($step==='setting'){$f=$s['field'];if(!in_array($f,['public_url','card_number','card_owner','channel'],true))return;$v=$t;
 if($f==='public_url'&&(!validUrl($v)||strlen($v)>500)){$error('آدرس باید HTTPS همین فایل و بدون پارامتر یا # باشد.');return;}
 if($f==='card_number'){$v=digits($v);if(!preg_match('/^\d{16}$/D',$v)){$error('شماره کارت باید ۱۶ رقم باشد.');return;}}
 if($f==='card_owner'&&($v===''||mb_strlen($v)>100)){$error('نام صاحب کارت معتبر نیست.');return;}
 if($f==='channel'){if($v==='-')$v='';elseif(!preg_match('/^@[A-Za-z][A-Za-z0-9_]{4,31}$/D',$v)){$error('نام عمومی کانال را با @ وارد کنید.');return;}}
 dbmut(function(&$d)use($f,$v){$d['settings'][$f]=$v;if($f==='channel'&&$v==='')$d['settings']['force_join']=false;});adminSettings($u,$mid);return;
 }
 if($step==='balance'){$n=amount($t);if($n===null){$error('مبلغ معتبر نیست.');return;}$delta=$s['direction']==='sub'?-$n:$n;$nonce=bin2hex(random_bytes(10));page($u,$mid,'💳 | تغییر موجودی کاربر #'.$s['uid'].' به مقدار '.money($delta).' تومان تأیید شود؟',[[b('✅ | تأیید','balance_commit|'.$nonce)],back('au|'.$s['uid'])],['step'=>'balance_confirm','uid'=>$s['uid'],'delta'=>$delta,'nonce'=>$nonce]);return;}

 if($step==='ad_code'){$code=strtoupper($t);if(!preg_match('/^[A-Z0-9_-]{2,24}$/D',$code)||isset(dbread()['discounts'][$code])){$error('کد نامعتبر یا تکراری است.');return;}ask($u,$mid,"🎟 | شرایط را در چهار خط بفرستید:\nدرصد (۱ تا ۱۰۰)\nسقف کل مصرف (۰ نامحدود)\nسقف هر کاربر (۰ نامحدود)\nاعتبار به روز (۰ بدون انقضا)",'ad_rules',['code'=>$code,'new'=>true],'a_discounts');return;}
 if($step==='ad_rules'){$a=preg_split('/\R/u',$t);$a=array_map(fn($v)=>amount($v,true),$a);if(count($a)!==4||in_array(null,$a,true)||$a[0]<1||$a[0]>100||$a[3]>3650){$error('چهار عدد معتبر مطابق راهنما وارد کنید.');return;}$code=$s['code'];dbmut(function(&$d)use($code,$a,$s){if(($s['new']??false)&&isset($d['discounts'][$code]))throw new RuntimeException('کد تکراری است');$old=$d['discounts'][$code]??[];$d['discounts'][$code]=array_merge($old,['percent'=>$a[0],'max_uses'=>$a[1],'per_user_limit'=>$a[2],'expires_at'=>$a[3]?time()+$a[3]*86400:0,'active'=>true,'uses'=>(int)($old['uses']??0),'user_uses'=>$old['user_uses']??[]]);});page($u,$mid,'✅ | شرایط تخفیف ذخیره شد.',[[b('🎟 | مشاهده کد','ad|'.$code)],back('a_discounts')]);return;}
 page($u,$mid,'🏠 | برای ادامه از دکمه‌ها استفاده کنید.',mainkb($u));
}
function dispatchUpdate(array $up):void{
 $msg=$up['message']??null;$q=$up['callback_query']??null;$from=$q['from']??$msg['from']??[];$uid=(int)($from['id']??0);$chat=(int)($q['message']['chat']['id']??$msg['chat']['id']??0);if(!$uid||$uid!==$chat||($from['is_bot']??false))return;usertouch($from);
 if($q){$GLOBALS['incoming_text']=false;cb($q);}elseif($msg){$GLOBALS['incoming_text']=true;try{message($msg);}finally{$GLOBALS['incoming_text']=false;}}
}
function expiryReminders():void{
 $expired=[];
 dbmut(function(&$d)use(&$expired){
  foreach($d['invoices'] as &$iv)if(($iv['status']??'')==='unpaid'&&(int)($iv['expires']??0)<time()){$iv['status']='expired';unset($iv['draft']);}unset($iv);
  foreach($d['bots'] as $sid=>&$x){if(!in_array($x['status'],['active','paused','expired','suspended'],true)||!$x['expires'])continue;$exp=(int)$x['expires'];if($exp<=time()){if(in_array($x['status'],['active','paused'],true)){$x['status']='expired';$expired[]=(string)$sid;}notifyLater($d,(int)$x['uid'],'⌛ | اشتراک @'.esc($x['username']).' منقضی شد؛ از اشتراک‌های من تمدید کنید.','expired:'.$sid.':'.$exp);}elseif($exp-time()<=3*86400)notifyLater($d,(int)$x['uid'],'⏰ | کمتر از سه روز تا انقضای @'.esc($x['username']).' باقی مانده است.','expiry:'.$sid.':'.$exp);}unset($x);
  foreach($d['states'] as &$state)if(isset($state['sealed'])&&time()-(int)($state['at']??0)>1800){unset($state['sealed']);$state['step']='home';}unset($state);
 });
 foreach($expired as $sid){try{faoximaProvisioner()->transition(faoximaBotName($sid),'expired');}catch(Throwable $e){error_log('[faoxima-expire:'.$sid.'] '.$e->getMessage());}}
}
function worker(int $limit=10):void{
 $h=fopen(DB.'.worker.lock','c+');if(!$h||!flock($h,LOCK_EX|LOCK_NB))return;
 try{expiryReminders();dbmut(function(&$d){$d['meta']['worker_last_seen']=time();foreach($d['jobs'] as &$j)if($j['kind']==='telegram'&&$j['status']==='running')$j['status']='queued';unset($j);});
 for($i=0;$i<$limit;$i++){$j=dbmut(function(&$d){foreach($d['jobs'] as &$j)if($j['kind']==='telegram'&&$j['status']==='queued'&&(int)$j['next']<=time()){$j['status']='running';$j['attempts']++;return$j;}return null;});if(!$j)break;
 $r=tg($j['payload']['method'],$j['payload']['data']);dbmut(function(&$d)use($j,$r){$x=&$d['jobs'][(string)$j['id']];$x['status']=($r['ok']??false)?'done':((in_array((int)($r['error_code']??0),[400,401,403],true)||$x['attempts']>=6)?'failed':'queued');$x['next']=time()+max(2,(int)($r['parameters']['retry_after']??min(300,2**$x['attempts'])));});if(!defined('BOT_TEST_MODE'))usleep(80000);}
 }finally{flock($h,LOCK_UN);fclose($h);}
}
function childCall(array $bot,string $method,array $data):array{return api(openPayload($bot)['token'],$method,$data);}
function childSend(array $bot,int $chat,string $text,array $kb=[]):array{$r=childCall($bot,'sendMessage',['chat_id'=>$chat,'text'=>bold(esc($text)),'parse_mode'=>'HTML','reply_markup'=>ik($kb)]);if(!($r['ok']??false))throw new RuntimeException('Child delivery failed');return$r;}
function childAuthorized(array $x,string $secret):bool{return isset($x['webhook_secret'])&&$secret!==''&&hash_equals($x['webhook_secret'],$secret);}
function addBotEvent(array &$d,string $sid,string $text,int $actor):void{$d['bots'][$sid]['events'][]=['at'=>time(),'actor'=>$actor,'text'=>$text];$d['bots'][$sid]['events']=array_slice($d['bots'][$sid]['events'],-200);}
function recordChildUpdate(int $sid,string $update,int $u):void{dbmut(function(&$d)use($sid,$update,$u){$b=&$d['bots'][(string)$sid];$b['visitors'][(string)$u]=time();$b['child_updates'][$update]=time();foreach($b['child_updates'] as $id=>$at)if($at<time()-86400)unset($b['child_updates'][$id]);});}
function invoiceLabel(string $s):string{return['paid'=>'✅ پرداخت‌شده','unpaid'=>'🕓 پرداخت‌نشده','refunded'=>'↩️ بازپرداخت‌شده','cancelled'=>'❌ لغوشده','expired'=>'⌛ منقضی'][$s]??$s;}
function invoiceList(int $u,int $m,bool $all=false):void{$kb=[];foreach(array_reverse(dbread()['invoices'],true) as $id=>$x)if($all||((int)$x['uid']===$u&&$x['status']==='unpaid'))$kb[]=[b('🧾 | #'.$id.' · '.money((int)$x['amount']).' · '.invoiceLabel($x['status']),'invoice|'.$id)];$kb[]=back($all?'admin':'home');page($u,$m,$all?'🧾 | فاکتورهای فروش':'🧾 | خریدهای ناتمام',$kb);}
function invoiceView(int $u,int $m,string $id):void{
 $x=dbread()['invoices'][$id]??[];if(!$x||(!isAdmin($u)&&((int)$x['uid']!==$u||$x['status']!=='unpaid'))){page($u,$m,'🧾 | این فاکتور در بخش خریدهای ناتمام موجود نیست.');return;}$kb=[];
 if($x['status']==='unpaid'&&(int)$x['uid']===$u){$kb[]=[b('💳 | پرداخت فاکتور','checkout|'.$x['quote_nonce']),b('➕ | شارژ کیف پول','wallet')];$kb[]=[b('🎟 | تخفیف','invoice_coupon|'.$id),b('❌ | لغو فاکتور','invoice_cancel|'.$id)];}
 if(isAdmin($u)&&!empty($x['bot_id']))$kb[]=[b('📦 | مشاهده اشتراک','bot|'.$x['bot_id'])];$kb[]=back(isAdmin($u)?'a_invoices':'invoices');
 page($u,$m,'🧾 | فاکتور #'.$id."\n\nوضعیت: ".invoiceLabel($x['status'])."\nمبلغ: ".money((int)$x['amount'])." تومان\nتاریخ: ".date('Y/m/d H:i',(int)$x['created'])."\nخریدار: <code>".$x['uid']."</code>".(!empty($x['plan_name'])?"\nپلن: ".esc($x['plan_name']):'').(!empty($x['username'])?"\nربات: @".esc($x['username']):'').($x['status']==='unpaid'?"\nاعتبار فاکتور: ".date('Y/m/d H:i',(int)$x['expires'])."\nبعد از پرداخت، مرحله تأیید مدیر و سپس فعال‌سازی انجام می‌شود.":''),$kb);
}
function ubuntuConfig(string $dest):void{
 $url=publicUrl();if(!validUrl($url))throw new RuntimeException('Set public_url in legacy-config.php first.');$host=parse_url($url,PHP_URL_HOST);$route=parse_url($url,PHP_URL_PATH);$script=realpath(__FILE__);$root=dirname($script);$state=realpath(dirname(DB));
 if(!preg_match('/^[A-Za-z0-9.-]+$/D',$host)||!preg_match('~^/[A-Za-z0-9_./-]+\.php$~D',$route)||!preg_match('~^/[A-Za-z0-9_./-]+$~D',$script)||!preg_match('~^/[A-Za-z0-9_./-]+$~D',$state))throw new RuntimeException('Use ASCII filesystem paths and URL.');
 if($dest===''||$dest[0]!=='/')throw new RuntimeException('Destination must be an absolute directory.');if(!is_dir($dest)&&!mkdir($dest,0700,true))throw new RuntimeException('Cannot create directory.');
 $nginx=<<<'NGINX'
# Install in /etc/nginx/conf.d/muteshop.conf (http context).
# HTTPS certificate must already exist at the paths below.
# Telegram shares source IPs; use a generous IP limit + application user limits.
limit_req_zone $binary_remote_addr zone=maker_ip:10m rate=50r/s;
limit_req_zone $server_name zone=maker_total:1m rate=150r/s;
limit_conn_zone $binary_remote_addr zone=maker_conn:10m;
server {
    listen 443 ssl;
    server_name __HOST__;
    ssl_certificate /etc/letsencrypt/live/__HOST__/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/__HOST__/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    server_tokens off;
    root __ROOT__;
    client_max_body_size 256k;
    client_body_timeout 10s;
    client_header_timeout 10s;
    keepalive_timeout 10s;
    reset_timedout_connection on;
    limit_req_status 429;
    limit_conn_status 429;
    location = __ROUTE__ {
        if ($request_method != POST) { return 405; }
        if ($http_x_telegram_bot_api_secret_token = "") { return 403; }
        limit_req zone=maker_ip burst=100 nodelay;
        limit_req zone=maker_total burst=200 nodelay;
        limit_conn maker_conn 100;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME __SCRIPT__;
        fastcgi_param HTTP_PROXY "";
        fastcgi_pass unix:/run/php/muteshop.sock;
        fastcgi_request_buffering on;
        fastcgi_connect_timeout 3s;
        fastcgi_read_timeout 10s;
        access_log off;
    }
    # JSON, SQLite, config, backups, keys, dotfiles and all other PHP are denied.
    location / { return 404; }
}
NGINX;
 $pool=<<<'POOL'
[muteshop]
user = www-data
group = www-data
listen = /run/php/muteshop.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = dynamic
pm.max_children = 8
pm.start_servers = 2
pm.min_spare_servers = 2
pm.max_spare_servers = 4
pm.max_requests = 500
request_terminate_timeout = 15s
catch_workers_output = yes
clear_env = yes
env[BOT_DATABASE] = __DB__
security.limit_extensions = .php
php_admin_value[memory_limit] = 96M
php_admin_value[max_execution_time] = 10
php_admin_value[post_max_size] = 256K
php_admin_flag[display_errors] = off
php_admin_flag[log_errors] = on
php_admin_flag[allow_url_fopen] = off
php_admin_flag[expose_php] = off
POOL;
 $service=<<<'SERVICE'
[Unit]
Description=MuteShop queue worker %i
After=network-online.target
Wants=network-online.target
[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=__ROOT__
Environment=BOT_DATABASE=__DB__
ExecStart=/usr/bin/php __SCRIPT__ --serve
Restart=always
RestartSec=3
TimeoutStopSec=620
UMask=0077
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=__STATE__
MemoryMax=256M
TasksMax=32
[Install]
WantedBy=multi-user.target
SERVICE;
 $mapping=['__HOST__'=>$host,'__ROOT__'=>$root,'__ROUTE__'=>$route,'__SCRIPT__'=>$script,'__STATE__'=>$state,'__DB__'=>DB];
 $instructions=<<<'INFO'
Ubuntu 24.04 / PHP 8.3. Configuration only; nothing installed automatically.
1. Install nginx php8.3-cli php8.3-fpm php8.3-curl php8.3-mbstring.
2. Back up the bot source and bot_data.json before replacing.
   On the FIRST upgrade retain legacy config.php, kyc_key.php and maker_queue.sqlite
   until `php bot.php --migrate-json` succeeds. Legacy SQLite import needs PDO SQLite once.
   Stop old workers before migration. All ongoing storage is bot_data.json afterward.
3. Run `php bot.php --configure` to enter token, admin ID and HTTPS URL privately.
4. The www-data user needs write access to __STATE__ and bot_data.json.
   Prefer a private data directory using the BOT_DATABASE environment variable consistently
   in both PHP-FPM and systemd. Code should remain read-only.
5. Obtain a valid TLS certificate for __HOST__, then install the generated files:
   nginx.conf -> /etc/nginx/conf.d/muteshop.conf (avoid duplicate hostname definitions)
   php-fpm.conf -> /etc/php/8.3/fpm/pool.d/muteshop.conf
   muteshop-worker@.service -> /etc/systemd/system/
6. php-fpm8.3 -t && nginx -t
   systemctl reload php8.3-fpm nginx
   systemctl daemon-reload
   systemctl enable --now muteshop-worker@1 muteshop-worker@2
7. runuser -u www-data -- php __SCRIPT__ --set-webhook https://__HOST____ROUTE__
8. Restart after code edits:
   systemctl restart muteshop-worker@1 muteshop-worker@2 php8.1-fpm
Only foxima is offered. It responds to /start using the subscription's welcome text.
Wallet funding uses transfer references and admin approval; no receipt-photo workflow.
No identity verification, diagnostics page, security/queue page or free test-bot creation.
Queues and tokens are inside the same JSON; lock files carry no user information.
Old files are not deleted automatically: archive them after confirming migration.
Serialized JSON is appropriate for a small deployment; it is not a high-load database.
INFO;
 foreach(['nginx.conf'=>$nginx,'php-fpm.conf'=>$pool,'muteshop-worker@.service'=>$service,'INSTALL.txt'=>$instructions] as $name=>$body){$path=rtrim($dest,'/').'/'.$name;if(is_file($path))throw new RuntimeException('Refusing to overwrite existing generated config: '.$name);if(file_put_contents($path,strtr($body,$mapping)."\n")===false)throw new RuntimeException('Config write failed');chmod($path,0600);}echo "Configuration generated in ".$dest."; read INSTALL.txt.\n";
}

function bold(string $s):string{
 $s=trim(str_replace('\\n',"\n",$s));if($s==='')return'';
 // Code/pre cannot overlap bold in Telegram. Style every other text fragment,
 // leaving structural tags (quote/link/italic/spoiler) properly nested.
 $s=preg_replace('~</?b>~i','',$s);
 if(!str_contains($s,'<blockquote>')){$parts=preg_split('/\n\s*\n/u',$s);$first=array_shift($parts);$head=explode("\n",$first,2);$title=$head[0];if(isset($head[1]))array_unshift($parts,$head[1]);$s=$title;foreach($parts as $p)if(trim($p)!=='')$s.="\n\n<blockquote>".$p.'</blockquote>';}
 $parts=preg_split('~(<[^>]+>)~u',$s,-1,PREG_SPLIT_DELIM_CAPTURE);$out='';$mono=0;
 foreach($parts as $p){if($p==='' )continue;if($p[0]==='<'){if(preg_match('~^<(code|pre)(\s|>)~i',$p))$mono++;$out.=$p;if(preg_match('~^</(code|pre)>~i',$p))$mono=max(0,$mono-1);}else{if(trim($p)!==''){$p=preg_replace('/(^|\n)(?=.)/u','$1'."\u{200F}",$p);}$out.=($mono||trim($p)==='')?$p:'<b>'.$p.'</b>';}}
 return$out;
}
function dbload():array{
 $def=['meta'=>[],'config'=>[],'users'=>[],'states'=>[],'tickets'=>[],'discounts'=>[],'settings'=>[],'orders'=>[],'invoices'=>[],'funding'=>[],'ledger'=>[],'jobs'=>[],'updates'=>[],'bot_plans'=>[],'bot_categories'=>[],'bots'=>[],'templates'=>[],'inbox'=>[],'rates'=>[],'seq'=>['ticket'=>1000,'order'=>0,'invoice'=>0,'payment'=>0,'ledger'=>0,'job'=>0,'bot'=>0,'bot_plan'=>0,'bot_category'=>0,'inbox'=>0]];
 $d=$def;if(is_file(DB)){$raw=file_get_contents(DB);if($raw===false||trim($raw)==='')throw new RuntimeException('Database unreadable; restore a backup, do not reset it.');$d=json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($d))throw new RuntimeException('Invalid database');$d=array_replace($def,$d);}
 foreach(array_keys($def) as $k)if(!is_array($d[$k]))throw new RuntimeException('Invalid collection: '.$k);$d['seq']=array_replace($def['seq'],$d['seq']);
 $d['settings']+=['enabled'=>true,'force_join'=>false,'channel'=>'','card_number'=>'','card_owner'=>'','public_url'=>'','gateway_card'=>true,'gateway_atlas'=>false,'gateway_toon'=>false,'atlas_api'=>'','toon_api'=>'','admins'=>[]];
 foreach(['order'=>'orders','invoice'=>'invoices','payment'=>'funding','ticket'=>'tickets','ledger'=>'ledger','job'=>'jobs','bot'=>'bots','bot_plan'=>'bot_plans','bot_category'=>'bot_categories','inbox'=>'inbox'] as $key=>$table)foreach($d[$table] as $id=>$row)$d['seq'][$key]=max((int)$d['seq'][$key],(int)($row['id']??$id));return$d;
}
function encryptionKey():string{if(!isset($GLOBALS['CRYPTO_KEY']))throw new RuntimeException('Storage not initialized');return$GLOBALS['CRYPTO_KEY'];}
function hasSealed($v):bool{if(!is_array($v))return false;if(!empty($v['sealed']))return true;foreach($v as $x)if(is_array($x)&&hasSealed($x))return true;return false;}
function bootStore():void{
 $path=getenv('BOT_DATABASE')?:__DIR__.'/bot_data.json';$legacy=[];$peek=[];
 if(is_file($path)){$raw=file_get_contents($path);$peek=json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($peek))throw new RuntimeException('Invalid storage');}
 if(($peek['meta']['maker_schema']??0)<3&&is_file(__DIR__.'/config.php')){$legacy=require __DIR__.'/config.php';if(!is_array($legacy))throw new RuntimeException('Invalid legacy configuration');if(!getenv('BOT_DATABASE')&&!empty($legacy['database']))$path=$legacy['database'];}
 define('DB',$path);$d=dbread();
 if(($d['meta']['maker_schema']??0)<3){if(PHP_SAPI!=='cli'&&!(defined('BOT_TEST_MODE')&&BOT_TEST_MODE))throw new RuntimeException('Run --migrate-json via CLI before serving requests');dbmut(function(&$d)use($legacy){
  foreach($d['users'] as &$u)unset($u['verification']);unset($u);unset($d['identity_audit'],$d['settings']['kyc_required']);
  $cfg=array_replace($legacy,$d['config']);$key=(string)($cfg['crypto_key']??'');$keyFile=(string)($cfg['identity_key_file']??dirname(DB).'/kyc_key.php');
  if($key===''&&is_file($keyFile)){$key=require $keyFile;if(!is_string($key))throw new RuntimeException('Invalid legacy key');}
  if($key===''){if(hasSealed($d))throw new RuntimeException('Existing encrypted tokens need the original kyc_key.php for migration');$key=bin2hex(random_bytes(32));}
  if(!preg_match('/^[a-f0-9]{64}$/D',$key))throw new RuntimeException('Invalid encryption key');$GLOBALS['CRYPTO_KEY']=hex2bin($key);
  $cfg['crypto_key']=$key;$cfg['token']=(string)($cfg['token']??getenv('BOT_TOKEN')?:'');$cfg['admin']=(int)($cfg['admin']??245136195);$cfg['webhook_secret']=(string)($cfg['webhook_secret']??getenv('BOT_WEBHOOK_SECRET')?:'');
  if($cfg['webhook_secret']==='')$cfg['webhook_secret']=hash_hmac('sha256','maker-webhook-v1',$GLOBALS['CRYPTO_KEY']);
  if($d['settings']['public_url']===''&&!empty($cfg['public_url']))$d['settings']['public_url']=$cfg['public_url'];
  $queue=(string)($cfg['queue_database']??dirname(DB).'/maker_queue.sqlite');
  if(is_file($queue)){
   if(!extension_loaded('pdo_sqlite'))throw new RuntimeException('One-time migration requires php-sqlite3; no data was committed');
   $q=new PDO('sqlite:'.$queue,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$q->exec('PRAGMA query_only=ON; PRAGMA busy_timeout=3000;');
   foreach($q->query("SELECT * FROM inbox WHERE status IN ('queued','running','dead') ORDER BY id") as $r){$decoded=openPayload(['sealed'=>$r['payload']]);$up=$decoded['update']??[];if(!$up)continue;$id=++$d['seq']['inbox'];$d['inbox'][(string)$id]=['id'=>$id,'scope'=>(int)$r['scope'],'update_id'=>(int)$r['update_id'],'actor'=>(int)$r['actor'],'payload'=>sealPayload(['update'=>$up]),'status'=>$r['status']==='dead'?'dead':'queued','attempts'=>(int)$r['attempts'],'next'=>(int)$r['next_at'],'at'=>(int)$r['at'],'lease'=>0,'owner'=>''];}
   $q=null;
  }
  foreach(['database','identity_key_file','queue_database','public_url','proxy','proxy_auth'] as $k)unset($cfg[$k]);$d['config']=$cfg;
  foreach($d['payments']??[] as $id=>$p){unset($p['file_id'],$p['photo'],$p['receipt']);$p['reference']=$p['reference']??'انتقال از نسخه قبلی';$d['funding'][(string)$id]=$p;}$d['seq']['payment']=max((int)$d['seq']['payment'],0);unset($d['payments']);
  $d['templates']=['foxima'=>['name'=>'foxima','active'=>true,'description'=>'ربات اختصاصی شما؛ پاسخ به /start با پیام دلخواه.']];
  foreach($d['bot_plans'] as &$p)$p['template']='foxima';unset($p);
  foreach($d['bots'] as &$b){$b['template']='foxima';unset($b['items'],$b['reply_map']);}unset($b);
  foreach($d['invoices'] as &$iv)if(isset($iv['draft']))$iv['draft']['template']='foxima';unset($iv);
  $d['states']=[];
  foreach($d['jobs'] as &$j){if(str_contains((string)($j['unique']??''),'identity-'))$j['status']='skipped';if(isset($j['payload']['data']['reply_markup']))$j['payload']['data']['reply_markup']=str_replace('payment|','funding|',$j['payload']['data']['reply_markup']);}unset($j);
  foreach(['products','categories','services','panels','virtualizor_plans'] as $k)if(isset($d[$k])){$d['legacy_vps_archive'][$k]=$d[$k];unset($d[$k]);}
  $d['meta']['maker_schema']=3;$d['meta']['json_migrated_at']=time();
 });$d=dbread();}
 $cfg=$d['config'];$GLOBALS['BOT_CONFIG']=$cfg;$GLOBALS['CRYPTO_KEY']=hex2bin($cfg['crypto_key']);define('TOKEN',(string)$cfg['token']);define('ADMIN',(int)$cfg['admin']);define('WEBHOOK_SECRET',(string)$cfg['webhook_secret']);
}
function purchaseAllowed(array $d,int $u):bool{return !($d['users'][(string)$u]['banned']??false)&&($d['settings']['enabled']??true);}
function fields():array{return['name'=>'🏷 | نام پلن','description'=>'📝 | توضیحات','price'=>'💰 | قیمت به تومان','days'=>'📅 | مدت به روز','stock'=>'📦 | موجودی (-1 نامحدود)','category'=>'📁 | دسته‌بندی'];}
function mainkb(int $u):array{$kb=[[b('💎 | خرید اشتراک','buy')],[b('📦 | اشتراک‌های من','my_bots'),b('💳 | کیف پول','wallet')],[b('🎟 | کد تخفیف','discount_main'),b('💬 | پشتیبانی','support')],[b('📖 | راهنمای خرید','help')]];if(isAdmin($u))$kb[]=[b('⚙️ | مدیریت','admin')];return$kb;}
function welcome(int $c,int $u,string $n):void{
 $old=(int)(state($u)['mid']??0);$t="🦊 | به <u>مــــیوت شــــاپ</u> خوش آمدید\n\n<i>ربات اختصاصی شما، با foxima</i>\nاشتراک دلخواهتان را انتخاب کنید و مدیریت ربات را به دست بگیرید.\n\n💎 خرید اشتراک  •  📦 مدیریت ربات  •  💬 پشتیبانی";
 $r=tg('sendAnimation',['chat_id'=>$c,'animation'=>START_GIF,'caption'=>bold($t),'parse_mode'=>'HTML','reply_markup'=>ik(mainkb($u))]);if(!($r['ok']??false))$r=send($c,$t,mainkb($u));$mid=(int)($r['result']['message_id']??0);if($mid){del($c,$old);retireMenu($u,$old);setstate($u,['step'=>'home','mid'=>$mid,'photo'=>isset($r['result']['animation'])]);}
}
function adminHome(int $u,int $m):void{page($u,$m,"⚙️ | مدیریت فروشگاه\n\n🦊 قالب فعال: <u>foxima</u>\nاز بخش‌های زیر سفارش‌ها، موجودی کاربران و پلن‌های فروش را مدیریت کنید.",[[b('📊 | آمار فروش','a_stats'),b('📦 | سفارش‌ها و اشتراک‌ها','a_bots')],[b('💰 | مالی و درگاه‌ها','a_finance'),b('🧾 | فاکتورهای فروش','a_invoices')],[b('📁 | دسته‌بندی‌ها','a_categories'),b('💎 | پلن‌های فروش','a_plans')],[b('🎟 | کدهای تخفیف','a_discounts'),b('👥 | کاربران','a_users')],[b('📥 | درخواست‌های افزایش موجودی','a_wallet')],[b('💬 | تیکت‌های پشتیبانی','adm_tickets'),b('🔎 | جستجوی اشتراک','a_bot_search')],[b('👮 | مدیریت ادمین‌ها','a_admins'),b('⚙️ | تنظیمات فروشگاه','a_settings')],back()]);}
function wallet(int $u,int $m):void{$d=dbread();page($u,$m,"💳 | کیف پول شما\n\n💰 موجودی قابل استفاده: <u>".money((int)($d['users'][(string)$u]['wallet']??0))." تومان</u>\n\n<i>برای خرید و تمدید اشتراک از موجودی کیف پول استفاده کنید.</i>",[[b('➕ | افزایش موجودی','deposit')],[b('📜 | گردش حساب','history')],back()]);}
function fundingLabel(string $s):string{return['pending'=>'🕓 منتظر بررسی','approved'=>'✅ تأیید شد','rejected'=>'❌ رد شد'][$s]??$s;}
function fundingView(int $u,int $m,string $id):void{$x=dbread()['funding'][$id]??[];if(!$x||(!isAdmin($u)&&(int)$x['uid']!==$u)){page($u,$m,'❌ | درخواست پیدا نشد.');return;}$kb=[];if(isAdmin($u)&&$x['status']==='pending')$kb[]=[b('✅ | تأیید واریز','funding_confirm|'.$id),b('❌ | رد درخواست','funding_reject|'.$id)];$kb[]=back(isAdmin($u)?'a_wallet':'wallet');page($u,$m,'💳 | درخواست شارژ #'.$id."\n\n💰 مبلغ: <u>".money((int)$x['amount'])." تومان</u>\n👤 کاربر: <code>".$x['uid']."</code>\n🔖 کد پیگیری: <code>".esc($x['reference']??'—')."</code>\n📌 وضعیت: ".fundingLabel($x['status']).(!empty($x['reason'])?"\n\n✍️ توضیح مدیریت: ".esc($x['reason']):''),$kb);}
function reviewFunding(int $pid,int $actor,bool $approve,string $reason=''):array{
 if(!isAdmin($actor))return['ok'=>false,'reason'=>'دسترسی مجاز نیست.'];return dbmut(function(&$d)use($pid,$actor,$approve,$reason){$p=$d['funding'][(string)$pid]??[];if(!$p||$p['status']!=='pending')return['ok'=>false,'reason'=>'درخواست قبلاً بررسی شده است.'];if($approve)book($d,(int)$p['uid'],(int)$p['amount'],'deposit','payment:'.$pid,$actor);$p['status']=$approve?'approved':'rejected';$p['reason']=$reason;$p['reviewed_at']=time();$p['reviewed_by']=$actor;$d['funding'][(string)$pid]=$p;notifyLater($d,(int)$p['uid'],$approve?'✅ | شارژ کیف پول انجام شد' ."\n\n💰 مبلغ افزوده‌شده: ".money((int)$p['amount']).' تومان':'❌ | درخواست شارژ تأیید نشد'."\n\n✍️ دلیل: ".esc($reason),'payment-review:'.$pid,[[b('💳 | کیف پول','wallet')]]);return['ok'=>true];});
}
function makerCallbacks(array $q):bool{
 $u=(int)$q['from']['id'];$m=(int)$q['message']['message_id'];$a=explode('|',(string)($q['data']??''));$act=$a[0];$v=$a[1]??'';$id=$q['id'];
 if(in_array($act,['a_invoices','a_bot_search','a_wallet','funding_confirm','funding_commit','funding_reject'],true)&&!isAdmin($u)){ans($id,'دسترسی ندارید.',true);return true;}
 if($act==='invoice'){invoiceView($u,$m,$v);return true;}
 if($act==='invoice_cancel'){$ok=dbmut(function(&$d)use($u,$v){$x=$d['invoices'][$v]??[];if(!$x||(int)$x['uid']!==$u||$x['status']!=='unpaid')return false;$d['invoices'][$v]['status']='cancelled';unset($d['invoices'][$v]['draft']);return true;});ans($id,$ok?'خرید لغو شد.':'فاکتور قابل لغو نیست.',true);invoiceList($u,$m);return true;}
 if($act==='invoice_coupon'){$x=dbread()['invoices'][$v]??[];if(!$x||(int)$x['uid']!==$u||$x['status']!=='unpaid')return true;ask($u,$m,"🎟 | تخفیف خرید\n\nکد تخفیف خود را ارسال کنید؛ مبلغ نهایی پیش از پرداخت نمایش داده می‌شود.",'invoice_coupon',['iid'=>$v],'invoice|'.$v);return true;}
 if($act==='a_invoices'){invoiceList($u,$m,true);return true;}
 if($act==='a_bot_search'){ask($u,$m,"🔎 | جستجوی اشتراک\n\nشماره اشتراک، شناسه مالک یا نام کاربری ربات را ارسال کنید.",'admin_bot_search',[],'a_bots');return true;}
 if(in_array($act,['bot_history','bot_support'],true)){$x=dbread()['bots'][$v]??[];if(!owns($x,$u)){ans($id,'دسترسی ندارید.',true);return true;}if($act==='bot_support'){ask($u,$m,'💬 | پشتیبانی اشتراک #'.$v."\n\nسؤال یا مشکل خود را بنویسید؛ جزئیات بیشتر به پاسخ دقیق‌تر کمک می‌کند.",'support_new',['related_bot'=>$v],'bot|'.$v);return true;}$t='📋 | رویدادهای اشتراک #'.$v;foreach(array_slice($x['events']??[],-10) as $e)$t.="\n\n🗓 ".date('Y/m/d H:i',$e['at'])."\n".esc($e['text']);page($u,$m,$t,[back('bot|'.$v)]);return true;}
 if($act==='refund_commit'&&isAdmin($u)){$s=state($u);if(($s['step']??'')==='refund_confirm'&&($s['sid']??'')===$v)ask($u,$m,"✍️ | دلیل بازپرداخت\n\nدلیل لغو سفارش را بنویسید؛ این توضیح برای خریدار ارسال می‌شود.",'refund_reason',['sid'=>$v],'ab|'.$v);return true;}
 if($act==='a_wallet'){if(!isAdmin($u)){ans($id,'دسترسی ندارید.',true);return true;}$kb=[];foreach(array_reverse(dbread()['funding'],true) as $key=>$x)if(($x['gateway']??'card')==='card')$kb[]=[b('💳 | #'.$key.' · '.money((int)$x['amount']).' · '.fundingLabel($x['status']),'funding|'.$key)];$kb[]=back('admin');page($u,$m,"📥 | درخواست‌های افزایش موجودی\n\nواریزهای کارت‌به‌کارت را بررسی و تأیید یا رد کنید.\nپرداخت‌های تون پی به‌صورت خودکار بررسی می‌شوند.",$kb);return true;}
 if($act==='funding'){fundingView($u,$m,$v);return true;}
 if($act==='funding_confirm'){page($u,$m,"💳 | تأیید افزایش موجودی\n\n<u>واریز را در حساب بانکی بررسی کرده‌اید؟</u>\nبا تأیید، مبلغ درخواست به کیف پول کاربر افزوده می‌شود.",[[b('✅ | واریز تأیید شد','funding_commit|'.$v)],back('funding|'.$v)],['step'=>'funding_confirm','pid'=>$v]);return true;}
 if($act==='funding_commit'){$s=state($u);if(($s['step']??'')!=='funding_confirm'||($s['pid']??'')!==$v)return true;$r=reviewFunding((int)$v,$u,true);ans($id,$r['ok']?'موجودی اضافه شد.':$r['reason'],true);fundingView($u,$m,$v);return true;}
 if($act==='funding_reject'){ask($u,$m,'✍️ | دلیل رد درخواست شارژ را بنویسید.','funding_reject',['pid'=>$v],'funding|'.$v);return true;}
 if($act==='tonpay_check'){$r=tonPayCheckAndCredit((int)$v,$u);ans($id,$r['message'],true);tonPayView($u,$m,(int)$v);return true;}
 return false;
}
function makerMessages(array $msg):bool{
 $u=(int)$msg['from']['id'];$s=state($u);$step=$s['step']??'';$m=(int)($s['mid']??0);$t=trim((string)($msg['text']??''));$error=function(string $t)use($u,$m,$s){page($u,$m,'❌ | '.$t,[back(isAdmin($u)?'admin':'wallet')],$s);};
 if($step==='invoice_coupon'){$x=dbread()['invoices'][(string)$s['iid']]??[];$code=strtoupper($t);if(!$x||(int)$x['uid']!==$u||$x['status']!=='unpaid'||empty($x['draft'])){$error('این خرید قابل تغییر نیست.');return true;}if(!checkDiscount(dbread(),$code,$u,(string)$x['draft']['plan'])){$error('کد برای این پلن معتبر نیست.');return true;}dbmut(function(&$d)use($u,$code){$d['users'][(string)$u]['coupon']=$code;});$draft=$x['draft'];unset($draft['nonce'],$draft['at'],$draft['invoice_id']);quotePurchase($u,$m,$draft);return true;}
 if($step==='gateway_api'){if(!isAdmin($u))return true;$g=(string)($s['gateway']??'');if(!in_array($g,['atlas','toon'],true))return true;$val=trim($t)==='-'?'':trim($t);if($val!==''&&(strlen($val)<6||strlen($val)>500)){$error('API باید بین ۶ تا ۵۰۰ کاراکتر باشد.');return true;}dbmut(function(&$d)use($g,$val,$u){$d['settings'][$g.'_api']=$val;$d['states'][(string)$u]=['step'=>'admin'];});financeHome($u,$m);return true;}
 if($step==='admin_add'){if(!isAdmin($u))return true;$raw=digits($t);if(!ctype_digit($raw)||strlen($raw)<5||strlen($raw)>15){$error('آیدی عددی تلگرام معتبر ارسال کنید.');return true;}$aid=(int)$raw;if($aid===ADMIN){$error('این شناسه ادمین اصلی است.');return true;}dbmut(function(&$d)use($aid,$u){$list=array_values(array_unique(array_map('intval',(array)($d['settings']['admins']??[]))));if(!in_array($aid,$list,true))$list[]=$aid;$d['settings']['admins']=$list;$d['states'][(string)$u]=['step'=>'admin'];});adminManagers($u,$m);return true;}
 if($step==='deposit_amount'){$n=amount($t);if($n===null){$error('مبلغ را به تومان و با عدد مثبت وارد کنید.');return true;}$gateway=(string)($s['gateway']??'card');$cfg=dbread()['settings'];if($gateway==='toon'){$r=tonPayCreate($u,$n);if(!($r['ok']??false)){$error('ساخت پرداخت تون پی ناموفق بود: '.($r['message']??'خطای نامشخص'));return true;}$pid=(int)$r['payment_id'];tonPayView($u,$m,$pid);return true;}if(!$cfg['card_number']||!$cfg['card_owner']){$error('اطلاعات واریز هنوز تکمیل نشده است.');return true;}page($u,$m,"💳 | اطلاعات واریز

💰 مبلغ: <u>".money($n)." تومان</u>
💳 شماره کارت: <code>".esc($cfg['card_number'])."</code>
👤 صاحب حساب: ".esc($cfg['card_owner'])."

🔖 پس از واریز، <u>کد پیگیری بانکی</u> را ارسال کنید.
<i>موجودی پس از بررسی و تأیید مدیر افزوده می‌شود.</i>",[back('wallet')],['step'=>'funding_reference','amount'=>$n,'gateway'=>'card','nonce'=>bin2hex(random_bytes(10)),'at'=>time()]);return true;}
 if($step==='funding_reference'){$ref=digits($t);if(!preg_match('/^[A-Za-z0-9-]{4,40}$/D',$ref)){$error('کد پیگیری باید ۴ تا ۴۰ حرف یا رقم باشد؛ تصویر دریافت نمی‌شود.');return true;}$pid=dbmut(function(&$d)use($u,$s,$ref){$cur=$d['states'][(string)$u]??[];if(($cur['step']??'')!=='funding_reference'||($cur['nonce']??'')!==($s['nonce']??'')||time()-(int)$s['at']>86400)return 0;foreach($d['funding'] as $p)if(($p['reference']??'')===$ref&&in_array($p['status'],['pending','approved'],true))return 0;$id=++$d['seq']['payment'];$d['funding'][(string)$id]=['id'=>$id,'uid'=>$u,'amount'=>(int)$s['amount'],'reference'=>$ref,'status'=>'pending','created'=>time(),'nonce'=>$s['nonce'],'gateway'=>(string)($s['gateway']??'card')];$d['states'][(string)$u]=['step'=>'wallet','mid'=>$s['mid']??0];notifyLater($d,ADMIN,'💳 | درخواست شارژ #'.$id."\n\n👤 کاربر: <code>".$u."</code>\n💰 مبلغ: ".money((int)$s['amount']).' تومان','funding-new:'.$id,[[b('🔎 | بررسی درخواست','funding|'.$id)]]);return$id;});if(!$pid){$error('درخواست منقضی شده یا این کد پیگیری قبلاً ثبت شده است.');return true;}fundingView($u,$m,(string)$pid);return true;}
 if(!in_array($step,['admin_bot_search','refund_reason','funding_reject'],true))return false;if(!isAdmin($u))return true;
 if($step==='admin_bot_search'){$kb=[];foreach(dbread()['bots'] as $id=>$x)if((string)$id===digits($t)||(string)$x['uid']===digits($t)||($t!==''&&stripos($x['username'],ltrim($t,'@'))!==false))$kb[]=[b('🦊 | #'.$id.' @'.$x['username'],'ab|'.$id)];$kb[]=back('a_bots');page($u,$m,'🔎 | نتیجه جستجو',$kb);return true;}
 if(mb_strlen($t)<3||mb_strlen($t)>300){$error('توضیح باید بین ۳ تا ۳۰۰ نویسه باشد.');return true;}
 if($step==='funding_reject'){$r=reviewFunding((int)$s['pid'],$u,false,$t);if(!$r['ok'])$error($r['reason']);else fundingView($u,$m,(string)$s['pid']);return true;}
 $r=refundBot((int)$s['sid'],$u,$t);if(!$r['ok'])$error($r['reason']);else botView($u,$m,(string)$s['sid']);return true;
}
function tonPayRequest(string $method,string $path,?array $body=null):array{
 $cfg=dbread()['settings']??[];$key=trim((string)($cfg['toon_api']??''));if($key==='')return['ok'=>false,'message'=>'API تون پی ثبت نشده است.'];
 $ch=curl_init('https://tonpays.online'.$path);$headers=['Accept: application/json','X-API-Key: '.$key];$opt=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>25,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CUSTOMREQUEST=>$method];
 if($body!==null){$json=json_encode($body,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$headers[]='Content-Type: application/json';$opt[CURLOPT_HTTPHEADER]=$headers;$opt[CURLOPT_POSTFIELDS]=$json;}
 curl_setopt_array($ch,$opt);$raw=curl_exec($ch);$err=curl_error($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
 if($raw===false||$err!=='')return['ok'=>false,'message'=>'خطا در اتصال به تون پی'];$j=json_decode((string)$raw,true);if(!is_array($j))return['ok'=>false,'message'=>'پاسخ نامعتبر از تون پی','http'=>$code];if($code<200||$code>=300){$msg='HTTP '.$code;if(isset($j['error'])&&is_array($j['error'])){$msg=trim((string)($j['error']['message']??$j['error']['code']??$msg));}elseif(isset($j['detail'])){if(is_string($j['detail']))$msg=$j['detail'];elseif(is_array($j['detail'])){$parts=[];foreach($j['detail'] as $e){if(is_array($e)){$loc=isset($e['loc'])&&is_array($e['loc'])?implode('.',array_map('strval',$e['loc'])):'';$em=trim((string)($e['msg']??$e['message']??''));if($em!=='')$parts[]=(($loc!=='')?$loc.': ':'').$em;}}if($parts)$msg=implode(' | ',$parts);}}elseif(isset($j['message']))$msg=(string)$j['message'];return['ok'=>false,'message'=>$msg,'http'=>$code,'data'=>$j];}return['ok'=>true,'http'=>$code,'data'=>$j];
}
function tonPayCallbackUrl():string{$base=publicUrl();if($base==='')return'';return$base.(str_contains($base,'?')?'&':'?').'tonpays=1';}
function tonPayCreate(int $uid,int $amount):array{
 $cfg=dbread()['settings']??[];if($amount<20000)return['ok'=>false,'message'=>'حداقل مبلغ پرداخت از طریق تون پی ۲۰٬۰۰۰ تومان است.'];if(!($cfg['gateway_toon']??false))return['ok'=>false,'message'=>'درگاه تون پی غیرفعال است.'];if(trim((string)($cfg['toon_api']??''))==='')return['ok'=>false,'message'=>'API تون پی ثبت نشده است.'];$cb=tonPayCallbackUrl();if($cb==='')return['ok'=>false,'message'=>'آدرس HTTPS ربات تنظیم نشده است.'];
 $order='MS'.time().strtoupper(bin2hex(random_bytes(3)));$r=tonPayRequest('POST','/api/v1/invoices/create',['amount'=>$amount,'callback_url'=>$cb,'order_id'=>$order]);if(!($r['ok']??false))return$r;$x=$r['data'];$iid=trim((string)($x['invoice_id']??''));$url=trim((string)($x['payment_url']??$x['invoice_url']??''));if($iid===''||!filter_var($url,FILTER_VALIDATE_URL))return['ok'=>false,'message'=>'شناسه یا لینک پرداخت در پاسخ تون پی موجود نیست.'];
 $pid=dbmut(function(&$d)use($uid,$amount,$order,$iid,$url,$x){$id=++$d['seq']['payment'];$d['funding'][(string)$id]=['id'=>$id,'uid'=>$uid,'amount'=>$amount,'request_amount'=>(int)($x['request_amount']??$amount),'final_amount'=>(int)($x['final_amount']??0),'reference'=>$iid,'invoice_id'=>$iid,'order_id'=>$order,'payment_url'=>$url,'status'=>'pending','provider_status'=>(string)($x['status']??'created'),'created'=>time(),'next_check'=>time()+20,'gateway'=>'toon'];$d['states'][(string)$uid]=['step'=>'wallet'];return$id;});return['ok'=>true,'payment_id'=>$pid,'invoice_id'=>$iid,'payment_url'=>$url];
}
function tonPayApply(array $payload):array{
 $iid=trim((string)($payload['invoice_id']??''));$order=trim((string)($payload['order_id']??''));$paid=($payload['paid']??false)===true;$status=strtolower(trim((string)($payload['status']??'')));if($iid===''||$order==='')return['ok'=>false,'message'=>'اطلاعات پرداخت ناقص است.'];
 return dbmut(function(&$d)use($payload,$iid,$order,$paid,$status){$key=null;foreach($d['funding'] as $k=>$f)if(($f['gateway']??'')==='toon'&&hash_equals((string)($f['invoice_id']??''),$iid)&&hash_equals((string)($f['order_id']??''),$order)){$key=(string)$k;break;}if($key===null)return['ok'=>false,'message'=>'پرداخت پیدا نشد.'];$f=&$d['funding'][$key];$f['provider_status']=$status;$f['final_amount']=(int)($payload['final_amount']??$f['final_amount']??0);$f['checked_at']=time();if(($f['status']??'')==='approved')return['ok'=>true,'message'=>'این پرداخت قبلاً ثبت شده است.'];if(!$paid||$status!=='completed')return['ok'=>false,'message'=>'پرداخت هنوز تکمیل نشده است.'];if((int)($payload['request_amount']??0)!==(int)$f['amount'])return['ok'=>false,'message'=>'مبلغ پرداخت با درخواست مطابقت ندارد.'];$f['status']='approved';$f['approved_at']=time();book($d,(int)$f['uid'],(int)$f['amount'],'wallet_funding','tonpay:'.$iid,0);notifyLater($d,(int)$f['uid'],'✅ | پرداخت تون پی تأیید شد.' . "\n\n💰 مبلغ: ".money((int)$f['amount']).' تومان' . "\n💳 موجودی کیف پول شما افزایش یافت.",'tonpay-paid:'.$iid);notifyLater($d,ADMIN,'💳 | پرداخت تون پی موفق' . "\n\n🧾 ".esc($iid)."\n👤 کاربر: <code>".(int)$f['uid']."</code>\n💰 مبلغ: ".money((int)$f['amount']).' تومان','tonpay-admin:'.$iid);return['ok'=>true,'message'=>'پرداخت تأیید و موجودی اضافه شد.'];});
}
function tonPayCheckAndCredit(int $pid,int $uid):array{$f=dbread()['funding'][(string)$pid]??null;if(!is_array($f)||($f['gateway']??'')!=='toon'||(!isAdmin($uid)&&(int)$f['uid']!==$uid))return['ok'=>false,'message'=>'پرداخت پیدا نشد.'];if(($f['status']??'')==='approved')return['ok'=>true,'message'=>'پرداخت قبلاً تأیید شده است.'];if(($f['status']??'')==='expired')return['ok'=>false,'message'=>'این فاکتور منقضی شده است.'];$iid=(string)($f['invoice_id']??'');$r=tonPayRequest('GET','/api/v1/invoices/check/'.rawurlencode($iid));if(!($r['ok']??false))return['ok'=>false,'message'=>'بررسی تون پی ناموفق بود: '.($r['message']??'خطا')];$x=$r['data'];if(!isset($x['order_id']))$x['order_id']=$f['order_id']??'';return tonPayApply($x);}
function tonPayView(int $u,int $m,int $pid):void{
 $f=dbread()['funding'][(string)$pid]??[];if(!$f||($f['gateway']??'')!=='toon'||(!isAdmin($u)&&(int)($f['uid']??0)!==$u)){page($u,$m,'❌ | پرداخت تون پی پیدا نشد.',[back('wallet')]);return;}
 $approved=($f['status']??'')==='approved';$expired=($f['status']??'')==='expired';$request=(int)($f['request_amount']??$f['amount']??0);$final=(int)($f['final_amount']??0);$iid=esc((string)($f['invoice_id']??'—'));
 if($approved){$txt="✅ | پرداخت تون پی با موفقیت انجام شد\n\n💰 | مبلغ شارژ کیف پول: ".money($request)." تومان\n🧾 | شناسه پرداخت: <code>".$iid."</code>\n💳 | موجودی کیف پول شما افزایش یافت.\n\n<i>پرداخت تأیید شده و نیازی به اقدام دیگری نیست.</i>";page($u,$m,$txt,[[b('💳 | کیف پول','wallet')],back()],['step'=>'wallet']);return;}
 if($expired){$txt="⌛ | فاکتور تون پی منقضی شد\n\n💰 | مبلغ: ".money($request)." تومان\n🧾 | شناسه پرداخت: <code>".$iid."</code>\n\nاین فاکتور طی ۲۴ ساعت پرداخت نشد و منقضی شده است.\nبرای پرداخت، یک فاکتور جدید ایجاد کنید.";page($u,$m,$txt,[[b('💳 | ساخت پرداخت جدید','deposit_gateway|toon')],back('wallet')],['step'=>'wallet']);return;}
 $txt="🌐 | پرداخت آنلاین با تون پی\n\n💰 | مبلغ شارژ کیف پول: ".money($request)." تومان".($final>0?"\n💵 | مبلغ نهایی درگاه: ".money($final)." تومان":'')."\n🧾 | شناسه پرداخت: <code>".$iid."</code>\n📊 | وضعیت: 🕓 در انتظار پرداخت\n\nابتدا روی «ورود به صفحه پرداخت» بزنید و پرداخت را کامل کنید.\n\n<i>پس از پرداخت نیازی به ارسال رسید یا انجام کار خاصی نیست؛ ربات هر ۲۰ ثانیه وضعیت پرداخت را به‌صورت خودکار بررسی می‌کند و پس از تأیید، موجودی کیف پول شما خودکار افزایش می‌یابد.</i>";
 $kb=[];$url=(string)($f['payment_url']??'');if(filter_var($url,FILTER_VALIDATE_URL))$kb[]=[['text'=>'💳 | ورود به صفحه پرداخت','url'=>$url]];$kb[]=[b('🔄 | بررسی همین حالا','tonpay_check|'.$pid)];$kb[]=back('wallet');page($u,$m,$txt,$kb,['step'=>'tonpay_wait','payment_id'=>$pid]);
}
function tonPayAutoCheck():void{
 $due=dbmut(function(&$d){$out=[];$now=time();foreach($d['funding'] as $k=>&$f){if(($f['gateway']??'')!=='toon'||($f['status']??'')!=='pending')continue;if((int)($f['next_check']??0)>$now)continue;$f['next_check']=$now+20;$out[]=(int)$k;if(count($out)>=10)break;}unset($f);return$out;});
 foreach($due as $pid){$before=dbread()['funding'][(string)$pid]??[];$uid=(int)($before['uid']??0);if(!$uid)continue;$age=time()-(int)($before['created']??time());$r=tonPayCheckAndCredit($pid,ADMIN);$after=dbread()['funding'][(string)$pid]??[];if(($after['status']??'')==='approved'){$st=state($uid);if(($st['step']??'')==='tonpay_wait'&&(int)($st['payment_id']??0)===$pid&&(int)($st['mid']??0)>0)tonPayView($uid,(int)$st['mid'],$pid);continue;}if($age>=86400){dbmut(function(&$d)use($pid){$f=&$d['funding'][(string)$pid];if(($f['gateway']??'')==='toon'&&($f['status']??'')==='pending'){$f['status']='expired';$f['provider_status']='expired';$f['expired_at']=time();unset($f['next_check']);notifyLater($d,(int)$f['uid'],'⌛ | فاکتور تون پی منقضی شد' . "\n\nاین فاکتور طی ۲۴ ساعت پرداخت نشد. برای پرداخت، فاکتور جدید ایجاد کنید.",'tonpay-expired:'.$pid,[[b('💳 | کیف پول','wallet')]]);}});$st=state($uid);if(($st['step']??'')==='tonpay_wait'&&(int)($st['payment_id']??0)===$pid&&(int)($st['mid']??0)>0)tonPayView($uid,(int)$st['mid'],$pid);}
 }
}
function tonPayWebhook(string $raw,string $apiKey):int{$cfg=dbread()['settings']??[];$key=trim((string)($cfg['toon_api']??''));if($key===''||$apiKey===''||!hash_equals($key,$apiKey))return 403;try{$p=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}catch(Throwable $e){return 400;}if(!is_array($p))return 400;$r=tonPayApply($p);return($r['ok']??false)?200:202;}
function childDispatch(int $sid,array $up):void{
 $h=fopen(DB.'.child.'.$sid.'.lock','c+');if(!$h||!flock($h,LOCK_EX))throw new RuntimeException('Child lock failed');
 try{$x=dbread()['bots'][(string)$sid]??[];$update=(string)($up['update_id']??'');if(!$x||$update===''||isset($x['child_updates'][$update]))return;$msg=$up['message']??[];$q=$up['callback_query']??[];$u=(int)($q['from']['id']??$msg['from']['id']??0);$chat=(int)($q['message']['chat']['id']??$msg['chat']['id']??0);if(!$u||$u!==$chat)return;
 if($x['status']!=='active'||(int)$x['expires']<=time()||(dbread()['users'][(string)$x['uid']]['banned']??false)){recordChildUpdate($sid,$update,$u);return;}
 if($q)childCall($x,'answerCallbackQuery',['callback_query_id'=>$q['id']]);
 elseif(preg_match('~^/start(?:@\w+)?(?:\s|$)~',trim((string)($msg['text']??'')))){$r=childSend($x,$u,$x['welcome']);$new=(int)($r['result']['message_id']??0);$old=(int)($x['last_messages'][(string)$u]??0);if($new){if($old)childCall($x,'deleteMessage',['chat_id'=>$u,'message_id'=>$old]);dbmut(function(&$d)use($sid,$u,$new){$d['bots'][(string)$sid]['last_messages'][(string)$u]=$new;});}}
 recordChildUpdate($sid,$update,$u);
 }finally{flock($h,LOCK_UN);fclose($h);}
}
function mainSecret():string{return WEBHOOK_SECRET;}
function ingressAuthorized(int $scope,string $secret):bool{
 if($secret===''||strlen($secret)>256)return false;if($scope===0)return hash_equals(WEBHOOK_SECRET,$secret);$x=dbread()['bots'][(string)$scope]??[];return$x&&$x['status']!=='refunded'&&hash_equals((string)$x['webhook_secret'],$secret);
}
function takeRate(array &$d,string $key,float $cap,float $rate):bool{$now=microtime(true);$r=$d['rates'][$key]??['tokens'=>$cap,'at'=>$now];$tokens=min($cap,(float)$r['tokens']+max(0,$now-(float)$r['at'])*$rate);$ok=$tokens>=1;if($ok)$tokens--;$d['rates'][$key]=['tokens'=>$tokens,'at'=>$now];return$ok;}
function ingress(int $scope,string $secret,string $raw):int{
 if(!ingressAuthorized($scope,$secret))return 403;if(strlen($raw)>262144)return 413;
 try{$up=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(Throwable $e){return 400;}
 if(!is_array($up)||!isset($up['update_id'])||!is_int($up['update_id'])||$up['update_id']<0)return 400;
 $from=$up['callback_query']['from']??$up['message']['from']??[];$u=$from['id']??0;$chat=$up['callback_query']['message']['chat']['id']??$up['message']['chat']['id']??0;if(!is_int($u)||$u<=0||$chat!==$u||($from['is_bot']??false))return 200;
 return dbmut(function(&$d)use($scope,$up,$u){foreach($d['inbox'] as $j)if((int)$j['scope']===$scope&&(int)$j['update_id']===$up['update_id'])return 200;
 if($scope===0&&isset($d['updates'][(string)$up['update_id']])&&$d['updates'][(string)$up['update_id']]['status']==='done')return 200;
 if($scope>0&&isset($d['bots'][(string)$scope]['child_updates'][(string)$up['update_id']]))return 200;
 $pending=0;$perUser=0;foreach($d['inbox'] as $j)if(in_array($j['status'],['queued','running','dead'],true)){$pending++;if((int)$j['scope']===$scope&&(int)$j['actor']===$u)$perUser++;}
 if($pending>=2000)return 503;if($perUser>=20||!takeRate($d,'u:'.$scope.':'.$u,$scope?8:20,$scope?0.2:0.5))return 200;
 if(!takeRate($d,'scope:'.$scope,$scope?40:150,$scope?10:50))return 503;
 $id=++$d['seq']['inbox'];$d['inbox'][(string)$id]=['id'=>$id,'scope'=>$scope,'actor'=>$u,'update_id'=>$up['update_id'],'payload'=>sealPayload(['update'=>$up]),'status'=>'queued','attempts'=>0,'next'=>0,'lease'=>0,'owner'=>'','at'=>time()];return 200;
 });
}
function processInbox(int $limit=10):int{
 $processed=0;for($i=0;$i<$limit;$i++){
 $job=dbmut(function(&$d){foreach($d['inbox'] as &$j)if($j['status']==='running'&&(int)$j['lease']<time()){$j['status']='queued';$j['owner']='';}unset($j);$blocked=[];
 foreach($d['inbox'] as &$j){$key=$j['scope'].':'.$j['actor'];if(in_array($j['status'],['queued','running'],true)){if(isset($blocked[$key]))continue;$blocked[$key]=true;if($j['status']==='queued'&&(int)$j['next']<=time()){$j['status']='running';$j['owner']=bin2hex(random_bytes(12));$j['lease']=time()+600;$j['attempts']++;return$j;}}}unset($j);return null;});if(!$job)break;
 try{$GLOBALS['answered_callbacks']=[];$GLOBALS['incoming_text']=false;$GLOBALS['photo_callback']=false;$up=openPayload(['sealed'=>$job['payload']])['update'];if((int)$job['scope']===0)acceptUpdate($up);else childDispatch((int)$job['scope'],$up);
 dbmut(function(&$d)use($job){$j=&$d['inbox'][(string)$job['id']];if($j['owner']!==$job['owner'])return;$j['status']='done';$j['payload']='';$j['lease']=0;$j['owner']='';});}
 catch(Throwable $e){error_log('[foxima] update '.$job['id'].': '.get_class($e));dbmut(function(&$d)use($job){$j=&$d['inbox'][(string)$job['id']];if($j['owner']!==$job['owner'])return;$j['status']=$j['attempts']>=5?'dead':'queued';$j['next']=time()+min(300,2**$j['attempts']);$j['lease']=0;$j['owner']='';});}$processed++;
 }return$processed;
}
function maintainQueue():void{dbmut(function(&$d){foreach($d['inbox'] as $k=>$j)if($j['status']==='done'&&(int)$j['at']<time()-86400)unset($d['inbox'][$k]);foreach($d['rates'] as $k=>$r)if($r['at']<time()-86400)unset($d['rates'][$k]);});}
function configureStore():void{
 if(PHP_SAPI!=='cli')return;echo "Bot token (input hidden when terminal supports it): ";$hidden=function_exists('shell_exec')&&function_exists('stream_isatty')&&stream_isatty(STDIN);if($hidden)shell_exec('stty -echo');try{$token=trim((string)fgets(STDIN));}finally{if($hidden)shell_exec('stty echo');}echo"\nAdmin numeric ID: ";$admin=trim((string)fgets(STDIN));echo"HTTPS URL of this bot.php: ";$url=trim((string)fgets(STDIN));
 if(!preg_match('/^\d{5,16}:[A-Za-z0-9_-]{20,100}$/D',$token)||!ctype_digit($admin)||(int)$admin<=0||!validUrl($url))throw new RuntimeException('Invalid token, admin ID or HTTPS URL');
 dbmut(function(&$d)use($token,$admin,$url){$d['config']['token']=$token;$d['config']['admin']=(int)$admin;$d['settings']['public_url']=$url;});echo"Saved in bot_data.json. Restart workers after configuration.\n";
}
// No web-side migration: old workers must stop before the one-time CLI migration.
if(PHP_SAPI!=='cli'&&!(defined('BOT_TEST_MODE')&&BOT_TEST_MODE)){
 if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);header('Allow: POST');exit('Method not allowed');}
 if((int)($_SERVER['CONTENT_LENGTH']??0)>262144){http_response_code(413);exit('Too large');}
 $isTonPay=(($_GET['tonpays']??'')==='1');$incomingSecret=(string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']??'');if(!$isTonPay&&($incomingSecret===''||strlen($incomingSecret)>256)){http_response_code(403);exit('Forbidden');}
}
try{bootStore();}catch(Throwable $e){if(PHP_SAPI==='cli'){fwrite(STDERR,'Storage: '.$e->getMessage()."\n");exit(1);}error_log('[foxima] boot failed: '.get_class($e));http_response_code(503);exit('RETRY');}
if(defined('BOT_TEST_MODE')&&BOT_TEST_MODE)return;
if(PHP_SAPI!=='cli'&&isset($isTonPay)&&$isTonPay){if(!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json')){http_response_code(415);exit('Unsupported');}$raw=file_get_contents('php://input',false,null,0,262145);$code=tonPayWebhook($raw===false?'':$raw,(string)($_SERVER['HTTP_X_API_KEY']??''));http_response_code($code);header('Content-Type: text/plain; charset=utf-8');echo $code===200?'OK':($code===202?'IGNORED':'Rejected');exit;}
if(PHP_SAPI==='cli'){
 try{$cmd=$argv[1]??'--help';
 if($cmd==='--configure'){configureStore();exit;}
 if($cmd==='--migrate-json'){echo"Migration complete. Active state, tokens and queues are in ".DB."\nOld files were not deleted. Restart workers.\n";exit;}
 if($cmd==='--ubuntu-config'){ubuntuConfig($argv[2]??'');exit;}
 if($cmd==='--worker'){processInbox(30);worker(20);maintainQueue();exit;}
 if($cmd==='--serve'){$last=0;$notify=0;$tonpay=0;while(true){if(time()-$last>=60){maintainQueue();$last=time();}$n=processInbox(5);if(time()-$notify>=2){worker(5);$notify=time();}if(time()-$tonpay>=20){tonPayAutoCheck();$tonpay=time();}if(!$n)usleep(250000);}}
 if($cmd==='--retry-failed'){dbmut(function(&$d){foreach($d['inbox'] as &$j)if($j['status']==='dead'){$j['status']='queued';$j['attempts']=0;$j['next']=0;}unset($j);});echo"Retried failed updates.\n";exit;}
 if($cmd==='--test-provision'){provisionPdo()->query('SELECT 1')->fetchColumn();$root=templateRoot();echo"MySQL provisioner: OK\nTemplate root: ".$root."\ntable.php: ".(is_readable($root.'/table.php')?'OK':'MISSING')."\n";exit;}
 if($cmd==='--set-webhook'){$url=$argv[2]??'';if(!validUrl($url))throw new RuntimeException('Valid HTTPS URL required');$r=tg('setWebhook',['url'=>$url,'secret_token'=>WEBHOOK_SECRET,'allowed_updates'=>json_encode(['message','callback_query']),'max_connections'=>8]);if(!($r['ok']??false))throw new RuntimeException('Webhook failed; check configured token and HTTPS.');dbmut(function(&$d)use($url){$d['settings']['public_url']=$url;});echo"Webhook configured. Start workers.\n";exit;}
 echo <<<'HELP'
foxima / MuteShop 6.0 — PHP 8.1+, curl, mbstring, ctype, openssl
All persistent application state is in ONE bot_data.json; .lock files contain no data.
Stop old workers and BACK UP before upgrading. Keep legacy config.php, kyc_key.php
and maker_queue.sqlite during the first --migrate-json; legacy SQLite needs PDO SQLite
for that import only. Once successful, legacy files are no longer used or written.
  php bot.php --migrate-json
  php bot.php --configure
  php bot.php --set-webhook https://YOUR_DOMAIN/bot.php
  php bot.php --serve
Run two --serve instances under the existing systemd worker services.
Restart: systemctl restart muteshop-worker@1 muteshop-worker@2 php8.1-fpm
Generate optional Ubuntu configs: php bot.php --ubuntu-config /absolute/new/directory
The PHP/worker user must write the JSON directory. Keep JSON outside the public root,
or use the supplied Nginx configuration that only permits the bot.php endpoint.
Use BOT_DATABASE consistently in both PHP-FPM and systemd for a custom JSON path.
foxima responds to /start; subscription status, renewals and token changes remain.
Photo receipts are replaced by bank-reference funding requests with admin approval.
Only unpaid purchase invoices are visible to customers; financial history stays intact.
User messages are NEVER deleted. On input, the bot sends a fresh menu, then removes
its prior menu after successful delivery. Callback-only navigation edits the menu.
Queues survive restart. Wallet operations are idempotent; notifications may repeat
if a process dies after Telegram delivery but before local acknowledgement.
HELP;
 exit;
 }catch(Throwable $e){fwrite(STDERR,'Error: '.$e->getMessage()."\n");exit(1);}
}
header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: no-store');
$child=$_GET['child']??'0';if(!is_string($child)||!preg_match('/^(0|[1-9][0-9]{0,9})$/D',$child)){http_response_code(403);exit('Forbidden');}
if(!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json')){http_response_code(415);exit('Unsupported');}
try{if(!ingressAuthorized((int)$child,$incomingSecret)){http_response_code(403);exit('Forbidden');}$raw=file_get_contents('php://input',false,null,0,262145);if($raw===false)throw new RuntimeException('Read failed');$code=ingress((int)$child,$incomingSecret,$raw);http_response_code($code);if($code===503)header('Retry-After: 5');echo$code===200?'OK':'Rejected';}catch(Throwable $e){error_log('[foxima] ingress: '.get_class($e));http_response_code(503);header('Retry-After: 5');echo'RETRY';}
